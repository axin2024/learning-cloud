<?php
declare(strict_types=1);

namespace LearningCloud;

use DateTimeImmutable;
use DateTimeZone;

final readonly class Reports
{
    private const FLAGS = "e.enrolled_at>=b.from_at AND e.enrolled_at<b.until_at AS enrolled_in_period,
        e.state='completed' AND e.closed_at>=b.from_at AND e.closed_at<b.until_at AS completed_in_period,
        e.enrolled_at<b.until_at AND (e.state='completed' OR COALESCE(succ.enrolled_at,e.closed_at) IS NULL
             OR COALESCE(succ.enrolled_at,e.closed_at)>=b.until_at) AS effective,
        e.state='completed' AND e.closed_at<b.until_at AS completed_at_cutoff,
        e.state='withdrawn' AND e.enrolled_at IS NOT NULL AND e.closed_at>=b.from_at AND e.closed_at<b.until_at AS withdrawn_in_period,
        e.state='transferred' AND succ.enrolled_at>=b.from_at AND succ.enrolled_at<b.until_at AS transferred_in_period";
    private const METRICS = 'COUNT(CASE WHEN f.enrolled_in_period THEN 1 END) AS enrollments_in_period,
        COUNT(CASE WHEN f.completed_in_period THEN 1 END) AS completions_in_period,
        COUNT(CASE WHEN f.effective THEN 1 END) AS effective_enrollments,
        COUNT(DISTINCT CASE WHEN f.effective THEN f.learner_id END) AS effective_learners,
        COUNT(CASE WHEN f.completed_at_cutoff THEN 1 END) AS effective_completions,
        COUNT(CASE WHEN f.withdrawn_in_period THEN 1 END) AS withdrawals_in_period,
        COUNT(CASE WHEN f.transferred_in_period THEN 1 END) AS transfers_in_period';
    private const COMPANY_LINEAGE = "WITH RECURSIVE lineage AS (
        SELECT a.tenant_id,a.department_id,a.enrollment_id
        FROM seat_assignments a WHERE a.tenant_id=? AND a.company_id=? AND a.state='activated'
        UNION ALL
        SELECT l.tenant_id,l.department_id,e.id FROM lineage l
        JOIN enrollments e ON e.tenant_id=l.tenant_id AND e.transferred_from_id=l.enrollment_id
    ), bounds AS (
        SELECT CAST(? AS DATETIME) AS from_at, DATE_ADD(CAST(? AS DATE), INTERVAL 1 DAY) AS until_at
    ), facts AS (
        SELECT l.tenant_id,l.department_id,e.id,e.learner_id,e.cohort_id,e.state,e.enrolled_at,
            COALESCE(succ.enrolled_at,e.closed_at) AS closed_at,b.until_at,"
        . self::FLAGS . "
        FROM lineage l JOIN enrollments e ON e.tenant_id=l.tenant_id AND e.id=l.enrollment_id
        LEFT JOIN enrollments succ ON succ.tenant_id=e.tenant_id AND succ.transferred_from_id=e.id
        CROSS JOIN bounds b WHERE e.enrolled_at<b.until_at AND (? IS NULL OR e.cohort_id=?)
    )";

    public function __construct(private Store $store, private Companies $companies)
    {
    }

    public function courses(Actor $actor, array $input, int $after): array
    {
        $actor->requireManagement();
        [$from, $to] = $this->period($input);
        $course = $this->filterId($input, 'course_id');
        $rows = $this->store->all("WITH page AS (
            SELECT c.tenant_id,c.id,c.name,c.code,c.capacity,r.course_id,r.title,r.revision
            FROM cohorts c JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
            WHERE c.tenant_id=? AND c.id>? AND (? IS NULL OR r.course_id=?) ORDER BY c.id LIMIT 51
        ), bounds AS (SELECT CAST(? AS DATETIME) AS from_at, DATE_ADD(CAST(? AS DATE), INTERVAL 1 DAY) AS until_at), facts AS (
            SELECT e.cohort_id,e.learner_id," . self::FLAGS . '
            FROM page p JOIN enrollments e ON e.tenant_id=p.tenant_id AND e.cohort_id=p.id
            LEFT JOIN enrollments succ ON succ.tenant_id=e.tenant_id AND succ.transferred_from_id=e.id
            CROSS JOIN bounds b WHERE e.enrolled_at<b.until_at
        )
        SELECT p.id,p.name,p.code,p.capacity,p.course_id,p.title,p.revision,' . self::METRICS . '
        FROM page p LEFT JOIN facts f ON f.cohort_id=p.id GROUP BY p.id,p.name,p.code,p.capacity,p.course_id,p.title,p.revision
        ORDER BY p.id', [$actor->tenantId, $after, $course, $course, $from, $to]);
        return ['from' => $from, 'to' => $to, 'course_id' => $course] + $this->page($this->rates($rows));
    }

    public function departments(Actor $actor, int $company, array $input, int $after): array
    {
        $this->companies->scope($actor, $company);
        [$from, $to] = $this->period($input);
        $cohort = $this->filterId($input, 'cohort_id');
        $rows = $this->store->all(self::COMPANY_LINEAGE . ', departments AS (
            SELECT d.id,d.name,d.code,' . self::METRICS . '
            FROM company_departments d LEFT JOIN facts f ON f.tenant_id=d.tenant_id AND f.department_id=d.id
            WHERE d.tenant_id=? AND d.company_id=? GROUP BY d.id,d.name,d.code
        ), totals AS (
            SELECT COALESCE(SUM(effective_enrollments),0) AS company_effective_enrollments,
                COALESCE(SUM(effective_learners),0) AS company_effective_learners FROM departments
        ), page AS (
            SELECT * FROM departments WHERE id>? ORDER BY id LIMIT 51
        ) SELECT page.*,totals.* FROM totals LEFT JOIN page ON TRUE ORDER BY page.id',
            [$actor->tenantId, $company, $from, $to, $cohort, $cohort, $actor->tenantId, $company, $after]);
        $totals = ['effective_enrollments' => (int) ($rows[0]['company_effective_enrollments'] ?? 0),
            'effective_learners' => (int) ($rows[0]['company_effective_learners'] ?? 0)];
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] !== null));
        foreach ($rows as &$row) {
            unset($row['company_effective_enrollments'], $row['company_effective_learners']);
        }
        unset($row);
        return ['from' => $from, 'to' => $to, 'cohort_id' => $cohort, 'totals' => $totals] + $this->page($this->rates($rows));
    }

    public function companyEnrollments(Actor $actor, int $company, array $input, int $after): array
    {
        $this->companies->scope($actor, $company);
        [$from, $to] = $this->period($input);
        $cohort = $this->filterId($input, 'cohort_id');
        $department = $this->filterId($input, 'department_id');
        $view = Input::choice(['view' => $input['view'] ?? 'all'], 'view', ['all', 'effective', 'history']);
        $rows = $this->store->all(self::COMPANY_LINEAGE . "
        SELECT f.id,f.learner_id,person.name AS learner_name,d.name AS department_name,f.cohort_id,c.name AS cohort_name,
            CASE WHEN f.effective AND (f.closed_at IS NULL OR f.closed_at>=f.until_at) THEN 'enrolled' ELSE f.state END AS state,f.enrolled_at,
            CASE WHEN f.closed_at<f.until_at THEN f.closed_at END AS closed_at,f.effective,
            (SELECT a.score FROM exam_attempts a JOIN exam_sittings s ON s.tenant_id=a.tenant_id AND s.id=a.sitting_id
                WHERE a.tenant_id=f.tenant_id AND a.enrollment_id=f.id AND a.graded_at<f.until_at
                ORDER BY " . Exams::EFFECTIVE_ORDER . " LIMIT 1) AS exam_score
        FROM facts f JOIN accounts person ON person.tenant_id=f.tenant_id AND person.id=f.learner_id
        JOIN cohorts c ON c.tenant_id=f.tenant_id AND c.id=f.cohort_id
        JOIN company_departments d ON d.tenant_id=f.tenant_id AND d.id=f.department_id
        WHERE f.id>? AND (? IS NULL OR f.department_id=?)
            AND (CASE ? WHEN 'effective' THEN f.effective WHEN 'history' THEN NOT f.effective ELSE TRUE END)
        ORDER BY f.id LIMIT 51", [$actor->tenantId, $company, $from, $to, $cohort, $cohort, $after, $department, $department, $view]);
        foreach ($rows as &$row) {
            $row['effective'] = (bool) $row['effective'];
        }
        unset($row);
        return ['from' => $from, 'to' => $to, 'view' => $view, 'cohort_id' => $cohort, 'department_id' => $department] + $this->page($rows);
    }

    private function period(array $input): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $to = Input::date(['to' => $input['to'] ?? $today->format('Y-m-d')], 'to');
        $from = Input::date(['from' => $input['from'] ?? substr($to, 0, 7) . '-01'], 'from');
        if ($from > $to || $to > $today->format('Y-m-d')) {
            throw new Problem(422, 'invalid_report_period');
        }
        return [$from, $to];
    }

    private function filterId(array $input, string $field): ?int
    {
        if (!isset($input[$field])) return null;
        $value = filter_var($input[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) throw new Problem(422, 'invalid_input', ['field' => $field]);
        return $value;
    }

    private function rates(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['completion_percent'] = $row['effective_enrollments'] === 0 ? null
                : round(100 * $row['effective_completions'] / $row['effective_enrollments'], 2);
        }
        unset($row);
        return $rows;
    }

    private function page(array $rows): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }
}
