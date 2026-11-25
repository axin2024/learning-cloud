<?php
declare(strict_types=1);

use LearningCloud\Accounts;
use LearningCloud\Admissions;
use LearningCloud\Audit;
use LearningCloud\Catalog;
use LearningCloud\Store;

require dirname(__DIR__) . '/src/bootstrap.php';

$dsn = getenv('LEARNING_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=learning_test_[a-z0-9_]+(?:;|$)/', substr($dsn, 6))) {
    fwrite(STDERR, "Use a dedicated learning_test_* MySQL database.\n");
    exit(2);
}
$store = Store::connect();
$audit = new Audit($store);
$accounts = new Accounts($store, $audit);
$catalog = new Catalog($store, $audit);
$admissions = new Admissions($store, $catalog, $accounts, $audit);

function report(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR), PHP_EOL;
    fflush(STDOUT);
}

$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $password = bin2hex(random_bytes(20));
    $admin = $accounts->provision([
        'tenant' => 'admission-capacity-' . bin2hex(random_bytes(6)), 'tenant_name' => 'Capacity Fixture',
        'email' => 'registrar@example.test', 'name' => 'Capacity Registrar', 'password' => $password,
    ]);
    $course = $catalog->create($admin, ['slug' => 'capacity-course']);
    $rev = $catalog->draft($admin, $course['id'], [
        'title' => 'Workshop', 'summary' => 'Synthetic course', 'pass_score' => 60,
        'minimum_study_seconds' => 0, 'retake_mode' => 'highest', 'chapters' => [[
            'title' => 'Workshop reading', 'kind' => 'text', 'content' => 'Synthetic material', 'duration_seconds' => 600,
            'required_seconds' => 0, 'rights_basis' => 'original', 'rights_reference' => 'Test fixture text',
        ]],
    ]);
    $catalog->publish($admin, $course['id'], $rev['id'], ['edit_version' => 1]);
    $cohort = $admissions->createCohort($admin, [
        'course_id' => $course['id'], 'name' => 'Capacity workshop', 'code' => 'capacity-workshop',
        'starts_on' => '2026-12-01', 'ends_on' => '2026-12-15', 'capacity' => 10, 'registration_open' => true,
    ]);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $learners = $store->transaction(function () use ($store, $admin, $passwordHash): array {
        $ids = [];
        for ($i = 1; $i <= 105; $i++) {
            $id = $store->insert('INSERT INTO accounts (tenant_id, email, name, password_hash) VALUES (?, ?, ?, ?)',
                [$admin->tenantId, "learner-$i@example.test", "Learner $i", $passwordHash]);
            $store->execute("INSERT INTO account_roles (tenant_id, account_id, role) VALUES (?, ?, 'learner')", [$admin->tenantId, $id]);
            $ids[] = $id;
        }
        return $ids;
    });
    foreach (array_slice($learners, 0, 5) as $learner) {
        if ($admissions->enroll($admin, $cohort['id'], ['learner_id' => $learner], "prefill:$learner")['state'] !== 'enrolled') {
            throw new RuntimeException('Could not prefill the initial five seats.');
        }
    }
    report(['tenant' => $admin->tenantId, 'actor' => $admin->accountId, 'cohort' => $cohort['id'], 'contenders' => array_slice($learners, 5)]);
    exit;
}

$tenantId = (int) ($argv[2] ?? 0);
$adminId = (int) ($argv[3] ?? 0);
$cohortId = (int) ($argv[4] ?? 0);
$actor = $accounts->find($tenantId, $adminId);

if ($mode === 'race') {
    echo "ready\n";
    fflush(STDOUT);
    $input = json_decode((string) fgets(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $result = $admissions->transfer($actor, $cohortId, $input);
    $events = $store->one("SELECT COUNT(*) AS total FROM audit_events
        WHERE tenant_id=? AND object_type='enrollment' AND object_id=? AND action='transferred'", [$tenantId, $cohortId]);
    report($result + ['transfer_events' => $events['total']]);
    exit;
}

if ($mode === 'worker') {
    $key = $argv[5];
    $learners = array_map('intval', explode(',', $argv[6]));
    report(['ready' => true]);
    if (trim((string) fgets(STDIN)) !== 'start') {
        exit(3);
    }
    $results = [];
    foreach ($learners as $learner) {
        $result = $admissions->enroll($actor, $cohortId, ['learner_id' => $learner], "$key:$learner");
        $results[] = array_intersect_key($result, array_flip(['id', 'learner_id', 'state', 'created']));
    }
    report($results);
    exit;
}

if ($mode === 'read') {
    $counts = $store->all('SELECT state, COUNT(*) AS count FROM enrollments WHERE tenant_id=? AND cohort_id=? GROUP BY state', [$tenantId, $cohortId]);
    $requests = $store->one('SELECT COUNT(*) AS count FROM enrollment_requests WHERE tenant_id=? AND cohort_id=?', [$tenantId, $cohortId]);
    $audits = $store->one("SELECT COUNT(*) AS count FROM audit_events a JOIN enrollments e ON e.tenant_id=a.tenant_id AND e.id=a.object_id
                          WHERE a.tenant_id=? AND a.object_type='enrollment' AND e.cohort_id=? AND a.action IN ('enrolled','waitlisted')", [$tenantId, $cohortId]);
    report(['states' => array_column($counts, 'count', 'state'), 'requests' => $requests['count'], 'creation_events' => $audits['count']]);
    exit;
}

fwrite(STDERR, "Unknown fixture mode.\n");
exit(2);
