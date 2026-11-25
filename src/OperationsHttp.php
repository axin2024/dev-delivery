<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class OperationsHttp
{
    private Milestones $milestones;
    private ReviewPackages $packages;
    private OwnershipTransfers $ownership;

    public function __construct(Store $store, Projects $projects, Accounts $accounts, Audit $audit)
    {
        $this->milestones = new Milestones($store, $projects, $audit);
        $this->packages = new ReviewPackages($store, $projects, $accounts, $audit);
        $this->ownership = new OwnershipTransfers($store, $projects, $audit);
    }

    public function external(string $method, Actor $actor, string $path, int $after): array
    {
        if ($method === 'GET' && $path === '/api/review-packages') {
            return [200, $this->packages->externalListing($actor, $after), null];
        }
        if ($method === 'GET' && preg_match('~\A/api/review-packages/([1-9][0-9]{0,17})(/content)?\z~', $path, $parts)) {
            $id = (int) $parts[1];
            $download = isset($parts[2]);
            return [200, $this->packages->externalView($actor, $id, $download), $download ? 'review-package-' . $id . '.json' : null];
        }
        throw new Problem(404, 'route_not_found');
    }

    public function dispatch(string $method, Actor $actor, int $project, string $path, array $body, int $after): array
    {
        if (preg_match('~\Amilestones/([1-9][0-9]{0,17})(/[a-z-]+)?\z~', $path, $parts)) {
            $id = (int) $parts[1];
            return match ($method . ($parts[2] ?? '')) {
                'GET' => [200, $this->milestones->view($actor, $project, $id)],
                'PUT' => [200, $this->milestones->bind($actor, $project, $id, $body)],
                'GET/timeline' => [200, $this->milestones->timeline($actor, $project, $id, $after)],
                'GET/metrics' => [200, $this->milestones->snapshots($actor, $project, $id, $after)],
                'POST/metrics' => [201, $this->milestones->capture($actor, $project, $id)],
                'GET/ownership' => [200, $this->ownership->history($actor, $project, 'milestone', $id, $after)],
                'PUT/ownership' => [200, $this->ownership->change($actor, $project, 'milestone', $id, $body)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        if (preg_match('~\Awork-items/([1-9][0-9]{0,17})/ownership\z~', $path, $parts)) {
            $id = (int) $parts[1];
            return match ($method) {
                'GET' => [200, $this->ownership->history($actor, $project, 'work_item', $id, $after)],
                'PUT' => [200, $this->ownership->change($actor, $project, 'work_item', $id, $body)],
                default => throw new Problem(405, 'method_not_allowed'),
            };
        }
        if (preg_match('~\Areview-packages/([1-9][0-9]{0,17})/grants/([1-9][0-9]{0,17})(/revoke)?\z~', $path, $parts)) {
            $package = (int) $parts[1];
            $reviewer = (int) $parts[2];
            return match ($method . ($parts[3] ?? '')) {
                'PUT' => [200, $this->packages->setGrant($actor, $project, $package, $reviewer, $body, false)],
                'POST/revoke' => [200, $this->packages->setGrant($actor, $project, $package, $reviewer, $body, true)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        if (preg_match('~\Areview-packages/([1-9][0-9]{0,17})(/[a-z-]+)?\z~', $path, $parts)) {
            $id = (int) $parts[1];
            return match ($method . ($parts[2] ?? '')) {
                'GET' => [200, $this->packages->internalView($actor, $project, $id)],
                'GET/grants' => [200, $this->packages->grants($actor, $project, $id, $after)],
                'GET/timeline' => [200, $this->packages->timeline($actor, $project, $id, $after)],
                default => throw new Problem(404, 'route_not_found'),
            };
        }
        return match ($method . ' ' . $path) {
            'GET milestones' => [200, $this->milestones->listing($actor, $project, $after)],
            'POST milestones' => [201, $this->milestones->create($actor, $project, $body)],
            'GET review-packages' => [200, $this->packages->internalListing($actor, $project, $after)],
            'POST review-packages' => [201, $this->packages->create($actor, $project, $body)],
            default => throw new Problem(404, 'route_not_found'),
        };
    }
}
