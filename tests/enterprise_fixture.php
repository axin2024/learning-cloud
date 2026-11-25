<?php
declare(strict_types=1);

use LearningCloud\Accounts;
use LearningCloud\Admissions;
use LearningCloud\Audit;
use LearningCloud\Catalog;
use LearningCloud\Companies;
use LearningCloud\SeatPurchases;
use LearningCloud\Store;

require dirname(__DIR__) . '/src/bootstrap.php';

if (!preg_match('/(?:^|;)dbname=learning_test_[a-z0-9_]+(?:;|$)/', substr(getenv('LEARNING_DSN') ?: '', 6))) {
    fwrite(STDERR, "Use a dedicated learning_test_* MySQL database.\n");
    exit(2);
}
$store = Store::connect();
[$mode, $tenant] = [$argv[1], (int) $argv[2]];

if ($mode === 'seed') {
    $count = (int) $argv[3];
    $password = trim((string) fgets(STDIN));
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $result = $store->transaction(function () use ($store, $tenant, $count, $hash): array {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $email = "seat-learner-$i@example.test";
            $id = $store->insert('INSERT INTO accounts (tenant_id, email, name, password_hash) VALUES (?, ?, ?, ?)', [$tenant, $email, "Seat Learner $i", $hash]);
            $store->execute("INSERT INTO account_roles (tenant_id, account_id, role) VALUES (?, ?, 'learner')", [$tenant, $id]);
            $ids[] = ['id' => $id, 'email' => $email];
        }
        return $ids;
    });
} elseif ($mode === 'race') {
    [$actorId, $companyId, $poolId] = array_map('intval', array_slice($argv, 3, 3));
    $audit = new Audit($store);
    $accounts = new Accounts($store, $audit);
    $catalog = new Catalog($store, $audit);
    $admissions = new Admissions($store, $catalog, $accounts, $audit);
    $companies = new Companies($store, $accounts, $audit);
    $seats = new SeatPurchases($store, $companies, $admissions, $audit);
    $actor = $accounts->find($tenant, $actorId);
    echo "ready\n";
    fflush(STDOUT);
    $request = json_decode((string) fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $result = $seats->assign($actor, $companyId, $poolId, $request['input'], $request['key']);
} elseif ($mode === 'check') {
    $poolId = (int) $argv[3];
    $result = $store->one(
        "SELECT COUNT(*) AS active_assignments, COUNT(DISTINCT seat_number) AS distinct_seat_numbers,
                (SELECT COUNT(*) FROM seat_batches b WHERE b.tenant_id=? AND b.pool_id=?) AS batches,
                (SELECT COUNT(*) FROM audit_events a JOIN seat_assignments s ON s.tenant_id=a.tenant_id AND s.id=a.object_id
                    WHERE a.tenant_id=? AND s.pool_id=? AND a.object_type='seat_assignment' AND a.action='assigned') AS assigned_events
           FROM seat_assignments WHERE tenant_id=? AND pool_id=? AND state IN ('assigned','activated')",
        [$tenant, $poolId, $tenant, $poolId, $tenant, $poolId]
    );
} else {
    throw new RuntimeException('Unknown fixture mode.');
}

echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
