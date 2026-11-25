<?php
declare(strict_types=1);

use LearningCloud\Accounts;
use LearningCloud\Audit;
use LearningCloud\ExamCatalog;
use LearningCloud\Exams;
use LearningCloud\Store;
use LearningCloud\TeachingStaff;

require dirname(__DIR__) . '/src/bootstrap.php';

if (($argv[1] ?? '') !== 'race'
    || !preg_match('/(?:^|;)dbname=learning_test_[a-z0-9_]+(?:;|$)/', substr(getenv('LEARNING_DSN') ?: '', 6))) {
    fwrite(STDERR, "Use a dedicated learning_test_* database and the race command.\n");
    exit(2);
}
[$tenant, $account, $attempt] = array_map('intval', array_slice($argv, 2, 3));
$store = Store::connect();
$audit = new Audit($store);
$accounts = new Accounts($store, $audit);
$staff = new TeachingStaff($store, $accounts, $audit);
$exams = new Exams($store, new ExamCatalog($store, $staff, $audit), $staff, $audit);
$actor = $accounts->find($tenant, $account);
echo "ready\n";
fflush(STDOUT);
$input = json_decode((string) fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
$receipt = $exams->submit($actor, $attempt, $input);
$events = $store->one("SELECT COUNT(*) AS total FROM audit_events
    WHERE tenant_id=? AND object_type='exam_attempt' AND object_id=? AND action='submitted'", [$tenant, $attempt]);
echo json_encode($receipt + ['submitted_events' => $events['total']], JSON_THROW_ON_ERROR), PHP_EOL;
