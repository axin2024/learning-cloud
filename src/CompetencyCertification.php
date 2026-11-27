<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class CompetencyCertification
{
    public function __construct(private Store $store, private Audit $audit) {}

    public function subject(Actor $actor, int $case, bool $lock = false): array
    {
        $row = $this->store->one('SELECT c.id,c.assignment_id,c.status,c.final_scores,c.submission_id,s.rubric_id,
            s.attempt,s.team_id,s.practicum_id,s.missing_evidence,a.learner_id FROM practicum_review_cases c
            JOIN practicum_submissions s ON s.tenant_id=c.tenant_id AND s.id=c.submission_id
            JOIN practicum_assignments a ON a.tenant_id=c.tenant_id AND a.id=c.assignment_id
            WHERE c.tenant_id=? AND c.id=?', [$actor->tenantId, $case]);
        if (!$row || (!$actor->managesCatalog() && $row['learner_id'] !== $actor->accountId)) {
            throw new Problem(404, 'review_not_found');
        }
        if ($row['status'] !== 'scored') {
            throw new Problem(409, 'final_score_required');
        }
        if ($lock) {
            $this->store->one('SELECT id FROM practicum_assignments WHERE tenant_id=? AND id=? FOR UPDATE',
                [$actor->tenantId, $row['assignment_id']]);
        }
        return $row;
    }

    private function appeals(Actor $actor, int $case): array
    {
        return $this->store->all('SELECT id,dimension_code,evidence_submission_id,statement,opened_at,status,reason,previous_score,
            decided_at,decided_score,clears_evidence_gap FROM competency_appeals WHERE tenant_id=? AND case_id=? ORDER BY id',
            [$actor->tenantId, $case]);
    }

    private function evaluation(Actor $actor, array $subject, array $appeals): array
    {
        $rubric = (new CompetencyRubrics($this->store, $this->audit))->read($actor->tenantId, $subject['rubric_id']);
        return self::assess($rubric, $subject, $appeals);
    }

    public static function assess(array $rubric, array $subject, array $appeals): array
    {
        $scores = json_decode($subject['final_scores'], true, flags: JSON_THROW_ON_ERROR);
        $missing = [];
        foreach (json_decode($subject['missing_evidence'], true, flags: JSON_THROW_ON_ERROR) as $item) {
            if ($item['assignment_id'] === $subject['assignment_id']) {
                $missing[$item['code']] = $item['name'];
            }
        }
        foreach ($appeals as $appeal) {
            if ($appeal['status'] === 'amended') {
                $scores[$appeal['dimension_code']] = (float)$appeal['decided_score'];
                if ($appeal['clears_evidence_gap']) {
                    unset($missing[$appeal['dimension_code']]);
                }
            }
        }
        $total = 0;
        $meets = !$missing;
        foreach ($rubric['dimensions'] as $dimension) {
            $score = $scores[$dimension['code']];
            $total += $score * $dimension['weight_pct'] / 100;
            if ($dimension['mandatory'] && $score < $dimension['minimum_score']) {
                $meets = false;
            }
        }
        $total = round($total, 1);
        return ['scores' => $scores, 'weighted_score' => number_format($total, 1, '.', ''),
            'meets_standard' => $meets && $total >= $rubric['passing_score'], 'missing_dimensions' => $missing];
    }

    private function current(Actor $actor, array $subject): ?array
    {
        return $this->store->one('SELECT k.id,k.serial,k.case_id,k.revision,k.revoked_at,k.revoked_appeal_id,k.replaces_id,
            k.issued_at,k.total,s.attempt FROM competency_credentials k JOIN practicum_review_cases c ON c.id=k.case_id
            JOIN practicum_submissions s ON s.id=c.submission_id WHERE k.tenant_id=? AND k.assignment_id=?
            ORDER BY k.revision DESC LIMIT 1', [$actor->tenantId, $subject['assignment_id']]);
    }

    private function pending(Actor $actor, array $subject): ?array
    {
        return $this->store->one('SELECT id,case_id FROM competency_appeals WHERE tenant_id=? AND active_assignment=?',
            [$actor->tenantId, $subject['assignment_id']]);
    }

    public function detail(Actor $actor, int $case): array
    {
        $subject = $this->subject($actor, $case);
        $appeals = $this->appeals($actor, $case);
        $disclosure = $this->store->one('SELECT show_title,show_score FROM competency_disclosures WHERE assignment_id=?', [$subject['assignment_id']]);
        $evidence = $this->store->all('SELECT id submission_id,attempt FROM practicum_submissions WHERE tenant_id=? AND practicum_id=? AND team_id=?
            AND attempt>=? ORDER BY attempt', [$actor->tenantId, $subject['practicum_id'], $subject['team_id'], $subject['attempt']]);
        return $this->evaluation($actor, $subject, $appeals) + ['case_id' => $case, 'holder' => $subject['learner_id'] === $actor->accountId,
            'credential' => $this->current($actor, $subject), 'appeals' => $appeals, 'pending' => $this->pending($actor, $subject),
            'evidence_versions' => $evidence, 'disclosure' => ['show_title' => (bool)($disclosure['show_title'] ?? false),
            'show_score' => (bool)($disclosure['show_score'] ?? false)]];
    }

    private function mint(Actor $actor, array $subject, array $evaluation, ?array $prior, ?int $appeal): array
    {
        $serial = 'PC-' . strtoupper(bin2hex(random_bytes(16)));
        $revision = ($prior['revision'] ?? 0) + 1;
        $id = $this->store->insert('INSERT INTO competency_credentials (tenant_id,assignment_id,case_id,revision,serial,total,
            issued_by,replaces_id,appeal_id) VALUES (?,?,?,?,?,?,?,?,?)', [$actor->tenantId, $subject['assignment_id'], $subject['id'],
            $revision, $serial, $evaluation['weighted_score'], $actor->accountId, $prior['id'] ?? null, $appeal]);
        $this->audit->record($actor, 'competency_credential', $id, 'issued', ['case_id' => $subject['id'], 'revision' => $revision,
            'replaced_credential' => $prior['id'] ?? null, 'appeal_id' => $appeal]);
        $this->store->execute('UPDATE competency_training_tasks t JOIN practicums p ON p.tenant_id=t.tenant_id AND p.cohort_id=t.cohort_id
            JOIN practicum_submissions s ON s.tenant_id=t.tenant_id AND s.practicum_id=p.id
            JOIN competency_plan_members m ON m.tenant_id=t.tenant_id AND m.company_id=t.company_id AND m.plan_id=t.plan_id AND m.learner_id=t.learner_id
            LEFT JOIN competency_employment e ON e.tenant_id=t.tenant_id AND e.company_id=t.company_id AND e.learner_id=t.learner_id
            SET t.completed_at=UTC_TIMESTAMP(6),t.credential_id=? WHERE t.tenant_id=? AND t.learner_id=? AND p.id=? AND t.completed_at IS NULL
            AND COALESCE(e.employed,1)=1 AND s.id=COALESCE((SELECT evidence_submission_id FROM competency_appeals WHERE id=?),?)
            AND s.submitted_at>=t.assigned_at', [$id, $actor->tenantId, $subject['learner_id'], $subject['practicum_id'], $appeal, $subject['submission_id']]);
        return ['credential_id' => $id, 'serial' => $serial, 'revision' => $revision, 'replaced_credential' => $prior['id'] ?? null];
    }

    public function issue(Actor $actor, int $case): array
    {
        $actor->requireManagement();
        $s = $this->subject($actor, $case, true);
        if ($this->pending($actor, $s)) {
            throw new Problem(409, 'appeal_pending');
        }
        $outcome = $this->evaluation($actor, $s, $this->appeals($actor, $case));
        if (!$outcome['meets_standard']) {
            throw new Problem(422, 'competency_not_met', ['missing_dimensions' => $outcome['missing_dimensions']]);
        }
        $prior = $this->current($actor, $s);
        if ($prior && $prior['case_id'] === $case && !$prior['revoked_at']) {
            return ['credential_id' => $prior['id'], 'serial' => $prior['serial'], 'revision' => $prior['revision'], 'replaced_credential' => $prior['replaces_id']];
        }
        if ($prior && $prior['attempt'] >= $s['attempt']) {
            throw new Problem(409, 'new_assessment_required');
        }
        return $this->mint($actor, $s, $outcome, $prior, null);
    }

    public function disclose(Actor $actor, int $case, array $input): array
    {
        $s = $this->subject($actor, $case, true);
        if ($s['learner_id'] !== $actor->accountId) {
            throw new Problem(403, 'holder_required');
        }
        $title = Input::boolean($input, 'show_title');
        $score = Input::boolean($input, 'show_score');
        $this->store->execute('INSERT INTO competency_disclosures (assignment_id,show_title,show_score) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE show_title=?,show_score=?', [$s['assignment_id'], $title, $score, $title, $score]);
        $this->audit->record($actor, 'practicum_review', $case, 'public_fields_changed', ['show_title' => $title, 'show_score' => $score]);
        return ['show_title' => $title, 'show_score' => $score];
    }

    public function revoke(Actor $actor, int $case, array $input): array
    {
        $actor->requireManagement();
        $s = $this->subject($actor, $case, true);
        $prior = $this->current($actor, $s);
        if (!$prior || $prior['case_id'] !== $case || $prior['id'] !== Input::integer($input, 'credential_id', 1) || $prior['revoked_at']) {
            throw new Problem(409, 'current_credential_required');
        }
        $this->invalidate($actor, $prior, Input::string($input, 'reason', 500), null);
        return ['credential_id' => $prior['id'], 'status' => 'revoked'];
    }

    private function invalidate(Actor $actor, array $credential, string $reason, ?int $appeal): void
    {
        $this->store->execute('UPDATE competency_credentials SET revoked_at=UTC_TIMESTAMP(6),revoked_by=?,revoked_appeal_id=?,revocation_reason=?
            WHERE tenant_id=? AND id=?', [$actor->accountId, $appeal, $reason, $actor->tenantId, $credential['id']]);
        $this->audit->record($actor, 'competency_credential', $credential['id'], 'revoked', ['reason' => $reason, 'appeal_id' => $appeal]);
    }

    public function openAppeal(Actor $actor, int $case, array $input): array
    {
        $s = $this->subject($actor, $case, true);
        if ($actor->accountId !== $s['learner_id']) {
            throw new Problem(403, 'holder_required');
        }
        if ($this->pending($actor, $s)) {
            throw new Problem(409, 'appeal_pending');
        }
        $prior = $this->current($actor, $s);
        if ($prior && $prior['attempt'] > $s['attempt']) {
            throw new Problem(409, 'assessment_superseded');
        }
        $dimension = Input::slug($input, 'dimension_code');
        $outcome = $this->evaluation($actor, $s, $this->appeals($actor, $case));
        if (!array_key_exists($dimension, $outcome['scores'])) {
            throw new Problem(422, 'unknown_dimension', ['field' => 'dimension_code']);
        }
        $evidence = Input::integer($input, 'evidence_submission_id', 1);
        $this->evidence($actor, $s, $evidence);
        $id = $this->store->insert('INSERT INTO competency_appeals (tenant_id,case_id,assignment_id,dimension_code,evidence_submission_id,
            statement,previous_score) VALUES (?,?,?,?,?,?,?)', [$actor->tenantId, $case, $s['assignment_id'], $dimension, $evidence,
            Input::string($input, 'statement', 1600), (string)$outcome['scores'][$dimension]]);
        $this->audit->record($actor, 'competency_appeal', $id, 'opened', ['case_id' => $case, 'dimension_code' => $dimension]);
        return ['appeal_id' => $id, 'status' => 'pending', 'dimension_code' => $dimension];
    }

    private function evidence(Actor $actor, array $s, int $id): array
    {
        return $this->store->one('SELECT evidence_manifest FROM practicum_submissions WHERE tenant_id=? AND id=? AND practicum_id=?
            AND team_id=? AND rubric_id=? AND attempt>=?', [$actor->tenantId, $id, $s['practicum_id'], $s['team_id'], $s['rubric_id'], $s['attempt']])
            ?? throw new Problem(422, 'invalid_evidence_submission', ['field' => 'evidence_submission_id']);
    }

    public function dossier(Actor $actor, int $case, int $id): array
    {
        $s = $this->subject($actor, $case);
        $a = $this->store->one('SELECT id,dimension_code,statement,evidence_submission_id,status,previous_score,decided_score,reason
            FROM competency_appeals WHERE tenant_id=? AND case_id=? AND id=?', [$actor->tenantId, $case, $id])
            ?? throw new Problem(404, 'appeal_not_found');
        $manifest = json_decode($this->evidence($actor, $s, $a['evidence_submission_id'])['evidence_manifest'], true, flags: JSON_THROW_ON_ERROR);
        $files = array_values(array_filter($manifest['artifacts'], fn($f) => $f['dimension_code'] === $a['dimension_code']
            && ($f['scope'] === 'team' || $f['assignment_id'] === $s['assignment_id'])));
        $ballots = [];
        foreach ($this->store->all('SELECT id,seat,scores,locked_at FROM practicum_review_seats
            WHERE tenant_id=? AND case_id=? AND locked_at IS NOT NULL ORDER BY id', [$actor->tenantId, $case]) as $vote) {
            $score = json_decode($vote['scores'], true, flags: JSON_THROW_ON_ERROR)[$a['dimension_code']] ?? null;
            if ($score !== null) {
                $ballots[] = ['review_id' => $vote['id'], 'seat' => $vote['seat'], 'locked_at' => $vote['locked_at']] + $score;
            }
        }
        return $a + ['votes' => $ballots, 'artifacts' => $files, 'practicum_id' => $s['practicum_id'], 'team_id' => $s['team_id']];
    }

    public function decide(Actor $actor, int $case, int $id, array $input): array
    {
        $actor->requireManagement();
        $s = $this->subject($actor, $case, true);
        if ($s['learner_id'] === $actor->accountId) {
            throw new Problem(403, 'independent_officer_required');
        }
        $dossier = $this->dossier($actor, $case, $id);
        if ($dossier['status'] !== 'pending') {
            throw new Problem(409, 'appeal_decided');
        }
        $decision = Input::choice($input, 'decision', ['upheld','amended']);
        $reason = Input::string($input, 'reason', 1600);
        $amended = $decision === 'amended';
        $score = $amended ? Input::integer($input, 'score', 0, 100) : $dossier['previous_score'];
        $before = $this->evaluation($actor, $s, $this->appeals($actor, $case));
        $gap = isset($before['missing_dimensions'][$dossier['dimension_code']]);
        if ($amended && $gap && !$dossier['artifacts']) {
            throw new Problem(422, 'supplemental_evidence_required', ['dimension_code' => $dossier['dimension_code']]);
        }
        $this->store->execute('UPDATE competency_appeals SET status=?,decided_by=?,decided_at=UTC_TIMESTAMP(6),decided_score=?,
            reason=?,clears_evidence_gap=? WHERE tenant_id=? AND id=?', [$decision, $actor->accountId, $score, $reason,
            $amended && $gap, $actor->tenantId, $id]);
        $outcome = $this->evaluation($actor, $s, $this->appeals($actor, $case));
        $prior = $this->current($actor, $s);
        $credential = [];
        if ($amended && $outcome['meets_standard'] && (!$prior || !$prior['revoked_at'] || $prior['revoked_appeal_id'] || $s['attempt'] > $prior['attempt'])) {
            $credential = $this->mint($actor, $s, $outcome, $prior, $id);
        } elseif ($amended && !$outcome['meets_standard'] && $prior && !$prior['revoked_at'] && $prior['case_id'] === $case) {
            $this->invalidate($actor, $prior, '资格复核未达标', $id);
        }
        $this->audit->record($actor, 'competency_appeal', $id, $decision, ['case_id' => $case, 'dimension_code' => $dossier['dimension_code']]);
        return $credential + ['appeal_id' => $id, 'status' => $decision, 'evaluation' => $outcome, 'replaced_credential' => null];
    }

    public static function verify(Store $store, string $serial): array
    {
        $row = $store->one('SELECT k.id,current_key.id current_id,current_key.revision,current_key.total,current_key.issued_at,
            COALESCE(k.revoked_at,current_key.revoked_at) invalid_at, d.show_title,d.show_score,r.title
            FROM competency_credentials k JOIN competency_credentials current_key ON current_key.id=(
               SELECT n.id FROM competency_credentials n WHERE n.tenant_id=k.tenant_id AND n.assignment_id=k.assignment_id ORDER BY n.revision DESC LIMIT 1)
            LEFT JOIN competency_disclosures d ON d.assignment_id=k.assignment_id JOIN practicum_review_cases c ON c.id=current_key.case_id
            JOIN practicum_submissions s ON s.id=c.submission_id JOIN rubric_revisions r ON r.id=s.rubric_id WHERE k.serial=?', [$serial])
            ?? throw new Problem(404, 'credential_not_found');
        if ($row['invalid_at']) {
            return ['status' => 'revoked', 'date' => substr($row['invalid_at'], 0, 10)];
        }
        $card = ['status' => 'valid', 'date' => substr($row['issued_at'], 0, 10), 'revision' => $row['revision'],
            'replaced' => $row['id'] !== $row['current_id']];
        if ($row['show_title']) {
            $card['title'] = $row['title'];
        }
        if ($row['show_score']) {
            $card['score'] = $row['total'];
        }
        return $card;
    }
}
