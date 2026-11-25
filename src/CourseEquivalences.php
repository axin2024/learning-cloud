<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class CourseEquivalences
{
    public function __construct(private Store $store, private Audit $audit)
    {
    }

    public function detail(Actor $actor, int $source, int $target): array
    {
        $actor->requireManagement();
        $this->revisions($actor->tenantId, $source, $target);
        return $this->find($actor->tenantId, $source, $target) ?? [
            'id' => null, 'version' => 0, 'enabled' => false, 'reason' => '', 'mappings' => [],
        ];
    }

    public function save(Actor $actor, int $source, int $target, array $input): array
    {
        $actor->requireManagement();
        $tenant = $actor->tenantId;
        $version = Input::integer($input, 'version', 0);
        $enabled = Input::boolean($input, 'enabled');
        $reason = Input::string($input, 'reason', 1000);
        $this->revisions($tenant, $source, $target);
        $mappings = $this->mappings($tenant, $source, $target, $input);
        return $this->store->transaction(function () use ($actor, $tenant, $source, $target, $version, $enabled, $reason, $mappings): array {
            // Lock in identifier order so approving both directions can run concurrently.
            $this->store->one('SELECT id FROM course_revisions WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, min($source, $target)]);
            $this->store->one('SELECT id FROM course_revisions WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, max($source, $target)]);
            $prior = $this->find($tenant, $source, $target);
            if (($prior['version'] ?? 0) !== $version) {
                throw new Problem(409, 'equivalence_version_conflict', ['version' => $prior['version'] ?? 0]);
            }
            $encoded = json_encode($mappings, JSON_THROW_ON_ERROR);
            $id = $prior['id'] ?? $this->store->insert('INSERT INTO course_equivalences
                (tenant_id, source_revision_id, target_revision_id, enabled, reason, mappings, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$tenant, $source, $target, $enabled, $reason, $encoded, $actor->accountId]);
            if ($prior !== null) {
                $this->store->execute('UPDATE course_equivalences SET enabled=?, reason=?, mappings=?, updated_by=?,
                    version=version+1, updated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',
                    [$enabled, $reason, $encoded, $actor->accountId, $tenant, $id]);
            }
            $this->audit->record($actor, 'course_equivalence', $id, $prior === null ? 'created' : 'revised',
                ['source_revision_id' => $source, 'target_revision_id' => $target, 'version' => $version + 1,
                    'enabled' => $enabled, 'reason' => $reason, 'mappings' => $mappings]);
            return $this->find($tenant, $source, $target);
        });
    }

    public function approved(int $tenant, int $source, int $target): array
    {
        $rule = $this->find($tenant, $source, $target, true);
        if (!$rule || !$rule['enabled']) {
            throw new Problem(409, 'course_equivalence_required');
        }
        return $rule;
    }

    public function copyStudy(int $tenant, int $sourceEnrollment, int $targetEnrollment, ?array $rule): int
    {
        if ($rule === null) {
            $this->store->execute('INSERT INTO study_spans (tenant_id, enrollment_id, chapter_id, start_second, end_second)
                SELECT tenant_id, ?, chapter_id, start_second, end_second FROM study_spans WHERE tenant_id=? AND enrollment_id=?',
                [$targetEnrollment, $tenant, $sourceEnrollment]);
        } else {
            $this->store->execute("INSERT INTO study_spans (tenant_id, enrollment_id, chapter_id, start_second, end_second)
                SELECT s.tenant_id, ?, m.target_chapter_id,
                    m.target_start + (GREATEST(s.start_second, m.source_start) - m.source_start),
                    m.target_start + (LEAST(s.end_second, m.source_start + m.duration) - m.source_start)
                FROM study_spans s JOIN JSON_TABLE(?, '$[*]' COLUMNS (
                    source_chapter_id BIGINT PATH '$.source_chapter_id', target_chapter_id BIGINT PATH '$.target_chapter_id',
                    source_start INT PATH '$.source_start_second', target_start INT PATH '$.target_start_second',
                    duration INT PATH '$.seconds')) m ON m.source_chapter_id=s.chapter_id
                WHERE s.tenant_id=? AND s.enrollment_id=?
                    AND s.start_second < m.source_start + m.duration AND s.end_second > m.source_start",
                [$targetEnrollment, json_encode($rule['mappings'], JSON_THROW_ON_ERROR), $tenant, $sourceEnrollment]);
        }
        return (int) $this->store->one('SELECT COALESCE(SUM(end_second-start_second), 0) AS seconds
            FROM study_spans WHERE tenant_id=? AND enrollment_id=?', [$tenant, $targetEnrollment])['seconds'];
    }

    private function find(int $tenant, int $source, int $target, bool $lock = false): ?array
    {
        $row = $this->store->one('SELECT r.id, r.source_revision_id, r.target_revision_id, r.version, r.enabled, r.reason, r.mappings, r.updated_at,
                src.course_id AS source_course_id, dst.course_id AS target_course_id
            FROM course_equivalences r
            JOIN course_revisions src ON src.tenant_id=r.tenant_id AND src.id=r.source_revision_id
            JOIN course_revisions dst ON dst.tenant_id=r.tenant_id AND dst.id=r.target_revision_id
            WHERE r.tenant_id=? AND r.source_revision_id=? AND r.target_revision_id=?' . ($lock ? ' FOR SHARE OF r' : ''),
            [$tenant, $source, $target]);
        if ($row !== null) {
            $row['enabled'] = (bool) $row['enabled'];
            $row['mappings'] = json_decode($row['mappings'], true, 16, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    private function revisions(int $tenant, int $source, int $target): void
    {
        if ($source === $target) {
            throw new Problem(422, 'equivalence_distinct_revisions_required');
        }
        $revisions = $this->store->all('SELECT id FROM course_revisions WHERE tenant_id=? AND id IN (?, ?) AND published_at IS NOT NULL',
            [$tenant, $source, $target]);
        if (count($revisions) !== 2) {
            throw new Problem(404, 'equivalence_published_revisions_required');
        }
    }

    private function mappings(int $tenant, int $source, int $target, array $input): array
    {
        $chapters = array_column($this->store->all('SELECT id, revision_id, duration_seconds FROM chapters WHERE tenant_id=? AND revision_id IN (?, ?)',
            [$tenant, $source, $target]), null, 'id');
        $sources = $targets = $mappings = [];
        foreach (Input::list($input, 'mappings', 1, 100) as $row) {
            if (!is_array($row)) {
                throw new Problem(422, 'invalid_equivalence_mapping');
            }
            $from = Input::integer($row, 'source_chapter_id', 1);
            $to = Input::integer($row, 'target_chapter_id', 1);
            $fromStart = Input::integer($row, 'source_start_second', 0, 86_399);
            $toStart = Input::integer($row, 'target_start_second', 0, 86_399);
            $seconds = Input::integer($row, 'seconds', 1, 86_400);
            if (($chapters[$from]['revision_id'] ?? null) !== $source || ($chapters[$to]['revision_id'] ?? null) !== $target
                || isset($sources[$from]) || isset($targets[$to]) || $fromStart + $seconds > $chapters[$from]['duration_seconds']
                || $toStart + $seconds > $chapters[$to]['duration_seconds']) {
                throw new Problem(422, 'invalid_equivalence_mapping', ['source_chapter_id' => $from, 'target_chapter_id' => $to]);
            }
            $sources[$from] = $targets[$to] = true;
            $mappings[] = ['source_chapter_id' => $from, 'target_chapter_id' => $to, 'source_start_second' => $fromStart,
                'target_start_second' => $toStart, 'seconds' => $seconds];
        }
        return $mappings;
    }
}
