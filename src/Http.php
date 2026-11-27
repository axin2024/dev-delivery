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
            if (preg_match('~\A/api/connectors/deployments/([0-9a-f]{32})\z~', $path, $connector)) {
                if ($method !== 'POST') {
                    throw new Problem(405, 'method_not_allowed');
                }
                $this->respond(200, (new DeploymentHttp($store, $projects, $audit))->receive($connector[1],
                    $_SERVER['HTTP_X_DELIVERY_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_DELIVERY_SIGNATURE'] ?? '', $this->rawJson()));
                return;
            }
            $this->startSession();
            if ($method === 'GET' && $path === '/api/session') {
                $actor = isset($_SESSION['account_id']) ? $this->actor($accounts)->view() : null;
                $this->respond(200, ['csrf_token' => $_SESSION['csrf'], 'actor' => $actor]);
                return;
            }
            $junit = $method === 'POST' && preg_match('~\A/api/projects/[1-9][0-9]{0,17}/test-plans/[1-9][0-9]{0,17}/reports\z~', $path);
            $artifactContent = preg_match('~\A/api/projects/[1-9][0-9]{0,17}/artifacts/[1-9][0-9]{0,17}/content\z~', $path);
            $retry = $method === 'POST' && preg_match('~\A/api/projects/[1-9][0-9]{0,17}/test-plans/[1-9][0-9]{0,17}/report-retries\z~', $path);
            $input = $method === 'GET' || $junit || ($method === 'PUT' && $artifactContent) ? []
                : $this->mutation($retry ? JUnit::MAX_RETRY_JSON_BYTES : 65_536);
            if ($method === 'POST' && $path === '/api/login') {
                $actor = $accounts->authenticate($input);
                session_regenerate_id(true);
                $_SESSION = ['tenant_id' => $actor->tenantId, 'account_id' => $actor->accountId, 'csrf' => bin2hex(random_bytes(32))];
                $this->respond(200, ['csrf_token' => $_SESSION['csrf'], 'actor' => $actor->view()]);
                return;
            }
            $actor = $this->actor($accounts);
            if (preg_match('~\A/release-trains/([1-9][0-9]{0,17})/waves/([1-9][0-9]{0,17})/advance\z~',$path,$direct)) {
                $owner=$store->one('SELECT project_id FROM release_trains WHERE tenant_id=? AND id=?',[$actor->tenantId,(int)$direct[1]])
                    ?? throw new Problem(404,'train_not_found');
                $path='/api/projects/'.$owner['project_id'].$path;
            }
            if ($method === 'POST' && $path === '/api/logout') {
                session_regenerate_id(true);
                $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
                $this->respond(200, ['csrf_token' => $_SESSION['csrf'], 'actor' => null]);
                return;
            }
            if ($actor->has(Actor::EXTERNAL_REVIEWER)) {
                [$status, $result, $filename] = (new OperationsHttp($store, $projects, $accounts, $audit))->external(
                    $method, $actor, $path, $this->cursor());
            } elseif ($path === '/api/accounts') {
                [$status, $result] = match ($method) {
                    'GET' => [200, $accounts->listing($actor, $this->cursor())],
                    'POST' => [201, $accounts->create($actor, $input)->view()],
                    default => throw new Problem(405, 'method_not_allowed'),
                };
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/release-trains/([1-9][0-9]{0,17})/executions/([1-9][0-9]{0,17})/handover\z~',$path,$m)) {
                if ($method!=='POST') throw new Problem(405,'method_not_allowed');
                $result=(new TrainExecution($store,$projects,$audit))->handover($actor,(int)$m[1],(int)$m[2],(int)$m[3],$input);
                $status=200;
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/release-trains/([1-9][0-9]{0,17})/recovery(?:/(step))?\z~',$path,$m)) {
                $recovery=new TrainRecovery($store,$projects,$audit);
                [$status,$result]=match ([$method,$m[3] ?? '']) {
                    ['GET','']=>[200,$recovery->detail($actor,(int)$m[1],(int)$m[2])],
                    ['POST',''],['POST','step']=>[200,$recovery->control($actor,(int)$m[1],(int)$m[2],$input,!isset($m[3]))],
                    default=>throw new Problem(405,'method_not_allowed'),
                };
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/release-trains/([1-9][0-9]{0,17})/(wave-plan|pause|resume|waves/([1-9][0-9]{0,17})/(samples|observations|waivers|advance)|executions/([1-9][0-9]{0,17}))\z~',$path,$m)) {
                $waves=new TrainWaves($store,$projects,$audit);
                $worker=new TrainExecution($store,$projects,$audit);
                $project=(int)$m[1];
                $id=(int)$m[2];
                $wave=(int)($m[4] ?? 0);
                $action=isset($m[6])?'execution':($m[5] ?? $m[3]);
                [$status,$result]=match ([$method,$action]) {
                    ['GET','wave-plan'] => [200,$waves->overview($actor,$project,$id)],
                    ['POST','wave-plan'] => [201,$waves->plan($actor,$project,$id,$input)],
                    ['POST','samples'] => [201,$waves->sample($actor,$project,$id,$wave,$input)],
                    ['POST','observations'] => [201,$waves->observe($actor,$project,$id,$wave)],
                    ['POST','waivers'] => [201,$waves->waive($actor,$project,$id,$wave,$input)],
                    ['POST','advance'] => [202,$waves->advance($actor,$project,$id,$wave,$input,$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')],
                    ['GET','execution'] => [200,$worker->detail($actor,$project,$id,(int)$m[6])],
                    ['POST','pause'],['POST','resume'] => [200,$worker->hold($actor,$project,$id,$action==='resume',$input)],
                    default => throw new Problem(405,'method_not_allowed'),
                };
                if ($action==='advance') {
                    header('Location: /api/projects/'.$project.'/release-trains/'.$id.'/executions/'.$result['execution_id']);
                }
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/environments/([1-9][0-9]{0,17})/train-access(?:/(owner|connector))?\z~',$path,$m)) {
                $access=new TrainEnvironments($store,$projects,$audit);
                [$status,$result]=match ([$method,$m[3] ?? '']) {
                    ['GET',''] => [200,$access->read($actor,(int)$m[1],(int)$m[2])],
                    ['PUT','owner'], ['PUT','connector'] => [200,$access->configure($actor,(int)$m[1],(int)$m[2],$m[3],$input)],
                    default => throw new Problem(405,'method_not_allowed'),
                };
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/release-trains(?:/([1-9][0-9]{0,17})(?:/(freeze|replace|gates|rehearse|approve|window|manifests/([1-9][0-9]{0,8})))?)?\z~',$path,$m)) {
                $trains=new ReleaseTrains($store,$projects,$audit);
                $readiness=new TrainReadiness($store,$projects,$audit);
                $project=(int)$m[1];
                $id=(int)($m[2] ?? 0);
                $action=$m[3] ?? '';
                if ($method==='GET' && isset($m[4])) {
                    $version=$trains->version($actor,$project,$id,(int)$m[4]);
                    $this->respond(200,$version['manifest'],"release-train-".$id."-r".$version['revision'].".json");
                    return;
                }
                [$status,$result]=match ([$method,$id===0,$action]) {
                    ['GET',true,''] => [200,$trains->listing($actor,$project,$this->cursor())],
                    ['POST',true,''] => [201,$trains->create($actor,$project,$input)],
                    ['GET',false,''] => [200,$trains->current($actor,$project,$id)],
                    ['POST',false,'freeze'], ['POST',false,'replace'] => [201,$trains->freeze($actor,$project,$id,$input,$action==='replace')],
                    ['GET',false,'gates'] => [200,$readiness->gates($actor,$project,$id)],
                    ['POST',false,'rehearse'] => [200,$readiness->rehearse($actor,$project,$id,$input)],
                    ['POST',false,'approve'] => [201,$readiness->approve($actor,$project,$id,$input)],
                    ['POST',false,'window'] => [201,$readiness->book($actor,$project,$id,$input)],
                    default => throw new Problem(405,'method_not_allowed'),
                };
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/release-services(?:/([1-9][0-9]{0,17})(?:/(dependencies|pin))?)?\z~', $path, $m)) {
                $catalog = new ReleaseServices($store,$projects,$audit);
                $project = (int)$m[1];
                [$status,$result] = match ([$method,isset($m[2]),$m[3] ?? '']) {
                    ['GET',false,''] => [200,$catalog->graph($actor,$project)],
                    ['POST',false,''] => [201,$catalog->register($actor,$project,$input)],
                    ['GET',true,''] => [200,$catalog->read($actor,$project,(int)$m[2])],
                    ['PUT',true,'dependencies'], ['PUT',true,'pin'] => [200,$catalog->change($actor,$project,(int)$m[2],$m[3],$input)],
                    default => throw new Problem(405,'method_not_allowed'),
                };
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/signoffs(/.*)?\z~', $path, $signoff)) {
                [$status, $result, $filename] = array_pad((new SignoffHttp(new BusinessSignoff($store, $projects, $audit)))->dispatch(
                    $method, $actor, (int) $signoff[1], $signoff[2] ?? '', $input, $this->cursor()), 3, null);
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/((?:milestones|review-packages)(?:/.*)?|work-items/[1-9][0-9]{0,17}/ownership)\z~', $path, $operations)) {
                [$status, $result] = (new OperationsHttp($store, $projects, $accounts, $audit))->dispatch(
                    $method, $actor, (int) $operations[1], $operations[2], $input, $this->cursor());
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/(deployments(?:/.*)?|environments/[1-9][0-9]{0,17}/deployment-connector)\z~', $path, $deployment)) {
                [$status, $result] = (new DeploymentHttp($store, $projects, $audit))->dispatch(
                    $method, $actor, (int) $deployment[1], $deployment[2], $input, $this->cursor(), $_GET);
            } elseif (preg_match('~\A/api/projects/([1-9][0-9]{0,17})/(artifacts|environments|test-cases|test-plans|test-reports|test-results|defects|candidates)(/.*)?\z~', $path, $testing)) {
                if ($junit) {
                    $input = $this->xmlUpload();
                } elseif ($method === 'PUT' && $artifactContent) {
                    $this->assertCsrf();
                    $input = ['bytes' => $this->readPayload(['application/octet-stream'], 'artifact_content_type_required', BuildTargets::MAX_CONTENT_BYTES)];
                }
                [$status, $result] = (new TestingHttp($store, $projects, $audit))->dispatch(
                    $method, $actor, (int) $testing[1], $testing[2] . ($testing[3] ?? ''), $input, $this->cursor());
            } else {
                $baselines = new Baselines($store, $projects, $audit);
                $changes = new Changes($store, $projects, $baselines, $audit);
                $planning = new Planning($store, $projects, $audit);
                [$status, $result] = $this->dispatch($method, $path, $actor, $projects, $baselines, $changes, $planning, $git, $gitEvents, $input);
            }
            $this->respond($status, $result, $filename ?? ($artifactContent ? 'artifact.bin' : 'junit.xml'));
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

    private function mutation(int $limit): array
    {
        $this->assertCsrf();
        $body = $this->rawJson($limit);
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
        return [
            'xml' => $this->readPayload(['application/xml', 'text/xml'], 'xml_required', JUnit::MAX_BYTES),
            'source_key' => $_SERVER['HTTP_X_DELIVERY_REPORT_KEY'] ?? '',
            'artifact_sha256' => $_SERVER['HTTP_X_DELIVERY_ARTIFACT_SHA256'] ?? '',
            'attempt' => filter_var($_SERVER['HTTP_X_DELIVERY_ATTEMPT'] ?? '', FILTER_VALIDATE_INT),
            'build_id' => filter_var($_SERVER['HTTP_X_DELIVERY_BUILD_ID'] ?? '', FILTER_VALIDATE_INT),
            'environment_id' => filter_var($_SERVER['HTTP_X_DELIVERY_ENVIRONMENT_ID'] ?? '', FILTER_VALIDATE_INT),
        ];
    }

    private function rawJson(int $limit = 65_536): string
    {
        return $this->readPayload(['application/json'], 'json_required', $limit);
    }

    private function readPayload(array $contentTypes, string $error, int $max): string
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if (!in_array($contentType, $contentTypes, true)) {
            throw new Problem(415, $error);
        }
        $body = file_get_contents('php://input', false, null, 0, $max + 1);
        if ($body === false || strlen($body) > $max) {
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

    private function respond(int $status, array|string $body, string $filename = 'junit.xml'): void
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        if (is_string($body)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $body;
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
