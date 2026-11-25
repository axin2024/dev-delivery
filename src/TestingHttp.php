<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class TestingHttp
{
    private TestCases $cases;
    private BuildTargets $targets;
    private TestPlans $plans;
    private TestEvidence $evidence;
    private Defects $defects;

    public function __construct(Store $store, Projects $projects, Audit $audit)
    {
        $this->cases = new TestCases($store, $projects, $audit);
        $this->targets = new BuildTargets($store, $projects, $audit);
        $this->plans = new TestPlans($store, $projects, $this->targets, $audit);
        $this->evidence = new TestEvidence($store, $projects, $this->plans, $audit);
        $this->defects = new Defects($store, $projects, $this->targets, $this->evidence, $audit);
    }

    public function dispatch(string $method, Actor $actor, int $project, string $path, array $body, int $after): array
    {
        if (preg_match('~\A([a-z-]+)/([1-9][0-9]{0,17})(/[a-z-]+)?\z~', $path, $item)) {
            $id = (int) $item[2];
            return match ($method . ' ' . $item[1] . ($item[3] ?? '')) {
                'GET artifacts' => [200, $this->targets->artifact($actor, $project, $id)],
                'GET environments' => [200, $this->targets->environment($actor, $project, $id)],
                'GET test-cases' => [200, $this->cases->history($actor, $project, $id, $after)],
                'PUT test-cases' => [200, $this->cases->revise($actor, $project, $id, $body)],
                'GET test-cases/timeline' => [200, $this->cases->timeline($actor, $project, $id, $after)],
                'GET test-plans' => [200, $this->plans->view($actor, $project, $id)],
                'POST test-plans/gate' => [200, $this->plans->assess($actor, $project, $id, $body)],
                'GET test-plans/reports' => [200, $this->evidence->reports($actor, $project, $id, $after)],
                'POST test-plans/reports' => [201, $this->evidence->import($actor, $project, $id, $body, $body['xml'])],
                'POST test-plans/manual' => [201, $this->evidence->manual($actor, $project, $id, $body)],
                'GET test-reports' => [200, $this->evidence->report($actor, $project, $id)],
                'GET test-reports/xml' => [200, $this->evidence->original($actor, $project, $id)],
                'GET test-results' => [200, $this->evidence->result($actor, $project, $id)],
                'GET defects' => [200, $this->defects->view($actor, $project, $id)],
                'POST defects/fix' => [200, $this->defects->fix($actor, $project, $id, $body)],
                'POST defects/close' => [200, $this->defects->close($actor, $project, $id, $body)],
                'GET defects/timeline' => [200, $this->defects->timeline($actor, $project, $id, $after)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        return match ($method . ' ' . $path) {
            'GET artifacts' => [200, $this->targets->listing($actor, $project, $after, 'artifacts')],
            'POST artifacts' => [201, $this->targets->register($actor, $project, $body)],
            'GET environments' => [200, $this->targets->listing($actor, $project, $after, 'environments')],
            'POST environments' => [201, $this->targets->createEnvironment($actor, $project, $body)],
            'GET test-cases' => [200, $this->cases->listing($actor, $project, $after)],
            'POST test-cases' => [201, $this->cases->create($actor, $project, $body)],
            'GET test-plans' => [200, $this->plans->listing($actor, $project, $after)],
            'POST test-plans' => [201, $this->plans->create($actor, $project, $body)],
            'GET defects' => [200, $this->defects->listing($actor, $project, $after)],
            'POST defects' => [201, $this->defects->open($actor, $project, $body)],
            default => throw new Problem(404, 'route_not_found'),
        };
    }
}
