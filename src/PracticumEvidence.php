<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class PracticumEvidence
{
    public function __construct(private Store $store, private Practicums $tasks, private Audit $audit) {}

    private function dimensions(Actor $actor, array $task): array
    {
        return array_column($this->store->all('SELECT code,name,evidence_kind,evidence_scope,mandatory FROM rubric_dimensions
            WHERE tenant_id=? AND rubric_id=? ORDER BY ordinal', [$actor->tenantId, $task['rubric_id']]), null, 'code');
    }

    private function anchor(string $kind, array $input): array
    {
        $anchor = $input['anchor'] ?? null;
        if (!is_array($anchor)) {
            throw new Problem(422, 'invalid_input', ['field' => 'anchor']);
        }
        if ($kind === 'document') {
            return ['page' => Input::integer($anchor, 'page', 1, 100000)];
        }
        if ($kind === 'image') {
            $x = Input::integer($anchor, 'x_pct', 0, 99);
            $y = Input::integer($anchor, 'y_pct', 0, 99);
            return ['x_pct' => $x, 'y_pct' => $y, 'width_pct' => Input::integer($anchor, 'width_pct', 1, 100 - $x),
                'height_pct' => Input::integer($anchor, 'height_pct', 1, 100 - $y)];
        }
        $start = Input::integer($anchor, 'start_ms', 0, 86400000);
        return ['start_ms' => $start, 'end_ms' => Input::integer($anchor, 'end_ms', $start + 1, 86400001)];
    }

    public function upload(Actor $actor, int $taskId, int $team, array $input): array
    {
        $task = $this->tasks->task($actor, $taskId);
        $assignment = $this->tasks->membership($actor, $task, $team, true)['id'];
        $code = Input::slug($input, 'dimension_code');
        $dimension = $this->dimensions($actor, $task)[$code] ?? throw new Problem(422, 'unknown_dimension', ['field' => 'dimension_code']);
        $bytes = base64_decode(Input::string($input, 'content_base64', 2796204), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 2097152) {
            throw new Problem(422, 'invalid_artifact', ['field' => 'content_base64']);
        }
        $hash = hash('sha256', $bytes);
        $kind = $dimension['evidence_kind'];
        $anchor = $this->anchor($kind, $input);
        $id = $this->store->insert('INSERT INTO practicum_artifacts
            (tenant_id,practicum_id,team_id,assignment_id,dimension_code,digest,kind,anchor,content) VALUES (?,?,?,?,?,?,?,?,?)',
            [$actor->tenantId, $taskId, $team, $assignment, $code, $hash, $kind, json_encode($anchor, JSON_THROW_ON_ERROR), $bytes]);
        return ['artifact_id' => $id, 'digest' => 'sha256:' . $hash, 'anchor' => $anchor, 'dimension_code' => $code];
    }

    private function artifacts(array $scope): array
    {
        $rows = $this->store->all("SELECT id artifact_id,assignment_id,dimension_code,kind,anchor,CONCAT('sha256:',digest) digest
            FROM practicum_artifacts WHERE tenant_id=? AND practicum_id=? AND team_id=? ORDER BY id", $scope);
        foreach ($rows as &$row) {
            $row['anchor'] = json_decode($row['anchor'], true, flags: JSON_THROW_ON_ERROR);
        }
        return $rows;
    }

    public function drafts(Actor $actor, int $id, int $team): array
    {
        $task = $this->tasks->task($actor, $id);
        $this->tasks->membership($actor, $task, $team);
        return ['items' => $this->artifacts([$actor->tenantId, $id, $team])];
    }

    public function download(Actor $actor, int $id, int $team, int $artifact): array
    {
        $task = $this->tasks->task($actor, $id);
        $scope = [$task['rubric_id'], $actor->tenantId, $id, $team, $artifact];
        $filter = '';
        if (!$this->tasks->isMarker($actor, $task['cohort_id'])) {
            $scope[] = $this->tasks->membership($actor, $task, $team)['id'];
            $filter = " AND (d.evidence_scope='team' OR a.assignment_id=?)";
        }
        $row = $this->store->one('SELECT a.content,a.digest FROM practicum_artifacts a JOIN rubric_dimensions d
            ON d.tenant_id=a.tenant_id AND d.rubric_id=? AND d.code=a.dimension_code
            WHERE a.tenant_id=? AND a.practicum_id=? AND a.team_id=? AND a.id=?' . $filter, $scope)
            ?? throw new Problem(404, 'artifact_not_found');
        return ['artifact_id' => $artifact, 'digest' => 'sha256:' . $row['digest'], 'content_base64' => base64_encode($row['content'])];
    }

    public function submit(Actor $actor, int $id, array $input): array
    {
        $task = $this->tasks->task($actor, $id);
        $team = Input::integer($input, 'team_id', 1);
        $scope = [$actor->tenantId, $id, $team];
        $this->tasks->membership($actor, $task, $team, true);
        if (Input::integer($input, 'rubric_rev', 1) !== $task['rubric_rev']) {
            throw new Problem(409, 'rubric_revision_changed');
        }
        $attempt = Input::integer($input, 'attempt', 1);
        $previous = $this->store->one('SELECT attempt,evidence_manifest FROM practicum_submissions
            WHERE tenant_id=? AND practicum_id=? AND team_id=? ORDER BY attempt DESC LIMIT 1', $scope);
        if ($attempt !== ($previous['attempt'] ?? 0) + 1) {
            throw new Problem(409, 'attempt_out_of_sequence', ['next' => ($previous['attempt'] ?? 0) + 1]);
        }
        $now = $this->store->one('SELECT UTC_TIMESTAMP(6) clock')['clock'];
        $late = $now > $task['due_at'];
        $extension = null;
        if ($late) {
            $extension = $this->store->one('SELECT id FROM practicum_extensions WHERE tenant_id=? AND practicum_id=?
                AND team_id=? AND attempt=? AND approved_by IS NOT NULL AND until_at>=?', [...$scope, $attempt, $now])['id']
                ?? throw new Problem(409, 'late_approval_required', ['attempt' => $attempt]);
        }
        $selected = [];
        foreach (Input::list($input, 'artifact_hashes', 0, 256) as $index => $digest) {
            if (!is_string($digest) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) || isset($selected[$digest])) {
                throw new Problem(422, 'invalid_input', ['field' => "artifact_hashes.$index"]);
            }
            $selected[$digest] = true;
        }
        $claims = [];
        $found = [];
        $coverage = [];
        $dimensions = $this->dimensions($actor, $task);
        foreach ($this->artifacts($scope) as $item) {
            if (isset($selected[$item['digest']])) {
                $item['scope'] = $dimensions[$item['dimension_code']]['evidence_scope'];
                $claims[] = $item;
                $found[$item['digest']] = true;
                $owner = $dimensions[$item['dimension_code']]['evidence_scope'] === 'team' ? 0 : $item['assignment_id'];
                $coverage[$owner][$item['dimension_code']] = true;
            }
        }
        if (array_diff_key($selected, $found)) {
            throw new Problem(422, 'unknown_team_artifact', ['field' => 'artifact_hashes']);
        }
        $sharedMissing = [];
        foreach ($dimensions as $code => $dimension) {
            if ($dimension['mandatory'] && $dimension['evidence_scope'] === 'team' && !isset($coverage[0][$code])) {
                $sharedMissing[] = ['code' => $code, 'name' => $dimension['name']];
            }
        }
        if ($sharedMissing || !$claims) {
            throw new Problem(422, 'team_evidence_required', ['field' => 'artifact_hashes', 'dimensions' => $sharedMissing]);
        }
        $members = $this->store->all('SELECT a.id assignment_id,a.learner_id,c.revision FROM practicum_assignments a
            LEFT JOIN practicum_contributions c ON c.assignment_id=a.id AND c.revision=(SELECT MAX(v.revision)
            FROM practicum_contributions v WHERE v.assignment_id=a.id)
            WHERE a.tenant_id=? AND a.practicum_id=? AND a.team_id=? ORDER BY a.id', $scope);
        $missing = [];
        $absent = [];
        foreach ($members as $member) {
            $personal = [];
            foreach ($dimensions as $code => $dimension) {
                if (!$dimension['mandatory']) {
                    continue;
                }
                $owner = $dimension['evidence_scope'] === 'team' ? 0 : $member['assignment_id'];
                if (!isset($coverage[$owner][$code])) {
                    $missing[] = ['assignment_id' => $member['assignment_id'], 'code' => $code, 'name' => $dimension['name']];
                }
                if ($owner !== 0) {
                    $personal[] = $dimension['name'];
                }
            }
            if ($member['revision'] === null) {
                $absent[] = ['learner_id' => $member['learner_id'], 'dimensions' => $personal];
            }
        }
        if ($absent) {
            throw new Problem(422, 'contribution_required', ['members' => $absent]);
        }
        $manifest = ['rubric_id' => $task['rubric_id'], 'members' => $members, 'artifacts' => $claims];
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
        $digest = PracticumWrites::digest($manifest);
        $submission = $this->store->insert('INSERT INTO practicum_submissions (tenant_id,practicum_id,team_id,attempt,
            submitted_by,submitted_at,late,extension_id,rubric_id,evidence_manifest,manifest_digest,missing_evidence)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [...$scope, $attempt, $actor->accountId, $now, $late, $extension,
            $task['rubric_id'], $encoded, $digest, json_encode($missing, JSON_THROW_ON_ERROR)]);
        $this->audit->record($actor, 'practicum', $id, 'submitted', ['submission_id' => $submission, 'attempt' => $attempt, 'digest' => $digest]);
        return $this->receipt($actor, $id, $team, $attempt);
    }

    public function receipt(Actor $actor, int $id, int $team, int $attempt): array
    {
        $task = $this->tasks->task($actor, $id);
        if (!$this->tasks->isMarker($actor, $task['cohort_id'])) {
            $this->tasks->membership($actor, $task, $team);
        }
        $scope = [$actor->tenantId, $id, $team];
        $row = $this->store->one('SELECT id submission_id,attempt,submitted_at,late,extension_id,manifest_digest,evidence_manifest,missing_evidence
            FROM practicum_submissions WHERE tenant_id=? AND practicum_id=? AND team_id=? AND attempt=?', [...$scope, $attempt])
            ?? throw new Problem(404, 'submission_not_found');
        $row['evidence_manifest'] = json_decode($row['evidence_manifest'], true, flags: JSON_THROW_ON_ERROR);
        $row['missing_evidence'] = json_decode($row['missing_evidence'], true, flags: JSON_THROW_ON_ERROR);
        $row['late'] = (bool)$row['late'];
        $row['submitted_at'] = str_replace(' ', 'T', $row['submitted_at']) . 'Z';
        $previous = $this->store->one('SELECT evidence_manifest FROM practicum_submissions
            WHERE tenant_id=? AND practicum_id=? AND team_id=? AND attempt=?', [...$scope, $attempt - 1]);
        $prior = $previous ? json_decode($previous['evidence_manifest'], true, flags: JSON_THROW_ON_ERROR) : [];
        $before = array_column($prior['artifacts'] ?? [], 'artifact_id');
        $after = array_column($row['evidence_manifest']['artifacts'], 'artifact_id');
        $revisions = array_column($prior['members'] ?? [], 'revision', 'assignment_id');
        $changed = [];
        foreach ($row['evidence_manifest']['members'] as $member) {
            if (($revisions[$member['assignment_id']] ?? null) !== $member['revision']) {
                $changed[] = $member['assignment_id'];
            }
        }
        $row['changes'] = ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after)),
            'changed_contributions' => $changed];
        return $row + ['attempt_id' => $row['submission_id']];
    }
}
