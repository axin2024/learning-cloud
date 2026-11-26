<?php
declare(strict_types=1);

use LearningCloud\Accounts;
use LearningCloud\Audit;
use LearningCloud\ExamCatalog;
use LearningCloud\Exams;
use LearningCloud\Store;
use LearningCloud\TeachingStaff;

require dirname(__DIR__) . '/src/bootstrap.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['race', 'race-grade', 'marking-audit'], true)
    || !preg_match('/(?:^|;)dbname=learning_test_[a-z0-9_]+(?:;|$)/', substr(getenv('LEARNING_DSN') ?: '', 6))) {
    fwrite(STDERR, "Use a dedicated learning_test_* database and specify a fixture command.\n");
    exit(2);
}
[$tenant, $account, $attempt] = array_map('intval', array_slice($argv, 2, 3));
$store = Store::connect();
if ($mode === 'marking-audit') {
    $history = $store->all('SELECT id, assigned_by, assigned_to, closed_reason FROM exam_marking_assignments
        WHERE tenant_id=? AND attempt_id=? ORDER BY id', [$tenant, $attempt]);
    $events = $store->all("SELECT actor_id, action, details FROM audit_events WHERE tenant_id=? AND object_type='exam_attempt'
        AND object_id=? AND action IN ('marking_assigned','marking_assignment_revoked','grade_published') ORDER BY id", [$tenant, $attempt]);
    foreach ($events as &$event) {
        $event['details'] = json_decode($event['details'], true, 16, JSON_THROW_ON_ERROR);
    }
    unset($event);
    echo json_encode(['history' => $history, 'events' => $events], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
$audit = new Audit($store);
$accounts = new Accounts($store, $audit);
$staff = new TeachingStaff($store, $accounts, $audit);
$exams = new Exams($store, new ExamCatalog($store, $staff, $audit), $staff, $audit);
$actor = $accounts->find($tenant, $account);
echo "ready\n";
fflush(STDOUT);
$input = json_decode((string) fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
$event = $mode === 'race-grade' ? 'grade_published' : 'submitted';
$receipt = $mode === 'race-grade' ? $exams->grade($actor, $attempt, $input) : $exams->submit($actor, $attempt, $input);
$events = $store->one("SELECT COUNT(*) AS total FROM audit_events
    WHERE tenant_id=? AND object_type='exam_attempt' AND object_id=? AND action=?", [$tenant, $attempt, $event]);
echo json_encode($receipt + [$event . '_events' => $events['total']], JSON_THROW_ON_ERROR), PHP_EOL;
