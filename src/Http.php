<?php
declare(strict_types=1);

namespace LearningCloud;

use JsonException;
use Throwable;

final class Http
{
    private const MAX_BODY = 6 * 1024 * 1024;

    public function handle(string $method, string $path): never
    {
        if ($method === 'GET' && $path === '/healthz') {
            try {
                $ready = (new Schema(Store::connect()))->ready();
            } catch (Throwable) {
                $ready = false;
            }
            $this->send(['ready' => $ready], $ready ? 200 : 503);
        }

        $requestId = bin2hex(random_bytes(12));
        header('X-Request-Id: ' . $requestId);
        try {
            if ($method === 'GET' && str_starts_with($path, '/api/verify-credential/')) {
                header('X-Robots-Tag: noindex, nofollow');
                if (!preg_match('~\A/api/verify-credential/(LC-[0-9A-F]{32})\z~i', $path, $parts)) {
                    throw new Problem(404, 'credential_not_found');
                }
                $this->send(Credentials::verify(Store::connect(), strtoupper($parts[1])));
            }
            if ($method === 'POST' && $path === '/api/live-attendance') {
                $input = $this->body();
                $store = Store::connect();
                $audit = new Audit($store);
                $attendance = new LiveAttendance($store, new StudyProgress($store, $audit), $audit);
                $receipt = $attendance->submit($_SERVER['HTTP_AUTHORIZATION'] ?? '', $input);
                $this->send($receipt, $receipt['replayed'] ? 200 : 202);
            }
            $this->startSession();
            if ($method === 'GET' && $path === '/api/session') {
                $result = $this->sessionSummary();
                session_write_close();
                $this->send($result);
            }
            $input = [];
            if (in_array($method, ['POST', 'PUT'], true)) {
                $this->validateCsrf();
                $input = $this->body();
            } elseif ($method !== 'GET') {
                header('Allow: GET, POST, PUT');
                throw new Problem(405, 'method_not_allowed');
            }

            if ($method === 'POST' && $path === '/api/logout') {
                unset($_SESSION['principal']);
                session_regenerate_id(true);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $this->send(['actor' => null, 'csrf_token' => $_SESSION['csrf']]);
            }
            $store = Store::connect();
            $audit = new Audit($store);
            $accounts = new Accounts($store, $audit);
            if ($method === 'POST' && $path === '/api/login') {
                $actor = $accounts->authenticate($input);
                session_regenerate_id(true);
                $_SESSION['principal'] = [$actor->tenantId, $actor->accountId];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $this->send(['actor' => $actor->view(), 'csrf_token' => $_SESSION['csrf']]);
            }

            $principal = $_SESSION['principal'] ?? null;
            session_write_close();
            if ($principal === null) {
                throw new Problem(401, 'login_required');
            }
            $actor = $accounts->find(...$principal);
            $catalog = new Catalog($store, $audit);
            $admissions = new Admissions($store, $catalog, $accounts, $audit);
            if ($method === 'GET' && $path === '/api/reports/courses') {
                $reports = new Reports($store, new Companies($store, $accounts, $audit));
                [$status, $result] = [200, $reports->courses($actor, $_GET, $this->cursor())];
            } elseif ($method === 'GET' && preg_match('~\A/api/companies/([1-9][0-9]*)/reports/(departments|enrollments)\z~', $path, $report)) {
                $reports = new Reports($store, new Companies($store, $accounts, $audit));
                $company = $this->id($report[1]);
                $result = match ($report[2]) {
                    'departments' => $reports->departments($actor, $company, $_GET, $this->cursor()),
                    'enrollments' => $reports->companyEnrollments($actor, $company, $_GET, $this->cursor()),
                };
                $status = 200;
            } elseif ($path === '/api/learning-paths' || str_starts_with($path, '/api/learning-paths/')) {
                [$status, $result] = $this->learningPaths($actor, new LearningPaths($store, $audit), $method, $path, $input);
            } elseif (preg_match('~\A/api/course-equivalences/([1-9][0-9]*)/([1-9][0-9]*)\z~', $path, $pair)) {
                $equivalences = new CourseEquivalences($store, $audit);
                $source = $this->id($pair[1]);
                $target = $this->id($pair[2]);
                [$status, $result] = match ($method) {
                    'GET' => [200, $equivalences->detail($actor, $source, $target)],
                    'PUT' => [200, $equivalences->save($actor, $source, $target, $input)],
                    default => $this->notFound(),
                };
            } elseif (preg_match('~\A/api/(?:companies(?:/|\z)|me/seat-assignments\z|seat-assignments/)~', $path)) {
                $companies = new Companies($store, $accounts, $audit);
                $seats = new SeatPurchases($store, $companies, $admissions, $audit);
                [$status, $result] = $this->enterprise($actor, $companies, $seats, $method, $path, $input);
            } elseif (str_starts_with($path, '/api/study/')) {
                $study = new StudyProgress($store, $audit);
                $attendance = new LiveAttendance($store, $study, $audit);
                [$status, $result] = $this->study($actor, $study, $attendance, $method, $path, $input);
            } elseif (str_starts_with($path, '/api/coursework/')) {
                $staff = new TeachingStaff($store, $accounts, $audit);
                $assignments = new AssignmentCatalog($store, $staff, $audit);
                $coursework = new Coursework($store, $assignments, $staff, $audit);
                [$status, $result] = $this->coursework($actor, $staff, $assignments, $coursework, $method, $path, $input);
            } elseif (str_starts_with($path, '/api/exams/')) {
                $staff = new TeachingStaff($store, $accounts, $audit);
                $papers = new ExamCatalog($store, $staff, $audit);
                [$status, $result] = $this->exams($actor, $papers, new Exams($store, $papers, $staff, $audit), $method, $path, $input);
            } elseif (str_starts_with($path, '/api/credentials/')) {
                $staff = new TeachingStaff($store, $accounts, $audit);
                $exams = new Exams($store, new ExamCatalog($store, $staff, $audit), $staff, $audit);
                $credentials = new Credentials($store, new StudyProgress($store, $audit), $exams, $audit);
                [$status, $result] = $this->credentials($actor, $credentials, new Retraining($store, $audit), $method, $path, $input);
            } else {
                [$status, $result] = $this->dispatch($actor, $accounts, $catalog, $admissions, $method, $path, $input);
            }
            $this->send($result, $status);
        } catch (Problem $error) {
            $this->send(['error' => $error->reason, 'details' => (object) $error->details, 'request_id' => $requestId], $error->status);
        } catch (Throwable $error) {
            error_log('Learning Cloud request ' . $requestId . ' failed: ' . $error::class . ' [' . $error->getCode() . ']');
            $this->send(['error' => 'internal_error', 'request_id' => $requestId], 500);
        }
    }

