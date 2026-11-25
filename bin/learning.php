<?php
declare(strict_types=1);

use LearningCloud\Accounts;
use LearningCloud\Audit;
use LearningCloud\Problem;
use LearningCloud\Schema;
use LearningCloud\Store;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? '';
if (!in_array($command, ['migrate', 'health', 'create-tenant'], true)) {
    fwrite(STDERR, "Usage: php bin/learning.php migrate|health|create-tenant TENANT TENANT_NAME ADMIN_EMAIL ADMIN_NAME\n");
    exit(2);
}

try {
    $store = Store::connect();
    $schema = new Schema($store);
    if ($command === 'migrate') {
        $result = ['applied' => $schema->migrate()];
    } elseif ($command === 'health') {
        $ready = $schema->ready();
        echo json_encode(['ready' => $ready], JSON_THROW_ON_ERROR), PHP_EOL;
        exit($ready ? 0 : 1);
    } else {
        if (count($argv) !== 6) {
            fwrite(STDERR, "Usage: php bin/learning.php create-tenant TENANT TENANT_NAME ADMIN_EMAIL ADMIN_NAME\n");
            exit(2);
        }
        if (stream_isatty(STDIN)) {
            fwrite(STDERR, "Administrator password (input is visible): ");
        }
        $password = fgets(STDIN);
        if ($password === false) {
            throw new Problem(422, 'password_required_on_stdin');
        }
        $result = (new Accounts($store, new Audit($store)))->provision([
            'tenant' => $argv[2], 'tenant_name' => $argv[3], 'email' => $argv[4], 'name' => $argv[5], 'password' => rtrim($password, "\r\n"),
        ])->view();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Problem $error) {
    fwrite(STDERR, json_encode(['error' => $error->reason, 'details' => (object) $error->details], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Learning Cloud command failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
