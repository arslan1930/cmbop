@extends('advertiser.layouts.app')

@section('title', 'Dashboard')

@section('content')

@php
    $stats = $stats ?? [
        'total' => 0,
        'completed' => 0,
        'in_progress' => 0,
        'cancelled' => 0,
        'needs_review' => 0,
        'needs_action' => 0,
        'awaiting_payment' => 0,
    ];
    $recentOrders = $recentOrders ?? collect();
    $recommendedSites = $recommendedSites ?? collect();
    $hasOrderableArticle = (bool) ($hasOrderableArticle ?? false);
    $isNewAdvertiser = (bool) ($isNewAdvertiser ?? (($stats['total'] ?? 0) === 0));
    $browseCatalogUrl = route('advertiser.catalog');
    $guidedFlowUrl = route('advertiser.wizard.start');
    $needsAction = (int) ($stats['needs_action'] ?? 0);
    $needsActionOrders = $needsActionOrders ?? collect();
    $awaitingPayment = (int) ($stats['awaiting_payment'] ?? 0);
    $upcomingScheduledCount = (int) ($upcomingScheduledCount ?? 0);
    $primaryAction = (string) ($primaryAction ?? 'catalog');
    $welcomeSituation = (string) ($welcomeSituation ?? '');
    $wallet = $wallet ?? ['spendable' => 0, 'available' => 0, 'bonus' => 0, 'currency' => 'EUR'];
    $budgetStatus = $budgetStatus ?? ['has_budget' => false, 'low_balance' => false];
    $spendSummary = $spendSummary ?? ['net' => 0, 'spent' => 0, 'in_progress' => 0];
    $spendCandles = $spendCandles ?? ['has_spend' => false, 'series' => []];
    $supportTelegramUrl = config('services.support.telegram_url', 'https://t.me/arslan_seolinkbuildings');
    $urlVisibility = app(\App\Services\Catalog\SiteUrlVisibility::class);
@endphp

