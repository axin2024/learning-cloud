<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class CompanyCompetencies
{
    public function __construct(private Store $store, private Companies $companies, private Audit $audit) {}

    public function plans(Actor $actor, int $company, int $after): array
    {
        $this->companies->scope($actor, $company);
        $rows = $this->store->all('SELECT p.id,p.department_id,d.name department,p.cohort_id,c.name cohort,r.title,r.revision,p.disclosure_minimum
            FROM competency_plans p JOIN company_departments d ON d.id=p.department_id JOIN cohorts c ON c.id=p.cohort_id
            JOIN cohort_rubrics b ON b.tenant_id=p.tenant_id AND b.cohort_id=p.cohort_id JOIN rubric_revisions r ON r.id=b.rubric_id
            WHERE p.tenant_id=? AND p.company_id=? AND p.id>? ORDER BY p.id LIMIT 51', [$actor->tenantId, $company, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows)>50 ? $rows[49]['id'] : null];
    }

    public function configure(Actor $actor, int $company, array $input): array
    {
        $this->companies->requireSpaceAdmin($actor);
        $this->companies->lock($actor, $company);
        $department = Input::integer($input, 'department_id', 1);
        $cohort = Input::integer($input, 'cohort_id', 1);
        $minimum = Input::integer($input, 'disclosure_minimum', 2, 1000);
        if (!$this->store->one('SELECT id FROM company_departments WHERE tenant_id=? AND company_id=? AND id=?', [$actor->tenantId,$company,$department])) {
            throw new Problem(404, 'department_not_found');
        }
        if (!$this->store->one('SELECT rubric_id FROM cohort_rubrics WHERE tenant_id=? AND cohort_id=?', [$actor->tenantId,$cohort])) {
            throw new Problem(422, 'bind_cohort_rubric_first', ['field' => 'cohort_id']);
        }
        $scope = [$actor->tenantId, $company, $department, $cohort];
        $this->store->execute('INSERT INTO competency_plans (tenant_id,company_id,department_id,cohort_id,disclosure_minimum)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE disclosure_minimum=?', [...$scope,$minimum,$minimum]);
        $id = $this->store->one('SELECT id FROM competency_plans WHERE tenant_id=? AND company_id=? AND department_id=? AND cohort_id=?', $scope)['id'];
        $this->audit->record($actor, 'competency_plan', $id, 'configured', ['disclosure_minimum'=>$minimum]);
        return ['plan_id'=>$id,'cohort_id'=>$cohort,'department_id'=>$department,'disclosure_minimum'=>$minimum];
    }

    private function plan(Actor $actor, int $company, int $id): array
    {
        $this->companies->scope($actor, $company);
        return $this->store->one('SELECT p.*,b.rubric_id FROM competency_plans p JOIN cohort_rubrics b ON b.tenant_id=p.tenant_id AND b.cohort_id=p.cohort_id
            WHERE p.tenant_id=? AND p.company_id=? AND p.id=?', [$actor->tenantId,$company,$id]) ?? throw new Problem(404,'plan_not_found');
    }

    public function roster(Actor $actor, int $company, int $id, array $input): array
    {
        $this->companies->requireSpaceAdmin($actor);
        $this->companies->lock($actor, $company);
        $plan = $this->plan($actor, $company, $id);
        $required = Input::boolean($input, 'required');
        $members = Input::list($input, 'learner_ids', 1, 500);
        foreach ($members as $value) {
            $learner = Input::integer(['learner_id'=>$value], 'learner_id', 1);
            $scope = [$actor->tenantId, $company, $id, $learner];
            if (!$this->store->one('SELECT learner_id FROM company_learners WHERE tenant_id=? AND company_id=? AND department_id=? AND learner_id=?',
                [$actor->tenantId, $company, $plan['department_id'], $learner])) {
                throw new Problem(422,'department_employee_required',['learner_id'=>$learner]);
            }
            if ($required) {
                $this->store->execute('INSERT INTO competency_plan_members (tenant_id,company_id,plan_id,learner_id) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE learner_id=competency_plan_members.learner_id', $scope);
            } else {
                $this->store->execute('DELETE FROM competency_plan_members WHERE tenant_id=? AND company_id=? AND plan_id=? AND learner_id=?', $scope);
            }
        }
        $this->audit->record($actor,'competency_plan',$id,'roster_updated',['learner_ids'=>$members,'required'=>$required]);
        return ['plan_id'=>$id,'learner_ids'=>array_values(array_unique($members)),'required'=>$required];
    }

    public function employment(Actor $actor, int $company, int $learner, array $input): array
    {
        $this->companies->requireSpaceAdmin($actor);
        $this->companies->lock($actor, $company);
        $scope = [$actor->tenantId,$company,$learner];
        if (!$this->store->one('SELECT learner_id FROM company_learners WHERE tenant_id=? AND company_id=? AND learner_id=?', $scope)) {
            throw new Problem(404,'company_learner_not_found');
        }
        $active = Input::boolean($input,'employed');
        $this->store->execute('INSERT INTO competency_employment (tenant_id,company_id,learner_id,employed) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE employed=?', [...$scope,$active,$active]);
        $this->audit->record($actor,'company',$company,'employment_updated',['learner_id'=>$learner,'employed'=>$active]);
        return ['learner_id'=>$learner,'employed'=>$active];
    }

    private function facts(Actor $actor, array $p, array $rubric): array
    {
        $rows = $this->store->all("WITH staff AS (
            SELECT m.learner_id,a.name FROM competency_plan_members m
            JOIN company_learners l ON l.tenant_id=m.tenant_id AND l.company_id=m.company_id AND l.learner_id=m.learner_id
            JOIN accounts a ON a.tenant_id=m.tenant_id AND a.id=m.learner_id
            LEFT JOIN competency_employment e ON e.tenant_id=m.tenant_id AND e.company_id=m.company_id AND e.learner_id=m.learner_id
            WHERE m.tenant_id=? AND m.company_id=? AND m.plan_id=? AND l.department_id=? AND COALESCE(e.employed,1)=1
        ), assessments AS (
            SELECT a.learner_id,a.id assignment_id,c.id case_id,c.final_scores,s.missing_evidence,
                ROW_NUMBER() OVER(PARTITION BY a.learner_id ORDER BY s.submitted_at DESC,c.id DESC) pos,
                MAX(EXISTS(SELECT 1 FROM competency_appeals f WHERE f.tenant_id=c.tenant_id AND f.active_assignment=a.id))
                    OVER(PARTITION BY a.learner_id) pending
            FROM staff m JOIN practicum_assignments a ON a.tenant_id=? AND a.learner_id=m.learner_id
            JOIN practicums t ON t.id=a.practicum_id JOIN practicum_review_cases c ON c.tenant_id=a.tenant_id AND c.assignment_id=a.id
            JOIN practicum_submissions s ON s.id=c.submission_id
            WHERE c.status='scored' AND s.rubric_id=? AND (t.cohort_id=? OR EXISTS (
                SELECT 1 FROM competency_training_tasks rt WHERE rt.tenant_id=a.tenant_id AND rt.plan_id=? AND rt.learner_id=a.learner_id AND rt.cohort_id=t.cohort_id))
        )
        SELECT m.learner_id,m.name,v.assignment_id,v.case_id,v.final_scores,v.missing_evidence,v.pending,
            k.id credential_id,k.revoked_at,k.case_id certificate_case,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT('id',f.id,'dimension_code',f.dimension_code,'status',f.status,'decided_score',f.decided_score,
                'clears_evidence_gap',f.clears_evidence_gap)) FROM competency_appeals f WHERE f.case_id=v.case_id AND f.status='amended') amendments,
            rt.initial_score training_baseline,rt.completed_at,rt.id training_id,tr.total training_final
        FROM staff m LEFT JOIN assessments v ON v.learner_id=m.learner_id AND v.pos=1
        LEFT JOIN competency_credentials k ON k.id=(SELECT ck.id FROM competency_credentials ck
            WHERE ck.tenant_id=? AND ck.assignment_id=v.assignment_id ORDER BY ck.revision DESC LIMIT 1)
        LEFT JOIN competency_training_tasks rt ON rt.id=(SELECT x.id FROM competency_training_tasks x
            WHERE x.tenant_id=? AND x.plan_id=? AND x.learner_id=m.learner_id ORDER BY x.id DESC LIMIT 1)
        LEFT JOIN competency_credentials tr ON tr.id=rt.credential_id ORDER BY m.learner_id",
            [$actor->tenantId,$p['company_id'],$p['id'],$p['department_id'],$actor->tenantId,$p['rubric_id'],$p['cohort_id'],$p['id'],
            $actor->tenantId,$actor->tenantId,$p['id']]);
        $required = array_column(array_filter($rubric['dimensions'], fn($d) => $d['mandatory']), 'name', 'code');
        $facts = [];
        foreach ($rows as $row) {
            $amendments = json_decode($row['amendments'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            usort($amendments, fn($left,$right) => $left['id'] <=> $right['id']);
            $evaluation = $row['case_id'] ? CompetencyCertification::assess($rubric,$row,$amendments) :
                ['meets_standard'=>false,'weighted_score'=>null,'missing_dimensions'=>$required];
            $qualified = $evaluation['meets_standard'] && !$row['pending'] && $row['credential_id'] !== null &&
                $row['certificate_case'] === $row['case_id'] && !$row['revoked_at'];
            $facts[] = ['learner_id'=>$row['learner_id'],'name'=>$row['name'],'case_id'=>$row['case_id'],'score'=>$evaluation['weighted_score'],
                'missing_dimensions'=>$evaluation['missing_dimensions'],'appeal_pending'=>(bool)$row['pending'],'qualified'=>$qualified,
                'training_id'=>$row['training_id'],'training_completed_at'=>$row['completed_at'],'training_baseline'=>$row['training_baseline'],
                'training_final'=>$row['training_final'],'score_change'=>$row['training_final'] !== null && $row['training_baseline'] !== null
                    ? round((float)$row['training_final'] - (float)$row['training_baseline'],1) : null];
        }
        return $facts;
    }

    public function report(Actor $actor, int $company, int $id): array
    {
        $p = $this->plan($actor,$company,$id);
        $rubric = (new CompetencyRubrics($this->store,$this->audit))->read($actor->tenantId,$p['rubric_id']);
        $members = $this->facts($actor,$p,$rubric);
        $count = count($members);
        $qualified = count(array_filter($members,fn($m) => $m['qualified']));
        $gaps = [];
        foreach ($members as $member) {
            foreach ($member['missing_dimensions'] as $code=>$name) {
                $gaps[$code] = ['name'=>$name,'missing_count'=>1 + ($gaps[$code]['missing_count'] ?? 0)];
            }
        }
        $hidden = $count < $p['disclosure_minimum'];
        return ['plan_id'=>$id,'cohort_id'=>$p['cohort_id'],'department_id'=>$p['department_id'],'job_title'=>$rubric['title'],
            'required_count'=>$count,'qualified_count'=>$qualified,'coverage_pct'=>$count ? round(100*$qualified/$count,1) : null,
            'disclosure_minimum'=>$p['disclosure_minimum'],'suppressed'=>$hidden,'gaps'=>(object)$gaps,
            'members'=>$hidden ? [] : $members,'training_completed'=>count(array_filter($members,fn($m) => $m['training_completed_at'] !== null))];
    }

    public function retrain(Actor $actor, int $company, int $id, array $input): array
    {
        $this->companies->lock($actor,$company);
        $p = $this->plan($actor,$company,$id);
        $target = Input::integer($input,'cohort_id',1);
        $instructions = Input::string($input,'instructions',1000);
        if (!$this->store->one('SELECT cohort_id FROM cohort_rubrics WHERE tenant_id=? AND cohort_id=? AND rubric_id=?', [$actor->tenantId,$target,$p['rubric_id']])) {
            throw new Problem(422,'matching_rubric_required',['field'=>'cohort_id']);
        }
        $rubric = (new CompetencyRubrics($this->store,$this->audit))->read($actor->tenantId,$p['rubric_id']);
        $created = 0;
        foreach ($this->facts($actor,$p,$rubric) as $m) {
            if ($m['qualified'] || $m['appeal_pending'] || ($m['training_id'] && !$m['training_completed_at'])) {
                continue;
            }
            $this->store->execute('INSERT INTO competency_training_tasks (tenant_id,company_id,plan_id,learner_id,cohort_id,initial_score,
                initial_case_id,initial_missing,instructions) VALUES (?,?,?,?,?,?,?,?,?)', [$actor->tenantId,$company,$id,$m['learner_id'],$target,
                $m['score'],$m['case_id'],json_encode($m['missing_dimensions'],JSON_THROW_ON_ERROR),$instructions]);
            $created++;
        }
        $this->audit->record($actor,'competency_plan',$id,'training_assigned',['cohort_id'=>$target,'created'=>$created]);
        return ['plan_id'=>$id,'cohort_id'=>$target,'created'=>$created];
    }

    public function myTraining(Actor $actor, int $after): array
    {
        $rows = $this->store->all('SELECT t.id,t.cohort_id,c.name cohort,t.instructions,t.assigned_at,t.completed_at,t.initial_score,
            t.initial_missing,k.total final_score FROM competency_training_tasks t JOIN cohorts c ON c.id=t.cohort_id
            JOIN competency_plan_members m ON m.tenant_id=t.tenant_id AND m.company_id=t.company_id AND m.plan_id=t.plan_id AND m.learner_id=t.learner_id
            LEFT JOIN competency_employment e ON e.tenant_id=t.tenant_id AND e.company_id=t.company_id AND e.learner_id=t.learner_id
            LEFT JOIN competency_credentials k ON k.id=t.credential_id WHERE t.tenant_id=? AND t.learner_id=? AND COALESCE(e.employed,1)=1
            AND t.id>? ORDER BY t.id LIMIT 51', [$actor->tenantId,$actor->accountId,$after]);
        foreach ($rows as &$r) {
            $r['initial_missing'] = json_decode($r['initial_missing'],true,flags:JSON_THROW_ON_ERROR);
        }
        return ['items'=>array_slice($rows,0,50),'next_after'=>count($rows)>50 ? $rows[49]['id'] : null];
    }
}