    private function learningPaths(Actor $actor, LearningPaths $paths, string $method, string $path, array $input): array
    {
        if ($path === '/api/learning-paths') {
            return match ($method) {
                'GET' => [200, $paths->listing($actor, $this->cursor())],
                'POST' => [201, $paths->create($actor, $input)],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/learning-paths/([1-9][0-9]*)(/publish)?\z~', $path, $parts)) {
            $id = $this->id($parts[1]);
            return match ($method . ($parts[2] ?? '')) {
                'GET' => [200, $paths->detail($actor, $id)],
                'PUT' => [200, $paths->edit($actor, $id, $input)],
                'POST/publish' => [200, $paths->publish($actor, $id, $input)],
                default => $this->notFound(),
            };
        }
        if ($method === 'GET' && preg_match('~\A/api/learning-paths/([1-9][0-9]*)/steps/([1-9][0-9]*)/cohorts\z~', $path, $parts)) {
            return [200, $paths->stepCohorts($actor, $this->id($parts[1]), $this->id($parts[2]), $this->cursor())];
        }
        $this->notFound();
    }

    private function dispatch(Actor $actor, Accounts $accounts, Catalog $catalog, Admissions $admissions, string $method, string $path, array $input): array
    {
        if ($path === '/api/accounts') {
            return match ($method) {
                'GET' => [200, $accounts->listing($actor, $this->cursor())],
                'POST' => [201, $accounts->create($actor, $input)->view()],
                default => $this->notFound(),
            };
        }
        if ($path === '/api/courses') {
            return match ($method) {
                'GET' => [200, $catalog->listing($actor, $this->cursor())],
                'POST' => [201, $catalog->create($actor, $input)],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/courses/([1-9][0-9]*)(?:/(revisions)(?:/([1-9][0-9]*)(/publish)?)?)?\z~', $path, $parts)) {
            $course = $this->id($parts[1]);
            if (!isset($parts[2]) && $method === 'GET') {
                return [200, $catalog->detail($actor, $course)];
            }
            if (!isset($parts[3]) && ($parts[2] ?? '') === 'revisions' && $method === 'POST') {
                return [201, $catalog->draft($actor, $course, $input)];
            }
            if (isset($parts[3])) {
                $revision = $this->id($parts[3]);
                if (isset($parts[4])) {
                    return $method === 'POST' ? [200, $catalog->publish($actor, $course, $revision, $input)] : $this->notFound();
                }
                return match ($method) {
                    'GET' => [200, $catalog->revision($actor, $course, $revision)],
                    'PUT' => [200, $catalog->edit($actor, $course, $revision, $input)],
                    default => $this->notFound(),
                };
            }
        }
        if ($path === '/api/cohorts') {
            return match ($method) {
                'GET' => [200, $admissions->cohorts($actor, $this->cursor())],
                'POST' => [201, $admissions->createCohort($actor, $input)],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/cohorts/([1-9][0-9]*)(?:/(enrollments))?\z~', $path, $parts)) {
            $cohort = $this->id($parts[1]);
            if (isset($parts[2])) {
                if ($method === 'POST') {
                    $item = $admissions->enroll($actor, $cohort, $input, Input::requestKey($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null));
                    return [$item['created'] ? 201 : 200, $item];
                }
                return $method === 'GET' ? [200, $admissions->roster($actor, $cohort, $this->cursor())] : $this->notFound();
            }
            return match ($method) {
                'GET' => [200, $admissions->cohort($actor, $cohort)],
                'PUT' => [200, $admissions->reviseCohort($actor, $cohort, $input)],
                default => $this->notFound(),
            };
        }
        if ($path === '/api/me/enrollments' && $method === 'GET') {
            return [200, $admissions->mine($actor, $this->cursor())];
        }
        if (preg_match('~\A/api/enrollments/([1-9][0-9]*)(?:/(withdraw|transfer|transfer-options))?\z~', $path, $parts)) {
            $id = $this->id($parts[1]);
            return match ($method . '/' . ($parts[2] ?? '')) {
                'GET/' => [200, $admissions->detail($actor, $id)],
                'POST/withdraw' => [200, $admissions->withdraw($actor, $id)],
                'POST/transfer' => [200, $admissions->transfer($actor, $id, $input)],
                'GET/transfer' => [200, $admissions->transferHistory($actor, $id)],
                'GET/transfer-options' => [200, $admissions->transferOptions($actor, $id, $this->cursor())],
                default => $this->notFound(),
            };
        }
        $this->notFound();
    }

    private function sessionSummary(): array
    {
        $identity = $_SESSION['principal'] ?? null;
        $actor = null;
        if ($identity !== null) {
            $store = Store::connect();
            try {
                $actor = (new Accounts($store, new Audit($store)))->find(...$identity)->view();
            } catch (Problem $error) {
                if ($error->status !== 404) {
                    throw $error;
                }
                unset($_SESSION['principal']);
            }
        }
        return ['actor' => $actor, 'csrf_token' => $_SESSION['csrf']];
    }

    private function enterprise(Actor $actor, Companies $companies, SeatPurchases $seats, string $method, string $path, array $input): array
    {
        if ($path === '/api/companies') {
            return match ($method) {
                'GET' => [200, $companies->listing($actor, $this->cursor())],
                'POST' => [201, $companies->create($actor, $input)],
                default => $this->notFound(),
            };
        }
        if ($path === '/api/me/seat-assignments' && $method === 'GET') {
            return [200, $seats->mine($actor, $this->cursor())];
        }
        if ($method === 'POST' && preg_match('~\A/api/seat-assignments/([1-9][0-9]*)/activate\z~', $path, $parts)) {
            return [200, $seats->activate($actor, $this->id($parts[1]))];
        }
        if (preg_match('~\A/api/companies/([1-9][0-9]*)(?:/(departments|learners|administrators|purchases))?\z~', $path, $parts)) {
            $company = $this->id($parts[1]);
            return match ($method . '/' . ($parts[2] ?? '')) {
                'GET/' => [200, $companies->scope($actor, $company)],
                'GET/departments' => [200, $companies->departments($actor, $company, $this->cursor())],
                'POST/departments' => [201, $companies->createDepartment($actor, $company, $input)],
                'GET/learners' => [200, $companies->learners($actor, $company, $this->cursor())],
                'POST/learners' => [200, $companies->addLearners($actor, $company, $input)],
                'GET/administrators' => [200, $companies->administrators($actor, $company, $this->cursor())],
                'GET/purchases' => [200, $seats->listing($actor, $company, $this->cursor())],
                'POST/purchases' => [201, $seats->purchase($actor, $company, $input)],
                default => $this->notFound(),
            };
        }
        if ($method === 'PUT' && preg_match('~\A/api/companies/([1-9][0-9]*)/administrators/([1-9][0-9]*)\z~', $path, $parts)) {
            return [200, $companies->setAdministrator($actor, $this->id($parts[1]), $this->id($parts[2]), $input)];
        }
        if (preg_match('~\A/api/companies/([1-9][0-9]*)/purchases/([1-9][0-9]*)(?:/(batches|assignments)(?:/([1-9][0-9]*)(/reclaim)?)?)?\z~', $path, $parts)) {
            $company = $this->id($parts[1]);
            $pool = $this->id($parts[2]);
            $resource = $parts[3] ?? '';
            if (isset($parts[4])) {
                $id = $this->id($parts[4]);
                return match ([$method, $resource, isset($parts[5])]) {
                    ['GET', 'batches', false] => [200, $seats->batch($actor, $company, $pool, $id)],
                    ['POST', 'assignments', true] => [200, $seats->reclaim($actor, $company, $pool, $id)],
                    default => $this->notFound(),
                };
            }
            if ($method === 'POST' && $resource === 'batches') {
                $receipt = $seats->assign($actor, $company, $pool, $input, Input::requestKey($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null));
                return [$receipt['replayed'] ? 200 : 201, $receipt];
            }
            return match ([$method, $resource]) {
                ['GET', ''] => [200, $seats->pool($actor, $company, $pool)],
                ['GET', 'assignments'] => [200, $seats->assignments($actor, $company, $pool, $this->cursor())],
                ['GET', 'batches'] => [200, $seats->batches($actor, $company, $pool, $this->cursor())],
                default => $this->notFound(),
            };
        }
        $this->notFound();
    }

    private function startSession(): void
    {
        session_name('learning_cloud');
        session_start([
            'use_strict_mode' => 1, 'use_only_cookies' => 1, 'cookie_secure' => getenv('LEARNING_COOKIE_SECURE') !== '0',
            'cookie_path' => '/', 'cookie_httponly' => 1, 'cookie_samesite' => 'Lax', 'cache_limiter' => '',
        ]);
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function study(Actor $actor, StudyProgress $study, LiveAttendance $attendance, string $method, string $path, array $input): array
    {
        if ($path === '/api/study/providers') {
            return match ($method) {
                'GET' => [200, $attendance->providers($actor, $this->cursor())],
                'POST' => [201, $attendance->register($actor, $input)],
                default => $this->notFound(),
            };
        }
        if ($path === '/api/study/attendance' && $method === 'GET') {
            return [200, $attendance->reviews($actor, $this->cursor(), Input::choice(['state' => $_GET['state'] ?? 'review'], 'state', ['review', 'accepted', 'rejected']))];
        }
        if (preg_match('~\A/api/study/enrollments/([1-9][0-9]*)(?:/chapters/([1-9][0-9]*)/start)?\z~', $path, $parts)) {
            $enrollment = $this->id($parts[1]);
            return match ([$method, isset($parts[2])]) {
                ['GET', false] => [200, $study->progress($actor, $enrollment)],
                ['POST', true] => [200, $study->start($actor, $enrollment, $this->id($parts[2]))],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/study/cohorts/([1-9][0-9]*)/live(?:/([1-9][0-9]*))?\z~', $path, $parts)) {
            $cohort = $this->id($parts[1]);
            return match ([$method, isset($parts[2])]) {
                ['GET', false] => [200, $attendance->bindings($actor, $cohort)],
                ['PUT', true] => [200, $attendance->bind($actor, $cohort, $this->id($parts[2]), $input)],
                default => $this->notFound(),
            };
        }
        if ($method === 'POST' && preg_match('~\A/api/study/(providers|sessions|attendance)/([1-9][0-9]*)/(rotate|intervals|review)\z~', $path, $parts)) {
            $id = $this->id($parts[2]);
            if ($parts[1] === 'sessions' && $parts[3] === 'intervals') {
                $event = $study->browserInterval($actor, $id, $input, Input::requestKey($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null));
                return [$event['replayed'] ? 200 : 201, $event];
            }
            return match ([$parts[1], $parts[3]]) {
                ['providers', 'rotate'] => [200, $attendance->rotate($actor, $id)],
                ['attendance', 'review'] => [200, $attendance->review($actor, $id, $input)],
                default => $this->notFound(),
            };
        }
        $this->notFound();
    }

    private function coursework(
        Actor $actor, TeachingStaff $staff, AssignmentCatalog $assignments, Coursework $coursework,
        string $method, string $path, array $input
    ): array {
        if ($method === 'GET' && $path === '/api/coursework/teaching-cohorts') {
            return [200, $staff->cohorts($actor, $this->cursor())];
        }
        if (preg_match('~\A/api/coursework/cohorts/([1-9][0-9]*)/(staff|assignments)(?:/([1-9][0-9]*))?\z~', $path, $parts)) {
            $cohort = $this->id($parts[1]);
            return match ([$method, $parts[2], isset($parts[3])]) {
                ['GET', 'staff', false] => [200, $staff->listing($actor, $cohort)],
                ['PUT', 'staff', true] => [200, $staff->set($actor, $cohort, $this->id($parts[3]), $input)],
                ['GET', 'assignments', false] => [200, $assignments->listing($actor, $cohort, $this->cursor())],
                ['POST', 'assignments', false] => [201, $assignments->draft($actor, $cohort, $input)],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/coursework/assignments/([1-9][0-9]*)(?:/(publish|submissions)(?:/([1-9][0-9]*))?)?\z~', $path, $parts)) {
            $assignment = $this->id($parts[1]);
            return match ([$method, $parts[2] ?? '', isset($parts[3])]) {
                ['GET', '', false] => [200, $assignments->detail($actor, $assignment)],
                ['PUT', '', false] => [200, $assignments->edit($actor, $assignment, $input)],
                ['POST', 'publish', false] => [200, $assignments->publish($actor, $assignment, $input)],
                ['GET', 'submissions', false] => [200, $coursework->queue($actor, $assignment, $this->cursor())],
                ['GET', 'submissions', true] => [200, $coursework->markingHistory($actor, $assignment, $this->id($parts[3]), $this->cursor())],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/coursework/enrollments/([1-9][0-9]*)/assignments(?:/([1-9][0-9]*))?\z~', $path, $parts)) {
            $enrollment = $this->id($parts[1]);
            if ($method === 'GET') {
                return [200, isset($parts[2])
                    ? $coursework->learnerHistory($actor, $enrollment, $this->id($parts[2]), $this->cursor())
                    : $coursework->mine($actor, $enrollment, $this->cursor())];
            }
            if ($method === 'POST' && isset($parts[2])) {
                $result = $coursework->submit($actor, $enrollment, $this->id($parts[2]), $input,
                    Input::requestKey($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null));
                return [$result['replayed'] ? 200 : 201, $result];
            }
        }
        if ($method === 'POST' && preg_match('~\A/api/coursework/submissions/([1-9][0-9]*)/(grade|return)\z~', $path, $parts)) {
            $submission = $this->id($parts[1]);
            $result = $parts[2] === 'grade' ? $coursework->grade($actor, $submission, $input) : $coursework->allowReturn($actor, $submission, $input);
            return [$result['replayed'] ? 200 : 201, $result];
        }
        $this->notFound();
    }

    private function exams(Actor $actor, ExamCatalog $catalog, Exams $exams, string $method, string $path, array $input): array
    {
        if (preg_match('~\A/api/exams/courses/([1-9][0-9]*)/papers\z~', $path, $parts)) {
            $course = $this->id($parts[1]);
            return match ($method) {
                'GET' => [200, $catalog->papers($actor, $course, $this->cursor())],
                'POST' => [201, $catalog->draft($actor, $course, $input)],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/exams/papers/([1-9][0-9]*)(/publish)?\z~', $path, $parts)) {
            $paper = $this->id($parts[1]);
            return match ([$method, isset($parts[2])]) {
                ['GET', false] => [200, $catalog->paper($actor, $paper)],
                ['PUT', false] => [200, $catalog->edit($actor, $paper, $input)],
                ['POST', true] => [200, $catalog->publish($actor, $paper, $input)],
                default => $this->notFound(),
            };
        }
        if (preg_match('~\A/api/exams/cohorts/([1-9][0-9]*)/sittings\z~', $path, $parts)) {
            $cohort = $this->id($parts[1]);
            return match ($method) {
                'GET' => [200, $catalog->sittings($actor, $cohort)],
                'POST' => [201, $catalog->schedule($actor, $cohort, $input)],
                default => $this->notFound(),
            };
        }
        if ($method === 'GET' && preg_match('~\A/api/exams/sittings/([1-9][0-9]*)/attempts\z~', $path, $parts)) {
            return [200, $exams->roster($actor, $this->id($parts[1]), $this->cursor())];
        }
        if (preg_match('~\A/api/exams/enrollments/([1-9][0-9]*)(?:/sittings/([1-9][0-9]*)/start)?\z~', $path, $parts)) {
            $enrollment = $this->id($parts[1]);
            if ($method === 'GET' && !isset($parts[2])) {
                return [200, $exams->enrollment($actor, $enrollment)];
            }
            if ($method === 'POST' && isset($parts[2])) {
                $result = $exams->start($actor, $enrollment, $this->id($parts[2]));
                return [$result['replayed'] ? 200 : 201, $result];
            }
        }
        if (preg_match('~\A/api/exams/attempts/([1-9][0-9]*)(?:/(answers|submit|marking|grade))?\z~', $path, $parts)) {
            $id = $this->id($parts[1]);
            $action = $parts[2] ?? '';
            return match ([$method, $action]) {
                ['GET', ''] => [200, $exams->detail($actor, $id, false)],
                ['GET', 'marking'] => [200, $exams->detail($actor, $id, true)],
                ['PUT', 'answers'] => [200, $exams->save($actor, $id, $input)],
                ['POST', 'submit'] => [200, $exams->submit($actor, $id, $input)],
                ['POST', 'grade'] => [200, $exams->grade($actor, $id, $input)],
                default => $this->notFound(),
            };
        }
        $this->notFound();
    }

    private function credentials(Actor $actor, Credentials $credentials, Retraining $retraining, string $method, string $path, array $input): array
    {
        if (preg_match('~\A/api/credentials/enrollments/([1-9][0-9]*)(/issue)?\z~', $path, $parts)) {
            $enrollment = $this->id($parts[1]);
            if ($method === 'GET' && !isset($parts[2])) {
                return [200, $credentials->review($actor, $enrollment)];
            }
            if ($method === 'POST' && isset($parts[2])) {
                $result = $credentials->issue($actor, $enrollment, $input);
                return [$result['replayed'] ? 200 : 201, $result];
            }
        }
        if ($method === 'POST' && preg_match('~\A/api/credentials/([1-9][0-9]*)/revoke\z~', $path, $parts)) {
            return [200, $credentials->revoke($actor, $this->id($parts[1]), $input)];
        }
        if ($method === 'GET' && $path === '/api/credentials/retraining') {
            return [200, $retraining->listing($actor, $this->cursor(), Input::choice(['state' => $_GET['state'] ?? 'all'], 'state',
                ['all', 'pending', 'enrolled', 'completed', 'cancelled']))];
        }
        if ($method === 'PUT' && preg_match('~\A/api/credentials/retraining/([1-9][0-9]*)/enrollment\z~', $path, $parts)) {
            return [200, $retraining->assign($actor, $this->id($parts[1]), $input)];
        }
        $this->notFound();
    }

    private function validateCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf'], $token)) {
            throw new Problem(403, 'invalid_csrf_token');
        }
    }

    private function body(): array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($type !== 'application/json') {
            throw new Problem(415, 'json_required');
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if ($raw === false || strlen($raw) > self::MAX_BODY) {
            throw new Problem(413, 'request_too_large');
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($data) && str_starts_with(ltrim($raw), '{')) {
                return $data;
            }
        } catch (JsonException) {
            throw new Problem(400, 'invalid_json');
        }
        throw new Problem(400, 'json_object_required');
    }

    private function cursor(): int
    {
        $cursor = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($cursor === false) {
            throw new Problem(422, 'invalid_input', ['field' => 'after']);
        }
        return $cursor;
    }

    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->notFound();
        }
        return $id;
    }

    private function notFound(): never
    {
        throw new Problem(404, 'route_not_found');
    }

    private function send(array $result, int $status = 200): never
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
