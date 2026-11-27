<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class ReleaseTrains
{
    private ReleaseServices $services;
    private TrainEnvironments $environments;

    public function __construct(private Store $store,private Projects $projects,private Audit $audit)
    {
        $this->services=new ReleaseServices($store,$projects,$audit);
        $this->environments=new TrainEnvironments($store,$projects,$audit);
    }

    public function listing(Actor $actor,int $project,int $after): array
    {
        $this->projects->view($actor,$project);
        $rows=$this->store->all('SELECT id,code,title,train_rev,state FROM release_trains WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId,$project,$after]);
        return ['items'=>array_slice($rows,0,50),'next_after'=>count($rows)>50?$rows[49]['id']:null];
    }

    public function current(Actor $actor,int $project,int $id): array
    {
        $this->projects->view($actor,$project);
        $row=$this->store->one('SELECT id,code,title,owner_id,baseline_id,staging_id,production_id,service_selection,
            train_rev,state FROM release_trains WHERE tenant_id=? AND project_id=? AND id=?',[$actor->tenantId,$project,$id])
            ?? throw new Problem(404,'train_not_found');
        $row['service_ids']=json_decode($row['service_selection'],true,flags:JSON_THROW_ON_ERROR);
        unset($row['service_selection']);
        return $row;
    }

    public function create(Actor $actor,int $project,array $body): array
    {
        return $this->store->transaction(function () use ($actor,$project,$body) {
            $this->projects->lockForRelease($actor,$project);
            $scope=[$actor->tenantId,$project];
            $code=Input::slug($body,'code');
            $title=Input::string($body,'title',160);
            $baseline=Input::integer($body,'baseline_id',1);
            $stage=Input::integer($body,'staging_id',1);
            $prod=Input::integer($body,'production_id',1);
            if (!$this->store->one('SELECT id FROM baselines WHERE tenant_id=? AND project_id=? AND id=?',[...$scope,$baseline])) {
                throw new Problem(404,'baseline_not_found');
            }
            foreach ([$stage=>'staging',$prod=>'production'] as $environment=>$kind) {
                if ($stage===$prod || $this->environments->read($actor,$project,$environment)['stage']!==$kind) {
                    throw new Problem(422,'train_environment_stage_mismatch');
                }
            }
            $ids=Input::list($body,'service_ids',1,256);
            $graph=$this->services->graph($actor,$project);
            $registered=array_column($graph['services'],null,'service_id');
            $selected=[];
            foreach ($ids as $id) {
                if (!is_int($id) || $id<1 || isset($selected[$id])) {
                    throw new Problem(422,'invalid_service_selection');
                }
                if (!isset($registered[$id])) {
                    throw new Problem(404,'service_not_found');
                }
                $selected[$id]=true;
            }
            sort($ids,SORT_NUMERIC);
            $existing=$this->store->one('SELECT id FROM release_trains WHERE tenant_id=? AND project_id=? AND code=?',[...$scope,$code]);
            if ($existing) {
                $row=$this->current($actor,$project,$existing['id']);
                if ([$row['title'],$row['baseline_id'],$row['staging_id'],$row['production_id'],$row['service_ids'],$row['owner_id']]
                    !==[$title,$baseline,$stage,$prod,$ids,$actor->accountId]) {
                    throw new Problem(409,'train_code_conflict');
                }
                return $row;
            }
            (new TrainPilot($this->store))->requireEnabled($actor);
            $id=$this->store->insert('INSERT INTO release_trains (tenant_id,project_id,code,title,owner_id,baseline_id,staging_id,production_id,service_selection)
                VALUES (?,?,?,?,?,?,?,?,?)',[...$scope,$code,$title,$actor->accountId,$baseline,$stage,$prod,json_encode($ids)]);
            $this->audit->record($actor,'project',$project,'release_train_created',['train_id'=>$id,'baseline_id'=>$baseline]);
            return $this->current($actor,$project,$id);
        });
    }

    public function version(Actor $actor,int $project,int $id,int $revision): array
    {
        $this->projects->view($actor,$project);
        return $this->store->one('SELECT revision,manifest,manifest_sha,frozen_at,frozen_by,reason,changed_service FROM release_train_revisions
            WHERE tenant_id=? AND project_id=? AND train_id=? AND revision=?',[$actor->tenantId,$project,$id,$revision]) ?? throw new Problem(404,'train_revision_not_found');
    }

    public function freeze(Actor $actor,int $project,int $id,array $body,bool $replace): array
    {
        return $this->store->transaction(function () use ($actor,$project,$id,$body,$replace) {
            $this->projects->lockForRelease($actor,$project);
            $t=$this->current($actor,$project,$id);
            $prior=Input::integer($body,'revision',0);
            if ($prior!==$t['train_rev'] || ($replace?!in_array($t['state'],['frozen','scheduled'],true):$t['state']!=='draft')) {
                throw new Problem(409,'train_revision_changed',['current'=>$t['train_rev'],'state'=>$t['state']]);
            }
            $scope=[$actor->tenantId,$project,$id];
            $graph=$this->services->graph($actor,$project);
            $services=array_column($graph['services'],null,'service_id');
            $changed=null;
            $revision=$prior+1;
            if ($replace) {
                $changed=Input::integer($body,'service_id',1);
                $reason=Input::string($body,'reason',600);
                $manifest=json_decode($this->version($actor,$project,$id,$prior)['manifest'],true,flags:JSON_THROW_ON_ERROR);
                $pos=array_search($changed,array_column($manifest['components'],'service_id'),true);
                if ($pos===false) {
                    throw new Problem(422,'service_outside_manifest');
                }
                $previous=$manifest['components'][$pos];
                $pin=$services[$changed];
                if ($pin['digest']!==Input::sha256($body,'digest') || $pin['artifact_id']===$previous['artifact_id']) {
                    throw new Problem(409,'new_signed_artifact_required');
                }
                // The sealed dependency graph is part of the released contract.
                if ($pin['upstream_ids']!==$previous['upstream_ids']) {
                    throw new Problem(409,'replacement_dependencies_changed');
                }
                $manifest['components'][$pos]=$pin+['pin_revision'=>$revision];
                $this->store->execute('UPDATE train_windows SET released_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND project_id=? AND train_id=? AND released_at IS NULL',$scope);
            } else {
                $reason='首次封板';
                $components=[];
                foreach ($graph['order'] as $key) {
                    if (!in_array($key,$t['service_ids'],true)) {
                        continue;
                    }
                    $pin=$services[$key];
                    if (!$pin['signed_by'] || array_diff($pin['upstream_ids'],$t['service_ids'])) {
                        throw new Problem(409,'train_component_incomplete',['service_id'=>$key,'missing_upstream'=>array_values(array_diff($pin['upstream_ids'],$t['service_ids']))]);
                    }
                    $components[]=$pin+['pin_revision'=>1];
                }
                $baseline=$this->store->one('SELECT id,sequence,manifest_digest FROM baselines WHERE tenant_id=? AND project_id=? AND id=?',
                    [$actor->tenantId,$project,$t['baseline_id']]);
                $manifest=['train_id'=>$id,'code'=>$t['code'],'baseline'=>$baseline,'staging_id'=>$t['staging_id'],'production_id'=>$t['production_id'],'components'=>$components];
            }
            $manifest['revision']=$revision;
            $bytes=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $hash=hash('sha256',$bytes);
            $this->store->execute('INSERT INTO release_train_revisions (tenant_id,project_id,train_id,revision,manifest,manifest_sha,changed_service,reason,frozen_by)
                VALUES (?,?,?,?,?,?,?,?,?)',[...$scope,$revision,$bytes,$hash,$changed,$reason,$actor->accountId]);
            $this->store->execute("UPDATE release_trains SET train_rev=?,state='frozen' WHERE tenant_id=? AND project_id=? AND id=?",[$revision,...$scope]);
            $this->audit->record($actor,'project',$project,'release_train_frozen',['train_id'=>$id,'revision'=>$revision,'manifest_sha'=>$hash,'changed_service'=>$changed]);
            return ['train_id'=>$id,'train_rev'=>$revision,'manifest_digest'=>$hash,'state'=>'frozen'];
        });
    }
}
