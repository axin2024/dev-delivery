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
            $store = Store::connect();
            $audit = new Audit($store);
            $accounts = new Accounts($store, $audit);
            $projects = new Projects($store, $accounts, $audit);
            $git = new GitDelivery($store, $projects, $audit);
            $gitEvents = new GitEvents($store, $projects, $git);
            if (preg_match('~\A/api/connectors/git/([0-9a-f]{32})\z~', $path, $connector)) {
                if ($method !== 'POST') {
                    throw new Problem(405, 'method_not_allowed');
                }
                $this->respond(200, $gitEvents->receive($connector[1], $_SERVER['HTTP_X_DELIVERY_TIMESTAMP'] ?? '',
                    $_SERVER['HTTP_X_DELIVERY_SIGNATURE'] ?? '', $this->rawJson()));
                return;
            }
            $this->startSession();
            if ($method === 'GET' && $path === '/api/session') {
                $actor = isset($_SESSION['account_id']) ? $this->actor($accounts)->view() : null;
                $this->respond(200, ['csrf_token' => $_SESSION['csrf'], 'actor' => $actor]);
                return;
            }
            $upload = $method === 'POST' && preg_match('~\A/api/projects/[1-9][0-9]{0,17}/test-plans/[1-9][0-9]{0,17}/reports\z~', $path);
            $input = $method === 'GET' || $upload ? [] : $this->mutation();
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
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/(artifacts|environments|test-cases|test-plans|test-reports|test-results|defects)(/.*)?\z~', $path, $testing)) {
                [$status, $result] = (new TestingHttp($store, $projects, $audit))->dispatch(
                    $method, $actor, (int) $testing[1], $testing[2] . ($testing[3] ?? ''), $upload ? $this->xmlUpload() : $input, $this->cursor());
            } else {
                $baselines = new Baselines($store, $projects, $audit);
                $changes = new Changes($store, $projects, $baselines, $audit);
                $planning = new Planning($store, $projects, $audit);
                [$status, $result] = $this->dispatch($method, $path, $actor, $projects, $baselines, $changes, $planning, $git, $gitEvents, $input);
            }
            $this->respond($status, $result);
        } catch (Problem $error) {
            $this->respond($error->status, ['error' => $error->reason, 'details' => (object) $error->details]);
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $this->respond(500, ['error' => 'internal_error']);
        }
    }

    private function dispatch(
        string $method, string $path, Actor $actor, Projects $projects, Baselines $baselines,
        Changes $changes, Planning $planning, GitDelivery $git, GitEvents $gitEvents, array $body,
    ): array
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
        if (preg_match('~\A/(members|requirements|criteria|baselines|changes|sprints|work-items)/([1-9][0-9]{0,17})(/[a-z-]+)?\z~', $relative, $item)) {
            $id = (int) $item[2];
            return match ($method . ' ' . $item[1] . ($item[3] ?? '')) {
                'PUT members' => [200, $projects->setMember($actor, $project, $id, $body)],
                'POST requirements/criteria' => [201, $baselines->createCriterion($actor, $project, $id, $body)],
                'GET criteria' => [200, $baselines->history($actor, $project, $id, $this->cursor())],
                'PUT criteria' => [201, $baselines->reviseCriterion($actor, $project, $id, $body)],
                'GET baselines' => [200, $baselines->view($actor, $project, $id)],
                'GET changes' => [200, $changes->view($actor, $project, $id)],
                'GET changes/timeline' => [200, $changes->timeline($actor, $project, $id, $this->cursor())],
                'POST changes/decision' => [200, $changes->decide($actor, $project, $id, $body)],
                'GET sprints' => [200, $planning->sprintView($actor, $project, $id, $this->cursor())],
                'GET work-items' => [200, $planning->workView($actor, $project, $id)],
                'GET work-items/timeline' => [200, $planning->workTimeline($actor, $project, $id, $this->cursor())],
                'PUT work-items/schedule' => [200, $planning->schedule($actor, $project, $id, $body)],
                'POST work-items/claim' => [200, $git->claim($actor, $project, $id)],
                'GET work-items/git-links' => [200, $git->workLinks($actor, $project, $id, $this->cursor())],
                'POST work-items/git-links' => [200, $git->link($actor, $project, $id, $body)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        if (preg_match('~\A/work-items/([1-9][0-9]{0,17})/dependencies/([1-9][0-9]{0,17})\z~', $relative, $item)) {
            return match ($method) {
                'PUT', 'DELETE' => [200, $planning->setDependency($actor, $project, (int) $item[1], (int) $item[2], $method === 'PUT' ? $body : null)],
                default => throw new Problem(405, 'method_not_allowed'),
            };
        }
        if (preg_match('~\A/repositories/([1-9][0-9]{0,17})(/events|/connector|/snapshots/([1-9][0-9]{0,17}))?\z~', $relative, $item)) {
            $id = (int) $item[1];
            $route = isset($item[3]) ? '/snapshots' : ($item[2] ?? '');
            return match ($method . ' ' . $route) {
                'GET ' => [200, $git->branches($actor, $project, $id, $this->cursor())],
                'GET /snapshots' => [200, $git->snapshotView($actor, $project, $id, (int) $item[3])],
                'GET /events' => [200, $gitEvents->history($actor, $project, $id, $this->cursor())],
                'POST /events' => [200, $gitEvents->imported($actor, $project, $id, $body)],
                'POST /connector' => [200, $gitEvents->credentials($actor, $project, $id)],
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
            'GET /criteria/current' => [200, $baselines->currentCriteria($actor, $project, $this->cursor())],
            'GET /baselines' => [200, $baselines->listing($actor, $project, $this->cursor())],
            'POST /baselines' => [201, $baselines->freeze($actor, $project, $body)],
            'GET /changes' => [200, $changes->listing($actor, $project, $this->cursor())],
            'POST /changes' => [201, $changes->create($actor, $project, $body)],
            'GET /sprints' => [200, $planning->listing($actor, $project, $this->cursor(), 'sprints')],
            'POST /sprints' => [201, $planning->createSprint($actor, $project, $body)],
            'GET /work-items' => [200, $planning->listing($actor, $project, $this->cursor(), 'work_items')],
            'POST /work-items' => [201, $planning->createWork($actor, $project, $body)],
            'GET /repositories' => [200, $git->listing($actor, $project, $this->cursor())],
            'POST /repositories' => [201, $git->register($actor, $project, $body)],
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
        $this->assertCsrf();
        $body = $this->rawJson();
        try {
            if (!str_starts_with(ltrim($body), '{')) {
                throw new Problem(400, 'json_object_required');
            }
            return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Problem(400, 'invalid_json');
        }
    }

    private function assertCsrf(): void
    {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new Problem(403, 'csrf_invalid');
        }
    }

    private function xmlUpload(): array
    {
        $this->assertCsrf();
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if (!in_array($contentType, ['application/xml', 'text/xml'], true)) {
            throw new Problem(415, 'xml_required');
        }
        $xml = file_get_contents('php://input', false, null, 0, JUnit::MAX_BYTES + 1);
        if ($xml === false || strlen($xml) > JUnit::MAX_BYTES) {
            throw new Problem(413, 'payload_too_large');
        }
        return [
            'xml' => $xml,
            'source_key' => $_SERVER['HTTP_X_DELIVERY_REPORT_KEY'] ?? '',
            'artifact_sha256' => $_SERVER['HTTP_X_DELIVERY_ARTIFACT_SHA256'] ?? '',
            'attempt' => filter_var($_SERVER['HTTP_X_DELIVERY_ATTEMPT'] ?? '', FILTER_VALIDATE_INT),
            'build_id' => filter_var($_SERVER['HTTP_X_DELIVERY_BUILD_ID'] ?? '', FILTER_VALIDATE_INT),
            'environment_id' => filter_var($_SERVER['HTTP_X_DELIVERY_ENVIRONMENT_ID'] ?? '', FILTER_VALIDATE_INT),
        ];
    }

    private function rawJson(): string
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new Problem(415, 'json_required');
        }
        $body = file_get_contents('php://input', false, null, 0, 65_537);
        if ($body === false || strlen($body) > 65_536) {
            throw new Problem(413, 'payload_too_large');
        }
        return $body;
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

    private function respond(int $status, array|string $body): void
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        if (is_string($body)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="junit.xml"');
            echo $body;
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
