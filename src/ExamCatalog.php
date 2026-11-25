<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class ExamCatalog
{
    public function __construct(private Store $store, private TeachingStaff $staff, private Audit $audit)
    {
    }

    public function papers(Actor $actor, int $course, int $after): array
    {
        $actor->requireManagement();
        $this->course($actor->tenantId, $course);
        $rows = $this->store->all('SELECT id, revision, edit_version, title, published_at FROM exam_papers
            WHERE tenant_id=? AND course_id=? AND id>? ORDER BY id LIMIT 51', [$actor->tenantId, $course, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function paper(Actor $actor, int $paper): array
    {
        $actor->requireManagement();
        return $this->paperRow($actor->tenantId, $paper);
    }

    public function draft(Actor $actor, int $course, array $input): array
    {
        $actor->requireManagement();
        $title = Input::string($input, 'title', 200);
        $pools = $this->pools($input);
        return $this->store->transaction(function () use ($actor, $course, $title, $pools): array {
            $this->course($actor->tenantId, $course, true);
            $next = $this->store->one('SELECT COALESCE(MAX(revision), 0)+1 AS next_revision FROM exam_papers WHERE tenant_id=? AND course_id=?',
                [$actor->tenantId, $course])['next_revision'];
            $id = $this->store->insert('INSERT INTO exam_papers (tenant_id, course_id, revision, title, pools) VALUES (?, ?, ?, ?, ?)',
                [$actor->tenantId, $course, $next, $title, json_encode($pools, JSON_THROW_ON_ERROR)]);
            $this->audit->record($actor, 'exam_paper', $id, 'drafted', ['course_id' => $course, 'revision' => $next]);
            return $this->paperRow($actor->tenantId, $id);
        });
    }

    public function edit(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'edit_version', 1);
        $title = Input::string($input, 'title', 200);
        $pools = $this->pools($input);
        return $this->store->transaction(function () use ($actor, $id, $version, $title, $pools): array {
            $this->editable($this->paperRow($actor->tenantId, $id, true), $version);
            $this->store->execute('UPDATE exam_papers SET title=?, pools=?, edit_version=edit_version+1 WHERE tenant_id=? AND id=?',
                [$title, json_encode($pools, JSON_THROW_ON_ERROR), $actor->tenantId, $id]);
            $this->audit->record($actor, 'exam_paper', $id, 'draft_edited', ['edit_version' => $version + 1]);
            return $this->paperRow($actor->tenantId, $id);
        });
    }

    public function publish(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'edit_version', 1);
        return $this->store->transaction(function () use ($actor, $id, $version): array {
            $paper = $this->paperRow($actor->tenantId, $id, true);
            if ($paper['published_at'] !== null && $paper['edit_version'] === $version) {
                return $paper;
            }
            $this->editable($paper, $version);
            $this->store->execute('UPDATE exam_papers SET published_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$actor->tenantId, $id]);
            $this->audit->record($actor, 'exam_paper', $id, 'published', ['revision' => $paper['revision']]);
            return $this->paperRow($actor->tenantId, $id);
        });
    }

    public function sittings(Actor $actor, int $cohort): array
    {
        $this->staff->requireMarker($actor, $cohort);
        return ['items' => $this->store->all(
            'SELECT s.*, p.revision AS paper_revision, p.title AS paper_title FROM exam_sittings s
                JOIN exam_papers p ON p.tenant_id=s.tenant_id AND p.id=s.paper_id
                WHERE s.tenant_id=? AND s.cohort_id=? ORDER BY s.sequence',
            [$actor->tenantId, $cohort])];
    }

    public function schedule(Actor $actor, int $cohort, array $input): array
    {
        $actor->requireManagement();
        $title = Input::string($input, 'title', 200);
        $paperId = Input::integer($input, 'paper_id', 1);
        $opens = Input::utcDateTime($input, 'opens_at');
        $closes = Input::utcDateTime($input, 'closes_at');
        $duration = Input::integer($input, 'duration_seconds', 60, 14400);
        if ($opens >= $closes) {
            throw new Problem(422, 'invalid_exam_window');
        }
        return $this->store->transaction(function () use ($actor, $cohort, $title, $paperId, $opens, $closes, $duration): array {
            $tenant = $actor->tenantId;
            $this->staff->requireMarker($actor, $cohort);
            $rule = $this->store->one('SELECT r.course_id, r.retake_mode, r.pass_score FROM cohorts c
                JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
                WHERE c.tenant_id=? AND c.id=? FOR UPDATE OF c', [$tenant, $cohort]);
            $paper = $this->paperRow($tenant, $paperId);
            if ($paper['published_at'] === null || $paper['course_id'] !== $rule['course_id']) {
                throw new Problem(409, 'published_course_paper_required');
            }
            $previous = $this->store->one('SELECT sequence, closes_at FROM exam_sittings WHERE tenant_id=? AND cohort_id=? ORDER BY sequence DESC LIMIT 1',
                [$tenant, $cohort]);
            if ($previous !== null && $previous['closes_at'] > $opens) {
                throw new Problem(409, 'exam_sittings_overlap');
            }
            $sequence = ($previous['sequence'] ?? 0) + 1;
            if ($sequence > 100) {
                throw new Problem(409, 'exam_sittings_limit');
            }
            if ($closes <= $this->store->one('SELECT UTC_TIMESTAMP(6) AS now_utc')['now_utc']) {
                throw new Problem(422, 'invalid_exam_window');
            }
            $id = $this->store->insert('INSERT INTO exam_sittings
                (tenant_id, cohort_id, paper_id, title, sequence, opens_at, closes_at, duration_seconds, pass_score, retake_mode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenant, $cohort, $paperId, $title, $sequence, $opens, $closes, $duration, $rule['pass_score'], $rule['retake_mode']]);
            $this->audit->record($actor, 'exam_sitting', $id, 'scheduled', ['sequence' => $sequence, 'paper_id' => $paperId, 'cohort_id' => $cohort]);
            return $this->sitting($tenant, $id);
        });
    }

    public function sitting(int $tenant, int $id): array
    {
        return $this->store->one('SELECT s.*, p.revision AS paper_revision FROM exam_sittings s
            JOIN exam_papers p ON p.tenant_id=s.tenant_id AND p.id=s.paper_id
            WHERE s.tenant_id=? AND s.id=?', [$tenant, $id]) ?? throw new Problem(404, 'exam_sitting_not_found');
    }

    public function paperRow(int $tenant, int $id, bool $lock = false): array
    {
        $row = $this->store->one('SELECT id, course_id, revision, title, edit_version, pools, published_at FROM exam_papers WHERE tenant_id=? AND id=?'
            . ($lock ? ' FOR UPDATE' : ''), [$tenant, $id]) ?? throw new Problem(404, 'exam_paper_not_found');
        $row['pools'] = json_decode($row['pools'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    private function course(int $tenant, int $course, bool $lock = false): void
    {
        if ($this->store->one('SELECT id FROM courses WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$tenant, $course]) === null) {
            throw new Problem(404, 'course_not_found');
        }
    }

    private function editable(array $paper, int $version): void
    {
        if ($paper['published_at'] !== null) {
            throw new Problem(409, 'exam_paper_already_published');
        }
        if ($paper['edit_version'] !== $version) {
            throw new Problem(409, 'exam_paper_version_conflict', ['edit_version' => $paper['edit_version']]);
        }
    }

    private function pools(array $input): array
    {
        $pools = [];
        $total = 0;
        $questionCount = 0;
        foreach (Input::list($input, 'pools', 1, 30) as $pool) {
            if (!is_array($pool)) {
                throw new Problem(422, 'invalid_exam_pool');
            }
            $questions = Input::list($pool, 'questions', 1, 100);
            $questionCount += count($questions);
            if ($questionCount > 300) {
                throw new Problem(422, 'exam_bank_too_large');
            }
            $draw = Input::integer($pool, 'draw', 1, count($questions));
            $points = Input::integer($pool, 'points', 1, 100);
            $total += $draw * $points;
            $pools[] = ['name' => Input::string($pool, 'name', 120), 'draw' => $draw, 'points' => $points,
                'questions' => array_map($this->question(...), $questions)];
        }
        if ($total !== 100) {
            throw new Problem(422, 'exam_points_must_total_100', ['points' => $total]);
        }
        return $pools;
    }

    private function question(mixed $item): array
    {
        if (!is_array($item)) {
            throw new Problem(422, 'invalid_exam_question');
        }
        $kind = Input::choice($item, 'kind', ['single', 'multiple', 'written']);
        $prompt = Input::string($item, 'prompt', 2500);
        if ($kind === 'written') {
            return ['kind' => $kind, 'prompt' => $prompt, 'options' => null, 'guidance' => Input::string($item, 'rubric', 2500)];
        }
        $options = array_map(static function ($text): string {
            $option = Input::string(['option' => $text], 'option', 400);
            if (str_contains($option, "\n") || str_contains($option, "\r")) {
                throw new Problem(422, 'exam_option_must_be_one_line');
            }
            return $option;
        }, Input::list($item, 'options', 2, 8));
        if (count(array_unique($options)) !== count($options)) {
            throw new Problem(422, 'duplicate_exam_options');
        }
        $choices = self::choices($item, 'correct', count($options), $kind === 'single' ? 1 : count($options) - 1);
        if ($choices === []) {
            throw new Problem(422, 'exam_answer_key_required');
        }
        return ['kind' => $kind, 'prompt' => $prompt, 'options' => $options, 'guidance' => $choices];
    }

    public static function choices(array $input, string $key, int $optionCount, int $maximum): array
    {
        $choices = [];
        foreach (Input::list($input, $key, 0, $maximum) as $choice) {
            $index = Input::integer([$key => $choice], $key, 1, $optionCount);
            if (isset($choices[$index])) {
                throw new Problem(422, 'duplicate_exam_choice', ['field' => $key]);
            }
            $choices[$index] = $index;
        }
        sort($choices, SORT_NUMERIC);
        return $choices;
    }
}
