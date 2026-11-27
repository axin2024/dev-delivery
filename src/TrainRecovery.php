<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class TrainRecovery
{
    public function __construct(private Store $store,private Projects $projects,private Audit $audit) {}

    public function detail(Actor $actor,int $project,int $train): array
    {
        $t=(new ReleaseTrains($this->store,$this->projects,$this->audit))->current($actor,$project,$train);
        $r=$this->store->one('SELECT id recovery_id,revision,state,requested_by,reason,created_at,production_rev FROM train_recoveries
            WHERE train_id=? AND revision=?',[$train,$t['train_rev']]);
        if (!$r) return ['recovery'=>null,'items'=>[],'repair'=>null];
        $items=$this->items($r['recovery_id']);
        foreach ($items as &$item) {
            foreach (['receipt','inspection'] as $field) {
                $item[$field]=$item[$field]===null?null:json_decode($item[$field],true,flags:JSON_THROW_ON_ERROR);
            }
        }
        $repair=$this->store->one('SELECT id,item_id,evidence,created_at FROM train_forward_repairs WHERE recovery_id=?',[$r['recovery_id']]);
        if ($repair) $repair['evidence']=json_decode($repair['evidence'],true,flags:JSON_THROW_ON_ERROR);
        return ['recovery'=>$r,'items'=>$items,'repair'=>$repair];
    }

    private function items(int $id): array
    {
        return $this->store->all('SELECT i.id,i.operation_key,i.target_operation,i.inspection,i.receipt,i.verified_at,
            o.node,o.service_id,o.digest,o.previous_digest,o.operation_key applied_operation FROM train_recovery_items i
            JOIN train_target_operations o ON o.id=i.target_operation WHERE i.recovery_id=? ORDER BY i.id',[$id]);
    }

    public function control(Actor $actor,int $project,int $train,array $body,bool $prepare): array
    {
        $revision=Input::integer($body,'revision',1);
        return $this->store->transaction(function () use ($actor,$project,$train,$revision,$body,$prepare) {
            $this->projects->lockForApproval($actor,$project);
            $trains=new ReleaseTrains($this->store,$this->projects,$this->audit);
            $t=$trains->current($actor,$project,$train);
            $env=(new TrainEnvironments($this->store,$this->projects,$this->audit))->authorized($actor,$project,$t['production_id']);
            if ($env['owner_id']!==$actor->accountId) throw new Problem(403,'environment_owner_required');
            if ($t['train_rev']!==$revision) throw new Problem(409,'train_revision_changed');
            $r=$this->store->one('SELECT * FROM train_recoveries WHERE train_id=? AND revision=? FOR UPDATE',[$train,$revision]);
            if ($prepare) {
                if (!$r) {
                    $reason=Input::string($body,'reason',600);
                    if ($t['state']!=='paused') throw new Problem(409,'pause_before_recovery');
                    $rows=$this->store->all('SELECT o.id,o.state,o.service_id,o.applied_at,o.node,o.digest,o.previous_digest,p.production_rev
                        FROM train_target_operations o JOIN train_executions e ON e.id=o.execution_id JOIN train_waves w ON w.id=e.wave_id
                        JOIN train_wave_plans p ON p.id=w.plan_id WHERE p.train_id=? AND p.revision=? ORDER BY o.id DESC',[$train,$revision]);
                    $changed=[];
                    foreach ($rows as $row) {
                        if (in_array($row['state'],['working','unknown'],true)) throw new Problem(409,'reconcile_pending_execution');
                        if ($row['applied_at']===null || $row['digest']===$row['previous_digest']) continue;
                        if ($row['production_rev']!==$env['environment_rev']) throw new Problem(409,'environment_revision_changed');
                        $changed[$row['service_id']][]=$row;
                    }
                    if (!$changed) throw new Problem(409,'no_changed_targets');
                    $manifest=json_decode($trains->version($actor,$project,$train,$revision)['manifest'],true,flags:JSON_THROW_ON_ERROR);
                    $id=$this->store->insert('INSERT INTO train_recoveries (train_id,revision,production_rev,requested_by,reason) VALUES (?,?,?,?,?)',
                        [$train,$revision,$env['environment_rev'],$actor->accountId,$reason]);
                    foreach (array_reverse($manifest['components']) as $component) {
                        foreach ($changed[$component['service_id']] ?? [] as $row) {
                            $this->store->execute('INSERT INTO train_recovery_items (recovery_id,target_operation,operation_key) VALUES (?,?,?)',
                                [$id,$row['id'],bin2hex(random_bytes(16))]);
                        }
                    }
                    $this->audit->record($actor,'project',$project,'train_recovery_requested',['recovery_id'=>$id,'reason'=>$reason]);
                }
                return $this->detail($actor,$project,$train);
            }
            if (!$r) throw new Problem(409,'recovery_not_requested');
            if (in_array($r['state'],['done','repair'],true)) return $this->detail($actor,$project,$train);
            if ($r['production_rev']!==$env['environment_rev']) throw new Problem(409,'environment_revision_changed');
            $requestId=Input::integer($body,'item_id',1);
            $phase=Input::string($body,'phase',12);
            $items=$this->items($r['id']);
            $checking=$r['state']==='inspecting';
            foreach ($items as $item) {
                if (($checking?$item['inspection']:$item['verified_at'])!==null) continue;
                // Pin each click or retry to the same item and phase, including after ambiguous HTTP outcomes.
                if ($requestId!==$item['id'] || $phase!==($checking?'inspect':'restore')) {
                    return $this->detail($actor,$project,$train);
                }
                $payload=['scope'=>implode(':',[$actor->tenantId,$project,$t['production_id']]),'node'=>$item['node'],'service'=>$item['service_id'],
                    'operation'=>$item['operation_key'],'digest'=>$item['previous_digest'],'expected_digest'=>$item['digest'],
                    'expected_operation'=>$item['applied_operation']];
                $worker=new TrainExecution($this->store,$this->projects,$this->audit);
                $invoke=fn(string $command)=>$worker->invoke($item['target_operation'],$command,$payload);
                try {
                    if ($checking) {
                        $probe=$invoke('inspect');
                        if (!array_key_exists('actual_digest',$probe) || !array_key_exists('target_digest',$probe) ||
                            $probe['actual_digest']!==$item['digest'] || $probe['target_digest']!==$item['previous_digest'] ||
                            ($probe['actual_operation'] ?? null)!==$item['applied_operation'] || ($probe['passed'] ?? null)!==true) {
                            return $this->repair($actor,$project,$train,$r['id'],$item['id'],$probe);
                        }
                        $this->store->execute('UPDATE train_recovery_items SET inspection=? WHERE id=?',[json_encode($probe,JSON_THROW_ON_ERROR),$item['id']]);
                    } else {
                        $prior=$invoke('result');
                        if (!is_bool($prior['found'] ?? null)) throw new Problem(503,'invalid_adapter_readback');
                        $receipt=$prior['found']?$prior['receipt']:$invoke('restore');
                        $read=$invoke('current');
                        if (($receipt['operation'] ?? null)!==$item['operation_key'] || ($receipt['node'] ?? null)!==$item['node'] ||
                            ($receipt['service'] ?? null)!==$item['service_id'] || !array_key_exists('digest',$receipt) ||
                            $receipt['digest']!==$item['previous_digest'] || ($receipt['probe']['passed'] ?? null)!==true ||
                            !array_key_exists('digest',$read) || $read['digest']!==$item['previous_digest'] || ($read['operation'] ?? null)!==$item['operation_key']) {
                            return $this->repair($actor,$project,$train,$r['id'],$item['id'],['error'=>'restore_verification_failed','actual'=>$read,'receipt'=>$receipt]);
                        }
                        $receipt['verified_digest']=$read['digest'];
                        $this->store->execute('UPDATE train_recovery_items SET receipt=?,verified_at=UTC_TIMESTAMP(6) WHERE id=?',
                            [json_encode($receipt,JSON_THROW_ON_ERROR),$item['id']]);
                    }
                } catch (Problem $error) {
                    if ($error->status!==409) throw $error;
                    return $this->repair($actor,$project,$train,$r['id'],$item['id'],['error'=>$error->reason,'details'=>$error->details]);
                }
                $column=$checking?'inspection':'verified_at';
                $pending=$this->store->one("SELECT id FROM train_recovery_items WHERE recovery_id=? AND $column IS NULL LIMIT 1",[$r['id']]);
                $state=$checking?($pending?'inspecting':'ready'):($pending?'restoring':'done');
                $this->store->execute('UPDATE train_recoveries SET state=? WHERE id=?',[$state,$r['id']]);
                if ($state==='done') {
                    $this->store->execute("UPDATE release_trains SET state='recovered' WHERE id=?",[$train]);
                    $this->store->execute("UPDATE train_windows SET released_at=UTC_TIMESTAMP(6) WHERE train_id=? AND released_at IS NULL",[$train]);
                    $this->audit->record($actor,'project',$project,'train_recovery_verified',['recovery_id'=>$r['id']]);
                }
                break;
            }
            return $this->detail($actor,$project,$train);
        });
    }

    private function repair(Actor $actor,int $project,int $train,int $recovery,int $item,array $evidence): array
    {
        $ticket=$this->store->insert('INSERT INTO train_forward_repairs (recovery_id,item_id,evidence) VALUES (?,?,?)',
            [$recovery,$item,json_encode($evidence,JSON_THROW_ON_ERROR)]);
        $this->store->execute("UPDATE train_recoveries SET state='repair' WHERE id=?",[$recovery]);
        $this->audit->record($actor,'project',$project,'train_forward_repair_required',['ticket'=>$ticket,'recovery_id'=>$recovery]);
        return $this->detail($actor,$project,$train);
    }
}
