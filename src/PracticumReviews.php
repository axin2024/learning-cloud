<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class PracticumReviews
{
    public function __construct(private Store $store, private Audit $audit) {}

    private function submission(Actor $actor, int $id): array
    {
        return $this->store->one('SELECT s.*,p.cohort_id FROM practicum_submissions s JOIN practicums p
            ON p.tenant_id=s.tenant_id AND p.id=s.practicum_id WHERE s.tenant_id=? AND s.id=?',
            [$actor->tenantId, $id]) ?? throw new Problem(404, 'submission_not_found');
    }

    private function reviewCase(Actor $actor, int $case, bool $lock = false): array
    {
        return $this->store->one('SELECT * FROM practicum_review_cases WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$actor->tenantId, $case]) ?? throw new Problem(404, 'review_not_found');
    }

    private function rubric(Actor $actor, array $submission): array
    {
        return (new CompetencyRubrics($this->store, $this->audit))->read($actor->tenantId, $submission['rubric_id']);
    }

    public function prepare(Actor $actor, int $submissionId, array $input): array
    {
        $actor->requireManagement();
        $s = $this->submission($actor, $submissionId);
        $manifest = json_decode($s['evidence_manifest'], true, flags: JSON_THROW_ON_ERROR);
        $assignment = Input::integer($input, 'assignment_id', 1);
        if (!in_array($assignment, array_column($manifest['members'], 'assignment_id'), true)) {
            throw new Problem(422, 'invalid_input', ['field' => 'assignment_id']);
        }
        $rubric = $this->rubric($actor, $s);
        $dims = array_column($rubric['dimensions'], null, 'code');
        $required = [];
        foreach ($manifest['artifacts'] as $file) {
            if ($dims[$file['dimension_code']]['evidence_scope'] === 'team' || $file['assignment_id'] === $assignment) {
                $required[$file['artifact_id']] = $file;
            }
        }
        $people = $this->store->all('SELECT a.name,a.email FROM practicum_assignments m JOIN accounts a ON a.tenant_id=m.tenant_id AND a.id=m.learner_id
            WHERE m.tenant_id=? AND m.practicum_id=? AND m.team_id=?', [$actor->tenantId, $s['practicum_id'], $s['team_id']]);
        $packet = [];
        foreach (Input::list($input, 'excerpts', 0, 512) as $index => $entry) {
            if (!is_array($entry)) {
                throw new Problem(422, 'invalid_input', ['field' => "excerpts.$index"]);
            }
            $id = Input::integer($entry, 'artifact_id', 1);
            $file = $required[$id] ?? throw new Problem(422, 'unexpected_review_artifact', ['field' => "excerpts.$index.artifact_id"]);
            $body = Input::string($entry, 'anonymous_text', 6000);
            foreach ($people as $person) {
                if (mb_stripos($body, $person['name']) !== false || mb_stripos($body, $person['email']) !== false) {
                    throw new Problem(422, 'identifying_review_text', ['field' => "excerpts.$index.anonymous_text"]);
                }
            }
            if (preg_match('/(?:学号|工号|student\s*id)\s*[:：#]?\s*[a-z0-9_-]+|[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', $body)) {
                throw new Problem(422, 'identifying_review_text', ['field' => "excerpts.$index.anonymous_text"]);
            }
            $excerpt = ['dimension_code' => $file['dimension_code'], 'kind' => $file['kind'], 'anchor' => $file['anchor'], 'text' => $body];
            if (in_array($file['kind'], ['image', 'video'], true)) {
                $excerpt['preview'] = $this->reviewPixels($file['kind'], $entry['preview'] ?? null);
            }
            $packet[] = $excerpt;
            unset($required[$id]);
        }
        if ($required) {
            throw new Problem(422, 'review_excerpts_incomplete', ['artifact_ids' => array_keys($required)]);
        }
        $this->store->one('SELECT id FROM practicum_submissions WHERE tenant_id=? AND id=? FOR UPDATE', [$actor->tenantId, $submissionId]);
        if ($this->store->one('SELECT id FROM practicum_review_cases WHERE tenant_id=? AND submission_id=? AND assignment_id=?',
            [$actor->tenantId, $submissionId, $assignment])) {
            throw new Problem(409, 'review_already_prepared');
        }
        $case = $this->store->insert('INSERT INTO practicum_review_cases (tenant_id,submission_id,assignment_id,prepared_by,blind_packet)
            VALUES (?,?,?,?,?)', [$actor->tenantId, $submissionId, $assignment, $actor->accountId, json_encode($packet, JSON_THROW_ON_ERROR)]);
        $this->audit->record($actor, 'practicum_review', $case, 'prepared', ['submission_id' => $submissionId, 'assignment_id' => $assignment]);
        return ['case_id' => $case, 'status' => 'reviewing', 'excerpt_count' => count($packet)];
    }

    private function reviewPixels(string $kind, mixed $preview): array
    {
        if (!is_array($preview)) {
            throw new Problem(422, 'anonymous_preview_required');
        }
        $width = Input::integer($preview, 'width', 1, 320);
        $height = Input::integer($preview, 'height', 1, 240);
        $count = Input::integer($preview, 'frames', $kind === 'image' ? 1 : 2, $kind === 'image' ? 1 : 24);
        $step = Input::integer($preview, 'frame_ms', $kind === 'image' ? 0 : 1, $kind === 'image' ? 0 : 1000);
        $encoded = $preview['pixels_zlib'] ?? null;
        $binary = is_string($encoded) && strlen($encoded) <= 5500000 ? base64_decode($encoded, true) : false;
        $size = $width * $height * 3 * $count;
        $pixels = $binary === false ? false : @gzuncompress($binary, $size);
        if ($pixels === false || strlen($pixels) !== $size) {
            throw new Problem(422, 'invalid_anonymous_pixels');
        }
        return ['width' => $width, 'height' => $height, 'frames' => $count, 'frame_ms' => $step,
            'pixels_zlib' => base64_encode(gzcompress($pixels, 6))];
    }

    private function eligible(Actor $actor, int $reviewer, array $s, int $preparer): void
    {
        $scope = [$actor->tenantId, $reviewer];
        if ($reviewer === $preparer || !$this->store->one("SELECT 1 FROM account_roles WHERE tenant_id=? AND account_id=? AND role='instructor'", $scope)
            || $this->store->one("SELECT 1 FROM account_roles WHERE tenant_id=? AND account_id=? AND role IN ('admin','registrar')", $scope)
            || $this->store->one('SELECT 1 FROM cohort_staff WHERE tenant_id=? AND account_id=? AND cohort_id=?', [...$scope, $s['cohort_id']])
            || $this->store->one('SELECT 1 FROM practicum_assignments WHERE tenant_id=? AND learner_id=? AND practicum_id=? AND team_id=?',
                [...$scope, $s['practicum_id'], $s['team_id']])) {
            throw new Problem(409, 'reviewer_conflict', ['field' => 'reviewer_id']);
        }
    }

    public function assign(Actor $actor, int $case, array $input): array
    {
        $actor->requireManagement();
        $c = $this->reviewCase($actor, $case, true);
        $seat = Input::choice($input, 'seat', ['first', 'second', 'arbiter']);
        $reviewer = Input::integer($input, 'reviewer_id', 1);
        if ($c['status'] !== ($seat === 'arbiter' ? 'arbitration' : 'reviewing')) {
            throw new Problem(409, 'review_seat_unavailable');
        }
        $this->eligible($actor, $reviewer, $this->submission($actor, $c['submission_id']), $c['prepared_by']);
        $scope = [$actor->tenantId, $case];
        $existing = $this->store->one('SELECT id,locked_at FROM practicum_review_seats WHERE tenant_id=? AND case_id=? AND seat=?', [...$scope, $seat]);
        if ($existing && $existing['locked_at'] !== null) {
            throw new Problem(409, 'review_locked');
        }
        if ($this->store->one('SELECT id FROM practicum_review_seats WHERE tenant_id=? AND case_id=? AND reviewer_id=? AND seat<>?',
            [...$scope, $reviewer, $seat])) {
            throw new Problem(409, 'independent_reviewers_required');
        }
        if ($existing) {
            $id = $existing['id'];
            $this->store->execute('UPDATE practicum_review_seats SET reviewer_id=? WHERE id=?', [$reviewer, $id]);
        } else {
            $id = $this->store->insert('INSERT INTO practicum_review_seats (tenant_id,case_id,seat,reviewer_id) VALUES (?,?,?,?)',
                [...$scope, $seat, $reviewer]);
        }
        $this->audit->record($actor, 'practicum_review', $case, 'assigned', ['seat' => $seat, 'review_id' => $id, 'reviewer_id' => $reviewer]);
        return ['case_id' => $case, 'review_id' => $id, 'seat' => $seat];
    }

    public function inbox(Actor $actor, int $after): array
    {
        $rows = $this->store->all('SELECT id review_id,case_id,seat,locked_at FROM practicum_review_seats
            WHERE tenant_id=? AND reviewer_id=? AND id>? ORDER BY id LIMIT 51', [$actor->tenantId, $actor->accountId, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['review_id'] : null];
    }

    public function cases(Actor $actor, int $submissionId): array
    {
        $s = $this->submission($actor, $submissionId);
        $scope = [$actor->tenantId, $submissionId];
        $filter = '';
        if (!$actor->managesCatalog()) {
            if (!$this->store->one('SELECT id FROM practicum_assignments WHERE tenant_id=? AND practicum_id=? AND team_id=? AND learner_id=?',
                [$actor->tenantId, $s['practicum_id'], $s['team_id'], $actor->accountId])) {
                throw new Problem(404, 'submission_not_found');
            }
            $filter = ' AND a.learner_id=?';
            $scope[] = $actor->accountId;
        }
        return ['items' => $this->store->all('SELECT c.id,c.assignment_id,c.status FROM practicum_review_cases c
            JOIN practicum_assignments a ON a.tenant_id=c.tenant_id AND a.id=c.assignment_id WHERE c.tenant_id=? AND c.submission_id=?'
            . $filter . ' ORDER BY c.id', $scope)];
    }

    public function ballot(Actor $actor, int $case): array
    {
        $own = $this->store->one('SELECT id review_id,seat,scores,locked_at FROM practicum_review_seats
            WHERE tenant_id=? AND case_id=? AND reviewer_id=?', [$actor->tenantId, $case, $actor->accountId]) ?? throw new Problem(404, 'review_not_found');
        $c = $this->reviewCase($actor, $case);
        $s = $this->submission($actor, $c['submission_id']);
        $this->eligible($actor, $actor->accountId, $s, $c['prepared_by']);
        $rubric = $this->rubric($actor, $s);
        $disputed = json_decode($c['disputed'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $dimensions = $rubric['dimensions'];
        if ($own['seat'] === 'arbiter') {
            $dimensions = array_values(array_filter($dimensions, fn($d) => in_array($d['code'], $disputed, true)));
        }
        $own['scores'] = $own['scores'] === null ? null : json_decode($own['scores'], true, flags: JSON_THROW_ON_ERROR);
        return $own + ['case_id' => $case, 'rubric_rev' => $rubric['rubric_rev'], 'dimensions' => $dimensions,
            'packet' => json_decode($c['blind_packet'], true, flags: JSON_THROW_ON_ERROR)];
    }

    public function score(Actor $actor, int $case, array $input): array
    {
        $c = $this->reviewCase($actor, $case, true);
        $ballot = $this->ballot($actor, $case);
        if ($ballot['locked_at'] !== null) {
            throw new Problem(409, 'review_locked');
        }
        $expected = array_column($ballot['dimensions'], 'code');
        $scores = [];
        foreach (Input::list($input, 'scores', count($expected), count($expected)) as $i => $row) {
            if (!is_array($row)) {
                throw new Problem(422, 'invalid_input', ['field' => "scores.$i"]);
            }
            $code = Input::slug($row, 'code');
            if (!in_array($code, $expected, true) || isset($scores[$code])) {
                throw new Problem(422, 'invalid_input', ['field' => "scores.$i.code"]);
            }
            $scores[$code] = ['score' => Input::integer($row, 'score', 0, 100), 'reason' => Input::string($row, 'reason', 1200)];
        }
        $this->store->execute('UPDATE practicum_review_seats SET scores=?,locked_at=UTC_TIMESTAMP(6) WHERE id=?',
            [json_encode($scores, JSON_THROW_ON_ERROR), $ballot['review_id']]);
        $this->audit->record($actor, 'practicum_review', $case, 'scores_locked', ['review_id' => $ballot['review_id']]);
        $this->reconcile($actor, $c);
        return $this->ballot($actor, $case);
    }

    private function reconcile(Actor $actor, array $c): void
    {
        $scope = [$actor->tenantId, $c['id']];
        $rows = $this->store->all('SELECT seat,scores FROM practicum_review_seats WHERE tenant_id=? AND case_id=? AND locked_at IS NOT NULL', $scope);
        $votes = [];
        foreach ($rows as $row) {
            $votes[$row['seat']] = json_decode($row['scores'], true, flags: JSON_THROW_ON_ERROR);
        }
        if (!isset($votes['first'], $votes['second'])) {
            return;
        }
        $s = $this->submission($actor, $c['submission_id']);
        $rubric = $this->rubric($actor, $s);
        $disputed = [];
        $final = [];
        $weighted = 0;
        $meets = true;
        foreach ($rubric['dimensions'] as $dimension) {
            $code = $dimension['code'];
            $left = $votes['first'][$code]['score'];
            $right = $votes['second'][$code]['score'];
            if (abs($left - $right) > $rubric['arbitration_gap']) {
                $disputed[] = $code;
                if (!isset($votes['arbiter'][$code])) {
                    continue;
                }
                $final[$code] = $votes['arbiter'][$code]['score'];
            } else {
                $final[$code] = ($left + $right) / 2;
            }
            $weighted += $final[$code] * $dimension['weight_pct'] / 100;
            $meets = $meets && (!$dimension['mandatory'] || $final[$code] >= $dimension['minimum_score']);
        }
        $pending = count($final) !== count($rubric['dimensions']);
        $missing = json_decode($s['missing_evidence'], true, flags: JSON_THROW_ON_ERROR);
        $qualified = $meets && round($weighted, 1) >= $rubric['passing_score'] && !in_array($c['assignment_id'], array_column($missing, 'assignment_id'), true);
        $this->store->execute('UPDATE practicum_review_cases SET status=?,disputed=?,final_scores=?,weighted_score=?,meets_standard=?
            WHERE tenant_id=? AND id=?', [$pending ? 'arbitration' : 'scored', json_encode($disputed, JSON_THROW_ON_ERROR),
            $pending ? null : json_encode($final, JSON_THROW_ON_ERROR), $pending ? null : number_format($weighted, 1, '.', ''),
            $pending ? null : $qualified, ...$scope]);
        $this->audit->record($actor, 'practicum_review', $c['id'], $pending ? 'arbitration_required' : 'score_finalized', [
            'previous_status' => $c['status'], 'rubric_id' => $s['rubric_id'], 'disputed' => $disputed,
            'weighted_score' => $pending ? null : round($weighted, 1),
        ]);
    }

    public function result(Actor $actor, int $case): array
    {
        $c = $this->reviewCase($actor, $case);
        if (!$actor->managesCatalog() && !$this->store->one('SELECT id FROM practicum_assignments WHERE tenant_id=? AND id=? AND learner_id=?',
            [$actor->tenantId, $c['assignment_id'], $actor->accountId])) {
            throw new Problem(404, 'review_not_found');
        }
        $seats = $this->store->all('SELECT id,seat,reviewer_id,locked_at FROM practicum_review_seats WHERE tenant_id=? AND case_id=? ORDER BY id',
            [$actor->tenantId, $case]);
        $result = ['case_id' => $case, 'status' => $c['status'], 'review_ids' => array_column($seats, 'id'),
            'arbitration_id' => array_column($seats, 'id', 'seat')['arbiter'] ?? null,
            'disputed' => json_decode($c['disputed'] ?? '[]', true, flags: JSON_THROW_ON_ERROR),
            'final_scores' => json_decode($c['final_scores'] ?? 'null', true, flags: JSON_THROW_ON_ERROR),
            'weighted_score' => $c['weighted_score'], 'meets_standard' => $c['meets_standard'] === null ? null : (bool)$c['meets_standard']];
        if ($actor->managesCatalog()) {
            $result['seats'] = $seats;
        }
        return $result;
    }
}
