<?php
declare(strict_types=1);

use DevDelivery\Store;

require dirname(__DIR__) . '/src/bootstrap.php';

$store = Store::connect();
$database = $store->one('SELECT DATABASE() AS name')['name'];
if (!preg_match('/\Adelivery_test_[a-z0-9_]+\z/', $database) || count($argv) !== 7) {
    fwrite(STDERR, "Use an isolated delivery_test_* database and six fixture IDs.\n");
    exit(2);
}
[$tenant, $project, $criterion, $revision, $sprint, $owner] = array_map('intval', array_slice($argv, 1));
$values = 'SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';
$changed = $store->execute("INSERT INTO work_items (tenant_id, project_id, criterion_id, revision_id, code, title, team, sprint_id, owner_id, effort)
    SELECT ?, ?, ?, ?, CONCAT('task-', a.digit, b.digit, c.digit, d.digit), 'Dispatch export verification', 'dispatch', ?, ?, 1
    FROM ({$values}) a CROSS JOIN ({$values}) b CROSS JOIN ({$values}) c CROSS JOIN ({$values}) d",
    [$tenant, $project, $criterion, $revision, $sprint, $owner]);
if ($changed !== 10000) {
    throw new RuntimeException('The capacity fixture must create 10000 work items.');
}
$data = $store->one('SELECT MIN(id) AS first, MAX(id) AS last FROM work_items WHERE tenant_id=? AND project_id=? AND sprint_id=?',
    [$tenant, $project, $sprint]);
echo json_encode($data, JSON_THROW_ON_ERROR), PHP_EOL;
