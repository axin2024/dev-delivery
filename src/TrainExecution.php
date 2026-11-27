<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class TrainExecution
{
    public function __construct(private Store $store,private Projects $projects,private Audit $audit) {}

    private function context(int $execution): array
    {
        return $this->store->one('SELECT e.id execution_id,e.actor_id,e.trace_id,w.id wave_id,w.state wave_state,w.plan_id,t.id train_id,t.tenant_id,
            t.project_id,t.production_id,t.state train_state,p.revision,p.production_rev,e.approval_id,t.train_rev
            FROM train_executions e JOIN train_waves w ON w.id=e.wave_id JOIN train_wave_plans p ON p.id=w.plan_id
            JOIN release_trains t ON t.id=p.train_id WHERE e.id=?',[$execution]) ?? throw new Problem(404,'execution_not_found');
    }

    public function detail(Actor $actor,int $project,int $train,int $execution): array
    {
        $this->projects->view($actor,$project);
        $c=$this->context($execution);
        if ([$c['tenant_id'],$c['project_id'],$c['train_id']]!==[$actor->tenantId,$project,$train]) {
            throw new Problem(404,'execution_not_found');
        }
        $items=$this->store->all('SELECT id,node,service_id,digest,previous_digest,operation_key,state,receipt,applied_at
            FROM train_target_operations WHERE execution_id=? ORDER BY id',[$execution]);
        foreach ($items as &$item) {
            $item['receipt']=$item['receipt']===null?null:json_decode($item['receipt'],true,flags:JSON_THROW_ON_ERROR);
        }
        $events=$this->store->all('SELECT h.id,h.operation_id,o.node,o.service_id,h.command,h.started_at,h.ended_at,h.outcome,h.observed_digest
            FROM train_adapter_calls h JOIN train_target_operations o ON o.id=h.operation_id WHERE o.execution_id=? ORDER BY h.id',[$execution]);
        $decision=$this->store->one('SELECT e.accepted_at,e.observation_id,e.approval_id,o.anchor_at,o.sample_through,o.before_requests,o.after_requests,
            o.before_errors,o.after_errors,o.decision FROM train_executions e JOIN train_observations o ON o.id=e.observation_id WHERE e.id=?',[$execution]);
        return ['execution_id'=>$execution,'trace_id'=>$c['trace_id'],'wave_state'=>$c['wave_state'],'targets'=>$items,'timeline'=>$events,
            'quality'=>$decision,'handovers'=>$this->audit->timeline($actor,'train_execution',$execution,0)['items']];
    }

    public function handover(Actor $actor,int $project,int $train,int $execution,array $body): array
    {
        return $this->store->transaction(function () use ($actor,$project,$train,$execution,$body) {
            $this->projects->lockForApproval($actor,$project);
            $c=$this->context($execution);
            if ([$c['tenant_id'],$c['project_id'],$c['train_id']]!==[$actor->tenantId,$project,$train]) throw new Problem(404,'execution_not_found');
            $env=(new TrainEnvironments($this->store,$this->projects,$this->audit))->authorized($actor,$project,$c['production_id']);
            if ($env['owner_id']!==$actor->accountId) throw new Problem(403,'environment_owner_required');
            $key=Input::string($body,'operation',32,32);
            $node=Input::slug($body,'node');
            $digest=Input::sha256($body,'digest');
            $external=Input::string($body,'external_receipt',200);
            $op=$this->store->one('SELECT * FROM train_target_operations WHERE execution_id=? AND operation_key=? AND node=? AND digest=?',
                [$execution,$key,$node,$digest]) ?? throw new Problem(404,'target_operation_not_found');
            $old=$this->store->one("SELECT id,details FROM audit_events WHERE tenant_id=? AND object_type='train_execution' AND object_id=?
                AND action='manual_handover' AND JSON_UNQUOTE(JSON_EXTRACT(details,'$.operation'))=? LIMIT 1",[$actor->tenantId,$execution,$key]);
            if ($old) {
                $saved=json_decode($old['details'],true,flags:JSON_THROW_ON_ERROR);
                if ($saved['external_receipt']!==$external) throw new Problem(409,'manual_receipt_conflict');
                return $saved;
            }
            $target=['scope'=>implode(':',[$actor->tenantId,$project,$c['production_id']]),'service'=>$op['service_id'],'node'=>$node,'operation'=>$key];
            $result=$this->invoke($op['id'],'result',$target);
            $read=$this->invoke($op['id'],'current',$target);
            if (($result['receipt']['operation'] ?? null)!==$key || ($result['receipt']['digest'] ?? null)!==$digest ||
                ($read['operation'] ?? null)!==$key || ($read['digest'] ?? null)!==$digest) throw new Problem(409,'manual_receipt_unverified');
            $saved=['operation'=>$key,'node'=>$node,'digest'=>$digest,'external_receipt'=>$external,'verified'=>$result['receipt']];
            $this->audit->record($actor,'train_execution',$execution,'manual_handover',$saved);
            return $saved;
        });
    }

    public function hold(Actor $actor,int $project,int $train,bool $resume,array $body): array
    {
        $reason=Input::string($body,'reason',600);
        return $this->store->transaction(function () use ($actor,$project,$train,$resume,$reason) {
            $this->projects->lockForApproval($actor,$project);
            $t=(new ReleaseTrains($this->store,$this->projects,$this->audit))->current($actor,$project,$train);
            $env=(new TrainEnvironments($this->store,$this->projects,$this->audit))->read($actor,$project,$t['production_id']);
            if (!$actor->has('release_manager') && $actor->accountId!==$env['owner_id']) {
                throw new Problem(403,'release_operator_required');
            }
            if ($resume?$t['state']!=='paused':!in_array($t['state'],['running','scheduled'],true)) {
                throw new Problem(409,'invalid_control_state');
            }
            if ($resume && $this->store->one('SELECT id FROM train_recoveries WHERE train_id=? AND revision=?',[$train,$t['train_rev']])) {
                throw new Problem(409,'recovery_in_progress');
            }
            if ($resume && $this->store->one("SELECT w.id FROM train_waves w JOIN train_wave_plans p ON p.id=w.plan_id
                WHERE p.train_id=? AND p.revision=? AND w.state='failed' LIMIT 1",[$train,$t['train_rev']])) {
                throw new Problem(409,'failed_wave_requires_recovery');
            }
            $state=$resume?'running':'paused';
            $this->store->execute('UPDATE release_trains SET state=? WHERE id=?',[$state,$train]);
            $this->audit->record($actor,'project',$project,'train_'.$state,['train_id'=>$train,'reason'=>$reason]);
            return ['train_id'=>$train,'state'=>$state,'reason'=>$reason];
        });
    }

    public function invoke(int $operation,string $command,array $payload): array
    {
        $call=$this->store->insert('INSERT INTO train_adapter_calls (operation_id,command) VALUES (?,?)',[$operation,$command]);
        try {
            $result=(new TrainAdapter())->call($command,$payload);
        } catch (Problem $error) {
            $this->store->execute('UPDATE train_adapter_calls SET ended_at=UTC_TIMESTAMP(6),outcome=? WHERE id=?',[$error->reason,$call]);
            throw $error;
        }
        $digest=$result['digest'] ?? $result['receipt']['digest'] ?? null;
        $this->store->execute("UPDATE train_adapter_calls SET ended_at=UTC_TIMESTAMP(6),outcome='ok',observed_digest=? WHERE id=?",[$digest,$call]);
        return $result;
    }

    private function permitted(Actor $actor,array $ctx): bool
    {
        $c=$this->context($ctx['execution_id']);
        if ($c['train_state']!=='running' || $c['revision']!==$c['train_rev']) {
            return false;
        }
        $window=$this->store->one('SELECT g.production_rev FROM train_windows w JOIN train_release_clearances g ON g.window_id=w.id
            WHERE w.train_id=? AND w.revision=? AND g.id=? AND w.released_at IS NULL AND w.starts_at<=UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6)<w.ends_at',
            [$c['train_id'],$c['revision'],$c['approval_id']]);
        $g=(new TrainReadiness($this->store,$this->projects,$this->audit))->gates($actor,$c['project_id'],$c['train_id']);
        return $g['ready'] && ($window['production_rev'] ?? null)===$g['production']['environment_rev'] && $c['production_rev']===$g['production']['environment_rev'];
    }

    public function step(int $execution): array
    {
        $c=$this->context($execution);
        $actor=(new Accounts($this->store,$this->audit))->find($c['tenant_id'],$c['actor_id']);
        $op=$this->store->transaction(function () use ($actor,$execution,$c) {
            $this->projects->lockForRelease($actor,$c['project_id']);
            $op=$this->store->one("SELECT * FROM train_target_operations WHERE execution_id=? AND state<>'done' ORDER BY id LIMIT 1 FOR UPDATE",[$execution]);
            if (!$op) {
                return ['result'=>'done'];
            }
            if ($op['state']==='failed') {
                return ['result'=>'failed'];
            }
            if ($op['state']==='working' && $this->store->one('SELECT 1 FROM train_target_operations WHERE id=? AND lease_until>UTC_TIMESTAMP(6)',[$op['id']])) {
                return ['result'=>'busy'];
            }
            if ($op['state']==='waiting' && !$this->permitted($actor,$c)) {
                return ['result'=>'paused'];
            }
            $op['lease_token']=bin2hex(random_bytes(16));
            $this->store->execute("UPDATE train_target_operations SET state='working',lease_token=?,lease_until=TIMESTAMPADD(SECOND,45,UTC_TIMESTAMP(6)) WHERE id=?",
                [$op['lease_token'],$op['id']]);
            $this->store->execute("UPDATE train_waves SET state='running' WHERE id=? AND state='accepted'",[$c['wave_id']]);
            return $op;
        });
        if (isset($op['result'])) {
            return ['execution_id'=>$execution,'state'=>$op['result']];
        }
        $target=['scope'=>implode(':',[$c['tenant_id'],$c['project_id'],$c['production_id']]),'node'=>$op['node'],'service'=>$op['service_id'],'operation'=>$op['operation_key']];
        $receipt=null;
        $state='unknown';
        try {
            $prior=$this->invoke($op['id'],'result',$target);
            $actual=$this->invoke($op['id'],'current',$target);
            if (!is_bool($prior['found'] ?? null) || !array_key_exists('digest',$actual)) {
                throw new Problem(503,'invalid_adapter_readback');
            }
            if ($prior['found']) {
                $receipt=$prior['receipt'];
            } elseif (!$this->permitted($actor,$c)) {
                $state='waiting';
            } elseif ($actual['digest']!==$op['previous_digest']) {
                $state='failed';
                $receipt=['error'=>'node_digest_changed','observed_digest'=>$actual['digest']];
            } else {
                $file=$this->store->one('SELECT content FROM artifact_contents WHERE tenant_id=? AND project_id=? AND artifact_id=?',
                    [$c['tenant_id'],$c['project_id'],$op['artifact_id']]) ?? throw new Problem(409,'artifact_content_not_found');
                $receipt=$this->invoke($op['id'],'apply',$target+['expected_digest'=>$op['previous_digest'],'digest'=>$op['digest'],'content_base64'=>base64_encode($file['content'])]);
                $actual=$this->invoke($op['id'],'current',$target);
            }
            if ($receipt!==null && $state!=='failed') {
                if (($receipt['operation'] ?? null)!==$op['operation_key'] || ($receipt['node'] ?? null)!==$op['node'] ||
                    ($receipt['service'] ?? null)!==$op['service_id'] || ($receipt['digest'] ?? null)!==$op['digest'] ||
                    !array_key_exists('previous_digest',$receipt) || $receipt['previous_digest']!==$op['previous_digest'] ||
                    !preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}\z/',$receipt['applied_at'] ?? '')) {
                    throw new Problem(503,'invalid_adapter_receipt');
                }
                $receipt['observed_digest']=$actual['digest'];
                $state=$actual['digest']===$op['digest'] && ($actual['operation'] ?? null)===$op['operation_key'] && ($receipt['probe']['passed'] ?? false)===true?'done':'failed';
            }
        } catch (Problem $error) {
            $receipt=['error'=>$error->reason,'details'=>$error->details];
            $state=$error->status===409?'failed':'unknown';
        }
        return $this->store->transaction(function () use ($actor,$execution,$c,$op,$receipt,$state) {
            $this->projects->lockForRelease($actor,$c['project_id']);
            $changed=$this->store->execute('UPDATE train_target_operations SET state=?,receipt=?,applied_at=?,lease_token=NULL,lease_until=NULL
                WHERE id=? AND lease_token=?',[$state,$receipt===null?null:json_encode($receipt,JSON_THROW_ON_ERROR),$receipt['applied_at'] ?? null,$op['id'],$op['lease_token']]);
            if (!$changed) {
                return ['execution_id'=>$execution,'state'=>'lease_reassigned'];
            }
            $outcome=$state;
            if ($state==='failed') {
                $this->store->execute("UPDATE train_waves SET state='failed' WHERE id=?",[$c['wave_id']]);
                $this->store->execute("UPDATE release_trains SET state='paused' WHERE id=?",[$c['train_id']]);
            } elseif ($state==='done') {
                $unfinished=$this->store->one("SELECT id FROM train_target_operations WHERE execution_id=? AND state<>'done' LIMIT 1",[$execution]);
                if ($unfinished) {
                    $outcome='running';
                } else {
                    $this->store->execute("UPDATE train_waves SET state='done',completed_at=(SELECT MAX(applied_at) FROM train_target_operations WHERE execution_id=?) WHERE id=?",
                        [$execution,$c['wave_id']]);
                    if (!$this->store->one("SELECT id FROM train_waves WHERE plan_id=? AND state<>'done' LIMIT 1",[$c['plan_id']])) {
                        $this->store->execute("UPDATE release_trains SET state='completed' WHERE id=?",[$c['train_id']]);
                    }
                }
            }
            return ['execution_id'=>$execution,'state'=>$outcome,'node'=>$op['node'],'service_id'=>$op['service_id'],'operation'=>$op['operation_key'],'receipt'=>$receipt];
        });
    }
}
