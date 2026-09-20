<?php

namespace App\Services\Advertiser;

use App\Models\Order;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve a checkout project and warn when the cart would repeat a host.
 */
class AdvertiserProjectCheckout
{
    /**
     * Owned project id, or a single auto-suggested match from destination hosts.
     *
     * @param  list<string|null>  $targetUrls
     */
    public function resolveId(int $userId, mixed $raw, array $targetUrls = []): ?int
    {
        $id = (int) $raw;
        if ($id > 0) {
            return $this->owned($userId, $id)?->id;
        }

        return $this->suggestId($userId, $targetUrls);
    }

    public function owned(int $userId, int $projectId): ?Project
    {
        if ($userId <= 0 || $projectId <= 0) {
            return null;
        }

        return Project::query()
            ->where('user_id', $userId)
            ->whereKey($projectId)
            ->first();
    }

    /**
     * When every cart destination maps to the same project host, preselect it.
     *
     * @param  list<string|null>  $targetUrls
     */
    public function suggestId(int $userId, array $targetUrls): ?int
    {
        $hosts = $this->uniqueHosts($targetUrls);
        if ($hosts === []) {
            return null;
        }

        $projects = Project::query()
            ->where('user_id', $userId)
            ->get(['id', 'project_url']);

        $matched = [];
        foreach ($hosts as $host) {
            $hit = $projects->first(
                fn (Project $project) => Project::hostFromUrl($project->project_url) === $host
            );
            if (! $hit) {
                return null;
            }
            $matched[] = (int) $hit->id;
        }

        $matched = array_values(array_unique($matched));

        return count($matched) === 1 ? $matched[0] : null;
    }

    /**
     * Existing placements on the same destination host (open or completed).
     *
     * @param  list<string|null>  $targetUrls
     * @return list<array{host: string, order_number: string, status: string, project_name: ?string}>
     */
    public function duplicateHostWarnings(int $userId, array $targetUrls): array
    {
        $hosts = $this->uniqueHosts($targetUrls);
        if ($hosts === [] || $userId <= 0) {
            return [];
        }

        $orders = Order::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($q) {
                $q->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'failed');
            })
            ->with(
                Schema::hasColumn('orders', 'project_id') && Schema::hasTable('projects')
                    ? ['items', 'project:id,project_name']
                    : ['items']
            )
            ->latest('id')
            ->limit(80)
            ->get();

        $seen = [];
        $warnings = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $host = Project::placementHost($item);
                if ($host === '' || ! in_array($host, $hosts, true)) {
                    continue;
                }
                $key = $host.'|'.$order->id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $warnings[] = [
                    'host' => $host,
                    'order_number' => (string) $order->order_number,
                    'status' => (string) $order->status,
                    'project_name' => $order->project?->project_name,
                ];
            }
        }

        return $warnings;
    }

    /**
     * Checkout page payload: projects, selected/suggested id, duplicate hosts.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $checkoutArticles
     * @return array{
     *     projects: Collection<int, Project>,
     *     selected_project_id: ?int,
     *     suggested_project_id: ?int,
     *     duplicate_hosts: list<array{host: string, order_number: string, status: string, project_name: ?string}>
     * }
     */
    public function checkoutContext(int $userId, mixed $requestedId, Collection|array $checkoutArticles): array
    {
        $projects = Project::query()
            ->where('user_id', $userId)
            ->orderBy('project_name')
            ->get();

        $targetUrls = $this->targetUrlsFromArticles($checkoutArticles);
        $suggested = $this->suggestId($userId, $targetUrls);
        $selected = $this->resolveId($userId, $requestedId !== null && $requestedId !== '' ? $requestedId : $suggested, $targetUrls);

        return [
            'projects' => $projects,
            'selected_project_id' => $selected,
            'suggested_project_id' => $suggested,
            'duplicate_hosts' => $this->duplicateHostWarnings($userId, $targetUrls),
        ];
    }

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $checkoutArticles
     * @return list<string|null>
     */
    public function targetUrlsFromArticles(Collection|array $checkoutArticles): array
    {
        $urls = [];
        foreach ($checkoutArticles as $article) {
            if (is_object($article) && isset($article->target_url)) {
                $urls[] = (string) $article->target_url;
            } elseif (is_array($article) && isset($article['target_url'])) {
                $urls[] = (string) $article['target_url'];
            }
        }

        return $urls;
    }

    /**
     * Limit an orders query to this project: assigned rows, or unassigned host matches.
     *
     * When `$stage` is a known Projects filter, the stage must hold on a line
     * that belongs to the project: any item on an assigned order, or a
     * host-matching item on an unassigned order. Sibling lines on another
     * host therefore cannot pull an unassigned order into this project's
     * `needs_improvements` / `needs_you` buckets.
     *
     * @param  Builder<Order>  $query
     */
    public function constrainOrdersToProject($query, Project $project, ?string $stage = null): void
    {
        $stage = is_string($stage) ? strtolower(trim($stage)) : '';
        $hasStage = $stage !== '' && Project::isKnownStageFilter($stage);
        $hasProjectId = Schema::hasColumn('orders', 'project_id');

        if ($hasStage && $hasProjectId) {
            $query->where(function ($outer) use ($project, $stage) {
                $outer->where(function ($assigned) use ($project, $stage) {
                    $assigned->where('project_id', $project->id);
                    Project::constrainOrdersByStage($assigned, $stage, null);
                })->orWhere(function ($legacy) use ($project, $stage) {
                    $legacy->whereNull('project_id');
                    Project::constrainOrdersByStage($legacy, $stage, (string) $project->project_url);
                });
            });

            return;
        }

        if ($hasStage) {
            Project::constrainOrdersByStage($query, $stage, (string) $project->project_url);

            return;
        }

        $query->where(function ($outer) use ($project, $hasProjectId) {
            if ($hasProjectId) {
                $outer->where('project_id', $project->id)
                    ->orWhere(function ($legacy) use ($project) {
                        $legacy->whereNull('project_id');
                        Project::constrainOrdersByHost($legacy, (string) $project->project_url);
                    });

                return;
            }

            Project::constrainOrdersByHost($outer, (string) $project->project_url);
        });
    }

    /**
     * @param  list<string|null>  $targetUrls
     * @return list<string>
     */
    private function uniqueHosts(array $targetUrls): array
    {
        $hosts = [];
        foreach ($targetUrls as $url) {
            $host = Project::hostFromUrl($url);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
