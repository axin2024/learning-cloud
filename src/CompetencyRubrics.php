<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class CompetencyRubrics
{
    private PracticumWrites $writes;

    public function __construct(private Store $store,private Audit $audit)
    {
        $this->writes=new PracticumWrites($store);
    }

    public function publish(Actor $actor,string $key,array $input): array
    {
        $actor->requireManagement();
        $code=Input::slug($input,'code');
        $title=Input::string($input,'title',160);
        $level=Input::string($input,'level',80);
        $previous=Input::integer($input,'previous_revision',0);
        $pass=Input::integer($input,'passing_score',0,100);
        $gap=Input::integer($input,'arbitration_gap',0,100);
        $dimensions=[];
        foreach (Input::list($input,'dimensions',1,64) as $d) {
            if (!is_array($d)) throw new Problem(422,'invalid_input',['field'=>'dimensions']);
            $slug=Input::slug($d,'code');
            $parent=isset($d['parent_code'])?Input::slug($d,'parent_code'):null;
            if (isset($dimensions[$slug]) || ($parent!==null && !isset($dimensions[$parent])))
                throw new Problem(422,'invalid_dimension_tree',['dimension'=>$slug]);
            $dimensions[$slug]=[$parent,Input::string($d,'name',160),Input::integer($d,'weight_pct',1,100),
                Input::integer($d,'minimum_score',0,100),Input::boolean($d,'mandatory'),Input::choice($d,'evidence_kind',['document','image','video']),
                Input::choice($d,'evidence_scope',['personal','team']),Input::string($d,'evidence_requirement',500)];
        }
        if (array_sum(array_column($dimensions,2))!==100) throw new Problem(422,'weights_must_total_100');
        return $this->writes->once($actor,'rubric.publish',$key,$input,function () use ($actor,$code,$title,$level,$previous,$pass,$gap,$dimensions) {
            $t=$actor->tenantId;
            $this->store->execute('INSERT INTO competency_standards (tenant_id,code) VALUES (?,?) ON DUPLICATE KEY UPDATE code=competency_standards.code',[$t,$code]);
            $standard=$this->store->one('SELECT id,latest_revision FROM competency_standards WHERE tenant_id=? AND code=? FOR UPDATE',[$t,$code]);
            if ($previous!==$standard['latest_revision']) throw new Problem(409,'rubric_revision_changed',['latest'=>$standard['latest_revision']]);
            $id=$this->store->insert('INSERT INTO rubric_revisions (tenant_id,standard_id,revision,title,level_name,passing_score,arbitration_gap,published_by)
                VALUES (?,?,?,?,?,?,?,?)',[$t,$standard['id'],$previous+1,$title,$level,$pass,$gap,$actor->accountId]);
            $ordinal=0;
            foreach ($dimensions as $slug=>$d) $this->store->execute('INSERT INTO rubric_dimensions
                (tenant_id,rubric_id,code,ordinal,parent_code,name,weight_pct,minimum_score,mandatory,evidence_kind,evidence_scope,evidence_requirement)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$t,$id,$slug,++$ordinal,...$d]);
            $this->store->execute('UPDATE competency_standards SET latest_revision=? WHERE tenant_id=? AND id=?',[$previous+1,$t,$standard['id']]);
            $this->audit->record($actor,'rubric',$id,'published',['standard_id'=>$standard['id'],'revision'=>$previous+1]);
            return $this->read($t,$id);
        });
    }

    public function read(int $tenant,int $id): array
    {
        $r=$this->store->one('SELECT r.id rubric_id,s.code,r.revision rubric_rev,r.title,r.level_name,r.passing_score,r.arbitration_gap FROM rubric_revisions r
            JOIN competency_standards s ON s.tenant_id=r.tenant_id AND s.id=r.standard_id WHERE r.tenant_id=? AND r.id=?',[$tenant,$id])
            ?? throw new Problem(404,'rubric_not_found');
        $r['dimensions']=$this->store->all('SELECT code,parent_code,name,weight_pct,minimum_score,mandatory,evidence_kind,evidence_scope,evidence_requirement
            FROM rubric_dimensions WHERE tenant_id=? AND rubric_id=? ORDER BY ordinal',[$tenant,$id]);
        $r['required_dimensions']=array_column(array_filter($r['dimensions'],fn($d)=>$d['mandatory']),'code');
        return $r;
    }

    public function listing(Actor $actor,int $after): array
    {
        $actor->requireManagement();
        $rows=$this->store->all('SELECT r.id,r.revision,r.title,r.level_name,s.code FROM rubric_revisions r
            JOIN competency_standards s ON s.tenant_id=r.tenant_id AND s.id=r.standard_id WHERE r.tenant_id=? AND r.id>? ORDER BY r.id LIMIT 51',[$actor->tenantId,$after]);
        return ['items'=>array_slice($rows,0,50),'next_after'=>count($rows)>50?$rows[49]['id']:null];
    }

    public function forCohort(Actor $actor,int $cohort): array
    {
        $scope=[$actor->tenantId,$cohort,$actor->accountId];
        if (!$actor->managesCatalog() && !$this->store->one('SELECT 1 FROM cohort_staff WHERE tenant_id=? AND cohort_id=? AND account_id=?',$scope) &&
            !$this->store->one("SELECT 1 FROM enrollments WHERE tenant_id=? AND cohort_id=? AND learner_id=? AND state IN ('enrolled','studying','completed')",$scope))
            throw new Problem(404,'cohort_rubric_not_found');
        $r=$this->store->one('SELECT rubric_id FROM cohort_rubrics WHERE tenant_id=? AND cohort_id=?',[$actor->tenantId,$cohort])
            ?? throw new Problem(404,'cohort_rubric_not_found');
        return ['cohort_id'=>$cohort]+$this->read($actor->tenantId,$r['rubric_id']);
    }

    public function bind(Actor $actor,int $cohort,string $key,array $input): array
    {
        $actor->requireManagement();
        $id=Input::integer($input,'rubric_id',1);
        return $this->writes->once($actor,'rubric.bind',$key,['cohort'=>$cohort,'rubric_id'=>$id],function () use ($actor,$cohort,$id) {
            $scope=[$actor->tenantId,$cohort];
            if (!$this->store->one('SELECT id FROM cohorts WHERE tenant_id=? AND id=? FOR UPDATE',$scope)) throw new Problem(404,'cohort_not_found');
            $prior=$this->store->one('SELECT rubric_id FROM cohort_rubrics WHERE tenant_id=? AND cohort_id=?',$scope);
            if ($prior && $prior['rubric_id']!==$id) throw new Problem(409,'cohort_rubric_frozen',['rubric_id'=>$prior['rubric_id']]);
            $result=$this->read($actor->tenantId,$id);
            if (!$prior) {
                $this->store->execute('INSERT INTO cohort_rubrics (tenant_id,cohort_id,rubric_id,bound_by) VALUES (?,?,?,?)',[...$scope,$id,$actor->accountId]);
                $this->audit->record($actor,'cohort',$cohort,'rubric_frozen',['rubric_id'=>$id]);
            }
            return ['cohort_id'=>$cohort]+$result;
        });
    }
}
