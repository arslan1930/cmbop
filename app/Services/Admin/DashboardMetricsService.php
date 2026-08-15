<?php

namespace App\Services\Admin;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItemDispute;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\SiteEnrichmentRun;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Models\Withdrawal;
use App\Services\Reminders\StalledOrderQueue;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Query layer for the admin dashboard JSON endpoints.
 *
 * Controllers stay responsible for HTTP wrapping; this class owns the counts
 * and series so KPI cards, sidebar badges, and action queues share one definition.
 */
class DashboardMetricsService
{
    public function __construct(
        private FinanceOverviewService $finance,
        private StalledOrderQueue $stalled,
    ) {}

    /**
     * Top-level KPI cards + action counts.
     *
     * @return array<string, int|float>
     */
    public function statistics(): array
    {
        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $publisherRoleId = Role::where('name', 'publisher')->value('id');
        $adminRoleId = Role::where('name', 'admin')->value('id');
        $marketingRoleId = Role::where('name', 'marketing')->value('id');
        $queues = $this->queueCounts();

        return [
            'total_users' => User::count(),
            'advertisers' => $advertiserRoleId
                ? (int) DB::table('role_user')->where('role_id', $advertiserRoleId)->distinct()->count('user_id')
                : 0,
            'publishers' => $publisherRoleId
                ? (int) DB::table('role_user')->where('role_id', $publisherRoleId)->distinct()->count('user_id')
                : 0,
            'admins' => $adminRoleId
                ? (int) DB::table('role_user')->where('role_id', $adminRoleId)->distinct()->count('user_id')
                : 0,
            'marketers' => $marketingRoleId
                ? (int) DB::table('role_user')->where('role_id', $marketingRoleId)->distinct()->count('user_id')
                : 0,
            'total_sites' => Site::count(),
            'verified_sites' => Site::where('verified', 1)->count(),
            'live_sites' => Site::query()->catalogVisible()->count(),
            'unverified_sites' => $queues['unverified_sites'],
            'total_orders' => Order::count(),
            'paid_orders' => Order::where('payment_status', 'paid')->count(),
            'revenue' => (float) Order::where('payment_status', 'paid')->sum('total_amount'),
            'pending_deposits' => $queues['pending_deposits'],
            'pending_withdrawals' => $queues['pending_withdrawals'],
            'pending_payments' => $queues['pending_payments'],
            'pending_community' => $queues['pending_community'],
            'open_disputes' => $queues['open_disputes'],
            'stalled_orders' => $queues['stalled_orders'],
            'needs_attention' => $queues['needs_attention'],
            'new_users_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'orders_7d' => Order::where('payment_status', 'paid')
                ->whereRaw($this->paidAtSql().' >= ?', [now()->subDays(7)])
                ->count(),
            'revenue_7d' => (float) Order::where('payment_status', 'paid')
                ->whereRaw($this->paidAtSql().' >= ?', [now()->subDays(7)])
                ->sum('total_amount'),
        ];
    }

    /**
     * Revenue + user signup series for the last N days (clamped 7–90).
     *
     * Paid GMV is bucketed by COALESCE(paid_at, created_at) so an order paid
     * this week is not missing because it was created earlier.
     *
     * @return array{labels: list<string>, revenue: list<float>, signups: list<int>, orders: list<int>}
     */
    public function trends(int $days = 30): array
    {
        $days = min(90, max(7, $days));
        $start = now()->subDays($days - 1)->startOfDay();
        $paidAt = $this->paidAtSql();

        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $start->copy()->addDays($i)->format('Y-m-d');
        }

