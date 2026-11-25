<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class SignoffHttp
{
    public function __construct(private BusinessSignoff $signoff)
    {
    }

    public function dispatch(string $method, Actor $actor, int $project, string $path, array $body, int $after): array
    {
        if ($method === 'GET' && $path === '') {
            return [200, $this->signoff->listing($actor, $project, $after)];
        }
        if (preg_match('~\A/([1-9][0-9]{0,17})/criteria/([1-9][0-9]{0,17})\z~', $path, $matches)) {
            [$deployment, $criterion] = [(int) $matches[1], (int) $matches[2]];
            return match ($method) {
                'GET' => [200, $this->signoff->history($actor, $project, $deployment, $criterion, $after)],
                'PUT' => [200, $this->signoff->decide($actor, $project, $deployment, $criterion, $body)],
                default => throw new Problem(405, 'method_not_allowed'),
            };
        }
        if (preg_match('~\A/([1-9][0-9]{0,17})(/[a-z-]+)?\z~', $path, $matches)) {
            $id = (int) $matches[1];
            return match ($method . ($matches[2] ?? '')) {
                'GET' => [200, $this->signoff->view($actor, $project, $id)],
                'GET/timeline' => [200, $this->signoff->timeline($actor, $project, $id, $after)],
                'GET/report-preview' => [200, $this->signoff->preview($actor, $project, $id)],
                'GET/report' => [200, $this->signoff->download($actor, $project, $id), 'delivery-report-' . $id . '.json'],
                'POST/report' => [201, $this->signoff->archive($actor, $project, $id, $body)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        throw new Problem(404, 'route_not_found');
    }
}
