<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class TrainWaves
{
    private ReleaseTrains $trains;
    private TrainEnvironments $envs;

    public function __construct(private Store $store,private Projects $projects,private Audit $audit)
    {
        $this->trains=new ReleaseTrains($store,$projects,$audit);
        $this->envs=new TrainEnvironments($store,$projects,$audit);
    }

    public function plan(Actor $actor,int $project,int $id,array $input): array
    {
        $t=$this->trains->current($actor,$project,$id);
        $revision=Input::integer($input,'revision',1);
        if (!$actor->has('release_manager')) {
            throw new Problem(403,'project_release_manager_required');
        }
        if ($t['train_rev']!==$revision || !in_array($t['state'],['frozen','scheduled'],true)) {
            throw new Problem(409,'train_revision_changed');
        }
        $env=$this->envs->authorized($actor,$project,$t['production_id']);
        $min=Input::integer($input,'min_requests',1,1000000000);
        $bps=Input::integer($input,'max_error_bps',0,10000);
        $seconds=Input::integer($input,'interval_seconds',1,86400);
        $inventory=array_column($env['nodes'],'region','node');
        $remaining=$inventory;
        $waves=[];
        foreach (Input::list($input,'waves',1,64) as $wave) {
            if (!is_array($wave)) {
                throw new Problem(422,'invalid_wave_spec');
            }
            $regions=Input::list($wave,'regions',1,512);
            foreach ($regions as $region) {
                if (!is_string($region) || !in_array($region,$inventory,true)) {
                    throw new Problem(422,'invalid_wave_regions');
                }
            }
            $percent=Input::integer($wave,'percent',1,100);
            $eligible=array_filter($inventory,fn($r)=>in_array($r,$regions,true));
            $want=(int)ceil(count($eligible)*$percent/100);
            $available=array_intersect_key($remaining,$eligible);
            // A final 100% wave drains the remaining eligible members, including uneven region sizes.
            $targets=array_keys(array_slice($available,0,$want,true));
            if (!$targets || ($percent!==100 && count($targets)!==$want)) {
                throw new Problem(422,'wave_capacity_unavailable');
            }
            $waves[]=$targets;
            foreach ($targets as $node) {
                unset($remaining[$node]);
            }
        }
        if ($remaining) {
            throw new Problem(422,'wave_coverage_incomplete',['nodes'=>array_keys($remaining)]);
        }
        $digest=hash('sha256',json_encode([$revision,$env['environment_rev'],$waves,$min,$bps,$seconds],JSON_THROW_ON_ERROR));
        $scope=[$actor->tenantId,$project,$id,$revision,$env['environment_rev']];
        $query='SELECT id,request_digest FROM train_wave_plans WHERE tenant_id=? AND project_id=? AND train_id=? AND revision=? AND production_rev=?';
        $prior=$this->store->one($query,$scope);
        if ($prior) {
            if ($prior['request_digest']!==$digest) {
                throw new Problem(409,'wave_plan_already_frozen');
            }
            return $this->overview($actor,$project,$id);
        }
        $pins=json_decode($this->trains->version($actor,$project,$id,$revision)['manifest'],true,flags:JSON_THROW_ON_ERROR)['components'];
        $adapter=new TrainAdapter();
        $baseline=[];
        foreach ($inventory as $node=>$region) {
            foreach ($pins as $pin) {
                $actual=$adapter->call('current',['scope'=>implode(':',[$actor->tenantId,$project,$t['production_id']]),'node'=>(string)$node,'service'=>$pin['service_id']]);
                if (!array_key_exists('digest',$actual) || ($actual['digest']!==null && !preg_match('/\A[0-9a-f]{64}\z/',(string)$actual['digest']))) {
                    throw new Problem(503,'invalid_adapter_readback');
                }
                $baseline[$node][$pin['service_id']]=$actual['digest'];
            }
        }
        return $this->store->transaction(function () use ($actor,$project,$id,$scope,$query,$digest,$min,$bps,$seconds,$baseline,$waves,$env,$revision) {
            $this->projects->lockForRelease($actor,$project);
            $live=$this->trains->current($actor,$project,$id);
            if ($live['train_rev']!==$revision || !in_array($live['state'],['frozen','scheduled'],true) ||
                $this->envs->authorized($actor,$project,$live['production_id'])['environment_rev']!==$env['environment_rev']) {
                throw new Problem(409,'wave_target_changed');
            }
            $existing=$this->store->one($query,$scope);
            if ($existing && $existing['request_digest']!==$digest) {
                throw new Problem(409,'wave_plan_already_frozen');
            }
            if (!$existing) {
                $plan=$this->store->insert('INSERT INTO train_wave_plans (tenant_id,project_id,train_id,revision,production_rev,min_requests,max_error_bps,
                    interval_seconds,request_digest,baseline,observed_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
                    [...$scope,$min,$bps,$seconds,$digest,json_encode($baseline,JSON_THROW_ON_ERROR)]);
                foreach ($waves as $i=>$targets) {
                    $this->store->execute('INSERT INTO train_waves (plan_id,sequence_no,targets) VALUES (?,?,?)',[$plan,$i+1,json_encode($targets)]);
                }
                $this->audit->record($actor,'project',$project,'train_wave_plan_frozen',['train_id'=>$id,'plan_id'=>$plan,'wave_count'=>count($waves)]);
            }
            return $this->overview($actor,$project,$id);
        });
    }

    public function overview(Actor $actor,int $project,int $id): array
    {
        $t=$this->trains->current($actor,$project,$id);
        $env=$this->envs->read($actor,$project,$t['production_id']);
        $plan=$this->store->one('SELECT id,production_rev,min_requests,max_error_bps,interval_seconds,observed_at FROM train_wave_plans
            WHERE tenant_id=? AND project_id=? AND train_id=? AND revision=? ORDER BY id DESC LIMIT 1',
            [$actor->tenantId,$project,$id,$t['train_rev']]);
        $window=$this->store->one('SELECT w.id window_id,w.starts_at,w.ends_at,c.id approval_id FROM train_windows w
            JOIN train_release_clearances c ON c.window_id=w.id WHERE w.tenant_id=? AND w.project_id=? AND w.train_id=? AND w.revision=? AND w.released_at IS NULL',
            [$actor->tenantId,$project,$id,$t['train_rev']]);
        $waves=$plan?$this->store->all('SELECT w.id wave_id,w.sequence_no,w.targets,w.state,w.completed_at,e.id execution_id,e.trace_id
            FROM train_waves w LEFT JOIN train_executions e ON e.wave_id=w.id WHERE w.plan_id=? ORDER BY w.sequence_no',[$plan['id']]):[];
        foreach ($waves as &$wave) {
            $wave['targets']=json_decode($wave['targets'],true,flags:JSON_THROW_ON_ERROR);
            $wave['observation']=$this->store->one('SELECT o.id observation_id,o.before_requests,o.before_errors,o.after_requests,o.after_errors,
                o.anchor_at,o.sample_through,o.decision,g.id waiver_id,g.expires_at FROM train_observations o
                LEFT JOIN train_gate_waivers g ON g.observation_id=o.id WHERE o.wave_id=? ORDER BY o.id DESC LIMIT 1',[$wave['wave_id']]);
        }
        return ['train_rev'=>$t['train_rev'],'state'=>$t['state'],'plan'=>$plan,'environment_rev'=>$env['environment_rev'],'window'=>$window,'waves'=>$waves];
    }

    public function wave(Actor $actor,int $project,int $id,int $wave): array
    {
        $this->projects->view($actor,$project);
        $row=$this->store->one('SELECT w.id wave_id,w.plan_id,w.sequence_no,w.targets,w.state wave_state,w.completed_at,p.production_rev,p.min_requests,
            p.max_error_bps,p.interval_seconds,p.observed_at,p.revision,t.train_rev,t.state train_state,t.production_id,t.staging_id FROM train_waves w
            JOIN train_wave_plans p ON p.id=w.plan_id JOIN release_trains t ON t.id=p.train_id WHERE t.tenant_id=? AND t.project_id=? AND t.id=? AND w.id=?',
            [$actor->tenantId,$project,$id,$wave]) ?? throw new Problem(404,'wave_not_found');
        $row['targets']=json_decode($row['targets'],true,flags:JSON_THROW_ON_ERROR);
        return $row;
    }

    public function sample(Actor $actor,int $project,int $id,int $wave,array $body): array
    {
        $this->wave($actor,$project,$id,$wave);
        if (!$actor->has('tester') && !$actor->has('release_manager')) {
            throw new Problem(403,'quality_operator_required');
        }
        $key=Input::string($body,'sample_key',120);
        if (!preg_match('/\A[A-Za-z0-9._:-]+\z/',$key)) {
            throw new Problem(422,'invalid_sample_key');
        }
        $when=TrainReadiness::instant($body,'measured_at');
        if ($when>gmdate('Y-m-d H:i:s')) {
            throw new Problem(422,'observation_in_future');
        }
        $requests=Input::integer($body,'requests',0,1000000000);
        $errors=Input::integer($body,'errors',0,$requests);
        return $this->store->transaction(function () use ($wave,$key,$when,$requests,$errors) {
            $this->store->one('SELECT id FROM train_waves WHERE id=? FOR UPDATE',[$wave]);
            $this->store->execute('INSERT INTO train_samples (wave_id,sample_key,measured_at,requests,errors) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE id=train_samples.id',[$wave,$key,$when,$requests,$errors]);
            $r=$this->store->one('SELECT id,measured_at,requests,errors FROM train_samples WHERE wave_id=? AND sample_key=?',[$wave,$key]);
            if ([substr($r['measured_at'],0,19),$r['requests'],$r['errors']]!==[$when,$requests,$errors]) {
                throw new Problem(409,'sample_key_conflict');
            }
            return ['sample_id'=>$r['id'],'requests'=>$requests,'errors'=>$errors];
        });
    }

    private function aggregate(array $w): array
    {
        $anchor=$w['observed_at'];
        if ($w['sequence_no']>1) {
            $prior=$this->store->one('SELECT completed_at FROM train_waves WHERE plan_id=? AND sequence_no=?',[$w['plan_id'],$w['sequence_no']-1]);
            $anchor=$prior['completed_at'] ?? throw new Problem(409,'previous_wave_incomplete');
        }
        $totals=$this->store->one('SELECT COALESCE(MAX(id),0) last_sample,
            COALESCE(SUM(IF(measured_at<?,requests,0)),0) before_requests,COALESCE(SUM(IF(measured_at<?,errors,0)),0) before_errors,
            COALESCE(SUM(IF(measured_at>=?,requests,0)),0) after_requests,COALESCE(SUM(IF(measured_at>=?,errors,0)),0) after_errors,
            LEAST(UTC_TIMESTAMP(6),TIMESTAMPADD(SECOND,?,CAST(? AS DATETIME(6)))) sample_through FROM train_samples WHERE wave_id=?
            AND measured_at>=TIMESTAMPADD(SECOND,?,CAST(? AS DATETIME(6))) AND measured_at<=LEAST(UTC_TIMESTAMP(6),TIMESTAMPADD(SECOND,?,CAST(? AS DATETIME(6))))',
            [$anchor,$anchor,$anchor,$anchor,$w['interval_seconds'],$anchor,$w['wave_id'],-$w['interval_seconds'],$anchor,$w['interval_seconds'],$anchor]);
        foreach (['last_sample','before_requests','before_errors','after_requests','after_errors'] as $key) {
            $totals[$key]=(int)$totals[$key];
        }
        $decision='allow';
        if (min($totals['after_requests'],$totals['before_requests'])<$w['min_requests']) {
            $decision='await_samples';
        } elseif ($totals['after_errors']*10000>$w['max_error_bps']*$totals['after_requests']) {
            $decision='pause';
        }
        return $totals+['anchor_at'=>$anchor,'decision'=>$decision];
    }

    public function observe(Actor $actor,int $project,int $id,int $wave): array
    {
        return $this->store->transaction(function () use ($actor,$project,$id,$wave) {
            $w=$this->wave($actor,$project,$id,$wave);
            if (!$actor->has('tester') && !$actor->has('release_manager')) {
                throw new Problem(403,'quality_operator_required');
            }
            $this->store->one('SELECT id FROM train_waves WHERE id=? FOR UPDATE',[$wave]);
            $m=$this->aggregate($w);
            $oid=$this->store->insert('INSERT INTO train_observations (wave_id,anchor_at,sample_through,last_sample,before_requests,before_errors,after_requests,
                after_errors,decision) VALUES (?,?,?,?,?,?,?,?,?)',[$wave,$m['anchor_at'],$m['sample_through'],$m['last_sample'],$m['before_requests'],$m['before_errors'],
                $m['after_requests'],$m['after_errors'],$m['decision']]);
            return $m+['observation_id'=>$oid,'wave_id'=>$wave];
        });
    }

    public function waive(Actor $actor,int $project,int $id,int $wave,array $body): array
    {
        return $this->store->transaction(function () use ($actor,$project,$id,$wave,$body) {
            $this->projects->lockForApproval($actor,$project);
            $w=$this->wave($actor,$project,$id,$wave);
            $access=$this->envs->authorized($actor,$project,$w['production_id']);
            if ($actor->accountId!==$access['owner_id']) {
                throw new Problem(403,'environment_owner_required');
            }
            $oid=Input::integer($body,'observation_id',1);
            $expires=TrainReadiness::instant($body,'expires_at');
            $reason=Input::string($body,'reason',600);
            $obs=$this->store->one('SELECT decision FROM train_observations WHERE wave_id=? AND id=?',[$wave,$oid]);
            if (!$obs || $obs['decision']!=='pause' || $w['train_rev']!==$w['revision'] || $expires<=gmdate('Y-m-d H:i:s')) {
                throw new Problem(409,'waiver_not_applicable');
            }
            $digest=$this->trains->version($actor,$project,$id,$w['revision'])['manifest_sha'];
            $existing=$this->store->one('SELECT id,reason,expires_at FROM train_gate_waivers WHERE observation_id=?',[$oid]);
            if ($existing) {
                if ($existing['reason']!==$reason || substr($existing['expires_at'],0,19)!==$expires) {
                    throw new Problem(409,'waiver_already_recorded');
                }
                return ['waiver_id'=>$existing['id'],'expires_at'=>$expires];
            }
            $key=$this->store->insert('INSERT INTO train_gate_waivers (observation_id,digest,reason,expires_at,approved_by) VALUES (?,?,?,?,?)',[$oid,$digest,$reason,$expires,$actor->accountId]);
            $this->audit->record($actor,'project',$project,'train_gate_waived',['waiver_id'=>$key,'observation'=>$oid,'reason'=>$reason,'expires_at'=>$expires]);
            return ['waiver_id'=>$key,'expires_at'=>$expires];
        });
    }

    public function advance(Actor $actor,int $project,int $id,int $wave,array $body,string $key): array
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/',$key)) {
            throw new Problem(422,'idempotency_key_required');
        }
        $rev=Input::integer($body,'revision',1);
        $obs=Input::integer($body,'observation_id',1);
        $approval=Input::integer($body,'approval_id',1);
        $hash=hash('sha256',json_encode([$id,$wave,$rev,$obs,$approval]));
        return $this->store->transaction(function () use ($actor,$project,$id,$wave,$key,$hash,$rev,$obs,$approval) {
            $this->projects->lockForRelease($actor,$project);
            $w=$this->wave($actor,$project,$id,$wave);
            $this->store->one('SELECT id FROM train_waves WHERE id=? FOR UPDATE',[$wave]);
            $reused=$this->store->one('SELECT wave_id FROM train_executions WHERE tenant_id=? AND request_key=?',[$actor->tenantId,$key]);
            if ($reused && $reused['wave_id']!==$wave) {
                throw new Problem(409,'idempotency_key_conflict');
            }
            $existing=$this->store->one('SELECT id,actor_id,request_key,request_digest FROM train_executions WHERE wave_id=?',[$wave]);
            if ($existing) {
                if ([$existing['actor_id'],$existing['request_key'],$existing['request_digest']]!==[$actor->accountId,$key,$hash]) {
                    throw new Problem(409,'wave_already_accepted');
                }
                return ['execution_id'=>$existing['id'],'pinned_targets'=>$w['targets'],'state'=>'accepted'];
            }
            (new TrainPilot($this->store))->requireEnabled($actor);
            if ($w['train_rev']!==$rev || $w['revision']!==$rev || !in_array($w['train_state'],['scheduled','running'],true)) {
                throw new Problem(409,'train_revision_or_state_changed');
            }
            $window=$this->store->one('SELECT w.id,w.ends_at,c.production_rev,c.manifest_sha FROM train_windows w JOIN train_release_clearances c ON c.window_id=w.id
                WHERE w.train_id=? AND w.revision=? AND c.id=? AND w.released_at IS NULL AND UTC_TIMESTAMP(6)>=w.starts_at AND UTC_TIMESTAMP(6)<w.ends_at',[$id,$rev,$approval]);
            $env=$this->envs->authorized($actor,$project,$w['production_id']);
            $v=$this->trains->version($actor,$project,$id,$rev);
            if (!$window || $window['production_rev']!==$env['environment_rev'] || $w['production_rev']!==$env['environment_rev'] || $window['manifest_sha']!==$v['manifest_sha']) {
                throw new Problem(409,'approval_or_window_invalid');
            }
            $gates=(new TrainReadiness($this->store,$this->projects,$this->audit))->gates($actor,$project,$id);
            if (!$gates['ready']) {
                throw new Problem(409,'production_approval_required');
            }
            $saved=$this->store->one('SELECT id,last_sample,anchor_at,decision FROM train_observations WHERE wave_id=? AND id=?',[$wave,$obs]);
            $live=$this->aggregate($w);
            if (!$saved || $saved['last_sample']!==$live['last_sample'] || $saved['anchor_at']!==$live['anchor_at'] || $saved['decision']!==$live['decision']) {
                throw new Problem(409,'observation_changed');
            }
            if ($live['decision']==='await_samples' || ($live['decision']==='pause' && !$this->store->one('SELECT id FROM train_gate_waivers
                WHERE observation_id=? AND digest=? AND expires_at>UTC_TIMESTAMP(6)',[$obs,$v['manifest_sha']]))) {
                throw new Problem(409,'quality_gate_closed',['decision'=>$live['decision']]);
            }
            try {
                $execution=$this->store->insert('INSERT INTO train_executions (tenant_id,wave_id,actor_id,request_key,request_digest,observation_id,approval_id,trace_id)
                    VALUES (?,?,?,?,?,?,?,?)',[$actor->tenantId,$wave,$actor->accountId,$key,$hash,$obs,$approval,bin2hex(random_bytes(16))]);
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null)===1062) throw new Problem(409,'idempotency_key_conflict');
                throw $error;
            }
            $baseline=json_decode($this->store->one('SELECT baseline FROM train_wave_plans WHERE id=?',[$w['plan_id']])['baseline'],true,flags:JSON_THROW_ON_ERROR);
            foreach (json_decode($v['manifest'],true,flags:JSON_THROW_ON_ERROR)['components'] as $pin) {
                foreach ($w['targets'] as $node) {
                    $this->store->execute('INSERT INTO train_target_operations (execution_id,node,service_id,artifact_id,digest,previous_digest,operation_key)
                        VALUES (?,?,?,?,?,?,?)',[$execution,$node,$pin['service_id'],$pin['artifact_id'],$pin['digest'],$baseline[$node][$pin['service_id']],bin2hex(random_bytes(16))]);
                }
            }
            $this->store->execute("UPDATE train_waves SET state='accepted' WHERE id=?",[$wave]);
            $this->store->execute("UPDATE release_trains SET state='running' WHERE id=?",[$id]);
            $this->audit->record($actor,'project',$project,'train_wave_accepted',['execution_id'=>$execution,'wave_id'=>$wave,'targets'=>$w['targets']]);
            return ['execution_id'=>$execution,'pinned_targets'=>$w['targets'],'state'=>'accepted'];
        });
    }
}