        $revenueRows = Order::where('payment_status', 'paid')
            ->whereRaw($paidAt.' >= ?', [$start])
            ->selectRaw('DATE('.$paidAt.') as day, SUM(total_amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $signupRows = User::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $orderRows = Order::where('payment_status', 'paid')
            ->whereRaw($paidAt.' >= ?', [$start])
            ->selectRaw('DATE('.$paidAt.') as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $revenueByDay = $this->indexByDay($revenueRows);
        $signupsByDay = $this->indexByDay($signupRows);
        $ordersByDay = $this->indexByDay($orderRows);

        $revenue = [];
        $signups = [];
        $orders = [];
        foreach ($labels as $day) {
            $revenue[] = (float) ($revenueByDay[$day] ?? 0);
            $signups[] = (int) ($signupsByDay[$day] ?? 0);
            $orders[] = (int) ($ordersByDay[$day] ?? 0);
        }

        return [
            'labels' => array_map(fn ($d) => Carbon::parse($d)->format('M j'), $labels),
            'revenue' => $revenue,
            'signups' => $signups,
            'orders' => $orders,
        ];
    }

    /**
     * Order status + role distribution pie data.
     *
     * @return array{orders: array{labels: mixed, values: mixed}, roles: array{labels: mixed, values: mixed}}
     */
    public function distributions(): array
    {
        $orderStatus = Order::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $roleCounts = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('roles.name', DB::raw('COUNT(DISTINCT role_user.user_id) as total'))
            ->groupBy('roles.name')
            ->pluck('total', 'name');

        return [
            'orders' => [
                'labels' => $orderStatus->keys()->map(fn ($s) => ucfirst($s))->values(),
                'values' => $orderStatus->values()->map(fn ($v) => (int) $v)->values(),
            ],
            'roles' => [
                'labels' => $roleCounts->keys()->map(fn ($s) => ucfirst($s))->values(),
                'values' => $roleCounts->values()->map(fn ($v) => (int) $v)->values(),
            ],
        ];
    }

    /**
     * Sidebar badge counts for pending ops queues.
     *
     * @return array<string, int>
     */
    public function queueCounts(): array
    {
        $pendingDeposits = DepositRequest::where('status', 'pending')->count();
        $pendingWithdrawals = Withdrawal::whereIn('status', ['pending', 'processing'])->count();
        // Ready-for-admin queue only (exclude unfinished awaiting_details drafts)
        $unverifiedSites = Site::query()->needsAdminReview()->count();
        $pendingPayments = $this->unpaidOrdersCount();
        $pendingClaims = $this->pendingCount(SiteClaim::class, 'site_claims');
        $pendingProblems = $this->pendingCount(ProblemReport::class, 'problem_reports');
        $pendingSuggestions = $this->pendingCount(Suggestion::class, 'suggestions');
        $pendingWebsites = $this->pendingCount(WebsiteSuggestion::class, 'website_suggestions');
        $pendingCommunity = $pendingClaims + $pendingProblems + $pendingSuggestions + $pendingWebsites;
        $openDisputes = $this->openDisputesCount();
        $stalledOrders = $this->stalled->count();
        $needsAttention = $pendingDeposits
            + $pendingWithdrawals
            + $unverifiedSites
            + $pendingPayments
            + $pendingCommunity
            + $openDisputes
            + $stalledOrders;

        return [
            'pending_deposits' => $pendingDeposits,
            'pending_withdrawals' => $pendingWithdrawals,
            'unverified_sites' => $unverifiedSites,
            'pending_payments' => $pendingPayments,
            'pending_claims' => $pendingClaims,
            'pending_problems' => $pendingProblems,
            'pending_suggestions' => $pendingSuggestions,
            'pending_websites' => $pendingWebsites,
            'pending_community' => $pendingCommunity,
            'open_disputes' => $openDisputes,
            'stalled_orders' => $stalledOrders,
            'needs_attention' => $needsAttention,
        ];
    }

    /**
     * Liability + this-month margin from FinanceOverviewService (same numbers as /admin/finance).
     *
     * @return array<string, float|string>
     */
    public function financeStrip(): array
    {
        $overview = $this->finance->overview($this->finance->resolvePeriod('month'));

        return [
            'period_label' => $overview['period']['label'],
            'due_to_pay_now' => (float) $overview['due_to_pay_now'],
            'in_publisher_wallets' => (float) $overview['in_publisher_wallets'],
            'total_publisher_liability' => (float) $overview['total_publisher_liability'],
            'margin' => (float) $overview['platform']['margin'],
            'url' => route('admin.finance'),
        ];
    }

    /**
     * Items that need admin attention (top 5 per queue).
     *
     * @return array{deposits: mixed, withdrawals: mixed, sites: mixed, unpaid: mixed, disputes: mixed, community: mixed, enrichment: mixed}
     */
    public function actionQueue(): array
    {
        $deposits = DepositRequest::with('user:id,name,email')
            ->where('status', 'pending')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'user' => $d->user?->name ?? 'Unknown',
                'email' => $d->user?->email,
                'amount' => (float) $d->amount,
                'method' => $d->payment_method,
                'date' => optional($d->created_at)->format('d M Y H:i'),
                // deposits.show is JSON for the list-page modal; the HTML queue is the working page.
                'url' => route('admin.deposits', ['status' => 'pending']),
            ]);

