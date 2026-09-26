<?php

namespace App\Services\Admin;

use App\Models\BulkSiteRequest;
use App\Models\ContentModerationLog;
use App\Models\ContentSubmission;
use App\Models\DepositRequest;
use App\Models\EmailCampaign;
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
use App\Services\Billing\InvoiceRepairQueue;
use App\Services\Reminders\StalledOrderQueue;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Services\Wallet\ManualWithdrawalMarkPaidLink;
use App\Support\MarketingOpsQueues;
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
        private InvoiceRepairQueue $repairQueue,
    ) {}

    /**
     * Top-level KPI cards + action counts.
     *
     * @return array<string, int|float>
     */
    public function statistics(): array
    {
        $advertiserRoleId = $this->roleId('advertiser');
        $publisherRoleId = $this->roleId('publisher');
        $adminRoleId = $this->roleId('admin');
        $marketingRoleId = $this->roleId('marketing');
        $queues = $this->queueCounts();

        return [
            'total_users' => $this->safeInt(fn () => User::count()),
            'advertisers' => $this->roleUserCount($advertiserRoleId),
            'publishers' => $this->roleUserCount($publisherRoleId),
            'admins' => $this->roleUserCount($adminRoleId),
            'marketers' => $this->roleUserCount($marketingRoleId),
            'total_sites' => $this->safeInt(fn () => Site::count()),
            'verified_sites' => $this->safeInt(fn () => Site::where('verified', 1)->count()),
            'live_sites' => $this->liveSitesCount(),
            'unverified_sites' => $queues['unverified_sites'],
            'total_orders' => $this->safeInt(fn () => Order::count()),
            'paid_orders' => $this->safeInt(fn () => Order::where('payment_status', 'paid')->count()),
            'revenue' => $this->safeFloat(fn () => Order::where('payment_status', 'paid')->sum('total_amount')),
            'pending_deposits' => $queues['pending_deposits'],
            'pending_withdrawals' => $queues['pending_withdrawals'],
            'pending_payments' => $queues['pending_payments'],
            'pending_community' => $queues['pending_community'],
            'open_disputes' => $queues['open_disputes'],
            'stalled_orders' => $queues['stalled_orders'],
            'open_bulk_requests' => $queues['open_bulk_requests'],
            'failed_mail' => $queues['failed_mail'],
            'moderation_errors' => $queues['moderation_errors'],
            'enrichment_failed' => $queues['enrichment_failed'],
            'catalog_hide' => $queues['catalog_hide'],
            'missing_tax_invoices' => $queues['missing_tax_invoices'],
            'missing_pdf_invoices' => $queues['missing_pdf_invoices'],
            'library_evaluating' => $queues['library_evaluating'],
            'campaigns_attention' => $queues['campaigns_attention'],
            'needs_attention' => $queues['needs_attention'],
            'new_users_7d' => $this->safeInt(fn () => User::where('created_at', '>=', now()->subDays(7))->count()),
            'orders_7d' => $this->safeInt(fn () => Order::where('payment_status', 'paid')
                ->whereRaw($this->paidAtSql().' >= ?', [now()->subDays(7)])
                ->count()),
            'revenue_7d' => $this->safeFloat(fn () => Order::where('payment_status', 'paid')
                ->whereRaw($this->paidAtSql().' >= ?', [now()->subDays(7)])
                ->sum('total_amount')),
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

        $revenueRows = $this->safeKeyedRows(function () use ($paidAt, $start) {
            return Order::where('payment_status', 'paid')
                ->whereRaw($paidAt.' >= ?', [$start])
                ->selectRaw('DATE('.$paidAt.') as day, SUM(total_amount) as total')
                ->groupBy('day')
                ->pluck('total', 'day');
        });

        $signupRows = $this->safeKeyedRows(function () use ($start) {
            return User::where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->pluck('total', 'day');
        });

        $orderRows = $this->safeKeyedRows(function () use ($paidAt, $start) {
            return Order::where('payment_status', 'paid')
                ->whereRaw($paidAt.' >= ?', [$start])
                ->selectRaw('DATE('.$paidAt.') as day, COUNT(*) as total')
                ->groupBy('day')
                ->pluck('total', 'day');
        });

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
            'dates' => $labels,
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
        $orderStatus = $this->safeKeyedRows(function () {
            return Order::select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status');
        });

        $roleCounts = $this->safeKeyedRows(function () {
            return DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->select('roles.name', DB::raw('COUNT(DISTINCT role_user.user_id) as total'))
                ->groupBy('roles.name')
                ->pluck('total', 'name');
        });

        return [
            'orders' => [
                'keys' => $orderStatus->keys()->map(fn ($s) => (string) $s)->values(),
                'labels' => $orderStatus->keys()->map(fn ($s) => ucfirst((string) $s))->values(),
                'values' => $orderStatus->values()->map(fn ($v) => (int) $v)->values(),
            ],
            'roles' => [
                'keys' => $roleCounts->keys()->map(fn ($s) => (string) $s)->values(),
                'labels' => $roleCounts->keys()->map(fn ($s) => ucfirst((string) $s))->values(),
                'values' => $roleCounts->values()->map(fn ($v) => (int) $v)->values(),
                'note' => 'Users with more than one role appear in more than one slice.',
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
        $pendingDeposits = $this->pendingDepositsCount();
        $pendingWithdrawals = $this->pendingWithdrawalsCount();
        // Ready-for-admin queue only (exclude unfinished awaiting_details drafts)
        $unverifiedSites = $this->unverifiedSitesCount();
        $pendingPayments = $this->unpaidOrdersCount();
        $pendingClaims = $this->pendingCount(SiteClaim::class, 'site_claims');
        $pendingProblems = $this->pendingCount(ProblemReport::class, 'problem_reports');
        $pendingSuggestions = $this->pendingCount(Suggestion::class, 'suggestions');
        $pendingWebsites = $this->pendingCount(WebsiteSuggestion::class, 'website_suggestions');
        $pendingCommunity = $pendingClaims + $pendingProblems + $pendingSuggestions + $pendingWebsites;
        $openDisputes = $this->openDisputesCount();
        $stalledOrders = $this->safeInt(fn () => $this->stalled->count());
        $openBulk = $this->openBulkRequestsCount();
        $failedMail = $this->failedMailCount();
        $moderationErrors = $this->moderationErrorsCount();
        $enrichmentFailed = $this->enrichmentFailedCount();
        $catalogHide = $this->catalogHideCount();
        $missingTaxInvoices = $this->safeInt(fn () => $this->repairQueue->missingTaxInvoiceCount());
        $missingPdfInvoices = $this->safeInt(fn () => $this->repairQueue->missingPdfPathCount());
        $libraryEvaluating = $this->libraryEvaluatingCount();
        $campaignsAttention = $this->campaignsAttentionCount();
        $needsAttention = $pendingDeposits
            + $pendingWithdrawals
            + $unverifiedSites
            + $pendingPayments
            + $pendingCommunity
            + $openDisputes
            + $stalledOrders
            + $openBulk
            + $failedMail
            + $moderationErrors
            + $enrichmentFailed
            + $catalogHide
            + $missingTaxInvoices
            + $missingPdfInvoices
            + $libraryEvaluating
            + $campaignsAttention;

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
            'open_bulk_requests' => $openBulk,
            'failed_mail' => $failedMail,
            'moderation_errors' => $moderationErrors,
            'enrichment_failed' => $enrichmentFailed,
            'catalog_hide' => $catalogHide,
            'missing_tax_invoices' => $missingTaxInvoices,
            'missing_pdf_invoices' => $missingPdfInvoices,
            'library_evaluating' => $libraryEvaluating,
            'campaigns_attention' => $campaignsAttention,
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
        $monthUrl = $this->safeRoute('admin.finance', ['period' => 'month']) ?? '';
        $overview = $this->finance->overview($this->finance->resolvePeriod('month'), throwOnFailure: true);
        $collected = data_get($overview, 'money_in.collected', []);
        $lines = [];
        foreach ((array) data_get($collected, 'by_currency', []) as $code => $parts) {
            if (! is_array($parts)) {
                continue;
            }
            $sum = round(
                (float) ($parts['card'] ?? 0) + (float) ($parts['paypal'] ?? 0) + (float) ($parts['other'] ?? 0),
                2
            );
            if ($sum == 0.0) {
                continue;
            }
            $lines[] = [
                'currency' => strtoupper((string) $code),
                'amount' => $sum,
            ];
        }

        return [
            'period_label' => (string) data_get($overview, 'period.label', ''),
            'due_to_pay_now' => (float) ($overview['due_to_pay_now'] ?? 0),
            'in_publisher_wallets' => (float) ($overview['in_publisher_wallets'] ?? 0),
            'total_publisher_liability' => (float) ($overview['total_publisher_liability'] ?? 0),
            'margin' => (float) data_get($overview, 'platform.margin', 0),
            'url' => $monthUrl,
            'collected' => $lines,
            'orders_not_recorded' => (int) data_get($collected, 'orders_not_recorded', 0),
            'features_not_recorded' => (int) data_get($collected, 'features_not_recorded', 0),
        ];
    }

    /**
     * Items that need admin attention (top 5 per queue).
     *
     * @return array{deposits: mixed, withdrawals: mixed, sites: mixed, unpaid: mixed, disputes: mixed, community: mixed, enrichment: mixed, bulk: mixed, mail: mixed, moderation: mixed, catalog_hide: mixed}
     */
    public function actionQueue(): array
    {
        $deposits = $this->depositQueue();
        $withdrawals = $this->withdrawalQueue();

        $sites = collect();
        try {
            if (Schema::hasTable('sites')) {
                $sites = $this->oldestWaiting(
                    Site::with('publisher:id,name,email')->needsAdminReview()
                )
                    ->take(5)
                    ->get()
                    ->map(fn ($s) => [
                        'id' => $s->id,
                        'site_name' => $s->site_name,
                        'site_url' => $s->site_url,
                        'publisher' => $s->publisher?->name ?? 'Unknown',
                        'date' => $this->formatDate($s->created_at, 'd M Y'),
                        'age' => $this->ageLabel($s->created_at),
                        'url' => route('admin.sites.edit', $s->id),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard sites queue failed', ['error' => $e->getMessage()]);
            $sites = collect();
        }

        return [
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'sites' => $sites,
            'unpaid' => $this->unpaidQueue(),
            'disputes' => $this->disputeQueue(),
            'community' => $this->communityQueue(),
            'enrichment' => $this->enrichmentQueue(),
            'bulk' => $this->bulkQueue(),
            'mail' => $this->failedMailQueue(),
            'moderation' => $this->moderationQueue(),
            'catalog_hide' => $this->catalogHideQueue(),
            'missing_tax' => $this->missingTaxQueue(),
            'missing_pdf' => $this->missingPdfQueue(),
            'library' => $this->libraryQueue(),
            'campaigns' => $this->campaignQueue(),
            'totals' => $this->queueCounts(),
        ];
    }

    /**
     * Same unpaid definition as FinanceOverviewService::opsQueues() / pending_payments.
     */
    private function unpaidOrdersQuery()
    {
        return Order::query()->unpaidOps();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function depositQueue(): Collection
    {
        try {
            if (! DepositRequest::tableAvailable()) {
                return collect();
            }

            $hasCharge = Schema::hasColumn('deposit_requests', 'charge_currency')
                && Schema::hasColumn('deposit_requests', 'charge_amount');

            return $this->oldestWaiting(
                DepositRequest::with('user:id,name,email')->where('status', 'pending')
            )
                ->take(5)
                ->get()
                ->map(function ($d) use ($hasCharge) {
                    $chargeCurrency = null;
                    $chargeAmount = null;
                    if ($hasCharge) {
                        $code = strtoupper(trim((string) ($d->charge_currency ?? '')));
                        if ($code !== '' && $code !== 'EUR' && $d->charge_amount !== null) {
                            $chargeCurrency = $code;
                            $chargeAmount = (float) $d->charge_amount;
                        }
                    }

                    return [
                        'id' => $d->id,
                        'user' => $d->user?->name ?? 'Unknown',
                        'email' => $d->user?->email,
                        'amount' => (float) $d->amount,
                        'charge_currency' => $chargeCurrency,
                        'charge_amount' => $chargeAmount,
                        'method' => $d->payment_method,
                        'method_label' => $this->paymentMethodLabel((string) $d->payment_method),
                        'date' => $this->formatDate($d->created_at, 'd M Y H:i'),
                        'age' => $this->ageLabel($d->created_at),
                        // deposits.show is JSON for the list-page modal; the HTML queue is the working page.
                        'url' => route('admin.deposits', ['status' => 'pending', 'search' => 'DEP-'.$d->id]),
                        'action_url' => $this->depositActionUrl((int) $d->id),
                        'action_label' => 'Review',
                    ];
                });
        } catch (\Throwable $e) {
            Log::warning('Dashboard deposits queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function withdrawalQueue(): Collection
    {
        try {
            if (! Withdrawal::tableAvailable()) {
                return collect();
            }

            return $this->oldestWaiting(
                Withdrawal::with('user:id,name,email')->whereIn('status', ['pending', 'processing'])
            )
                ->take(5)
                ->get()
                ->map(fn ($w) => [
                    'id' => $w->id,
                    'user' => $w->user?->name ?? 'Unknown',
                    'email' => $w->user?->email,
                    'amount' => (float) $w->net_amount,
                    'method' => $w->payment_method,
                    'method_label' => $this->paymentMethodLabel((string) $w->payment_method),
                    'status' => $w->status,
                    'date' => $this->formatDate($w->created_at, 'd M Y H:i'),
                    'age' => $this->ageLabel($w->created_at),
                    // withdrawals.show is JSON for the list-page modal; the HTML queue is the working page.
                    'url' => route('admin.withdrawals', ['queue' => 'open', 'search' => 'WD-'.$w->id]),
                    'action_url' => $this->withdrawalActionUrl((int) $w->id),
                    'action_label' => 'Mark paid',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard withdrawals queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    private function pendingDepositsCount(): int
    {
        try {
            if (! DepositRequest::tableAvailable()) {
                return 0;
            }

            return DepositRequest::where('status', 'pending')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function pendingWithdrawalsCount(): int
    {
        try {
            if (! Withdrawal::tableAvailable()) {
                return 0;
            }

            return Withdrawal::whereIn('status', ['pending', 'processing'])->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function unpaidOrdersCount(): int
    {
        return $this->safeInt(fn () => $this->unpaidOrdersQuery()->count());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function unpaidQueue(): Collection
    {
        try {
            return $this->oldestWaiting(
                $this->unpaidOrdersQuery()->with('user:id,name,email')
            )
                ->take(5)
                ->get()
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'user' => $o->user?->name ?? 'Unknown',
                    'email' => $o->user?->email,
                    'amount' => (float) $o->total_amount,
                    'method' => $o->payment_method,
                    'method_label' => $this->paymentMethodLabel((string) $o->payment_method),
                    'date' => $this->formatDate($o->created_at, 'd M Y H:i'),
                    'age' => $this->ageLabel($o->created_at),
                    'url' => route('admin.payments', [
                        'payment_status' => 'unpaid',
                        'search' => (string) ($o->order_number ?: $o->id),
                    ]),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard unpaid queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function disputeQueue(): Collection
    {
        if (! OrderItemDispute::tableAvailable()) {
            return collect();
        }

        try {
            return $this->oldestWaiting(
                OrderItemDispute::query()
                    ->where('status', OrderItemDispute::STATUS_OPEN)
                    ->with(['order:id,order_number,user_id', 'order.user:id,name', 'orderItem:id,site_name'])
            )
                ->take(5)
                ->get()
                ->map(fn (OrderItemDispute $d) => [
                    'id' => $d->id,
                    'order_number' => $d->order?->order_number ?? '',
                    'site_name' => $d->orderItem?->site_name ?: '—',
                    'advertiser' => $d->order?->user?->name ?? 'Unknown',
                    'reason' => Str::limit((string) $d->reason, 80),
                    'date' => $this->formatDate($d->created_at, 'd M Y H:i'),
                    'age' => $this->ageLabel($d->created_at),
                    'url' => $d->order_id ? route('admin.orders.show', $d->order_id) : route('admin.orders.index'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard dispute queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function communityQueue(): Collection
    {
        $rows = collect();

        $rows = $rows
            ->concat($this->communityQueueRows(ProblemReport::class, 'problem_reports', fn (ProblemReport $r) => [
                'type' => 'problem',
                'label' => $r->subject ?: 'Problem report',
                'from' => $r->name ?: ($r->email ?: 'Unknown'),
                'date' => $this->formatDate($r->created_at, 'd M Y'),
                'age' => $this->ageLabel($r->created_at),
                'sort_at' => $this->sortStamp($r->created_at),
                'sort_id' => (int) $r->id,
                'url' => route('admin.community.index', ['tab' => 'problems', 'status' => 'pending']),
            ]))
            ->concat($this->communityQueueRows(Suggestion::class, 'suggestions', fn (Suggestion $r) => [
                'type' => 'suggestion',
                'label' => Str::limit((string) $r->message, 60) ?: 'Suggestion',
                'from' => $r->name ?: ($r->email ?: 'Unknown'),
                'date' => $this->formatDate($r->created_at, 'd M Y'),
                'age' => $this->ageLabel($r->created_at),
                'sort_at' => $this->sortStamp($r->created_at),
                'sort_id' => (int) $r->id,
                'url' => route('admin.community.index', ['tab' => 'suggestions', 'status' => 'pending']),
            ]))
            ->concat($this->communityQueueRows(WebsiteSuggestion::class, 'website_suggestions', fn (WebsiteSuggestion $r) => [
                'type' => 'website',
                'label' => $r->website_name ?: ($r->website_url ?: 'Website suggestion'),
                'from' => $r->website_url ?: 'Unknown',
                'date' => $this->formatDate($r->created_at, 'd M Y'),
                'age' => $this->ageLabel($r->created_at),
                'sort_at' => $this->sortStamp($r->created_at),
                'sort_id' => (int) $r->id,
                'url' => route('admin.community.index', ['tab' => 'websites', 'status' => 'pending']),
            ]))
            ->concat($this->communityQueueRows(SiteClaim::class, 'site_claims', fn (SiteClaim $r) => [
                'type' => 'claim',
                'label' => $r->website_name ?: ($r->domain ?: 'Site claim'),
                'from' => $r->contact_email ?: 'Unknown',
                'date' => $this->formatDate($r->created_at, 'd M Y'),
                'age' => $this->ageLabel($r->created_at),
                'sort_at' => $this->sortStamp($r->created_at),
                'sort_id' => (int) $r->id,
                'url' => route('admin.community.index', ['tab' => 'claims', 'status' => 'pending']),
            ]));

        return $rows->sortBy([
            ['sort_at', 'asc'],
            ['sort_id', 'asc'],
        ])->take(5)->values()->map(function (array $row) {
            unset($row['sort_at'], $row['sort_id']);

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

            return $this->oldestWaiting(
                SiteEnrichmentRun::query()
                    ->with('site:id,site_name,site_url')
                    ->needsAttention()
            )
                ->take(5)
                ->get()
                ->map(fn (SiteEnrichmentRun $run) => [
                    'id' => $run->id,
                    'site_name' => $run->site?->site_name ?: 'Unknown site',
                    'status' => $run->status,
                    'error' => Str::limit((string) ($run->error ?: 'Enrichment failed'), 80),
                    'date' => $this->formatDate($run->created_at, 'd M Y'),
                    'age' => $this->ageLabel($run->created_at),
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

    /**
     * Same predicate as marketing “Waiting on you” so leftover Done rows stay visible.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function bulkQueue(): Collection
    {
        try {
            if (! Schema::hasTable('bulk_site_requests')) {
                return collect();
            }

            return $this->oldestWaiting(
                MarketingOpsQueues::bulkWaitingOnMarketer()
                    ->with('publisher:id,name,email')
            )
                ->take(5)
                ->get()
                ->map(fn (BulkSiteRequest $bulk) => [
                    'id' => $bulk->id,
                    'publisher' => $bulk->publisher?->name ?? 'Unknown',
                    'status' => $bulk->status,
                    'count' => (int) ($bulk->estimated_count ?? 0),
                    'date' => $this->formatDate($bulk->created_at, 'd M Y'),
                    'age' => $this->ageLabel($bulk->created_at),
                    'url' => $this->safeRoute('admin.bulk-site-requests.show', $bulk->id)
                        ?? $this->safeRoute('admin.bulk-site-requests.index', ['status' => MarketingOpsQueues::FILTER_NEEDS_MARKETER]),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard bulk queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Retryable SendQueuedMailable rows — same source as Email Center’s failed-mail count.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function failedMailQueue(): Collection
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return collect();
            }

            return collect(DB::table('failed_jobs')
                ->where('payload', 'like', '%SendQueuedMailable%')
                ->orderByRaw('CASE WHEN failed_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('failed_at')
                ->orderBy('id')
                ->take(5)
                ->get())
                ->map(function ($row) {
                    $firstLine = strtok(str_replace(["\r\n", "\r"], "\n", (string) ($row->exception ?? '')), "\n") ?: 'Failed mail job';

                    return [
                        'id' => $row->id,
                        'label' => Str::limit(trim((string) $firstLine), 80),
                        'date' => $this->formatDate($row->failed_at ?? null, 'd M Y H:i'),
                        'age' => $this->ageLabel($row->failed_at ?? null),
                        'url' => $this->safeRoute('admin.emails.index'),
                    ];
                });
        } catch (\Throwable $e) {
            Log::warning('Dashboard failed-mail queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Scan errors that never produced an approve/reject — same filter as /admin/moderation?status=error.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function moderationQueue(): Collection
    {
        try {
            if (! ContentModerationLog::tableAvailable()) {
                return collect();
            }

            return $this->oldestWaiting(
                ContentModerationLog::query()
                    ->where('status', ContentModerationLog::STATUS_ERROR)
            )
                ->take(5)
                ->get()
                ->map(fn (ContentModerationLog $log) => [
                    'id' => $log->id,
                    'label' => Str::limit((string) ($log->error_message ?: $log->error_code ?: 'Scan error'), 80),
                    'date' => $this->formatDate($log->created_at, 'd M Y'),
                    'age' => $this->ageLabel($log->created_at),
                    'url' => $this->safeRoute('admin.moderation.show', $log->id)
                        ?? $this->safeRoute('admin.moderation.index', ['status' => 'error']),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard moderation queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Advertisers currently in catalog hide mode.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function catalogHideQueue(): Collection
    {
        try {
            if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'catalog_hide_until')) {
                return collect();
            }

            return User::query()
                ->whereNotNull('catalog_hide_until')
                ->where('catalog_hide_until', '>', now())
                ->orderBy('catalog_hide_until')
                ->orderBy('id')
                ->get(['id', 'name', 'email', 'catalog_hide_until'])
                ->filter(fn (User $user) => $user->inCatalogHideMode())
                ->take(5)
                ->values()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'user' => $user->name ?: 'Unknown',
                    'email' => $user->email,
                    'date' => $this->formatDate($user->catalog_hide_until, 'd M Y H:i'),
                    'age' => $this->ageLabel($user->catalog_hide_until),
                    'url' => $this->safeRoute('admin.catalog-activity', ['user' => $user->id]),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard catalog-hide queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    private function openBulkRequestsCount(): int
    {
        try {
            if (! Schema::hasTable('bulk_site_requests')) {
                return 0;
            }

            return MarketingOpsQueues::bulkWaitingOnMarketer()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function failedMailCount(): int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 0;
            }

            return (int) DB::table('failed_jobs')->where('payload', 'like', '%SendQueuedMailable%')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function moderationErrorsCount(): int
    {
        try {
            if (! ContentModerationLog::tableAvailable()) {
                return 0;
            }

            return ContentModerationLog::where('status', ContentModerationLog::STATUS_ERROR)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function enrichmentFailedCount(): int
    {
        try {
            if (! Schema::hasTable('site_enrichment_runs')) {
                return 0;
            }

            return SiteEnrichmentRun::query()->needsAttention()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function catalogHideCount(): int
    {
        try {
            if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'catalog_hide_until')) {
                return 0;
            }

            return User::query()
                ->whereNotNull('catalog_hide_until')
                ->where('catalog_hide_until', '>', now())
                ->get(['id', 'catalog_hide_until'])
                ->filter(fn (User $user) => $user->inCatalogHideMode())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function openDisputesCount(): int
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0;
        }

        return $this->safeInt(fn () => OrderItemDispute::where('status', OrderItemDispute::STATUS_OPEN)->count());
    }

    private function liveSitesCount(): int
    {
        try {
            if (! Schema::hasTable('sites')) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        try {
            return (int) Site::query()->catalogVisible()->count();
        } catch (\Throwable $e) {
            // Leftover Hostinger: catalogVisible() joins bulk_site_requests.
            Log::warning('Dashboard live sites count failed', ['error' => $e->getMessage()]);
        }

        try {
            return (int) Site::query()->active()->notArchived()->count();
        } catch (\Throwable $e) {
            Log::warning('Dashboard live sites fallback failed', ['error' => $e->getMessage()]);
        }

        try {
            return (int) Site::query()->where('active', 1)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function roleId(string $name): mixed
    {
        try {
            return Role::where('name', $name)->value('id');
        } catch (\Throwable) {
            return null;
        }
    }

    private function roleUserCount(mixed $roleId): int
    {
        if (! $roleId) {
            return 0;
        }

        try {
            return (int) DB::table('role_user')->where('role_id', $roleId)->distinct()->count('user_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeInt(callable $resolve): int
    {
        try {
            return (int) $resolve();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeFloat(callable $resolve): float
    {
        try {
            return (float) $resolve();
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * @return Collection<string, mixed>
     */
    private function safeKeyedRows(callable $resolve): Collection
    {
        try {
            $rows = $resolve();

            return $rows instanceof Collection ? $rows : collect($rows);
        } catch (\Throwable $e) {
            Log::warning('Dashboard series query failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @param  class-string  $model
     * @param  callable(object): array<string, mixed>  $mapper
     * @return Collection<int, array<string, mixed>>
     */
    private function communityQueueRows(string $model, string $table, callable $mapper): Collection
    {
        try {
            if (! Schema::hasTable($table)) {
                return collect();
            }

            return $this->oldestWaiting(
                $model::query()->where('status', 'pending')
            )->take(5)->get()->map($mapper);
        } catch (\Throwable) {
            return collect();
        }
    }

    private function unverifiedSitesCount(): int
    {
        try {
            if (! Schema::hasTable('sites')) {
                return 0;
            }
            DB::table('sites')->limit(1)->exists();

            return Site::query()->needsAdminReview()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  class-string  $model
     */
    private function pendingCount(string $model, string $table): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return $model::where('status', 'pending')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function missingTaxQueue(): Collection
    {
        try {
            return $this->repairQueue->oldestMissingTaxOrders(5)
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'user' => $order->user?->name ?? 'Unknown',
                    'amount' => (float) $order->total_amount,
                    'date' => $this->formatDate($order->paid_at ?? $order->created_at, 'd M Y H:i'),
                    'age' => $this->ageLabel($order->paid_at ?? $order->created_at),
                    'url' => route('admin.invoices.index', [
                        'queue' => 'missing',
                        'search' => (string) ($order->order_number ?: $order->id),
                    ]),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard missing-tax queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function missingPdfQueue(): Collection
    {
        try {
            return $this->repairQueue->oldestMissingPdfInvoices(5)
                ->map(function ($invoice) {
                    $code = strtoupper(trim((string) ($invoice->currency ?? '')));

                    return [
                        'id' => $invoice->id,
                        'label' => $invoice->invoice_number ?: ('Invoice '.$invoice->id),
                        'amount' => (float) $invoice->total_amount,
                        'currency' => $code !== '' && $code !== 'EUR' ? $code : null,
                        'date' => $this->formatDate($invoice->invoice_date ?? $invoice->created_at, 'd M Y'),
                        'age' => $this->ageLabel($invoice->invoice_date ?? $invoice->created_at),
                        'url' => route('admin.invoices.index', [
                            'pdf' => 'missing',
                            'search' => (string) ($invoice->invoice_number ?: $invoice->id),
                        ]),
                    ];
                });
        } catch (\Throwable $e) {
            Log::warning('Dashboard missing-pdf queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function libraryQueue(): Collection
    {
        try {
            if (! Schema::hasTable('content_submissions')) {
                return collect();
            }

            $columns = ['id'];
            foreach (['title', 'original_filename', 'created_at'] as $column) {
                if (Schema::hasColumn('content_submissions', $column)) {
                    $columns[] = $column;
                }
            }

            return $this->oldestWaiting(ContentSubmission::query()->evaluatingInLibrary())
                ->take(5)
                ->get($columns)
                ->map(fn (ContentSubmission $submission) => [
                    'id' => $submission->id,
                    'label' => $submission->title ?: ($submission->original_filename ?: 'Article'),
                    'date' => $this->formatDate($submission->created_at, 'd M Y'),
                    'age' => $this->ageLabel($submission->created_at),
                    'url' => route('admin.content-library.show', $submission->id),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard library queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function campaignQueue(): Collection
    {
        try {
            if (! EmailCampaign::tableAvailable()) {
                return collect();
            }

            return $this->oldestWaiting(
                EmailCampaign::query()->whereIn('status', $this->campaignAttentionStatuses())
            )
                ->take(5)
                ->get(['id', 'subject', 'status', 'created_at'])
                ->map(fn (EmailCampaign $campaign) => [
                    'id' => $campaign->id,
                    'label' => Str::limit((string) ($campaign->subject ?: 'Campaign'), 80),
                    'status' => $campaign->status,
                    'date' => $this->formatDate($campaign->created_at, 'd M Y'),
                    'age' => $this->ageLabel($campaign->created_at),
                    'url' => route('admin.campaigns.show', $campaign->id),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard campaign queue failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    private function libraryEvaluatingCount(): int
    {
        return $this->safeInt(function () {
            if (! Schema::hasTable('content_submissions')) {
                return 0;
            }

            return ContentSubmission::query()->evaluatingInLibrary()->count();
        });
    }

    private function campaignsAttentionCount(): int
    {
        return $this->safeInt(function () {
            if (! EmailCampaign::tableAvailable()) {
                return 0;
            }

            return EmailCampaign::query()
                ->whereIn('status', $this->campaignAttentionStatuses())
                ->count();
        });
    }

    /**
     * @return list<string>
     */
    private function campaignAttentionStatuses(): array
    {
        return [
            EmailCampaign::STATUS_QUEUED,
            EmailCampaign::STATUS_SENDING,
            EmailCampaign::STATUS_FAILED,
        ];
    }

    /**
     * Rows with no usable date sort after dated rows, so a blank timestamp
     * does not take a slot ahead of work that has actually been waiting.
     */
    private function sortStamp(mixed $value): int
    {
        $date = $this->parseDate($value);

        return $date ? $date->getTimestamp() : PHP_INT_MAX;
    }

    private function oldestWaiting($query, string $column = 'created_at')
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            $column = 'created_at';
        }

        return $query
            ->orderByRaw('CASE WHEN '.$column.' IS NULL THEN 1 ELSE 0 END')
            ->orderBy($column)
            ->orderBy('id');
    }

    private function paymentMethodLabel(string $method): string
    {
        $method = strtolower(trim($method));

        return match ($method) {
            'stripe' => 'card',
            'bank_transfer' => 'bank',
            '' => '',
            default => $method,
        };
    }

    private function ageLabel(mixed $value): ?string
    {
        $date = $this->parseDate($value);
        if (! $date) {
            return null;
        }

        try {
            return $date->diffForHumans();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatDate(mixed $value, string $format = 'd M Y'): string
    {
        $date = $this->parseDate($value);
        if (! $date) {
            return '';
        }

        try {
            return $date->format($format);
        } catch (\Throwable) {
            return '';
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function depositActionUrl(int $id): ?string
    {
        try {
            if ($id > 0 && class_exists(ManualDepositApproveLink::class) && method_exists(ManualDepositApproveLink::class, 'relativeUrl')) {
                return ManualDepositApproveLink::relativeUrl($id);
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard deposit action URL failed', ['error' => $e->getMessage()]);
        }

        return $this->safeRoute('admin.deposits', ['status' => 'pending']);
    }

    private function withdrawalActionUrl(int $id): ?string
    {
        try {
            if ($id > 0 && class_exists(ManualWithdrawalMarkPaidLink::class) && method_exists(ManualWithdrawalMarkPaidLink::class, 'relativeUrl')) {
                return ManualWithdrawalMarkPaidLink::relativeUrl($id);
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard withdrawal action URL failed', ['error' => $e->getMessage()]);
        }

        return $this->safeRoute('admin.withdrawals', ['queue' => 'open']);
    }

    private function safeRoute(string $name, mixed $parameters = []): ?string
    {
        try {
            return route($name, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }

    private function paidAtSql(): string
    {
        try {
            if (Schema::hasColumn('orders', 'paid_at')) {
                return 'COALESCE(paid_at, created_at)';
            }
        } catch (\Throwable) {
            // Hostinger leftover: fall back to created_at only.
        }

        return 'created_at';
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
            try {
                $indexed[Carbon::parse((string) $day)->toDateString()] = $total;
            } catch (\Throwable) {
                continue;
            }
        }

        return $indexed;
    }
}