<style>
.get-started-cta, .dash-primary-cta {
    background: var(--brand-primary, #1a585e); color: #fff; border: none;
    border-radius: 10px; padding: 12px 18px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
    transition: background-color .2s ease, transform .2s ease;
}
.get-started-cta:hover, .dash-primary-cta:hover {
    color: #fff; background: var(--brand-primary-deep, #123f42); transform: none;
}
.kpi-tile {
    display: flex; align-items: flex-start; gap: 12px; padding: 16px;
    border: 1px solid #e3e8ea; border-radius: 12px; background: #fff; height: 100%;
    transition: transform 200ms ease, box-shadow 200ms ease;
}
.kpi-tile:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(26, 88, 94, 0.08);
}
.kpi-tile .kpi-label { font-size: 12.5px; color: #55606a; display: block; }
.kpi-tile .kpi-value {
    font-size: 1.6rem; font-weight: 650; color: #1f2a2e; line-height: 1.15; margin: 2px 0;
}
.kpi-tile .kpi-hint { font-size: 11.5px; color: #55606a; line-height: 1.4; margin: 0; }
.next-action {
    display: flex; align-items: center; gap: 14px;
    padding: 14px; border: 1px solid #e3e8ea; border-radius: 10px;
    text-decoration: none; color: inherit; background: #fff;
    margin-bottom: 0;
    transition: transform 200ms ease, box-shadow 200ms ease, border-color 200ms ease;
}
.next-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
    border-color: #c9d9d9;
    color: inherit;
}
.next-action:active { transform: scale(0.97); }
.next-action .na-ic {
    width: 38px; height: 38px; border-radius: 10px; flex: none;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--brand-primary-bg, #eef6f6);
    color: var(--brand-primary, #1a585e);
}
.next-action .na-ic svg {
    width: 18px; height: 18px;
    stroke: currentColor; fill: none; stroke-width: 1.75;
    stroke-linecap: round; stroke-linejoin: round;
}
.next-action .na-body { min-width: 0; flex: 1 1 auto; }
.next-action .na-title { font-weight: 600; font-size: 14px; color: #1f2a2e; }
.next-action .na-desc { font-size: 12px; color: #55606a; margin: 2px 0 0; line-height: 1.45; }
.next-action .na-arr { color: var(--brand-primary, #1a585e); font-size: 18px; line-height: 1; flex: none; }
.next-action.next-action-quiet { background: transparent; border-style: dashed; }
.dash-next-actions { display: flex; flex-direction: column; gap: 12px; margin-bottom: 1rem; }
@media (prefers-reduced-motion: reduce) {
    .kpi-tile, .next-action { transition: none !important; }
    .kpi-tile:hover, .next-action:hover, .next-action:active { transform: none !important; box-shadow: none !important; }
}
.order-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #334155;
    text-transform: capitalize;
    background: none;
    border: none;
    padding: 0;
}
.order-status-dot {
    --status-dot: var(--brand-live, #0ea5e9);
    position: relative;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--status-dot);
    flex-shrink: 0;
}
.order-status-dot::after {
    content: "";
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    background: var(--status-dot);
    opacity: 0.35;
    animation: order-status-pulse 1.8s ease-out infinite;
}
.order-status.pending .order-status-dot { --status-dot: var(--brand-ink-muted, #75787B); }
.order-status.processing .order-status-dot,
.order-status.review .order-status-dot { --status-dot: var(--brand-live, #0ea5e9); }
.order-status.completed .order-status-dot { --status-dot: var(--brand-success, #0f766e); }
.order-status.cancelled .order-status-dot {
    --status-dot: #94a3b8;
}
.order-status.cancelled .order-status-dot::after,
.order-status.completed .order-status-dot::after {
    animation: none;
    opacity: 0;
}
@keyframes order-status-pulse {
    0% { transform: scale(0.7); opacity: 0.45; }
    70% { transform: scale(1.9); opacity: 0; }
    100% { transform: scale(1.9); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .order-status-dot::after { animation: none !important; opacity: 0; }
}
.recent-orders-glass {
    position: relative;
    flex: 0 0 auto;
    align-self: start;
    width: 100%;
    border-radius: 18px;
    border: 1px solid rgba(255, 255, 255, 0.55);
    background: linear-gradient(145deg, rgba(255,255,255,0.72), rgba(240,251,251,0.55));
    box-shadow:
        0 18px 40px rgba(26, 88, 94, 0.1),
        inset 0 1px 0 rgba(255,255,255,0.75);
    backdrop-filter: blur(16px) saturate(1.35);
    -webkit-backdrop-filter: blur(16px) saturate(1.35);
    overflow: hidden;
}
.recent-orders-glass::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 55% 40% at 12% 0%, rgba(63, 174, 178, 0.22), transparent 60%),
        radial-gradient(ellipse 45% 35% at 90% 100%, rgba(26, 88, 94, 0.08), transparent 55%);
    pointer-events: none;
}
.recent-orders-glass .card-body { position: relative; z-index: 1; }
.recent-orders-glass .table { --bs-table-bg: transparent; }
.recent-orders-glass .table > :not(caption) > * > * {
    background: transparent; border-bottom-color: rgba(26, 88, 94, 0.08);
}
.recent-orders-glass thead th {
    font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
    color: var(--brand-ink-muted, #75787B) !important; font-weight: 700; border-bottom-width: 1px;
}
.recent-orders-glass tbody tr {
    transition: background .2s ease;
}
.recent-orders-glass tbody tr:hover {
    background: rgba(255,255,255,0.45);
}
.recent-order-num {
    font-weight: 700; font-size: 15px; color: #1a585e; letter-spacing: .02em;
}
.recent-order-site {
    font-size: 13px; font-weight: 600; color: #1f2937; margin-top: 4px;
}
.recent-order-url {
    font-size: 12px; color: var(--brand-ink-muted, #75787B); text-decoration: none;
}
.recent-order-url:hover { color: #1a585e; }
.recent-orders-title {
    font-weight: 700; color: #1a585e; letter-spacing: -.01em;
}
.recent-orders-link {
    color: #1a585e; font-weight: 600; text-decoration: none;
}
.recent-orders-link:hover { color: #123f42; text-decoration: underline; }
.dash-icon-link {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: none; background: transparent;
    color: #1a585e; border-radius: 999px; text-decoration: none;
}
.dash-icon-link i { width: 18px; height: 18px; font-size: 18px; flex: 0 0 18px; }
.dash-icon-link:hover { background: rgba(26, 88, 94, 0.08); color: #123f42; }
.help-secondary {
    border: 1px dashed #d7e7e8; border-radius: 12px; padding: 16px;
    background: #fafcfc;
}
.recommended-sites { display: grid; gap: 10px; }
.recommended-site {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 10px;
    background: #fff; color: inherit;
    transition: border-color .15s ease, background .15s ease;
}
.recommended-site:hover { border-color: #cbd5e1; background: rgba(15, 23, 42, 0.03); }
.recommended-site .rs-name {
    font-weight: 400;
    font-size: 14px;
    color: #1a585e;
    text-decoration: none;
    text-underline-offset: 2px;
    word-break: break-all;
}
.recommended-site .rs-name:hover {
    color: #123f42;
    text-decoration: underline;
}
.recommended-site .rs-meta { font-size: 12px; color: var(--brand-ink-muted, #75787B); margin: 0; }
.recommended-site .rs-price {
    font-weight: 600;
    color: #1a585e;
    white-space: nowrap;
    text-decoration: none;
}
.recommended-site .rs-price:hover { color: #123f42; }
.dash-wallet-strip, .dash-spend-strip {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: stretch;
    padding: 12px 14px; margin: 0 4px 16px; border-radius: 12px;
    border: 1px solid #d9e7e8; background: rgba(255,255,255,.85);
}
.dash-wallet-strip .dw-item, .dash-spend-strip .dw-item {
    flex: 1 1 120px; min-width: 110px;
}
.dash-wallet-strip .dw-label, .dash-spend-strip .dw-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em;
    color: #6b7280; display: block;
}
.dash-wallet-strip .dw-value, .dash-spend-strip .dw-value {
    font-size: 1.2rem; font-weight: 700; color: #1a585e; line-height: 1.2;
}
.dash-wallet-strip .dw-warn {
    flex: 1 1 100%; font-size: 12px; color: #92400e; margin: 0;
    padding: 8px 10px; border-radius: 8px; background: #fffbeb; border: 1px solid #fde68a;
}
.dash-spend-chart-wrap {
    position: relative;
    width: 100%;
    height: 140px;
    margin-top: 4px;
}
.dash-spend-chart-wrap canvas { display: block; width: 100% !important; height: 100% !important; }
.dash-spend-chart-hint {
    font-size: 12px;
    color: #6b7280;
    margin: 4px 0 0;
}
.dash-spend-chart-fallback {
    font-size: 13px;
    color: #6b7280;
    margin-top: 8px;
}
.recent-order-row { cursor: pointer; text-decoration: none; color: inherit; }
.recent-order-row:hover { background: rgba(255,255,255,0.45); }
.recent-order-stepper { max-width: 320px; }
</style>

<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">Welcome back, {{ auth()->user()->name }}!</h1>
        <small class="text-muted">
            @if($isNewAdvertiser)
                Browse the catalog to buy placements — or use a guided flow if you prefer step-by-step help.
            @elseif($welcomeSituation !== '')
                {{ ucfirst($welcomeSituation) }}.
            @endif
        </small>
    </div>
    @unless($isNewAdvertiser)
        @if($primaryAction === 'needs_action')
            <a href="{{ route('advertiser.orders', ['status' => 'needs_action']) }}" class="dash-primary-cta" id="dashPrimaryCta">
                <i class="fa fa-clipboard-check"></i> Open orders
            </a>
        @elseif($primaryAction === 'awaiting_payment')
            <a href="{{ route('advertiser.orders', ['status' => 'awaiting_payment']) }}" class="dash-primary-cta" id="dashPrimaryCta">
                <i class="fa fa-credit-card"></i> Complete payment
            </a>
        @elseif($primaryAction === 'scheduled')
            <a href="{{ route('advertiser.scheduled-orders', ['tab' => 'upcoming']) }}" class="dash-primary-cta" id="dashPrimaryCta">
                <i class="fa fa-calendar"></i> Upcoming scheduled
            </a>
        @else
            <a href="{{ $browseCatalogUrl }}" class="dash-primary-cta" id="dashPrimaryCta">
                <i class="fa fa-store"></i> Browse catalog
            </a>
        @endif
    @endunless
</div>

@if($isNewAdvertiser)
    <div class="row g-4 dash-page-end">
        <div class="col-lg-7">
            <div class="dash-panel h-100">
                <h5 class="mb-1">Get started</h5>
                <p class="text-muted small mb-3">Pick publishers from the live catalog, assign an approved article in your cart, then pay.</p>
                <a href="{{ $browseCatalogUrl }}" class="get-started-cta w-100 justify-content-center mb-3">
                    <i class="fa fa-store"></i> Browse catalog
                </a>
                <p class="small text-muted text-center mb-2">
                    Prefer a guided flow?
                    <a href="{{ $guidedFlowUrl }}">Start guided placement</a>
                </p>
                <p class="small text-muted text-center mb-0">
                    <a href="{{ route('advertiser.content-library') }}">Content Library</a>
                    — upload articles before checkout
                </p>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dash-panel h-100 mb-3">
                <h6 class="mb-1">Recommended for you</h6>
                <p class="small text-muted mb-3">Top verified placements to start with.</p>
                @if($recommendedSites->isEmpty())
                    <x-ui.empty-state
                        class="py-2"
                        icon="fa-store"
                        title="Explore live inventory"
                        message="Open the catalog to find verified publishers for your first placement."
                        primary-label="Browse catalog"
                        :primary-url="route('advertiser.catalog')"
                    />
                @else
                    <div class="recommended-sites">
                        @foreach($recommendedSites as $site)
                            @include('advertiser.partials.dashboard-recommended-site', [
                                'site' => $site,
                                'urlVisibility' => $urlVisibility,
                                'showLanguage' => true,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="help-secondary">
                <h6 class="mb-2">Need a hand?</h6>
                <p class="small text-muted mb-3">Message your client manager if you get stuck on catalog or checkout.</p>
                <a href="{{ $supportTelegramUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary" data-slb-open-support>
                    <i class="fa fa-message me-1" aria-hidden="true"></i> Start chat
                </a>
            </div>
        </div>
    </div>
@else
<style>
.dash-command-surface {
    position: relative;
    border-radius: 20px;
    padding: 4px;
    background:
        radial-gradient(ellipse 50% 60% at 80% 20%, rgba(63, 174, 178, 0.18), transparent 55%),
        radial-gradient(ellipse 40% 50% at 10% 80%, rgba(26, 88, 94, 0.08), transparent 50%),
        linear-gradient(180deg, #e6f5f5 0%, #f8f9fa 100%);
}
</style>
<div class="dash-command-surface mb-4 dash-page-end">
    <div class="dash-wallet-strip">
        <div class="dw-item">
            <span class="dw-label">Spendable</span>
            <div class="dw-value">{{ format_money($wallet['spendable'] ?? 0) }}</div>
        </div>
        <div class="dw-item">
            <span class="dw-label">Available</span>
            <div class="dw-value">{{ format_money($wallet['available'] ?? 0) }}</div>
        </div>
        <div class="dw-item">
            <span class="dw-label">Bonus</span>
            <div class="dw-value">{{ format_money($wallet['bonus'] ?? 0) }}</div>
        </div>
        <div class="dw-item d-flex align-items-center">
            <a href="{{ route('advertiser.add-funds') }}" class="btn btn-sm btn-primary">
                @if(!empty($budgetStatus['low_balance']))
                    Top up — low balance
                @else
                    Add funds
                @endif
            </a>
        </div>
        @if(!empty($budgetStatus['low_balance']))
            <p class="dw-warn">
                Spendable is below your {{ format_money($budgetStatus['low_balance_threshold'] ?? 0) }} alert threshold.
            </p>
        @elseif(!empty($budgetStatus['monthly_limit']))
            <p class="dw-warn" style="background:#f0fbfb;border-color:#b8e4e4;color:#1a585e;">
                This month committed {{ format_money($budgetStatus['committed'] ?? 0) }}
                / {{ format_money($budgetStatus['monthly_limit']) }}
                ({{ number_format((float) ($budgetStatus['percent'] ?? 0), 1) }}%)
            </p>
        @endif
    </div>

    <!-- KPIs -->
    @php
        $kpiTotal = (int) ($stats['total'] ?? 0);
        $kpiCompleted = (int) ($stats['completed'] ?? 0);
        $kpiInProgress = (int) ($stats['in_progress'] ?? 0);
        $kpiCancelled = (int) ($stats['cancelled'] ?? 0);
    @endphp
    <div class="row g-3 mb-4 px-1 pt-1">
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div>
                    <span class="kpi-label">Total orders</span>
                    <div class="kpi-value" data-count-to="{{ $kpiTotal }}">{{ $kpiTotal }}</div>
                    <p class="kpi-hint">{{ $kpiTotal === 0 ? 'Your first order shows here' : 'All orders you\'ve placed' }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div>
                    <span class="kpi-label">Completed</span>
                    <div class="kpi-value" data-count-to="{{ $kpiCompleted }}">{{ $kpiCompleted }}</div>
                    <p class="kpi-hint">{{ $kpiCompleted === 0 ? 'Approved placements will show here' : 'Approved and finished' }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div>
                    <span class="kpi-label">In progress</span>
                    <div class="kpi-value" data-count-to="{{ $kpiInProgress }}">{{ $kpiInProgress }}</div>
                    <p class="kpi-hint">{{ $kpiInProgress === 0 ? 'Your first order shows here' : 'Publisher is working on these' }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div>
                    <span class="kpi-label">Cancelled</span>
                    <div class="kpi-value" data-count-to="{{ $kpiCancelled }}">{{ $kpiCancelled }}</div>
                    <p class="kpi-hint">{{ $kpiCancelled === 0 ? 'Nothing to resolve' : 'Orders you cancelled' }}</p>
                </div>
            </div>
        </div>
    </div>

    @if($needsActionOrders->isNotEmpty())
        <div class="dash-panel mb-4 mx-1" id="dashNeedsYouQueue">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h5 class="mb-0">Needs you</h5>
                <a href="{{ route('advertiser.orders', ['status' => 'needs_action']) }}" class="small fw-semibold">Open all</a>
            </div>
            <div class="d-flex flex-column gap-2">
                @foreach($needsActionOrders as $needOrder)
                    @php
                        $needItem = $needOrder->items->first();
                        $needSite = $needItem->site_name ?? ($needItem->site_url ?? 'Placement');
                    @endphp
                    <a href="{{ route('advertiser.orders', ['status' => 'needs_action']) }}" class="next-action border-warning">
                        <span class="na-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg></span>
                        <div class="na-body">
                            <div class="na-title">{{ $needOrder->order_number }} · {{ $needSite }}</div>
                            <p class="na-desc mb-0">{{ $needOrder->status_label }} — {{ $needOrder->next_action }}</p>
                        </div>
                        <span class="na-arr" aria-hidden="true">›</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="row g-4 mb-4">
        <!-- Next actions + recommended -->
        <div class="col-lg-4">
            <div class="dash-panel h-100">
                <h5 class="mb-3">Next actions</h5>
                @php
                    $naIcon = [
                        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>',
                        'upload' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/><path d="M5 19h14"/></svg>',
                        'file' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/></svg>',
                        'guide' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8"/><path d="M8 11h6"/></svg>',
                        'review' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
                        'pay' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
                        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>',
                        'wallet' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 7V5a2 2 0 0 0-2-2h-4"/><circle cx="16" cy="14" r="1.5"/></svg>',
                        'chart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 5-6"/></svg>',
                        'alert' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>',
                    ];
                @endphp
                <div class="dash-next-actions">
                    @if($needsAction > 0)
                        <a href="{{ route('advertiser.orders', ['status' => 'needs_action']) }}" class="next-action border-warning">
                            <span class="na-ic" aria-hidden="true">{!! $naIcon['alert'] !!}</span>
                            <div class="na-body">
                                <div class="na-title">Orders need attention</div>
                                <p class="na-desc">{{ $needsAction }} {{ $needsAction === 1 ? 'order needs' : 'orders need' }} a revised article or live-URL review</p>
                            </div>
                            <span class="na-arr" aria-hidden="true">›</span>
                        </a>
                        <a href="{{ $browseCatalogUrl }}" class="next-action next-action-quiet">
                            <span class="na-ic" aria-hidden="true">{!! $naIcon['search'] !!}</span>
                            <div class="na-body">
                                <div class="na-title">Browse catalog</div>
                                <p class="na-desc">Find more publishers when you are ready</p>
                            </div>
                            <span class="na-arr" aria-hidden="true">›</span>
                        </a>
                    @else
                        @if($awaitingPayment > 0)
                            <a href="{{ route('advertiser.orders', ['status' => 'awaiting_payment']) }}" class="next-action">
                                <span class="na-ic" aria-hidden="true">{!! $naIcon['pay'] !!}</span>
                                <div class="na-body">
                                    <div class="na-title">Complete payment</div>
                                    <p class="na-desc">{{ $awaitingPayment }} awaiting payment</p>
                                </div>
                                <span class="na-arr" aria-hidden="true">›</span>
                            </a>
                        @endif
                        @if($upcomingScheduledCount > 0)
                            <a href="{{ route('advertiser.scheduled-orders', ['tab' => 'upcoming']) }}" class="next-action" id="dashUpcomingScheduledAction">
                                <span class="na-ic" aria-hidden="true">{!! $naIcon['calendar'] !!}</span>
                                <div class="na-body">
                                    <div class="na-title">Upcoming scheduled</div>
                                    <p class="na-desc">{{ $upcomingScheduledCount }} {{ $upcomingScheduledCount === 1 ? 'publication' : 'publications' }} waiting — reschedule, publish now, or cancel</p>
                                </div>
                                <span class="na-arr" aria-hidden="true">›</span>
                            </a>
                        @endif
                        <a href="{{ $browseCatalogUrl }}" class="next-action">
                            <span class="na-ic" aria-hidden="true">{!! $naIcon['search'] !!}</span>
                            <div class="na-body">
                                <div class="na-title">Browse catalog</div>
                                <p class="na-desc">
                                    @if($hasOrderableArticle)
                                        You have an approved article ready — pick a publisher and assign it in cart
                                    @else
                                        Find publishers and add placements to your cart
                                    @endif
                                </p>
                            </div>
                            <span class="na-arr" aria-hidden="true">›</span>
                        </a>
                        @if($hasOrderableArticle)
                            <a href="{{ route('advertiser.content-library', ['status' => 'approved', 'availability' => 'available']) }}" class="next-action" id="dashOrderableLibraryAction">
                                <span class="na-ic" aria-hidden="true">{!! $naIcon['file'] !!}</span>
                                <div class="na-body">
                                    <div class="na-title">Content Library</div>
                                    <p class="na-desc">Review approved articles ready to place</p>
                                </div>
                                <span class="na-arr" aria-hidden="true">›</span>
                            </a>
                        @else
                            <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="next-action" id="dashUploadLibraryAction">
                                <span class="na-ic" aria-hidden="true">{!! $naIcon['upload'] !!}</span>
                                <div class="na-body">
                                    <div class="na-title">Upload an article</div>
                                    <p class="na-desc">Approve content in your library before checkout</p>
                                </div>
                                <span class="na-arr" aria-hidden="true">›</span>
                            </a>
                        @endif
                        <a href="{{ $guidedFlowUrl }}" class="next-action">
                            <span class="na-ic" aria-hidden="true">{!! $naIcon['guide'] !!}</span>
                            <div class="na-body">
                                <div class="na-title">Guided placement</div>
                                <p class="na-desc">Optional walkthrough: market → publishers → content → pay</p>
                            </div>
                            <span class="na-arr" aria-hidden="true">›</span>
                        </a>
                        <a href="{{ route('advertiser.orders') }}" class="next-action">
                            <span class="na-ic" aria-hidden="true">{!! $naIcon['review'] !!}</span>
                            <div class="na-body">
                                <div class="na-title">Review orders</div>
                                <p class="na-desc">
                                    @if($needsAction > 0)
                                        {{ $needsAction }} waiting for your review
                                    @elseif($kpiInProgress > 0)
                                        {{ $kpiInProgress }} in progress right now
                                    @else
                                        Track placements you’ve ordered
                                    @endif
                                </p>
                            </div>
                            <span class="na-arr" aria-hidden="true">›</span>
                        </a>
                        <a href="{{ route('advertiser.add-funds') }}" class="next-action">
                            <span class="na-ic" aria-hidden="true">{!! $naIcon['wallet'] !!}</span>
                            <div class="na-body">
                                <div class="na-title">Add funds</div>
                                <p class="na-desc">
                                    @if(!empty($budgetStatus['low_balance']))
                                        Spendable is below your alert — top up to keep checkout ready
                                    @else
                                        Top up to keep checkout ready
                                    @endif
                                </p>
                            </div>
                            <span class="na-arr" aria-hidden="true">›</span>
                        </a>
                        <a href="{{ route('advertiser.analytics') }}" class="next-action">
                            <span class="na-ic" aria-hidden="true">{!! $naIcon['chart'] !!}</span>
                            <div class="na-body">
                                <div class="na-title">Spending history</div>
                                <p class="na-desc">See completed and in-progress spend over time</p>
                            </div>
                            <span class="na-arr" aria-hidden="true">›</span>
                        </a>
                    @endif
                </div>
                @if($recommendedSites->isNotEmpty())
                    <h6 class="mb-2">Recommended</h6>
                    <div class="recommended-sites">
                        @foreach($recommendedSites as $site)
                            @include('advertiser.partials.dashboard-recommended-site', [
                                'site' => $site,
                                'urlVisibility' => $urlVisibility,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Recent orders + spend -->
        <div class="col-lg-8 dash-recent-col">
            <div class="dash-spend-strip mb-3">
                <div class="dw-item">
                    <span class="dw-label">Net spend</span>
                    <div class="dw-value">{{ format_money($spendSummary['net'] ?? 0) }}</div>
                </div>
                <div class="dw-item">
                    <span class="dw-label">Spent</span>
                    <div class="dw-value">{{ format_money($spendSummary['spent'] ?? 0) }}</div>
                </div>
                <div class="dw-item">
                    <span class="dw-label">In progress</span>
                    <div class="dw-value">{{ format_money($spendSummary['in_progress'] ?? 0) }}</div>
                </div>
                <div class="dw-item d-flex align-items-center">
                    <a href="{{ route('advertiser.analytics', ['view' => 'day']) }}" class="dash-icon-link" aria-label="Full history" title="Full history">
                        <i class="fa fa-history" aria-hidden="true"></i>
                    </a>
                </div>
                @if(!empty($spendCandles['has_spend']))
                    <div class="w-100">
                        <p class="dash-spend-chart-hint" id="dashSpendChartHint">Solid = completed · Dim = still in progress</p>
                        <div class="dash-spend-chart-wrap" id="dashSpendChartWrap">
                            <canvas id="dashSpendChart" aria-label="Spend over recent days"></canvas>
                        </div>
                        <p class="dash-spend-chart-fallback d-none mb-0" id="dashSpendChartFallback" role="status">
                            Chart unavailable —
                            <a href="{{ route('advertiser.analytics', ['view' => 'day']) }}" class="dash-icon-link" aria-label="Full history" title="Full history"><i class="fa fa-history" aria-hidden="true"></i></a>
                        </p>
                    </div>
                @endif
            </div>
            <div class="recent-orders-glass">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 recent-orders-title">Recent orders</h5>
                        <a href="{{ route('advertiser.orders') }}" class="recent-orders-link">View all orders</a>
                    </div>
                    @if($recentOrders->isEmpty())
                        <x-ui.empty-state
                            icon="fa-receipt"
                            title="No orders yet"
                            message="When you buy placements from the catalog, they’ll show up here."
                            primary-label="Browse catalog"
                            :primary-url="route('advertiser.catalog')"
                            secondary-label="Content library"
                            :secondary-url="route('advertiser.content-library')"
                        />
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Status</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentOrders as $order)
                                        @php
                                            $firstItem = $order->items->first();
                                            $numericOrder = preg_replace('/\D+/', '', (string) ($order->order_number ?? '')) ?: (string) $order->id;
                                            $statusMeta = \App\Support\AdvertiserOrderStatus::meta($order);
                                            $statusLabel = $statusMeta['label'];
                                            $statusDotClass = ($statusMeta['stage'] ?? '') === 'url_delivered'
                                                ? 'review'
                                                : (($statusMeta['stage'] ?? '') === 'review' ? 'pending' : (string) $order->status);
                                            $orderFocusUrl = route('advertiser.orders', ['focus' => 'order', 'order' => $order->id]);
                                            $siteModel = $firstItem?->relationLoaded('site') ? $firstItem->site : null;
                                            $canSeeRecentUrl = $siteModel
                                                ? $urlVisibility->canSee(auth()->user(), $siteModel)
                                                : false;
                                            $recentDisplayHost = $siteModel
                                                ? $urlVisibility->hostFor(auth()->user(), $siteModel)
                                                : null;
                                            $lifecycleStepper = \App\Support\AdvertiserOrderStatus::lifecycleStepper($order, $firstItem);
                                        @endphp
                                        <tr class="recent-order-row" onclick="window.location='{{ $orderFocusUrl }}'">
                                            <td class="py-3">
                                                <a href="{{ $orderFocusUrl }}" class="recent-order-num text-decoration-none">#{{ $numericOrder }}</a>
                                                @if(filled($firstItem->site_name ?? null))
                                                    <div class="recent-order-site">{{ $firstItem->site_name }}</div>
                                                @endif
                                                @if($canSeeRecentUrl && $recentDisplayHost && $firstItem?->site_id)
                                                    <a href="{{ route('advertiser.catalog.visit', $firstItem->site_id) }}"
                                                       target="_blank" rel="noopener" class="recent-order-url"
                                                       onclick="event.stopPropagation()">
                                                        {{ \Illuminate\Support\Str::limit($recentDisplayHost, 48) }}
                                                        <i class="fa fa-external-link fa-xs"></i>
                                                    </a>
                                                @elseif($recentDisplayHost)
                                                    <div class="recent-order-url">{{ \Illuminate\Support\Str::limit($recentDisplayHost, 48) }}</div>
                                                @endif
                                                @if(($order->items->count() ?? 0) > 1)
                                                    <div class="small text-muted mt-1">+{{ $order->items->count() - 1 }} more site{{ $order->items->count() - 1 === 1 ? '' : 's' }}</div>
                                                @endif
                                                <div class="small text-muted mt-1">{{ $order->created_at?->format('M j, Y') }}</div>
                                                <div class="recent-order-stepper" onclick="event.stopPropagation()">
                                                    @include('partials.order-lifecycle-stepper', ['stepper' => $lifecycleStepper])
                                                </div>
                                            </td>
                                            <td class="py-3">
                                                <span class="order-status {{ $statusDotClass }}">
                                                    <span class="order-status-dot" aria-hidden="true"></span>
                                                    {{ $statusLabel }}
                                                </span>
                                            </td>
                                            <td class="text-end py-3 fw-semibold" style="color:#1f2a2e;">
                                                {{ format_money($order->total_amount) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="help-secondary mx-1 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <strong>Need assistance?</strong>
                <span class="text-muted small ms-1">Client manager ·
                    <span data-support-hours data-utc-start="09:00" data-utc-end="18:00" data-days="Mon–Fri">Mon–Fri, 9AM–6PM UTC</span>
                </span>
            </div>
            <a href="{{ $supportTelegramUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary" data-slb-open-support>
                <i class="fa fa-message me-1"></i> Start chat
            </a>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script src="{{ asset('js/support-hours.js') }}?v={{ @filemtime(public_path('js/support-hours.js')) ?: '1' }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('[data-count-to]').forEach(function (el) {
        var target = Number(el.getAttribute('data-count-to') || 0);
        if (!Number.isFinite(target)) return;
        if (reduce || target === 0) {
            el.textContent = String(target);
            return;
        }
        var start = 0;
        var duration = 700;
        var t0 = null;
        function tick(ts) {
            if (t0 === null) t0 = ts;
            var p = Math.min(1, (ts - t0) / duration);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = String(Math.round(start + (target - start) * eased));
            if (p < 1) window.requestAnimationFrame(tick);
        }
        el.textContent = '0';
        window.requestAnimationFrame(tick);
    });

    document.querySelectorAll('[data-slb-stepper]').forEach(function (stepper) {
        var fill = stepper.querySelector('.slb-stepper-fill');
        if (!fill) return;
        var width = fill.style.getPropertyValue('--slb-stepper-fill') || fill.style.width || '0%';
        fill.style.width = '0%';
        fill.style.setProperty('--slb-stepper-fill', '0%');
        window.requestAnimationFrame(function () {
            fill.style.setProperty('--slb-stepper-fill', width);
            fill.style.width = width;
        });
    });
});
</script>
@if(!($isNewAdvertiser ?? false) && !empty($spendCandles['has_spend']))
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('dashSpendChart');
    const wrap = document.getElementById('dashSpendChartWrap');
    const hint = document.getElementById('dashSpendChartHint');
    const fallback = document.getElementById('dashSpendChartFallback');

    function showChartFallback() {
        if (wrap) wrap.classList.add('d-none');
        if (hint) hint.classList.add('d-none');
        if (fallback) fallback.classList.remove('d-none');
    }

    if (!canvas || typeof Chart === 'undefined') {
        showChartFallback();
        return;
    }

    const rows = @json($spendCandles['series'] ?? []);

    function money(n) {
        const v = Number(n || 0);
        return (window.slbFormatMoney || function (x) { return '€' + Number(x).toFixed(2); })(v);
    }

    try {
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(r => r.short_label || r.label),
                datasets: [
                    {
                        label: 'Spent (completed)',
                        data: rows.map(r => Number(r.spent || 0)),
                        backgroundColor: 'rgba(26, 88, 94, 0.88)',
                        hoverBackgroundColor: 'rgba(26, 88, 94, 1)',
                        stack: 'spend',
                        maxBarThickness: 28,
                        borderRadius: 4,
                    },
                    {
                        label: 'In progress',
                        data: rows.map(r => Number(r.in_progress || 0)),
                        backgroundColor: 'rgba(26, 88, 94, 0.28)',
                        hoverBackgroundColor: 'rgba(63, 174, 178, 0.55)',
                        borderColor: 'rgba(26, 88, 94, 0.35)',
                        borderWidth: 1,
                        stack: 'spend',
                        maxBarThickness: 28,
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            title: (items) => {
                                const row = rows[items[0]?.dataIndex];
                                return row?.label || '';
                            },
                            label: (item) => {
                                const row = rows[item.dataIndex];
                                if (item.datasetIndex === 0) {
                                    return money(item.raw) + ' spent'
                                        + ' (' + (row?.spent_orders || 0) + ' completed)';
                                }
                                if (!item.raw) return null;
                                return money(item.raw) + ' in progress — adds to spent when completed'
                                    + ' (' + (row?.in_progress_orders || 0) + ' orders)';
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { callback: (v) => (window.slbFormatMoney || function (x) { return '€' + x; })(v) },
                    },
                },
            },
        });
    } catch (err) {
        showChartFallback();
    }
});
</script>
@endif
@endpush
