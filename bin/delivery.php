<?php
declare(strict_types=1);

use DevDelivery\Accounts;
use DevDelivery\Audit;
use DevDelivery\Problem;
use DevDelivery\Schema;
use DevDelivery\Store;
use DevDelivery\Projects;
use DevDelivery\TrainExecution;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? '';
if (!in_array($command, ['migrate', 'health', 'create-tenant','train-step','train-worker','train-pilot'], true)
    || count($argv) !== match ($command) {'create-tenant'=>6,'train-step'=>3,'train-pilot'=>4,default=>2}) {
    fwrite(STDERR, "Usage: php bin/delivery.php migrate|health|create-tenant TENANT NAME ADMIN_EMAIL ADMIN_NAME\n");
    fwrite(STDERR, "       php bin/delivery.php train-step EXECUTION_ID\n");
    fwrite(STDERR, "       php bin/delivery.php train-worker\n");
    fwrite(STDERR, "       php bin/delivery.php train-pilot TENANT preflight|backfill|enable|drain|pending\n");
    exit(2);
}

try {
    $store = Store::connect();
    $schema = new Schema($store);
    if ($command === 'health') {
        $ready = $schema->ready();
        echo json_encode(['ready' => $ready], JSON_THROW_ON_ERROR), PHP_EOL;
        exit($ready ? 0 : 1);
    }
    if ($command === 'migrate') {
        $result = ['applied' => $schema->migrate()];
    } elseif ($command==='train-pilot') {
        $result=(new DevDelivery\TrainPilot($store))->run($argv[2],$argv[3]);
    } elseif ($command==='train-worker') {
        $audit=new Audit($store);
        $projects=new Projects($store,new Accounts($store,$audit),$audit);
        $worker=new TrainExecution($store,$projects,$audit);
        $after=0;
        while (true) {
            $batch=$store->all("SELECT e.id FROM train_executions e JOIN train_waves w ON w.id=e.wave_id
                JOIN train_wave_plans p ON p.id=w.plan_id JOIN release_trains t ON t.id=p.train_id
                WHERE e.id>? AND w.state IN ('accepted','running') AND (t.state='running' OR EXISTS
                (SELECT 1 FROM train_target_operations o WHERE o.execution_id=e.id AND o.state IN ('unknown','working')))
                ORDER BY e.id LIMIT 32",[$after]);
            foreach ($batch as $item) {
                $after=$item['id'];
                try {
                    $progress=$worker->step($after);
                    if (!in_array($progress['state'],['paused','busy'],true)) {
                        echo json_encode(['execution_id'=>$after,'state'=>$progress['state']],JSON_THROW_ON_ERROR),PHP_EOL;
                    }
                } catch (Problem $error) {
                    fwrite(STDERR,json_encode(['execution_id'=>$after,'error'=>$error->reason]).PHP_EOL);
                }
            }
            if (count($batch)<32) {
                $after=0;
                sleep(2);
            }
        }
    } elseif ($command==='train-step') {
        $id=filter_var($argv[2],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) throw new Problem(422,'invalid_execution_id');
        $audit=new Audit($store);
        $projects=new Projects($store,new Accounts($store,$audit),$audit);
        $result=(new TrainExecution($store,$projects,$audit))->step($id);
    } else {
        if (stream_isatty(STDIN)) {
            fwrite(STDERR, "Administrator password (input is visible): ");
        }
        $input = fgets(STDIN);
        if ($input === false) {
            throw new Problem(422, 'password_required_on_stdin');
        }
        $result = (new Accounts($store, new Audit($store)))->provision([
            'tenant' => $argv[2], 'tenant_name' => $argv[3], 'email' => $argv[4], 'name' => $argv[5],
            'password' => rtrim($input, "\r\n"),
        ])->view();
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Problem $error) {
    fwrite(STDERR, json_encode(['error' => $error->reason, 'details' => (object) $error->details], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Dev Delivery command failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
