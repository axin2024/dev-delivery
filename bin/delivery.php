<?php
declare(strict_types=1);

use DevDelivery\Accounts;
use DevDelivery\Audit;
use DevDelivery\Problem;
use DevDelivery\Schema;
use DevDelivery\Store;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? '';
if (!in_array($command, ['migrate', 'health', 'create-tenant'], true)
    || count($argv) !== ($command === 'create-tenant' ? 6 : 2)) {
    fwrite(STDERR, "Usage: php bin/delivery.php migrate|health|create-tenant TENANT NAME ADMIN_EMAIL ADMIN_NAME\n");
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
