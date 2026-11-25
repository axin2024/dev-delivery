<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class DeploymentHttp
{
    private DeploymentQueue $queue;
    private DeploymentReceipts $receipts;

    public function __construct(Store $store, Projects $projects, Audit $audit)
    {
        $targets = new BuildTargets($store, $projects, $audit);
        $plans = new TestPlans($store, $projects, $targets, $audit);
        $candidates = new ReleaseCandidates($store, $projects, $targets, $plans, $audit);
        $this->queue = new DeploymentQueue($store, $projects, $targets, $candidates, $audit);
        $this->receipts = new DeploymentReceipts($store, $projects, $targets, $this->queue, $audit);
    }

    public function receive(string $connector, string $timestamp, string $signature, string $body): array
    {
        return $this->receipts->receive($connector, $timestamp, $signature, $body);
    }

    public function dispatch(string $method, Actor $actor, int $project, string $path, array $body, int $after, array $query): array
    {
        if ($method === 'POST' && preg_match('~\Aenvironments/([1-9][0-9]{0,17})/deployment-connector\z~', $path, $matches)) {
            return [200, $this->receipts->credentials($actor, $project, (int) $matches[1])];
        }
        if (preg_match('~\Adeployments/([1-9][0-9]{0,17})(/[a-z-]+)?\z~', $path, $item)) {
            $id = (int) $item[1];
            return match ($method . ($item[2] ?? '')) {
                'GET' => [200, $this->queue->view($actor, $project, $id)],
                'GET/timeline' => [200, $this->queue->timeline($actor, $project, $id, $after)],
                'GET/receipts' => [200, $this->receipts->history($actor, $project, $id, $after)],
                'POST/rollback' => [201, $this->queue->request($actor, $project, $body, $id)],
                'POST/timeout' => [200, $this->queue->timeout($actor, $project, $id, $body)],
                'POST/receipts' => [200, $this->receipts->imported($actor, $project, $id, $body)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        return match ($method . ' ' . $path) {
            'GET deployments' => [200, $this->queue->listing($actor, $project, $after)],
            'POST deployments' => [201, $this->queue->request($actor, $project, $body)],
            'GET deployments/lookup' => [200, $this->queue->lookup($actor, $project,
                Input::integer(['environment_id' => filter_var($query['environment_id'] ?? '', FILTER_VALIDATE_INT)], 'environment_id', 1),
                Input::string($query, 'request_key', 160))],
            default => throw new Problem(404, 'route_not_found'),
        };
    }
}
