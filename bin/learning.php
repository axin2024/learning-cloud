<?php
declare(strict_types=1);

use LearningCloud\Accounts;
use LearningCloud\Audit;
use LearningCloud\ExamCatalog;
use LearningCloud\Exams;
use LearningCloud\Problem;
use LearningCloud\Retraining;
use LearningCloud\Schema;
use LearningCloud\Store;
use LearningCloud\TeachingStaff;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? '';
if (!in_array($command, ['migrate', 'health', 'exam-sweep', 'credential-sweep', 'create-tenant'], true)) {
    fwrite(STDERR, "Usage: php bin/learning.php migrate|health|exam-sweep [LIMIT]|credential-sweep [LIMIT]|create-tenant TENANT TENANT_NAME ADMIN_EMAIL ADMIN_NAME\n");
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
    } elseif (in_array($command, ['exam-sweep', 'credential-sweep'], true)) {
        $limit = filter_var($argv[2] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (count($argv) > 3 || $limit === false) {
            throw new Problem(422, $command === 'exam-sweep' ? 'invalid_exam_sweep_limit' : 'invalid_credential_sweep_limit');
        }
        $audit = new Audit($store);
        if ($command === 'exam-sweep') {
            $staff = new TeachingStaff($store, new Accounts($store, $audit), $audit);
            $result = (new Exams($store, new ExamCatalog($store, $staff, $audit), $staff, $audit))->sweep($limit);
        } else {
            $result = (new Retraining($store, $audit))->sweep($limit);
        }
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
