<?php

namespace App\Services\Admin;

use App\Models\OrderItemDispute;
use App\Models\ProblemReport;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\WebsiteSuggestion;
use App\Services\Reminders\StalledOrderQueue;
use App\Support\CommunityInbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Unified staff work list: open disputes, pending community, stalled orders.
 *
 * Does not invent new queues — same counts as the dashboard badges, with
 * working links to the existing detail screens.
 */
class WorkInboxService
{
    public const TAB_ALL = 'all';

    public const TAB_DISPUTES = 'disputes';

    public const TAB_COMMUNITY = 'community';

    public const TAB_STALLED = 'stalled';

    public const TABS = [
        self::TAB_ALL => 'All',
        self::TAB_DISPUTES => 'Disputes',
        self::TAB_COMMUNITY => 'Community',
        self::TAB_STALLED => 'Stalled orders',
    ];

    public function __construct(
        private DashboardMetricsService $metrics,
        private StalledOrderQueue $stalled,
    ) {}

    /**
     * @return array{disputes: int, community: int, stalled: int, total: int}
     */
    public function counts(): array
    {
        $queues = $this->metrics->queueCounts();
        $disputes = (int) ($queues['open_disputes'] ?? 0);
        $community = (int) ($queues['pending_community'] ?? 0);
        $stalled = (int) ($queues['stalled_orders'] ?? 0);

        return [
            'disputes' => $disputes,
            'community' => $community,
            'stalled' => $stalled,
            'total' => $disputes + $community + $stalled,
        ];
    }

    public function normalizeTab(mixed $tab): string
    {
        $tab = search_text($tab);
        if ($tab === '' || $tab === self::TAB_ALL) {
            return self::TAB_ALL;
        }

        return array_key_exists($tab, self::TABS) ? $tab : self::TAB_ALL;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function items(string $tab, int $limit = 80): Collection
    {
        $tab = $this->normalizeTab($tab);
        $rows = collect();

        if ($tab === self::TAB_ALL || $tab === self::TAB_DISPUTES) {
            $rows = $rows->concat($this->disputeItems($limit));
        }
        if ($tab === self::TAB_ALL || $tab === self::TAB_COMMUNITY) {
            $rows = $rows->concat($this->communityItems($limit));
        }
        if ($tab === self::TAB_ALL || $tab === self::TAB_STALLED) {
            $rows = $rows->concat($this->stalledItems($limit));
        }

        return $rows
            ->sortByDesc('sort_at')
            ->take($limit)
            ->values()
            ->map(function (array $row) {
                unset($row['sort_at']);

                return $row;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function disputeItems(int $limit): Collection
    {
        if (! OrderItemDispute::tableAvailable()) {
            return collect();
        }

        return OrderItemDispute::query()
            ->where('status', OrderItemDispute::STATUS_OPEN)
            ->with(['order:id,order_number,user_id', 'order.user:id,name,email', 'orderItem:id,site_name'])
            ->latest()
            ->take($limit)
            ->get()
            ->map(fn (OrderItemDispute $d) => [
                'tab' => self::TAB_DISPUTES,
                'type' => 'Dispute',
                'title' => ($d->order?->order_number ?: 'Order').' · '.($d->orderItem?->site_name ?: 'placement'),
                'detail' => Str::limit((string) $d->reason, 120),
                'from' => $d->order?->user?->name ?? 'Unknown',
                'date' => optional($d->created_at)->format('M j, Y g:i A') ?: '—',
                'sort_at' => optional($d->created_at)?->timestamp ?? 0,
                'url' => $d->order_id
                    ? route('admin.orders.show', $d->order_id).'#order-disputes'
                    : route('admin.orders.index', ['dispute' => 'open']),
                'action' => 'Uphold or dismiss',
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function communityItems(int $limit): Collection
    {
        $perType = max(10, (int) ceil($limit / 2));

        return collect()
            ->concat($this->communityType(
                ProblemReport::class,
                'problem_reports',
                CommunityInbox::TAB_PROBLEMS,
                'Problem',
                fn (ProblemReport $r) => [
                    'title' => $r->subject ?: 'Problem report',
                    'detail' => Str::limit((string) $r->message, 120),
                    'from' => $r->name ?: ($r->email ?: 'Unknown'),
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'date' => optional($r->created_at)->format('M j, Y') ?: '—',
                ],
                $perType
            ))
            ->concat($this->communityType(
                Suggestion::class,
                'suggestions',
                CommunityInbox::TAB_SUGGESTIONS,
                'Suggestion',
                fn (Suggestion $r) => [
                    'title' => Str::limit((string) $r->message, 80) ?: 'Suggestion',
                    'detail' => $r->category ?: '',
                    'from' => $r->name ?: ($r->email ?: 'Unknown'),
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'date' => optional($r->created_at)->format('M j, Y') ?: '—',
                ],
                $perType
            ))
            ->concat($this->communityType(
                WebsiteSuggestion::class,
                'website_suggestions',
                CommunityInbox::TAB_WEBSITES,
                'Website',
                fn (WebsiteSuggestion $r) => [
                    'title' => $r->website_name ?: ($r->website_url ?: 'Website suggestion'),
                    'detail' => $r->website_url ?: '',
                    'from' => $r->user?->name ?: 'Unknown',
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'date' => optional($r->created_at)->format('M j, Y') ?: '—',
                ],
                $perType
            ))
            ->concat($this->communityType(
                SiteClaim::class,
                'site_claims',
                CommunityInbox::TAB_CLAIMS,
                'Claim',
                fn (SiteClaim $r) => [
                    'title' => $r->website_name ?: ($r->domain ?: 'Site claim'),
                    'detail' => $r->domain ?: ($r->website_url ?: ''),
                    'from' => $r->contact_email ?: 'Unknown',
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'date' => optional($r->created_at)->format('M j, Y') ?: '—',
                ],
                $perType
            ));
    }

    /**
     * @param  class-string  $model
     * @param  callable(object): array<string, mixed>  $mapper
     * @return Collection<int, array<string, mixed>>
     */
    private function communityType(
        string $model,
        string $table,
        string $communityTab,
        string $type,
        callable $mapper,
        int $limit,
    ): Collection {
        try {
            if (! Schema::hasTable($table)) {
                return collect();
            }

            return $model::query()
                ->where('status', 'pending')
                ->when(method_exists($model, 'user'), fn ($q) => $q->with('user:id,name,email'))
                ->latest('id')
                ->take($limit)
                ->get()
                ->map(function ($row) use ($mapper, $type, $communityTab) {
                    $mapped = $mapper($row);

                    return array_merge($mapped, [
                        'tab' => self::TAB_COMMUNITY,
                        'type' => $type,
                        'url' => route('admin.community.index', [
                            'tab' => $communityTab,
                            'status' => 'pending',
                        ]),
                        'action' => 'Review',
                    ]);
                });
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function stalledItems(int $limit): Collection
    {
        return $this->stalled->items($limit)->map(fn (array $row) => [
            'tab' => self::TAB_STALLED,
            'type' => ($row['track'] ?? '') === 'accept' ? 'Stalled accept' : 'Stalled publish',
            'title' => ($row['order_number'] ?? 'Order').' · '.($row['site_name'] ?? 'site'),
            'detail' => $row['late_label'] ?? '',
            'from' => $row['publisher'] ?? 'Unknown',
            'date' => $row['last_reminded_at'] ?: '—',
            'sort_at' => (int) (($row['hours_overdue'] ?? 0) * 3600),
            'url' => ($row['order_url'] ?? route('admin.orders.index')).'#order-money-actions',
            'action' => 'Remind or refund',
        ]);
    }
}
