<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class CompetencyRollout
{
    private const DIGEST = "SHA2(CONCAT_WS('|',e.tenant_id,e.id,e.learner_id,e.cohort_id,c.revision_id),256)";

    public function __construct(private Store $store) {}

    public function preflight(int $tenant): array
    {
        if (!$this->store->one('SELECT id FROM tenants WHERE id=?',[$tenant])) {
            throw new Problem(404,'tenant_not_found');
        }
        $tables = $this->store->one("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE()
            AND table_name='competency_rollouts'")['n'];
        $state = $tables ? $this->store->one('SELECT mode,high_water,last_enrollment FROM competency_rollouts WHERE tenant_id=?',[$tenant]) : null;
        $ceiling = $state['high_water'] ?? $this->store->one('SELECT COALESCE(MAX(id),0) n FROM enrollments WHERE tenant_id=?',[$tenant])['n'];
        $scope = [$tenant,$ceiling];
        $source = " FROM enrollments e LEFT JOIN accounts a ON a.tenant_id=e.tenant_id AND a.id=e.learner_id
            LEFT JOIN cohorts c ON c.tenant_id=e.tenant_id AND c.id=e.cohort_id
            WHERE e.tenant_id=? AND e.id<=? AND e.state='completed'";
        $total = (int)$this->store->one('SELECT COUNT(*) n'.$source,$scope)['n'];
        $conflicts = $this->store->all('SELECT e.id'.$source.' AND (a.id IS NULL OR c.id IS NULL) ORDER BY e.id LIMIT 100',$scope);
        $scopeConflicts = (int)$this->store->one('SELECT COUNT(*) n'.$source.' AND (a.id IS NULL OR c.id IS NULL)',$scope)['n'];
        $remaining = $tables ? (int)$this->store->one('SELECT COUNT(*) n'.$source.' AND NOT EXISTS(SELECT 1 FROM competency_prerequisites h
            WHERE h.tenant_id=e.tenant_id AND h.enrollment_id=e.id AND h.source_digest='.self::DIGEST.')',$scope)['n'] : $total;
        $pending = $this->store->all('SELECT e.id'.$source.($tables ? ' AND NOT EXISTS(SELECT 1 FROM competency_prerequisites h WHERE h.tenant_id=e.tenant_id
            AND h.enrollment_id=e.id AND h.source_digest='.self::DIGEST.')' : '').' ORDER BY e.id LIMIT 100',$scope);
        $orphaned = $tables ? (int)$this->store->one("SELECT COUNT(*) n FROM competency_prerequisites h LEFT JOIN enrollments e
            ON e.id=h.enrollment_id AND e.tenant_id=h.tenant_id WHERE h.tenant_id=? AND (e.id IS NULL OR e.state<>'completed')",[$tenant])['n'] : 0;
        return ['tenant_id'=>$tenant,'mode'=>$state['mode'] ?? 'before_migration','high_water'=>$ceiling,'last_enrollment'=>$state['last_enrollment'] ?? 0,
            'source_completed'=>$total,'missing_or_changed'=>$remaining,'orphaned'=>$orphaned,'scope_conflicts'=>$scopeConflicts,
            'conflict_ids'=>array_column($conflicts,'id'),'pending_sample'=>array_column($pending,'id')];
    }

    private function lock(int $tenant): array
    {
        return $this->store->one('SELECT * FROM competency_rollouts WHERE tenant_id=? FOR UPDATE',[$tenant])
            ?? throw new Problem(409,'migrate_before_rollout');
    }

    public function batch(int $tenant, int $limit): array
    {
        return $this->store->transaction(function () use ($tenant,$limit) {
            $p = $this->lock($tenant);
            if ($p['mode'] === 'enabled') {
                throw new Problem(409,'drain_before_backfill');
            }
            $rows = $this->store->all('SELECT e.id,e.learner_id,e.cohort_id,c.revision_id,'.self::DIGEST.' digest FROM enrollments e
                JOIN cohorts c ON c.tenant_id=e.tenant_id AND c.id=e.cohort_id JOIN accounts a ON a.tenant_id=e.tenant_id AND a.id=e.learner_id
                LEFT JOIN competency_prerequisites h ON h.tenant_id=e.tenant_id AND h.enrollment_id=e.id
                WHERE e.tenant_id=? AND e.id<=? AND e.state=\'completed\' AND (h.enrollment_id IS NULL OR h.source_digest<>'.self::DIGEST.')
                ORDER BY e.id LIMIT ? FOR SHARE OF e,c,a',[$tenant,$p['high_water'],$limit]);
            $cursor = $p['last_enrollment'];
            foreach ($rows as $r) {
                $this->store->execute('INSERT INTO competency_prerequisites (tenant_id,enrollment_id,learner_id,cohort_id,course_revision_id,source_digest)
                    VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE learner_id=?,cohort_id=?,course_revision_id=?,source_digest=?',
                    [$tenant,$r['id'],$r['learner_id'],$r['cohort_id'],$r['revision_id'],$r['digest'],$r['learner_id'],$r['cohort_id'],$r['revision_id'],$r['digest']]);
                $cursor = max($cursor,$r['id']);
            }
            $this->store->execute('UPDATE competency_rollouts SET last_enrollment=? WHERE tenant_id=?',[$cursor,$tenant]);
            return ['imported'=>count($rows),'last_enrollment'=>$cursor,'enrollments'=>array_column($rows,'id')];
        });
    }

    public function switchMode(int $tenant, bool $enable): array
    {
        return $this->store->transaction(function () use ($tenant,$enable) {
            $this->lock($tenant);
            $state = $this->preflight($tenant);
            if ($enable && ($state['missing_or_changed'] || $state['orphaned'] || $state['scope_conflicts'])) {
                throw new Problem(409,'prerequisites_not_reconciled',$state);
            }
            $this->store->execute('UPDATE competency_rollouts SET mode=? WHERE tenant_id=?',[$enable?'enabled':'drained',$tenant]);
            return ['mode'=>$enable?'enabled':'drained','tenant_id'=>$tenant,'source_completed'=>$state['source_completed']];
        });
    }

    public function pending(int $tenant, int $after, int $limit): array
    {
        $state = $this->store->one('SELECT mode FROM competency_rollouts WHERE tenant_id=?',[$tenant]);
        if (($state['mode'] ?? '') !== 'drained') {
            throw new Problem(409,'drain_before_export');
        }
        $entries = [];
        foreach (['reviews'=>"SELECT id,status FROM practicum_review_cases WHERE tenant_id=? AND status<>'scored'",
            'appeals'=>"SELECT id,status FROM competency_appeals WHERE tenant_id=? AND status='pending'",
            'extensions'=>"SELECT id,'pending' status FROM practicum_extensions WHERE tenant_id=? AND approved_by IS NULL",
            'training'=>"SELECT id,'assigned' status FROM competency_training_tasks WHERE tenant_id=? AND completed_at IS NULL"] as $type=>$query) {
            $entries[$type]=$this->store->all($query.' AND id>? ORDER BY id LIMIT ?',[$tenant,$after,$limit+1]);
        }
        $next = null;
        foreach ($entries as $list) {
            if (count($list)>$limit) {
                $next = $next === null ? $list[$limit-1]['id'] : min($next,$list[$limit-1]['id']);
            }
        }
        if ($next !== null) {
            foreach ($entries as &$list) {
                $list=array_values(array_filter($list,fn($row)=>$row['id']<=$next));
            }
        }
        return ['pending'=>$entries,'next_after'=>$next];
    }

    public function learnerEvidence(Actor $actor, int $after): array
    {
        $rows=$this->store->all('SELECT h.enrollment_id,h.course_revision_id,r.title,c.name cohort,h.source_digest FROM competency_prerequisites h
            JOIN cohorts c ON c.tenant_id=h.tenant_id AND c.id=h.cohort_id JOIN course_revisions r ON r.tenant_id=h.tenant_id AND r.id=h.course_revision_id
            WHERE h.tenant_id=? AND h.learner_id=? AND h.enrollment_id>? ORDER BY h.enrollment_id LIMIT 51',[$actor->tenantId,$actor->accountId,$after]);
        return ['items'=>array_slice($rows,0,50),'next_after'=>count($rows)>50 ? $rows[49]['enrollment_id'] : null];
    }
}
