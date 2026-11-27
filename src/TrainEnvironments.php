<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class TrainEnvironments
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit) {}

    public function read(Actor $actor, int $project, int $env): array
    {
        $this->projects->view($actor,$project);
        $row=$this->store->one('SELECT e.id environment_id,e.name,e.stage,a.owner_id,COALESCE(a.environment_rev,0) environment_rev,
            COALESCE(a.enabled,0) enabled,a.nodes FROM environments e LEFT JOIN train_environment_access a
            ON a.tenant_id=e.tenant_id AND a.project_id=e.project_id AND a.environment_id=e.id
            WHERE e.tenant_id=? AND e.project_id=? AND e.id=?',[$actor->tenantId,$project,$env])
            ?? throw new Problem(404,'environment_not_found');
        $row['nodes']=json_decode($row['nodes'] ?? '[]',true,flags:JSON_THROW_ON_ERROR);
        $row['enabled']=(bool)$row['enabled'];
        return $row;
    }

    public function authorized(Actor $actor, int $project, int $env): array
    {
        $row=$this->read($actor,$project,$env);
        if (!$row['enabled']) {
            throw new Problem(409,'environment_connector_disabled',['environment_id'=>$env]);
        }
        return $row;
    }

    public function configure(Actor $actor,int $project,int $env,string $action,array $body): array
    {
        return $this->store->transaction(function () use ($actor,$project,$env,$action,$body) {
            if ($action==='owner') {
                $this->projects->lockForPlanning($actor,$project);
            } else {
                $this->projects->lockForApproval($actor,$project);
            }
            $access=$this->read($actor,$project,$env);
            if ($access['environment_rev'] !== Input::integer($body,'environment_rev',0)) {
                throw new Problem(409,'environment_revision_changed');
            }
            if ($action==='owner') {
                $owner=Input::integer($body,'owner_id',1);
                $this->projects->assertParticipant($actor,$project,$owner);
                $nodes=[];
                $enabled=false;
            } else {
                $owner=$access['owner_id'];
                if ($actor->accountId!==$owner) {
                    throw new Problem(403,'environment_owner_required');
                }
                $enabled=Input::boolean($body,'enabled');
                $nodes=[];
                foreach (Input::list($body,'nodes',$enabled?1:0,512) as $node) {
                    if (!is_array($node)) {
                        throw new Problem(422,'invalid_environment_node');
                    }
                    $key=Input::slug($node,'node');
                    if (isset($nodes[$key])) {
                        throw new Problem(422,'duplicate_environment_node',['node'=>$key]);
                    }
                    $nodes[$key]=['node'=>$key,'region'=>Input::slug($node,'region')];
                }
                ksort($nodes,SORT_STRING);
            }
            $revision=$access['environment_rev']+1;
            $this->store->execute('INSERT INTO train_environment_access (tenant_id,project_id,environment_id,owner_id,environment_rev,enabled,nodes)
                VALUES (?,?,?,?,?,?,?) AS incoming ON DUPLICATE KEY UPDATE owner_id=incoming.owner_id,environment_rev=incoming.environment_rev,
                enabled=incoming.enabled,nodes=incoming.nodes',[$actor->tenantId,$project,$env,$owner,$revision,$enabled,json_encode(array_values($nodes),JSON_THROW_ON_ERROR)]);
            $this->audit->record($actor,'project',$project,'train_environment_configured',['environment_id'=>$env,'environment_rev'=>$revision,'owner'=>$owner,'enabled'=>$enabled]);
            return $this->read($actor,$project,$env);
        });
    }
}
