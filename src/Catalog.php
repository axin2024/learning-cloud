<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class Catalog
{
    private const VERSION_FIELDS = 'id, course_id, revision, edit_version, title, summary, minimum_study_seconds, pass_score, retake_mode, published_at';

    public function __construct(private Store $store, private Audit $audit)
    {
    }

    public function create(Actor $actor, array $input): array
    {
        $actor->requireManagement();
        $slug = Input::slug($input, 'slug');
        try {
            return $this->store->transaction(function () use ($actor, $slug): array {
                $id = $this->store->insert('INSERT INTO courses (tenant_id, slug, owner_id) VALUES (?, ?, ?)', [$actor->tenantId, $slug, $actor->accountId]);
                $this->audit->record($actor, 'course', $id, 'created', ['slug' => $slug]);
                return $this->course($actor, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'course_slug_exists');
            }
            throw $error;
        }
    }

    public function listing(Actor $actor, int $after): array
    {
        $visible = $actor->managesCatalog() ? '' : ' AND r.id IS NOT NULL';
        $rows = $this->store->all(
            'SELECT c.id, c.slug, r.id AS published_revision_id, r.revision, r.title, r.summary
               FROM courses c LEFT JOIN course_revisions r ON r.id=(
                   SELECT latest.id FROM course_revisions latest
                    WHERE latest.tenant_id=c.tenant_id AND latest.course_id=c.id AND latest.published_at IS NOT NULL
                    ORDER BY latest.revision DESC LIMIT 1
               ) WHERE c.tenant_id=? AND c.id>?' . $visible . ' ORDER BY c.id LIMIT 51',
            [$actor->tenantId, $after]
        );
        return $this->page($rows);
    }

    public function detail(Actor $actor, int $courseId): array
    {
        $actor->requireManagement();
        $course = $this->course($actor, $courseId);
        $course['revisions'] = $this->store->all(
            'SELECT ' . self::VERSION_FIELDS . ' FROM course_revisions WHERE tenant_id=? AND course_id=? ORDER BY revision DESC',
            [$actor->tenantId, $courseId]
        );
        return $course;
    }

    public function draft(Actor $actor, int $courseId, array $input): array
    {
        $actor->requireManagement();
        [$fields, $chapters] = $this->draftFields($input);
        return $this->store->transaction(function () use ($actor, $courseId, $fields, $chapters): array {
            $this->course($actor, $courseId, true);
            $next = $this->store->one('SELECT COALESCE(MAX(revision), 0) + 1 AS next FROM course_revisions WHERE tenant_id=? AND course_id=?', [$actor->tenantId, $courseId]);
            $id = $this->store->insert(
                'INSERT INTO course_revisions (tenant_id, course_id, revision, title, summary, minimum_study_seconds, pass_score, retake_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$actor->tenantId, $courseId, $next['next'], ...$fields]
            );
            $this->saveChapters($actor->tenantId, $id, $chapters);
            $this->audit->record($actor, 'course_revision', $id, 'drafted', ['course_id' => $courseId, 'revision' => $next['next']]);
            return $this->revision($actor, $courseId, $id);
        });
    }

    public function edit(Actor $actor, int $courseId, int $revisionId, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'edit_version', 1);
        [$fields, $chapters] = $this->draftFields($input);
        return $this->store->transaction(function () use ($actor, $courseId, $revisionId, $version, $fields, $chapters): array {
            $this->course($actor, $courseId, true);
            $revision = $this->revisionRow($actor, $courseId, $revisionId);
            $this->ensureEditable($revision, $version);
            $this->store->execute(
                'UPDATE course_revisions SET title=?, summary=?, minimum_study_seconds=?, pass_score=?, retake_mode=?, edit_version=edit_version+1 WHERE tenant_id=? AND id=?',
                [...$fields, $actor->tenantId, $revisionId]
            );
            $this->store->execute('DELETE FROM chapters WHERE tenant_id=? AND revision_id=?', [$actor->tenantId, $revisionId]);
            $this->saveChapters($actor->tenantId, $revisionId, $chapters);
            $this->audit->record($actor, 'course_revision', $revisionId, 'edited', ['edit_version' => $version + 1]);
            return $this->revision($actor, $courseId, $revisionId);
        });
    }

    public function publish(Actor $actor, int $courseId, int $revisionId, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'edit_version', 1);
        return $this->store->transaction(function () use ($actor, $courseId, $revisionId, $version): array {
            $this->course($actor, $courseId, true);
            $revision = $this->revisionRow($actor, $courseId, $revisionId);
            if ($revision['published_at'] !== null && $revision['edit_version'] === $version) {
                return $this->revision($actor, $courseId, $revisionId);
            }
            $this->ensureEditable($revision, $version);
            $latest = $this->store->one(
                'SELECT revision FROM course_revisions WHERE tenant_id=? AND course_id=? AND published_at IS NOT NULL ORDER BY revision DESC LIMIT 1',
                [$actor->tenantId, $courseId]
            );
            if ($latest !== null && $latest['revision'] >= $revision['revision']) {
                throw new Problem(409, 'revision_superseded', ['published_revision' => $latest['revision']]);
            }
            $unverified = $this->store->all(
                "SELECT ordinal FROM chapters WHERE tenant_id=? AND revision_id=? AND (rights_basis='unverified' OR rights_reference='') ORDER BY ordinal",
                [$actor->tenantId, $revisionId]
            );
            if ($unverified !== []) {
                throw new Problem(422, 'rights_evidence_required', ['chapters' => array_column($unverified, 'ordinal')]);
            }
            $this->store->execute('UPDATE course_revisions SET published_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$actor->tenantId, $revisionId]);
            $this->audit->record($actor, 'course_revision', $revisionId, 'published', ['course_id' => $courseId, 'edit_version' => $version]);
            return $this->revision($actor, $courseId, $revisionId);
        });
    }

    public function revision(Actor $actor, int $courseId, int $revisionId): array
    {
        $revision = $this->revisionRow($actor, $courseId, $revisionId);
        $manage = $actor->managesCatalog();
        if (!$manage && !$this->ownsLearningMaterial($actor, $revisionId)) {
            throw new Problem(404, 'revision_not_found');
        }
        $rights = $manage ? ', rights_basis, rights_reference' : '';
        $revision['chapters'] = $this->store->all(
            'SELECT id, ordinal, title, kind, content, duration_seconds, required_seconds' . $rights . ' FROM chapters WHERE tenant_id=? AND revision_id=? ORDER BY ordinal',
            [$actor->tenantId, $revisionId]
        );
        return $revision;
    }

    public function revisionForNewCohort(Actor $actor, int $courseId, ?int $revisionId): int
    {
        // The caller holds a transaction so publication and cohort creation agree on the latest version.
        $this->course($actor, $courseId, true);
        if ($revisionId !== null) {
            $revision = $this->revisionRow($actor, $courseId, $revisionId);
        } else {
            $revision = $this->store->one(
                'SELECT id, published_at FROM course_revisions WHERE tenant_id=? AND course_id=? AND published_at IS NOT NULL ORDER BY revision DESC LIMIT 1',
                [$actor->tenantId, $courseId]
            );
        }
        if (($revision['published_at'] ?? null) === null) {
            throw new Problem(409, 'published_revision_required');
        }
        return $revision['id'];
    }

    private function page(array $rows): array
    {
        $more = count($rows) > 50;
        $items = array_slice($rows, 0, 50);
        return ['items' => $items, 'next_after' => $more ? $items[49]['id'] : null];
    }

    private function course(Actor $actor, int $courseId, bool $lock = false): array
    {
        $row = $this->store->one('SELECT id, slug, owner_id FROM courses WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$actor->tenantId, $courseId]);
        return $row ?? throw new Problem(404, 'course_not_found');
    }

    private function revisionRow(Actor $actor, int $courseId, int $revisionId): array
    {
        $row = $this->store->one(
            'SELECT ' . self::VERSION_FIELDS . ' FROM course_revisions WHERE tenant_id=? AND course_id=? AND id=?',
            [$actor->tenantId, $courseId, $revisionId]
        );
        return $row ?? throw new Problem(404, 'revision_not_found');
    }

    private function ownsLearningMaterial(Actor $actor, int $revisionId): bool
    {
        return $this->store->one(
            "SELECT 1 FROM cohorts c JOIN enrollments e ON e.tenant_id=c.tenant_id AND e.cohort_id=c.id
              WHERE c.tenant_id=? AND c.revision_id=? AND e.learner_id=?
                AND e.state IN ('enrolled','studying','completed') LIMIT 1",
            [$actor->tenantId, $revisionId, $actor->accountId]
        ) !== null;
    }

    private function ensureEditable(array $revision, int $version): void
    {
        if ($revision['published_at'] !== null) {
            throw new Problem(409, 'revision_already_published');
        }
        if ($revision['edit_version'] !== $version) {
            throw new Problem(409, 'revision_version_conflict', ['edit_version' => $revision['edit_version']]);
        }
    }

    private function draftFields(array $input): array
    {
        $fields = [
            Input::string($input, 'title', 200),
            Input::string($input, 'summary', 4000, 0),
            Input::integer($input, 'minimum_study_seconds', 0, 10_000_000),
            Input::integer($input, 'pass_score', 0, 100),
            Input::choice($input, 'retake_mode', ['highest', 'latest']),
        ];
        $chapters = [];
        $possible = 0;
        foreach (Input::list($input, 'chapters', 1, 100) as $chapter) {
            if (!is_array($chapter)) {
                throw new Problem(422, 'invalid_input', ['field' => 'chapters']);
            }
            $duration = Input::integer($chapter, 'duration_seconds', 1, 86_400);
            $possible += $duration;
            $chapters[] = [
                Input::string($chapter, 'title', 200),
                Input::choice($chapter, 'kind', ['text', 'recorded', 'live']),
                Input::string($chapter, 'content', 12_000),
                $duration,
                Input::integer($chapter, 'required_seconds', 0, $duration),
                Input::choice($chapter, 'rights_basis', ['unverified', 'original', 'licensed', 'public_domain']),
                Input::string($chapter, 'rights_reference', 500, 0),
            ];
        }
        if ($fields[2] > $possible) {
            throw new Problem(422, 'study_time_exceeds_course_duration', ['available_seconds' => $possible]);
        }
        return [$fields, $chapters];
    }

    private function saveChapters(int $tenantId, int $revisionId, array $chapters): void
    {
        foreach ($chapters as $index => $chapter) {
            $this->store->execute(
                'INSERT INTO chapters (tenant_id, revision_id, ordinal, title, kind, content, duration_seconds, required_seconds, rights_basis, rights_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenantId, $revisionId, $index + 1, ...$chapter]
            );
        }
    }
}
