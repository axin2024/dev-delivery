<?php
declare(strict_types=1);

use DevDelivery\Store;

require dirname(__DIR__).'/src/bootstrap.php';
$db=Store::connect();
$counts=[];
foreach (['release_train_revisions','train_component_approvals','train_rehearsals','train_executions','train_recovery_items','train_samples'] as $table) {
    $counts[$table]=(int)$db->one("SELECT COUNT(*) n FROM $table")['n'];
    if (!$counts[$table]) throw new RuntimeException("Missing test records in $table");
}
$manifests=$db->all('SELECT train_id,revision,manifest_sha,SHA2(manifest,256) actual FROM release_train_revisions ORDER BY train_id,revision');
foreach ($manifests as $row) {
    if ($row['actual']!==$row['manifest_sha']) throw new RuntimeException('Manifest digest mismatch');
}
$signatures=$db->all('SELECT c.id,c.approved_by,c.digest FROM train_component_approvals c JOIN accounts a ON a.id=c.approved_by AND a.tenant_id=c.tenant_id ORDER BY c.id');
if (count($signatures)!==$counts['train_component_approvals']) throw new RuntimeException('Missing approver');
$nodes=$db->all('SELECT t.tenant_id,t.project_id,t.production_id,o.node,o.service_id,o.operation_key,o.digest,
    i.operation_key restore_operation,i.verified_at,o.previous_digest FROM train_target_operations o
    JOIN train_executions e ON e.id=o.execution_id JOIN train_waves w ON w.id=e.wave_id JOIN train_wave_plans p ON p.id=w.plan_id
    JOIN release_trains t ON t.id=p.train_id LEFT JOIN train_recovery_items i ON i.target_operation=o.id
    WHERE o.applied_at IS NOT NULL ORDER BY o.id');
echo json_encode(compact('counts','manifests','signatures','nodes'),JSON_THROW_ON_ERROR),PHP_EOL;
