<?php
declare(strict_types=1);

namespace DevDelivery;

use JsonException;
use Throwable;

final class Http
{
    public function handle(string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/healthz') {
            try {
                $ready = (new Schema(Store::connect()))->ready();
            } catch (Throwable $error) {
                error_log($error->getMessage());
                $ready = false;
            }
            $this->respond($ready ? 200 : 503, ['ready' => $ready]);
            return;
        }
        try {
            $this->startSession();
            $store = Store::connect();
            $audit = new Audit($store);
            $accounts = new Accounts($store, $audit);
            if ($method === 'GET' && $path === '/api/session') {
                $actor = isset($_SESSION['account_id']) ? $this->actor($accounts)->view() : null;
                $this->respond(200, ['csrf_token' => $_SESSION['csrf'], 'actor' => $actor]);
                return;
            }
            $input = $method === 'GET' ? [] : $this->mutation();
            if ($method === 'POST' && $path === '/api/login') {
                $actor = $accounts->authenticate($input);
                session_regenerate_id(true);
                $_SESSION = ['tenant_id' => $actor->tenantId, 'account_id' => $actor->accountId, 'csrf' => bin2hex(random_bytes(32))];
                $this->respond(200, ['csrf_token' => $_SESSION['csrf'], 'actor' => $actor->view()]);
                return;
            }
            $actor = $this->actor($accounts);
            if ($method === 'POST' && $path === '/api/logout') {
                session_regenerate_id(true);
                $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
                $this->respond(200, ['csrf_token' => $_SESSION['csrf'], 'actor' => null]);
                return;
            }
            if ($path === '/api/accounts') {
                [$status, $result] = match ($method) {
                    'GET' => [200, $accounts->listing($actor, $this->cursor())],
                    'POST' => [201, $accounts->create($actor, $input)->view()],
                    default => throw new Problem(405, 'method_not_allowed'),
                };
            } else {
                $projects = new Projects($store, $accounts, $audit);
                [$status, $result] = $this->dispatch($method, $path, $actor, $projects, new Baselines($store, $projects, $audit), $input);
            }
            $this->respond($status, $result);
        } catch (Problem $error) {
            $this->respond($error->status, ['error' => $error->reason, 'details' => (object) $error->details]);
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $this->respond(500, ['error' => 'internal_error']);
        }
    }

    private function dispatch(string $method, string $path, Actor $actor, Projects $projects, Baselines $baselines, array $body): array
    {
        if ($path === '/api/projects') {
            return match ($method) {
                'GET' => [200, $projects->listing($actor, $this->cursor())],
                'POST' => [201, $projects->create($actor, $body)],
                default => throw new Problem(405, 'method_not_allowed'),
            };
        }
        if (!preg_match('~\A/api/projects/([1-9][0-9]{0,17})(/.*)?\z~', $path, $parts)) {
            throw new Problem(404, 'route_not_found');
        }
        $project = (int) $parts[1];
        $relative = $parts[2] ?? '';
        if (preg_match('~\A/(members|requirements|criteria|baselines)/([1-9][0-9]{0,17})(/criteria)?\z~', $relative, $item)) {
            $id = (int) $item[2];
            return match ($method . ' ' . $item[1] . ($item[3] ?? '')) {
                'PUT members' => [200, $projects->setMember($actor, $project, $id, $body)],
                'POST requirements/criteria' => [201, $baselines->createCriterion($actor, $project, $id, $body)],
                'GET criteria' => [200, $baselines->history($actor, $project, $id, $this->cursor())],
                'PUT criteria' => [201, $baselines->reviseCriterion($actor, $project, $id, $body)],
                'GET baselines' => [200, $baselines->view($actor, $project, $id)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        return match ($method . ' ' . $relative) {
            'GET ' => [200, $projects->view($actor, $project)],
            'PUT ' => [200, $projects->update($actor, $project, $body)],
            'GET /timeline' => [200, $projects->timeline($actor, $project, $this->cursor())],
            'GET /requirements' => [200, $baselines->requirements($actor, $project, $this->cursor())],
            'POST /requirements' => [201, $baselines->createRequirement($actor, $project, $body)],
            'GET /scope' => [200, $baselines->scope($actor, $project)],
            'GET /baselines' => [200, $baselines->listing($actor, $project, $this->cursor())],
            'POST /baselines' => [201, $baselines->freeze($actor, $project, $body)],
            default => throw new Problem(404, 'route_not_found'),
        };
    }

    private function startSession(): void
    {
        session_name('dev_delivery');
        session_set_cookie_params(['path' => '/', 'secure' => getenv('DELIVERY_COOKIE_SECURE') !== '0', 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start(['use_strict_mode' => true, 'use_only_cookies' => true])) {
            throw new Problem(503, 'session_unavailable');
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function mutation(): array
    {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new Problem(403, 'csrf_invalid');
        }
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new Problem(415, 'json_required');
        }
        $body = file_get_contents('php://input', false, null, 0, 65_537);
        if ($body === false || strlen($body) > 65_536) {
            throw new Problem(413, 'payload_too_large');
        }
        try {
            if (!str_starts_with(ltrim($body), '{')) {
                throw new Problem(400, 'json_object_required');
            }
            return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Problem(400, 'invalid_json');
        }
    }

    private function actor(Accounts $accounts): Actor
    {
        if (!isset($_SESSION['tenant_id'], $_SESSION['account_id'])) {
            throw new Problem(401, 'login_required');
        }
        return $accounts->find($_SESSION['tenant_id'], $_SESSION['account_id']);
    }

    private function cursor(): int
    {
        $cursor = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($cursor === false) {
            throw new Problem(422, 'invalid_cursor');
        }
        return $cursor;
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
