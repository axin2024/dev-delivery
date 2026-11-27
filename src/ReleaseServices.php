<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class ReleaseServices
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit) {}

    public function register(Actor $actor, int $project, array $body): array
    {
        $code = Input::slug($body, 'code');
        $name = Input::string($body, 'name', 160);
        $owner = Input::integer($body, 'owner_id', 1);
        $repo = Input::integer($body, 'repository_id', 1);
        return $this->store->transaction(function () use ($actor, $project, $code, $name, $owner, $repo) {
            $this->projects->lockForRelease($actor, $project);
            $this->projects->assertParticipant($actor, $project, $owner);
            $scope = [$actor->tenantId, $project];
            if (!$this->store->one('SELECT id FROM repositories WHERE tenant_id=? AND project_id=? AND id=?', [...$scope, $repo])) {
                throw new Problem(404, 'repository_not_found');
            }
            $existing = $this->store->one('SELECT id,name,owner_id,repository_id FROM release_services WHERE tenant_id=? AND project_id=? AND code=?',
                [...$scope, $code]);
            if ($existing) {
                if ([$existing['name'],$existing['owner_id'],$existing['repository_id']] !== [$name,$owner,$repo]) {
                    throw new Problem(409, 'service_code_exists');
                }
                return $this->service($actor, $project, $existing['id']);
            }
            $id = $this->store->insert('INSERT INTO release_services (tenant_id,project_id,code,name,owner_id,repository_id) VALUES (?,?,?,?,?,?)',
                [...$scope,$code,$name,$owner,$repo]);
            $this->audit->record($actor,'project',$project,'release_service_registered',['service_id'=>$id,'owner_id'=>$owner,'repository_id'=>$repo]);
            return $this->service($actor,$project,$id);
        });
    }

    private function service(Actor $actor, int $project, int $id): array
    {
        return $this->store->one('SELECT s.id service_id,s.code,s.name,s.owner_id,s.repository_id,s.revision,s.artifact_id,
            a.sha256 digest,g.commit_sha,s.signed_by,s.signed_at FROM release_services s
            LEFT JOIN build_artifacts a ON a.id=s.artifact_id LEFT JOIN build_runs b ON b.id=a.build_id
            LEFT JOIN git_snapshots g ON g.id=b.snapshot_id WHERE s.tenant_id=? AND s.project_id=? AND s.id=?',
            [$actor->tenantId,$project,$id]) ?? throw new Problem(404,'service_not_found');
    }

    public function read(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor,$project);
        return $this->service($actor,$project,$id);
    }

    public function graph(Actor $actor, int $project): array
    {
        $this->projects->view($actor,$project);
        // Fetch nodes, pins and edges in one statement so a concurrent edit cannot tear the graph.
        $rows=$this->store->all('SELECT s.id service_id,s.code,s.name,s.owner_id,s.repository_id,s.revision,
            s.artifact_id,a.sha256 digest,g.commit_sha,s.signed_by,s.signed_at,d.upstream_id FROM release_services s
            LEFT JOIN release_service_dependencies d ON d.tenant_id=s.tenant_id AND d.project_id=s.project_id AND d.service_id=s.id
            LEFT JOIN build_artifacts a ON a.id=s.artifact_id LEFT JOIN build_runs b ON b.id=a.build_id
            LEFT JOIN git_snapshots g ON g.id=b.snapshot_id WHERE s.tenant_id=? AND s.project_id=? ORDER BY s.id,d.upstream_id',
            [$actor->tenantId,$project]);
        $nodes = [];
        $predecessors = [];
        foreach ($rows as $row) {
            $id = $row['service_id'];
            $parent = $row['upstream_id'];
            unset($row['upstream_id']);
            $nodes[$id] = $row;
            if ($parent !== null) {
                $predecessors[$id][] = $parent;
            }
        }
        foreach ($nodes as $id => &$node) {
            $node['upstream_ids'] = $predecessors[$id] ?? [];
        }
        unset($node);
        return ['services'=>array_values($nodes)] + DependencyGraph::resolve($predecessors,array_keys($nodes));
    }

    public function change(Actor $actor, int $project, int $id, string $action, array $body): array
    {
        $revision = Input::integer($body,'revision',1);
        return $this->store->transaction(function () use ($actor,$project,$id,$action,$body,$revision) {
            $this->projects->lockForApproval($actor,$project);
            $service=$this->service($actor,$project,$id);
            if ($actor->accountId !== $service['owner_id']) {
                throw new Problem(403,'service_owner_required');
            }
            if ($service['revision'] !== $revision) {
                throw new Problem(409,'service_revision_changed',['current'=>$service['revision']]);
            }
            $scope=[$actor->tenantId,$project,$id];
            if ($action === 'pin') {
                $artifact=Input::integer($body,'artifact_id',1);
                $digest=Input::sha256($body,'digest');
                $pin=$this->store->one("SELECT a.sha256,b.repository_id,b.status,c.byte_length FROM build_artifacts a
                    JOIN build_runs b ON b.id=a.build_id LEFT JOIN artifact_contents c ON c.artifact_id=a.id
                    WHERE a.tenant_id=? AND a.project_id=? AND a.id=?",[$actor->tenantId,$project,$artifact])
                    ?? throw new Problem(404,'artifact_not_found');
                if ($pin['status'] !== 'passed' || $pin['repository_id'] !== $service['repository_id'] || $pin['byte_length'] === null || !hash_equals($pin['sha256'],$digest)) {
                    throw new Problem(409,'service_artifact_mismatch');
                }
                $this->store->execute('UPDATE release_services SET artifact_id=?,signed_by=?,signed_at=UTC_TIMESTAMP(6),revision=revision+1
                    WHERE tenant_id=? AND project_id=? AND id=?',[$artifact,$actor->accountId,...$scope]);
            } else {
                $parents=Input::list($body,'upstream_ids',0,256);
                $this->store->execute('DELETE FROM release_service_dependencies WHERE tenant_id=? AND project_id=? AND service_id=?',$scope);
                $seen=[];
                foreach ($parents as $parent) {
                    if (!is_int($parent) || $parent<1 || isset($seen[$parent])) {
                        throw new Problem(422,'invalid_upstream_ids');
                    }
                    $this->service($actor,$project,$parent);
                    $seen[$parent]=true;
                    $this->store->execute('INSERT INTO release_service_dependencies (tenant_id,project_id,service_id,upstream_id) VALUES (?,?,?,?)',
                        [...$scope,$parent]);
                }
                $graph=$this->graph($actor,$project);
                if ($graph['cycle']) {
                    throw new Problem(409,'dependency_cycle',['cycle'=>$graph['cycle']]);
                }
                $this->store->execute('UPDATE release_services SET revision=revision+1 WHERE tenant_id=? AND project_id=? AND id=?',$scope);
            }
            $this->audit->record($actor,'project',$project,'release_service_'.$action,['service_id'=>$id,'prior_revision'=>$revision,'change'=>$body]);
            return $this->service($actor,$project,$id);
        });
    }
}
