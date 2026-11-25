<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class LearningPaths
{
    private const FIELDS = 'id, code, title, description, version, published_at';
    private const COURSE_COMPLETED = "EXISTS (
        SELECT 1 FROM enrollments e JOIN cohorts c ON c.tenant_id=e.tenant_id AND c.id=e.cohort_id
        JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
        WHERE e.tenant_id=p.tenant_id AND e.learner_id=? AND e.state='completed' AND r.course_id=p.course_id)";

    public function __construct(private Store $store, private Audit $audit)
    {
    }

    public function listing(Actor $actor, int $after): array
    {
        return $this->page($this->store->all('SELECT ' . self::FIELDS . ' FROM learning_paths
            WHERE tenant_id=? AND id>? AND (? OR published_at IS NOT NULL) ORDER BY id LIMIT 51',
            [$actor->tenantId, $after, $actor->managesCatalog()]));
    }

    public function detail(Actor $actor, int $id): array
    {
        $path = $this->path($actor, $id);
        $steps = $this->store->all('SELECT p.id, p.position, p.course_id, r.title,
                ' . self::COURSE_COMPLETED . ' AS completed
            FROM path_steps p JOIN course_revisions r ON r.tenant_id=p.tenant_id AND r.id=(
                SELECT x.id FROM course_revisions x WHERE x.tenant_id=p.tenant_id AND x.course_id=p.course_id
                    AND x.published_at IS NOT NULL ORDER BY x.revision DESC LIMIT 1)
            WHERE p.tenant_id=? AND p.path_id=? ORDER BY p.position', [$actor->accountId, $actor->tenantId, $id]);
        $eligible = true;
        foreach ($steps as &$step) {
            $step['completed'] = (bool) $step['completed'];
            $step['eligible'] = $eligible;
            $eligible = $eligible && $step['completed'];
        }
        unset($step);
        return $path + ['steps' => $steps];
    }

    public function create(Actor $actor, array $input): array
    {
        $actor->requireManagement();
        $code = Input::slug($input, 'code');
        [$title, $description, $courses] = $this->validate($actor->tenantId, $input);
        try {
            return $this->store->transaction(function () use ($actor, $code, $title, $description, $courses): array {
                $id = $this->store->insert('INSERT INTO learning_paths (tenant_id, code, title, description) VALUES (?, ?, ?, ?)',
                    [$actor->tenantId, $code, $title, $description]);
                $this->saveSteps($actor->tenantId, $id, $courses);
                $this->audit->record($actor, 'learning_path', $id, 'drafted', ['course_ids' => $courses]);
                return $this->detail($actor, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) throw new Problem(409, 'learning_path_code_exists');
            throw $error;
        }
    }

    public function edit(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'version', 1);
        [$title, $description, $courses] = $this->validate($actor->tenantId, $input);
        return $this->store->transaction(function () use ($actor, $id, $version, $title, $description, $courses): array {
            $path = $this->path($actor, $id, true);
            $this->requireVersion($path, $version);
            if ($path['published_at'] !== null) throw new Problem(409, 'learning_path_already_published');
            $this->store->execute('UPDATE learning_paths SET title=?, description=?, version=version+1 WHERE tenant_id=? AND id=?',
                [$title, $description, $actor->tenantId, $id]);
            $this->store->execute('DELETE FROM path_steps WHERE tenant_id=? AND path_id=?', [$actor->tenantId, $id]);
            $this->saveSteps($actor->tenantId, $id, $courses);
            $this->audit->record($actor, 'learning_path', $id, 'edited', ['version' => $version + 1, 'course_ids' => $courses]);
            return $this->detail($actor, $id);
        });
    }

    public function publish(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'version', 1);
        return $this->store->transaction(function () use ($actor, $id, $version): array {
            $path = $this->path($actor, $id, true);
            $this->requireVersion($path, $version);
            if ($path['published_at'] === null) {
                $this->store->execute('UPDATE learning_paths SET published_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$actor->tenantId, $id]);
                $this->audit->record($actor, 'learning_path', $id, 'published', ['version' => $version]);
            }
            return $this->detail($actor, $id);
        });
    }

    public function stepCohorts(Actor $actor, int $path, int $step, int $after): array
    {
        $this->path($actor, $path);
        if ($this->store->one('SELECT id FROM path_steps WHERE tenant_id=? AND path_id=? AND id=?', [$actor->tenantId, $path, $step]) === null) {
            throw new Problem(404, 'path_step_not_found');
        }
        return $this->page($this->store->all('SELECT c.id, c.name, c.starts_on, c.ends_on, c.registration_open, r.revision
            FROM cohort_path_steps b JOIN cohorts c ON c.tenant_id=b.tenant_id AND c.id=b.cohort_id
            JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
            WHERE b.tenant_id=? AND b.step_id=? AND b.cohort_id>? ORDER BY b.cohort_id LIMIT 51', [$actor->tenantId, $step, $after]));
    }

    public function bindCohort(int $tenant, int $cohort, int $course, int $step): void
    {
        $published = $this->store->one('SELECT p.id FROM path_steps p JOIN learning_paths f ON f.tenant_id=p.tenant_id AND f.id=p.path_id
             WHERE p.tenant_id=? AND p.id=? AND p.course_id=? AND f.published_at IS NOT NULL', [$tenant, $step, $course]);
        if ($published === null) throw new Problem(422, 'path_step_course_mismatch');
        $this->store->execute('INSERT INTO cohort_path_steps (tenant_id, cohort_id, step_id) VALUES (?, ?, ?)', [$tenant, $cohort, $step]);
    }

    public function cohortPath(int $tenant, int $cohort): ?array
    {
        return $this->store->one('SELECT f.id AS path_id, f.title, p.id AS step_id, p.position FROM cohort_path_steps b
            JOIN path_steps p ON p.tenant_id=b.tenant_id AND p.id=b.step_id
            JOIN learning_paths f ON f.tenant_id=p.tenant_id AND f.id=p.path_id WHERE b.tenant_id=? AND b.cohort_id=?', [$tenant, $cohort]);
    }

    public function missing(int $tenant, int $cohort, int $learner): array
    {
        return $this->store->all('SELECT p.course_id, p.position, r.title FROM cohort_path_steps b
            JOIN path_steps s ON s.tenant_id=b.tenant_id AND s.id=b.step_id
            JOIN path_steps p ON p.tenant_id=s.tenant_id AND p.path_id=s.path_id AND p.position<s.position
            JOIN course_revisions r ON r.tenant_id=p.tenant_id AND r.id=(
                SELECT x.id FROM course_revisions x WHERE x.tenant_id=p.tenant_id AND x.course_id=p.course_id
                    AND x.published_at IS NOT NULL ORDER BY x.revision DESC LIMIT 1)
            WHERE b.tenant_id=? AND b.cohort_id=? AND NOT ' . self::COURSE_COMPLETED . ' ORDER BY p.position', [$tenant, $cohort, $learner]);
    }

    public function requireEntry(int $tenant, int $cohort, int $learner): void
    {
        $missing = $this->missing($tenant, $cohort, $learner);
        if ($missing !== []) throw new Problem(409, 'path_prerequisites_required', ['missing' => $missing]);
    }

    private function path(Actor $actor, int $id, bool $lock = false): array
    {
        $row = $this->store->one('SELECT ' . self::FIELDS . ' FROM learning_paths
            WHERE tenant_id=? AND id=? AND (? OR published_at IS NOT NULL)' . ($lock ? ' FOR UPDATE' : ''),
            [$actor->tenantId, $id, $actor->managesCatalog()]);
        return $row ?? throw new Problem(404, 'learning_path_not_found');
    }

    private function validate(int $tenant, array $input): array
    {
        $title = Input::string($input, 'title', 200);
        $description = Input::string($input, 'description', 4000, 0);
        $courses = array_map(static fn ($course) => Input::integer(['course_id' => $course], 'course_id', 1),
            Input::list($input, 'course_ids', 2, 30));
        if (count(array_unique($courses)) !== count($courses)) throw new Problem(422, 'learning_path_courses_must_be_distinct');
        $ids = implode(',', array_fill(0, count($courses), '?'));
        $published = $this->store->one("SELECT COUNT(DISTINCT course_id) AS total FROM course_revisions
            WHERE tenant_id=? AND course_id IN ($ids) AND published_at IS NOT NULL", [$tenant, ...$courses]);
        if ($published['total'] !== count($courses)) throw new Problem(422, 'learning_path_requires_published_courses');
        return [$title, $description, $courses];
    }

    private function saveSteps(int $tenant, int $id, array $courses): void
    {
        foreach ($courses as $index => $course) {
            $this->store->execute('INSERT INTO path_steps (tenant_id, path_id, position, course_id) VALUES (?, ?, ?, ?)',
                [$tenant, $id, $index + 1, $course]);
        }
    }

    private function requireVersion(array $path, int $version): void
    {
        if ($path['version'] !== $version) throw new Problem(409, 'learning_path_version_conflict', ['version' => $path['version']]);
    }

    private function page(array $rows): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }
}
