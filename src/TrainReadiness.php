<?php
declare(strict_types=1);

namespace DevDelivery;

use DateTimeImmutable;
use DateTimeZone;

final readonly class TrainReadiness
{
    private ReleaseTrains $trains;
    private TrainEnvironments $environments;

    public function __construct(private Store $store,private Projects $projects,private Audit $audit)
    {
        $this->trains=new ReleaseTrains($store,$projects,$audit);
        $this->environments=new TrainEnvironments($store,$projects,$audit);
    }

    public static function instant(array $body,string $field): string
    {
        $input=Input::string($body,$field,20,20);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$input,new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z')!==$input) {
            throw new Problem(422,'invalid_utc_timestamp',['field'=>$field]);
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function context(Actor $actor,int $project,int $id,?int $expected=null): array
    {
        $train=$this->trains->current($actor,$project,$id);
        if (!$train['train_rev'] || ($expected!==null && $train['train_rev']!==$expected)) {
            throw new Problem(409,'train_revision_changed',['current'=>$train['train_rev']]);
        }
        $version=$this->trains->version($actor,$project,$id,$train['train_rev']);
        $manifest=json_decode($version['manifest'],true,flags:JSON_THROW_ON_ERROR);
        return [$train,$manifest,$this->environments->read($actor,$project,$train['staging_id']),
            $this->environments->read($actor,$project,$train['production_id'])];
    }

    public function gates(Actor $actor,int $project,int $id,?string $through=null): array
    {
        [$t,$manifest,$stage,$prod]=$this->context($actor,$project,$id);
        $scope=[$actor->tenantId,$project,$id];
        $results=$this->store->all('SELECT id,service_id,target,state,operation_key,receipt FROM train_rehearsals
            WHERE tenant_id=? AND project_id=? AND train_id=? AND revision=? AND staging_rev=? ORDER BY id',
            [...$scope,$t['train_rev'],$stage['environment_rev']]);
        $byService=[];
        foreach ($results as $r) {
            $r['receipt']=$r['receipt']===null?null:json_decode($r['receipt'],true,flags:JSON_THROW_ON_ERROR);
            $byService[$r['service_id']][$r['target']]=$r;
        }
        $approvals=$this->store->all('SELECT id,service_id,pin_revision,digest,approved_by,expires_at FROM train_component_approvals
            WHERE tenant_id=? AND project_id=? AND train_id=? AND staging_rev=? AND production_rev=?
            AND expires_at>GREATEST(UTC_TIMESTAMP(6),COALESCE(?,UTC_TIMESTAMP(6))) ORDER BY id DESC',
            [...$scope,$stage['environment_rev'],$prod['environment_rev'],$through]);
        $parts=[];
        foreach ($manifest['components'] as $pin) {
            $service=$pin['service_id'];
            $nodes=$byService[$service] ?? [];
            $passed=$stage['enabled'] && count($stage['nodes'])>0;
            foreach ($stage['nodes'] as $n) {
                $passed=$passed && ($nodes[$n['node']]['state'] ?? '')==='passed';
            }
            $approval=null;
            foreach ($approvals as $a) {
                if ($a['service_id']===$service && $a['pin_revision']===$pin['pin_revision'] && $a['digest']===$pin['digest']) {
                    $approval=$a;
                    break;
                }
            }
            $parts[]=['service_id'=>$service,'code'=>$pin['code'],'digest'=>$pin['digest'],'pin_revision'=>$pin['pin_revision'],
                'passed'=>$passed,'targets'=>array_values($nodes),'approval'=>$approval,'ready'=>$passed && $prod['enabled'] && $approval!==null];
        }
        return ['train_rev'=>$t['train_rev'],'staging'=>$stage,'production'=>$prod,'components'=>$parts,
            'ready'=>!in_array(false,array_column($parts,'ready'),true)];
    }

    public function rehearse(Actor $actor,int $project,int $id,array $body): array
    {
        $revision=Input::integer($body,'revision',1);
        $service=Input::integer($body,'service_id',1);
        $node=Input::slug($body,'node');
        $job=$this->store->transaction(function () use ($actor,$project,$id,$revision,$service,$node) {
            $this->projects->lockForApproval($actor,$project);
            [$t,$manifest,$stage]=$this->context($actor,$project,$id,$revision);
            if ($actor->accountId!==$stage['owner_id']) {
                throw new Problem(403,'environment_owner_required');
            }
            if (!$stage['enabled'] || !in_array($node,array_column($stage['nodes'],'node'),true) || $t['state']!=='frozen') {
                throw new Problem(409,'invalid_rehearsal_target');
            }
            $pin=array_column($manifest['components'],null,'service_id')[$service] ?? throw new Problem(404,'train_service_not_found');
            $scope=[$actor->tenantId,$project,$id,$revision];
            foreach ($pin['upstream_ids'] as $parent) {
                if (!$this->store->one("SELECT id FROM train_rehearsals WHERE tenant_id=? AND project_id=? AND train_id=? AND revision=?
                    AND service_id=? AND staging_rev=? AND target=? AND state='passed'",[...$scope,$parent,$stage['environment_rev'],$node])) {
                    throw new Problem(409,'upstream_rehearsal_required',['service_id'=>$parent,'node'=>$node]);
                }
            }
            $this->store->execute('INSERT INTO train_rehearsals (tenant_id,project_id,train_id,revision,service_id,digest,staging_rev,target,operation_key)
                VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=train_rehearsals.id',
                [...$scope,$service,$pin['digest'],$stage['environment_rev'],$node,bin2hex(random_bytes(16))]);
            $row=$this->store->one('SELECT id,operation_key,state,receipt FROM train_rehearsals WHERE tenant_id=? AND project_id=? AND train_id=?
                AND revision=? AND service_id=? AND staging_rev=? AND target=?',[...$scope,$service,$stage['environment_rev'],$node]);
            return [$stage['environment_id'],$pin,$row];
        });
        [$env,$pin,$row]=$job;
        if ($row['state']!=='pending') {
            return ['rehearsal_id'=>$row['id'],'state'=>$row['state'],'receipt'=>json_decode($row['receipt'],true,flags:JSON_THROW_ON_ERROR)];
        }
        $adapter=new TrainAdapter();
        $target=['scope'=>implode(':',[$actor->tenantId,$project,$env]),'service'=>$service,'node'=>$node,'operation'=>$row['operation_key']];
        $previous=$adapter->call('result',$target);
        $actual=$adapter->call('current',$target);
        if (!array_key_exists('digest',$actual)) {
            throw new Problem(503,'invalid_adapter_readback');
        }
        if (($previous['found'] ?? null)===true) {
            $receipt=$previous['receipt'];
        } elseif (($previous['found'] ?? null)===false) {
            $bytes=$this->store->one('SELECT content FROM artifact_contents WHERE tenant_id=? AND project_id=? AND artifact_id=?',
                [$actor->tenantId,$project,$pin['artifact_id']]) ?? throw new Problem(409,'artifact_content_not_found');
            $receipt=$adapter->call('apply',$target+['digest'=>$pin['digest'],'expected_digest'=>$actual['digest'],'content_base64'=>base64_encode($bytes['content'])]);
            $actual=$adapter->call('current',$target);
        } else {
            throw new Problem(503,'invalid_adapter_readback');
        }
        if (($receipt['digest'] ?? null)!==$pin['digest'] || ($receipt['operation'] ?? null)!==$row['operation_key']
            || ($receipt['node'] ?? null)!==$node || ($receipt['service'] ?? null)!==$service || !is_bool($receipt['probe']['passed'] ?? null)) {
            throw new Problem(503,'invalid_adapter_receipt');
        }
        $state=$actual['digest']===$pin['digest'] && $receipt['probe']['passed']?'passed':'failed';
        $receipt['observed_digest']=$actual['digest'];
        return $this->store->transaction(function () use ($actor,$project,$id,$row,$receipt,$state) {
            $changed=$this->store->execute("UPDATE train_rehearsals SET state=?,receipt=? WHERE id=? AND state='pending'",
                [$state,json_encode($receipt,JSON_THROW_ON_ERROR),$row['id']]);
            if ($changed) {
                $this->audit->record($actor,'project',$project,'train_rehearsed',['train_id'=>$id,'operation'=>$row['operation_key'],'state'=>$state]);
            }
            $saved=$this->store->one('SELECT state,receipt FROM train_rehearsals WHERE id=?',[$row['id']]);
            return ['rehearsal_id'=>$row['id'],'state'=>$saved['state'],'receipt'=>json_decode($saved['receipt'],true,flags:JSON_THROW_ON_ERROR)];
        });
    }

    public function approve(Actor $actor,int $project,int $id,array $body): array
    {
        return $this->store->transaction(function () use ($actor,$project,$id,$body) {
            $this->projects->lockForApproval($actor,$project);
            [$t,$manifest,$stage,$prod]=$this->context($actor,$project,$id,Input::integer($body,'revision',1));
            $service=Input::integer($body,'service_id',1);
            $pin=array_column($manifest['components'],null,'service_id')[$service] ?? throw new Problem(404,'train_service_not_found');
            if ($actor->accountId!==$prod['owner_id'] || in_array($actor->accountId,[$t['owner_id'],$pin['signed_by']],true)) {
                throw new Problem(403,'independent_environment_owner_required');
            }
            if ($t['state']!=='frozen' || !$prod['enabled'] || $pin['digest']!==Input::sha256($body,'digest')
                || $prod['environment_rev']!==Input::integer($body,'production_rev',1) || $stage['environment_rev']!==Input::integer($body,'staging_rev',1)) {
                throw new Problem(409,'approval_target_changed');
            }
            $gate=array_column($this->gates($actor,$project,$id)['components'],null,'service_id')[$service];
            if (!$gate['passed']) {
                throw new Problem(409,'rehearsal_required');
            }
            $expires=self::instant($body,'expires_at');
            if ($expires<=gmdate('Y-m-d H:i:s')) {
                throw new Problem(422,'approval_expired');
            }
            $approval=$this->store->insert('INSERT INTO train_component_approvals (tenant_id,project_id,train_id,service_id,signed_revision,pin_revision,digest,
                staging_rev,production_rev,approved_by,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$actor->tenantId,$project,$id,$service,$t['train_rev'],$pin['pin_revision'],$pin['digest'],$stage['environment_rev'],$prod['environment_rev'],$actor->accountId,$expires]);
            $this->audit->record($actor,'project',$project,'train_component_approved',['train_id'=>$id,'approval_id'=>$approval,'digest'=>$pin['digest'],'expires_at'=>$expires]);
            return ['approval_id'=>$approval,'service_id'=>$service,'digest'=>$pin['digest'],'environment_rev'=>$prod['environment_rev'],'expires_at'=>$expires];
        });
    }

    public function book(Actor $actor,int $project,int $id,array $body): array
    {
        return $this->store->transaction(function () use ($actor,$project,$id,$body) {
            $this->projects->lockForRelease($actor,$project);
            [$t]=$this->context($actor,$project,$id,Input::integer($body,'revision',1));
            $start=self::instant($body,'starts_at');
            $end=self::instant($body,'ends_at');
            if ($start>=$end || $end<=gmdate('Y-m-d H:i:s')) {
                throw new Problem(422,'invalid_change_window');
            }
            $scope=[$actor->tenantId,$project];
            $existing=$this->store->one('SELECT id,train_id,starts_at,ends_at FROM train_windows WHERE tenant_id=? AND project_id=? AND environment_id=?
                AND released_at IS NULL AND starts_at<? AND ends_at>? ORDER BY id LIMIT 1',[...$scope,$t['production_id'],$end,$start]);
            if ($existing && $existing['train_id']!==$id) {
                throw new Problem(409,'exclusive_window_conflict',['window_id'=>$existing['id'],'train_id'=>$existing['train_id']]);
            }
            $gates=$this->gates($actor,$project,$id,$end);
            if (!$gates['ready']) {
                throw new Problem(409,'production_approval_required');
            }
            if ($existing && $t['state']==='scheduled' && substr($existing['starts_at'],0,19)===$start && substr($existing['ends_at'],0,19)===$end) {
                $clearance=$this->store->one('SELECT id FROM train_release_clearances WHERE window_id=?',[$existing['id']]);
                return ['window_id'=>$existing['id'],'approval_id'=>$clearance['id'],'train_rev'=>$t['train_rev'],'state'=>'scheduled'];
            }
            if ($t['state']!=='frozen') {
                throw new Problem(409,'train_already_scheduled');
            }
            $approvals=array_column(array_column($gates['components'],'approval'),'id');
            $window=$this->store->insert('INSERT INTO train_windows (tenant_id,project_id,environment_id,train_id,revision,starts_at,ends_at,booked_by,approvals)
                VALUES (?,?,?,?,?,?,?,?,?)',[...$scope,$t['production_id'],$id,$t['train_rev'],$start,$end,$actor->accountId,json_encode($approvals)]);
            $this->store->execute("UPDATE release_trains SET state='scheduled' WHERE id=?",[$id]);
            $manifest=$this->trains->version($actor,$project,$id,$t['train_rev']);
            $clearance=$this->store->insert('INSERT INTO train_release_clearances (window_id,production_rev,manifest_sha,component_approvals)
                VALUES (?,?,?,?)',[$window,$gates['production']['environment_rev'],$manifest['manifest_sha'],json_encode($approvals)]);
            $this->audit->record($actor,'project',$project,'train_window_booked',['train_id'=>$id,'window_id'=>$window,'approval_ids'=>$approvals]);
            return ['window_id'=>$window,'approval_id'=>$clearance,'train_rev'=>$t['train_rev'],'state'=>'scheduled'];
        });
    }
}
