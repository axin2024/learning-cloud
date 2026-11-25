<?php
declare(strict_types=1);

use LearningCloud\Accounts;
use LearningCloud\Audit;
use LearningCloud\Credentials;
use LearningCloud\ExamCatalog;
use LearningCloud\Exams;
use LearningCloud\Retraining;
use LearningCloud\Store;
use LearningCloud\StudyProgress;
use LearningCloud\TeachingStaff;

require dirname(__DIR__) . '/src/bootstrap.php';

if (!preg_match('/(?:^|;)dbname=learning_test_[a-z0-9_]+(?:;|$)/', substr(getenv('LEARNING_DSN') ?: '', 6))) {
    fwrite(STDERR, "Use a dedicated learning_test_* database.\n");
    exit(2);
}
$store = Store::connect();
$audit = new Audit($store);
$mode = $argv[1] ?? '';
if ($mode === 'age') {
    [$tenant, $id] = array_map('intval', array_slice($argv, 2, 2));
    $result = ['aged' => $store->execute('UPDATE credentials
        SET issued_at=TIMESTAMPADD(DAY, -valid_days-1, UTC_TIMESTAMP(6)), expires_at=TIMESTAMPADD(DAY, -1, UTC_TIMESTAMP(6))
        WHERE tenant_id=? AND id=?', [$tenant, $id])];
} elseif ($mode === 'race') {
    echo "ready\n";
    fflush(STDOUT);
    $input = json_decode((string) fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if ($argv[2] === 'sweep') {
        $result = (new Retraining($store, $audit))->sweep(100);
    } else {
        [$tenant, $account, $enrollment] = array_map('intval', array_slice($argv, 3, 3));
        $accounts = new Accounts($store, $audit);
        $staff = new TeachingStaff($store, $accounts, $audit);
        $exams = new Exams($store, new ExamCatalog($store, $staff, $audit), $staff, $audit);
        $credentials = new Credentials($store, new StudyProgress($store, $audit), $exams, $audit);
        $result = $credentials->issue($accounts->find($tenant, $account), $enrollment, $input);
        $events = $store->one("SELECT COUNT(*) AS total FROM audit_events
            WHERE tenant_id=? AND object_type='credential' AND object_id=? AND action='issued'", [$tenant, $result['id']]);
        $result['issued_events'] = $events['total'];
    }
} else {
    throw new RuntimeException('Unknown credential fixture command.');
}
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