        $withdrawals = Withdrawal::with('user:id,name,email')
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'user' => $w->user?->name ?? 'Unknown',
                'email' => $w->user?->email,
                'amount' => (float) $w->net_amount,
                'method' => $w->payment_method,
                'status' => $w->status,
                'date' => optional($w->created_at)->format('d M Y H:i'),
                'url' => route('admin.withdrawals.show', $w->id),
            ]);

        $sites = Site::with('publisher:id,name,email')
            ->needsAdminReview()
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'site_name' => $s->site_name,
                'site_url' => $s->site_url,
                'publisher' => $s->publisher?->name ?? 'Unknown',
                'date' => optional($s->created_at)->format('d M Y'),
                'url' => route('admin.sites.edit', $s->id),
            ]);

        return [
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'sites' => $sites,
            'unpaid' => $this->unpaidQueue(),
            'disputes' => $this->disputeQueue(),
            'community' => $this->communityQueue(),
            'enrichment' => $this->enrichmentQueue(),
        ];
    }

    /**
     * Same unpaid definition as FinanceOverviewService::opsQueues() / pending_payments.
     */
    private function unpaidOrdersQuery()
    {
        return Order::query()->unpaidOps();
    }

    private function unpaidOrdersCount(): int
    {
        return $this->unpaidOrdersQuery()->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function unpaidQueue(): Collection
    {
        return $this->unpaidOrdersQuery()
            ->with('user:id,name,email')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'user' => $o->user?->name ?? 'Unknown',
                'email' => $o->user?->email,
                'amount' => (float) $o->total_amount,
                'date' => optional($o->created_at)->format('d M Y H:i'),
                'url' => route('admin.orders.show', $o->id),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function disputeQueue(): Collection
    {
        if (! OrderItemDispute::tableAvailable()) {
            return collect();
        }

        return OrderItemDispute::query()
            ->where('status', OrderItemDispute::STATUS_OPEN)
            ->with(['order:id,order_number,user_id', 'order.user:id,name', 'orderItem:id,site_name'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (OrderItemDispute $d) => [
                'id' => $d->id,
                'order_number' => $d->order?->order_number ?? '',
                'site_name' => $d->orderItem?->site_name ?: '—',
                'advertiser' => $d->order?->user?->name ?? 'Unknown',
                'reason' => Str::limit((string) $d->reason, 80),
                'date' => optional($d->created_at)->format('d M Y H:i'),
                'url' => $d->order_id ? route('admin.orders.show', $d->order_id) : route('admin.orders.index'),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function communityQueue(): Collection
    {
        $rows = collect();

        if (Schema::hasTable('problem_reports')) {
            $rows = $rows->concat(
                ProblemReport::where('status', 'pending')->latest()->take(5)->get()->map(fn (ProblemReport $r) => [
                    'type' => 'problem',
                    'label' => $r->subject ?: 'Problem report',
                    'from' => $r->name ?: ($r->email ?: 'Unknown'),
                    'date' => optional($r->created_at)->format('d M Y'),
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'url' => route('admin.community.index', ['tab' => 'problems', 'status' => 'pending']),
                ])
            );
        }

        if (Schema::hasTable('suggestions')) {
            $rows = $rows->concat(
                Suggestion::where('status', 'pending')->latest()->take(5)->get()->map(fn (Suggestion $r) => [
                    'type' => 'suggestion',
                    'label' => Str::limit((string) $r->message, 60) ?: 'Suggestion',
                    'from' => $r->name ?: ($r->email ?: 'Unknown'),
                    'date' => optional($r->created_at)->format('d M Y'),
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'url' => route('admin.community.index', ['tab' => 'suggestions', 'status' => 'pending']),
                ])
            );
        }

        if (Schema::hasTable('website_suggestions')) {
            $rows = $rows->concat(
                WebsiteSuggestion::where('status', 'pending')->latest()->take(5)->get()->map(fn (WebsiteSuggestion $r) => [
                    'type' => 'website',
                    'label' => $r->website_name ?: ($r->website_url ?: 'Website suggestion'),
                    'from' => $r->website_url ?: 'Unknown',
                    'date' => optional($r->created_at)->format('d M Y'),
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'url' => route('admin.community.index', ['tab' => 'websites', 'status' => 'pending']),
                ])
            );
        }

        if (Schema::hasTable('site_claims')) {
            $rows = $rows->concat(
                SiteClaim::where('status', 'pending')->latest()->take(5)->get()->map(fn (SiteClaim $r) => [
                    'type' => 'claim',
                    'label' => $r->website_name ?: ($r->domain ?: 'Site claim'),
                    'from' => $r->contact_email ?: 'Unknown',
                    'date' => optional($r->created_at)->format('d M Y'),
                    'sort_at' => optional($r->created_at)?->timestamp ?? 0,
                    'url' => route('admin.community.index', ['tab' => 'claims', 'status' => 'pending']),
                ])
            );
        }

        return $rows->sortByDesc('sort_at')->take(5)->values()->map(function (array $row) {
            unset($row['sort_at']);

            return $row;
        });
    }

    /**
     * Latest failed / partial / stuck runs — same queue as /admin/site-enrichment.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichmentQueue(): Collection
    {
        try {
            if (! Schema::hasTable('site_enrichment_runs')) {
                return collect();
            }

            return SiteEnrichmentRun::query()
                ->with('site:id,site_name,site_url')
                ->needsAttention()
                ->latest('id')
                ->take(5)
                ->get()
                ->map(fn (SiteEnrichmentRun $run) => [
                    'id' => $run->id,
                    'site_name' => $run->site?->site_name ?: 'Unknown site',
                    'status' => $run->status,
                    'error' => Str::limit((string) ($run->error ?: 'Enrichment failed'), 80),
                    'date' => optional($run->created_at)->format('d M Y'),
                    'url' => $run->site_id
                        ? route('admin.sites.edit', $run->site_id)
                        : route('admin.site-enrichment.index'),
                ]);
        } catch (\Throwable $e) {
            // Same Hostinger/schema drift the enrichment page already swallows —
            // do not take down deposits/withdrawals with it.
            Log::warning('Dashboard enrichment queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    private function openDisputesCount(): int
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0;
        }

        return OrderItemDispute::where('status', OrderItemDispute::STATUS_OPEN)->count();
    }

    /**
     * @param  class-string  $model
     */
    private function pendingCount(string $model, string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return $model::where('status', 'pending')->count();
    }

    private function paidAtSql(): string
    {
        return 'COALESCE(paid_at, created_at)';
    }

    /**
     * DATE() keys can come back as Y-m-d or a datetime string depending on driver.
     *
     * @param  Collection<string, mixed>  $rows
     * @return array<string, mixed>
     */
    private function indexByDay($rows): array
    {
        $indexed = [];
        foreach ($rows as $day => $total) {
            $indexed[Carbon::parse((string) $day)->toDateString()] = $total;
        }

        return $indexed;
    }
}
