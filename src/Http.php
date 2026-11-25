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
            [$status, $result] = $this->dispatch($actor, $accounts, $catalog, $admissions, $method, $path, $input);
            $this->send($result, $status);
        } catch (Problem $error) {
            $this->send(['error' => $error->reason, 'details' => (object) $error->details, 'request_id' => $requestId], $error->status);
        } catch (Throwable $error) {
            error_log('Learning Cloud request ' . $requestId . ' failed: ' . $error::class . ' [' . $error->getCode() . ']');
            $this->send(['error' => 'internal_error', 'request_id' => $requestId], 500);
        }
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
        if (preg_match('~\A/api/enrollments/([1-9][0-9]*)(?:/(withdraw|transfer))?\z~', $path, $parts)) {
            $id = $this->id($parts[1]);
            return match ($method . '/' . ($parts[2] ?? '')) {
                'GET/' => [200, $admissions->detail($actor, $id)],
                'POST/withdraw' => [200, $admissions->withdraw($actor, $id)],
                'POST/transfer' => [200, $admissions->transfer($actor, $id, $input)],
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

    private function startSession(): void
    {
        session_name('learning_cloud');
        session_start([
            'use_strict_mode' => 1, 'use_only_cookies' => 1, 'cookie_secure' => getenv('LEARNING_COOKIE_SECURE') !== '0',
            'cookie_path' => '/', 'cookie_httponly' => 1, 'cookie_samesite' => 'Lax', 'cache_limiter' => '',
        ]);
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
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
