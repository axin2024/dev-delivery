<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class TrainPilot
{
    public function __construct(private Store $store) {}

    public function run(string $slug,string $action): array
    {
        $tenant=$this->store->one('SELECT id FROM tenants WHERE slug=?',[$slug])['id'] ?? throw new Problem(404,'tenant_not_found');
        if ($action==='preflight') {
            $bounds=$this->store->one('SELECT COUNT(*) baseline_count,COALESCE(MAX(id),0) through_id FROM baselines WHERE tenant_id=?',[$tenant]);
            $conflicts=$this->store->all('SELECT b.id FROM baselines b LEFT JOIN projects p ON p.id=b.project_id AND p.tenant_id=b.tenant_id
                LEFT JOIN accounts a ON a.id=b.frozen_by AND a.tenant_id=b.tenant_id WHERE b.tenant_id=? AND (p.id IS NULL OR a.id IS NULL) ORDER BY b.id',[$tenant]);
            return ['tenant'=>$slug,'conflict_ids'=>array_column($conflicts,'id'),'through_id'=>(int)$bounds['through_id'],
                'baseline_count'=>(int)$bounds['baseline_count']];
        }
        if ($action==='pending') {
            return ['tenant'=>$slug,'items'=>$this->store->all("SELECT t.id train_id,e.id execution_id,o.node,o.operation_key,o.digest,o.state
                FROM release_trains t JOIN train_wave_plans p ON p.train_id=t.id JOIN train_waves w ON w.plan_id=p.id JOIN train_executions e ON e.wave_id=w.id
                JOIN train_target_operations o ON o.execution_id=e.id WHERE t.tenant_id=? AND o.state IN ('waiting','working','unknown','failed')
                AND t.state<>'recovered' ORDER BY e.id,o.id",[$tenant])];
        }
        if (!in_array($action,['backfill','enable','drain'],true)) throw new Problem(422,'invalid_pilot_command');
        return $this->store->transaction(function () use ($tenant,$slug,$action) {
            $this->store->one('SELECT id FROM tenants WHERE id=? FOR UPDATE',[$tenant]);
            $pilot=$this->store->one('SELECT * FROM train_pilots WHERE tenant_id=? FOR UPDATE',[$tenant]);
            if (!$pilot) {
                if ($action!=='backfill') throw new Problem(409,'backfill_required');
                $check=$this->run($slug,'preflight');
                if ($check['conflict_ids']) throw new Problem(409,'legacy_ownership_conflict',$check);
                $this->store->execute('INSERT INTO train_pilots (tenant_id,through_id) VALUES (?,?)',[$tenant,$check['through_id']]);
                $pilot=['cursor_id'=>0,'through_id'=>$check['through_id'],'state'=>'preparing'];
            }
            if ($action==='backfill') {
                $rows=$this->store->all('SELECT id,project_id,manifest_digest FROM baselines WHERE tenant_id=? AND id>? AND id<=? ORDER BY id LIMIT 100',
                    [$tenant,$pilot['cursor_id'],$pilot['through_id']]);
                foreach ($rows as $row) {
                    $this->store->execute('INSERT INTO train_legacy_baselines (tenant_id,project_id,baseline_id,manifest_digest) VALUES (?,?,?,?)',
                        [$tenant,$row['project_id'],$row['id'],$row['manifest_digest']]);
                    $pilot['cursor_id']=$row['id'];
                }
                $this->store->execute('UPDATE train_pilots SET cursor_id=? WHERE tenant_id=?',[$pilot['cursor_id'],$tenant]);
            } else {
                if ($action==='enable') {
                    $gap=$this->store->one('SELECT b.id FROM baselines b LEFT JOIN train_legacy_baselines h ON h.baseline_id=b.id AND h.tenant_id=b.tenant_id
                        AND h.project_id=b.project_id AND h.manifest_digest=b.manifest_digest WHERE b.tenant_id=? AND b.id<=? AND h.baseline_id IS NULL LIMIT 1',
                        [$tenant,$pilot['through_id']]);
                    if ($gap || $pilot['cursor_id']!==$pilot['through_id']) throw new Problem(409,'backfill_incomplete',['first_gap'=>$gap]);
                }
                $pilot['state']=$action==='enable'?'enabled':'draining';
                $this->store->execute('UPDATE train_pilots SET state=? WHERE tenant_id=?',[$pilot['state'],$tenant]);
            }
            return ['tenant'=>$slug,'state'=>$pilot['state'],'cursor'=>$pilot['cursor_id'],'through'=>$pilot['through_id'],
                'complete'=>$pilot['cursor_id']===$pilot['through_id']];
        });
    }

    public function requireEnabled(Actor $actor): void
    {
        $r=$this->store->one('SELECT state FROM train_pilots WHERE tenant_id=? FOR SHARE',[$actor->tenantId]);
        if (($r['state'] ?? null)!=='enabled') throw new Problem(409,'train_pilot_not_accepting');
    }
}
