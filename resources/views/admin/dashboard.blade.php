@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    @include('admin.partials.page-header', [
        'title' => 'Admin Dashboard',
        'subtitle' => 'Platform overview, money flow, and items that need your attention.',
    ])

    {{-- Moderation being off changes nothing visible anywhere else: articles are
         approved, orders go through, and the scan log fills with passes. Nobody
         visits the moderation screen to check something they believe is running,
         so it has to say so here. --}}
    @php
        $moderationOff = ! app(\App\Services\ContentModeration\ContentModerationService::class)->isEnabled();
        $opsAlerts = app(\App\Support\ProductionReadiness::class)->dashboardAlerts();
    @endphp
    @if($opsAlerts !== [])
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="fa fa-server mt-1" aria-hidden="true"></i>
            <div>
                <strong>Production is misconfigured.</strong>
                Register, verify-email, catalog images, or chat mail can fail silently until this is fixed.
                <ul class="mb-1 mt-2">
                    @foreach($opsAlerts as $opsAlert)
                        <li>
                            {{ $opsAlert['title'] }}
                            @if($opsAlert['detail'] !== '')
                                — {{ $opsAlert['detail'] }}
                            @endif
                        </li>
                    @endforeach
                </ul>
                The next production page view repairs migrate, MEDIA_PATH, APP_URL, and the storage link automatically.
                Or run <code>php artisan ops:production-ready --repair</code>.
            </div>
        </div>
    @endif
    @if($moderationOff)
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="fa fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div>
                <strong>Content moderation is switched off.</strong>
                No article is being scanned, so casino, adult and every other restricted
                category is passing straight through to checkout.
                <a href="{{ route('admin.moderation.index') }}" class="alert-link">Turn it back on</a>.
            </div>
        </div>
    @endif

    <!-- KPI cards -->
    <div class="row g-3 mb-4">
        <div class="col-12 d-none" id="kpiRetry"></div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.users.index') }}">
                <div class="card-body">
                    <div class="text-muted small">Total Users</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiUsers">—</h3>
                        <span class="badge bg-primary-subtle text-primary" id="kpiUsers7d">+0 / 7d</span>
                    </div>
                    <div class="small text-muted mt-1 js-kpi-users-caption">All accounts. Role counts can overlap.</div>
                    <div class="small text-muted mt-1">
                        <span id="kpiAdvertisers">0</span> advertisers ·
                        <span id="kpiPublishers">0</span> publishers ·
                        <span id="kpiAdmins">0</span> admins ·
                        <span id="kpiMarketers">0</span> marketing
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance') }}">
                <div class="card-body">
                    <div class="text-muted small">GMV (paid orders)</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiRevenue">—</h3>
                        <span class="badge bg-success-subtle text-success" id="kpiRevenue7d">€0 / 7d</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiPaidOrders">0</span> paid orders
                        · Margin &amp; wallets
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.sites.records') }}">
                <div class="card-body">
                    <div class="text-muted small">Sites</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiSites">—</h3>
                        <span class="badge bg-warning-subtle text-warning" id="kpiUnverified">0 in review</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiVerified">0</span> live in catalog
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="#dashboardActionQueues">
                <div class="card-body">
                    <div class="text-muted small">Needs Attention</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiAttention">—</h3>
                        <span class="badge bg-danger-subtle text-danger">Action queue</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiDeposits">0</span> deposits ·
                        <span id="kpiWithdrawals">0</span> withdrawals ·
                        <span id="kpiPayments">0</span> unpaid ·
                        <span id="kpiSitesReview">0</span> sites ·
                        <span id="kpiCommunity">0</span> community ·
                        <span id="kpiDisputes">0</span> disputes ·
                        <span id="kpiStalled">0</span> stalled
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Finance strip (same numbers as /admin/finance) -->
    <div class="row g-3 mb-4">
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong class="text-muted small"><span class="text-uppercase">Finance</span> <span id="financePeriod" class="fw-normal"></span></strong>
            <a href="{{ route('admin.finance') }}" class="small">Open finance</a>
        </div>
        <div class="col-12 d-none" id="financeRetry"></div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.withdrawals', ['queue' => 'open']) }}">
                <div class="card-body py-3">
                    <div class="text-muted small">Due to pay now</div>
                    <div class="fs-4 fw-semibold text-danger" id="financeDueNow">—</div>
                    <div class="small text-muted">Open withdrawal requests · <a href="{{ route('admin.withdrawals', ['queue' => 'open']) }}" class="link-secondary">Payout queue</a></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance') }}">
                <div class="card-body py-3">
                    <div class="text-muted small">In publisher wallets</div>
                    <div class="fs-4 fw-semibold" id="financeInWallets">—</div>
                    <div class="small text-muted">Earned, not requested</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance') }}">
                <div class="card-body py-3">
                    <div class="text-muted small">Total publisher liability</div>
                    <div class="fs-4 fw-semibold" id="financeLiability">—</div>
                    <div class="small text-muted">Due now + wallets</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance') }}">
                <div class="card-body py-3">
                    <div class="text-muted small">Margin (this month)</div>
                    <div class="fs-4 fw-semibold" id="financeMargin">—</div>
                    <div class="small text-muted">Fees − refunded fees − bonuses</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action queues (first viewport priority) -->
    <div id="dashboardActionQueues">
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-wallet me-2 text-success"></i>Pending Deposits</strong>
                    <a href="{{ route('admin.deposits', ['status' => 'pending']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>User</th><th>Amount</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueDeposits">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-money-bill-wave me-2 text-warning"></i>Pending Withdrawals</strong>
                    <a href="{{ route('admin.withdrawals', ['queue' => 'open']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>User</th><th>Amount</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueWithdrawals">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-globe me-2 text-primary"></i>Sites Awaiting Verify</strong>
                    <a href="{{ route('admin.sites.index', ['needs_review' => 1]) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Site</th><th>Publisher</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueSites">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-money-bill me-2 text-info"></i>Unpaid orders</strong>
                    <a href="{{ route('admin.payments', ['payment_status' => 'unpaid']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Order</th><th>Amount</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueUnpaid">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-gavel me-2 text-danger"></i>Open disputes</strong>
                    <a href="{{ route('admin.orders.index', ['dispute' => 'open']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Order</th><th>Reason</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueDisputes">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-comments me-2 text-secondary"></i>Community inbox</strong>
                    <a href="{{ route('admin.community.index', ['status' => 'pending']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Type</th><th>Item</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueCommunity">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-chart-line me-2 text-warning"></i>Enrichment failed</strong>
                    <a href="{{ route('admin.site-enrichment.index') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Site</th><th>Error</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueEnrichment">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Orders the reminder cadence could not rescue. Hidden entirely when the
         queue is empty so an untouched panel is not a permanent fixture.
         Kept inside #dashboardActionQueues so Needs Attention scrolls here too. --}}
    <div class="row g-3 mb-4 d-none" id="stalledOrdersRow">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-triangle-exclamation me-2 text-danger"></i>Stalled orders <span class="badge text-bg-danger ms-1" id="stalledOrdersCount">0</span></strong>
                    <span class="text-muted small">Remind the publisher, or open the order to refund.</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Order</th>
                                    <th>Site</th>
                                    <th>Publisher</th>
                                    <th>Advertiser</th>
                                    <th>Problem</th>
                                    <th>Late by</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="queueStalled">
                                <tr><td colspan="7" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong><i class="fa fa-chart-line me-2 text-primary"></i>Revenue &amp; Orders (<span class="js-chart-range-label">30 days</span>)</strong>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small">Paid revenue vs paid order volume</span>
                        <div class="btn-group btn-group-sm js-chart-range" role="group" aria-label="Chart range">
                            <button type="button" class="btn btn-outline-secondary" data-days="7">7</button>
                            <button type="button" class="btn btn-primary" data-days="30">30</button>
                            <button type="button" class="btn btn-outline-secondary" data-days="90">90</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="110"></canvas>
                    <div id="trendRetry" class="d-none text-center text-muted py-2"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-user-plus me-2 text-success"></i>New Signups (<span class="js-chart-range-label">30 days</span>)</strong>
                </div>
                <div class="card-body">
                    <canvas id="signupChart" height="110"></canvas>
                    <div id="signupRetry" class="d-none text-center text-muted py-2"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-shopping-cart me-2 text-info"></i>Orders by Status</strong>
                </div>
                <div class="card-body d-flex justify-content-center">
                    <canvas id="orderStatusChart" style="max-height:260px;"></canvas>
                    <div id="orderStatusRetry" class="d-none text-center text-muted py-2 align-self-center"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-users me-2 text-secondary"></i>Users by Role</strong>
                </div>
                <div class="card-body d-flex justify-content-center">
                    <canvas id="roleChart" style="max-height:260px;"></canvas>
                    <div id="roleRetry" class="d-none text-center text-muted py-2 align-self-center"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Promotions widget (below attention work) -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="text-muted small mb-1"><i class="fa fa-bullhorn me-1 text-primary"></i>Promotions Center</div>
                            <h5 class="mb-1">Announcements &amp; Ad Banners</h5>
                            <p class="text-muted mb-0 small">
                                Control discounts, platform changes, and sized website banners from one place.
                            </p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('admin.promotions.index') }}" class="btn btn-outline-secondary btn-sm">
                                Open Promotions
                            </a>
                        </div>
                    </div>
                    @php
                        $promoStats = app(\App\Services\PromotionService::class)->dashboardStats();
                    @endphp
                    <div class="row g-3 mt-2">
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Live announcements</div>
                                <div class="fs-4 fw-semibold">{{ $promoStats['announcements_live'] }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Live banners</div>
                                <div class="fs-4 fw-semibold">{{ $promoStats['banners_live'] }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Banner impressions</div>
                                <div class="fs-4 fw-semibold">{{ number_format($promoStats['banner_impressions']) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Banner clicks</div>
                                <div class="fs-4 fw-semibold">{{ number_format($promoStats['banner_clicks']) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="{{ asset('js/chart.umd.min.js') }}?v={{ @filemtime(public_path('js/chart.umd.min.js')) ?: '1' }}"></script>
<script>
const money = (n) => '€' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (n) => Number(n || 0).toLocaleString();

let trendChart, signupChart, orderStatusChart, roleChart;
let chartDays = 30;

async function dashboardFetch(url) {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    });
    let json = null;
    try {
        json = await res.json();
    } catch (e) {
        json = null;
    }
    if (!res.ok || !json || !json.success) {
        const message = (json && json.message) ? json.message : 'Could not load this panel';
        if (window.showAppToast) {
            window.showAppToast(message, 'error');
        }
        throw new Error(message);
    }
    return json;
}

function retryLink(loaderName) {
    return `Couldn’t load —
        <button type="button" class="btn btn-link btn-sm p-0 align-baseline js-dashboard-retry" data-loader="${escapeHtml(loaderName)}">retry</button>`;
}

function retryRow(cols, loaderName) {
    return `<tr><td colspan="${cols}" class="text-center text-muted py-3">${retryLink(loaderName)}</td></tr>`;
}

function showRetry(el, loaderName) {
    if (!el) return;
    el.innerHTML = retryLink(loaderName);
    el.classList.remove('d-none');
}

function hideRetry(el) {
    if (!el) return;
    el.classList.add('d-none');
    el.innerHTML = '';
}

function makeChart(existing, canvasId, config) {
    if (existing) {
        existing.destroy();
    }
    if (typeof Chart === 'undefined') {
        throw new Error('Charts unavailable');
    }
    return new Chart(document.getElementById(canvasId), config);
}

async function loadStatistics() {
    const retryEl = document.getElementById('kpiRetry');
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.statistics') }}`);
        const d = json.data;

        document.getElementById('kpiUsers').textContent = num(d.total_users);
        document.getElementById('kpiUsers7d').textContent = '+' + num(d.new_users_7d) + ' / 7d';
        document.getElementById('kpiAdvertisers').textContent = num(d.advertisers);
        document.getElementById('kpiPublishers').textContent = num(d.publishers);
        document.getElementById('kpiAdmins').textContent = num(d.admins);
        document.getElementById('kpiMarketers').textContent = num(d.marketers);
        document.getElementById('kpiRevenue').textContent = money(d.revenue);
        document.getElementById('kpiRevenue7d').textContent = money(d.revenue_7d) + ' / 7d';
        document.getElementById('kpiPaidOrders').textContent = num(d.paid_orders);
        document.getElementById('kpiSites').textContent = num(d.total_sites);
        document.getElementById('kpiVerified').textContent = num(d.live_sites ?? d.verified_sites);
        document.getElementById('kpiUnverified').textContent = num(d.unverified_sites) + ' in review';
        document.getElementById('kpiDeposits').textContent = num(d.pending_deposits);
        document.getElementById('kpiWithdrawals').textContent = num(d.pending_withdrawals);
        document.getElementById('kpiPayments').textContent = num(d.pending_payments);
        document.getElementById('kpiSitesReview').textContent = num(d.unverified_sites);
        document.getElementById('kpiCommunity').textContent = num(d.pending_community);
        document.getElementById('kpiDisputes').textContent = num(d.open_disputes);
        document.getElementById('kpiStalled').textContent = num(d.stalled_orders);
        document.getElementById('kpiAttention').textContent = num(d.needs_attention);
        hideRetry(retryEl);
    } catch (err) {
        showRetry(retryEl, 'loadStatistics');
        throw err;
    }
}

async function loadFinanceStrip() {
    const retryEl = document.getElementById('financeRetry');
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.finance') }}`);
        const d = json.data;
        document.getElementById('financePeriod').textContent = d.period_label ? '· ' + d.period_label : '';
        document.getElementById('financeDueNow').textContent = money(d.due_to_pay_now);
        document.getElementById('financeInWallets').textContent = money(d.in_publisher_wallets);
        document.getElementById('financeLiability').textContent = money(d.total_publisher_liability);
        document.getElementById('financeMargin').textContent = money(d.margin);
        hideRetry(retryEl);
    } catch (err) {
        showRetry(retryEl, 'loadFinanceStrip');
        throw err;
    }
}

async function loadTrends() {
    const retryEls = [
        document.getElementById('trendRetry'),
        document.getElementById('signupRetry'),
    ];
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.trends') }}?days=${chartDays}`);

        const commonOpts = {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: true, position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        };

        trendChart = makeChart(trendChart, 'trendChart', {
            type: 'line',
            data: {
                labels: json.labels,
                datasets: [
                    {
                        label: 'Revenue (€)',
                        data: json.revenue,
                        borderColor: '#1a585e',
                        backgroundColor: 'rgba(26, 88, 94, 0.12)',
                        fill: true,
                        tension: 0.35,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Paid orders',
                        data: json.orders,
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14, 165, 233, 0.08)',
                        fill: false,
                        tension: 0.35,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                ...commonOpts,
                scales: {
                    y:  { beginAtZero: true, position: 'left', title: { display: true, text: 'Revenue (€)' } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Paid orders' } }
                }
            }
        });

        signupChart = makeChart(signupChart, 'signupChart', {
            type: 'bar',
            data: {
                labels: json.labels,
                datasets: [{
                    label: 'New users',
                    data: json.signups,
                    backgroundColor: 'rgba(26, 88, 94, 0.75)',
                    borderRadius: 4
                }]
            },
            options: {
                ...commonOpts,
                plugins: { legend: { display: false } }
            }
        });
        retryEls.forEach(hideRetry);
    } catch (err) {
        retryEls.forEach((el) => showRetry(el, 'loadTrends'));
        throw err;
    }
}

async function loadDistributions() {
    const retryEls = [
        document.getElementById('orderStatusRetry'),
        document.getElementById('roleRetry'),
    ];
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.distributions') }}`);

        const palette = ['#1a585e', '#0ea5e9', '#3faeb2', '#75787B', '#0f766e', '#b8e4e4', '#94a3b8'];

        orderStatusChart = makeChart(orderStatusChart, 'orderStatusChart', {
            type: 'doughnut',
            data: {
                labels: json.orders.labels,
                datasets: [{
                    data: json.orders.values,
                    backgroundColor: palette
                }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });

        roleChart = makeChart(roleChart, 'roleChart', {
            type: 'doughnut',
            data: {
                labels: json.roles.labels,
                datasets: [{
                    data: json.roles.values,
                    backgroundColor: palette
                }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
        retryEls.forEach(hideRetry);
    } catch (err) {
        retryEls.forEach((el) => showRetry(el, 'loadDistributions'));
        throw err;
    }
}

function emptyRow(cols, msg) {
    return `<tr><td colspan="${cols}" class="text-center text-muted py-3">${escapeHtml(msg)}</td></tr>`;
}

function escapeHtml(str) {
    if (str == null || str === '') return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function cellLink(url, label) {
    const text = escapeHtml(label);
    if (!url) return text;
    return `<a href="${escapeHtml(url)}">${text}</a>`;
}

async function loadActionQueue() {
    const depBody = document.getElementById('queueDeposits');
    const wBody = document.getElementById('queueWithdrawals');
    const sBody = document.getElementById('queueSites');
    const unpaidBody = document.getElementById('queueUnpaid');
    const disputeBody = document.getElementById('queueDisputes');
    const communityBody = document.getElementById('queueCommunity');
    const enrichmentBody = document.getElementById('queueEnrichment');

    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.action-queue') }}`);
        const deposits = json.deposits || [];
        const withdrawals = json.withdrawals || [];
        const sites = json.sites || [];
        const unpaid = json.unpaid || [];
        const disputes = json.disputes || [];
        const community = json.community || [];
        const enrichment = json.enrichment || [];

        if (!deposits.length) {
            depBody.innerHTML = emptyRow(3, 'No pending deposits');
        } else {
            depBody.innerHTML = deposits.map(d => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(d.url, d.user)}</div>
                        <div class="small text-muted">${escapeHtml(d.email || '')}</div>
                    </td>
                    <td>${money(d.amount)}</td>
                    <td class="small text-muted">${escapeHtml(d.date)}</td>
                </tr>`).join('');
        }

        if (!withdrawals.length) {
            wBody.innerHTML = emptyRow(3, 'No pending withdrawals');
        } else {
            wBody.innerHTML = withdrawals.map(w => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(w.url, w.user)}</div>
                        <div class="small text-muted">${escapeHtml(w.email || '')}${w.status && w.status !== 'pending' ? ' · ' + escapeHtml(w.status) : ''}</div>
                    </td>
                    <td>${money(w.amount)}</td>
                    <td class="small text-muted">${escapeHtml(w.date)}</td>
                </tr>`).join('');
        }

        if (!sites.length) {
            sBody.innerHTML = emptyRow(3, 'No sites awaiting verification');
        } else {
            sBody.innerHTML = sites.map(s => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(s.url, s.site_name || '—')}</div>
                        <div class="small text-muted text-truncate" style="max-width:140px;">${escapeHtml(s.site_url || '')}</div>
                    </td>
                    <td>${escapeHtml(s.publisher)}</td>
                    <td class="small text-muted">${escapeHtml(s.date)}</td>
                </tr>`).join('');
        }

        if (!unpaid.length) {
            unpaidBody.innerHTML = emptyRow(3, 'No unpaid orders');
        } else {
            unpaidBody.innerHTML = unpaid.map(o => `
                <tr>
                    <td class="fw-semibold">${cellLink(o.url, '#' + o.order_number)}</td>
                    <td>${money(o.amount)}</td>
                    <td class="small text-muted">${escapeHtml(o.date)}</td>
                </tr>`).join('');
        }

        if (!disputes.length) {
            disputeBody.innerHTML = emptyRow(3, 'No open disputes');
        } else {
            disputeBody.innerHTML = disputes.map(d => `
                <tr>
                    <td class="fw-semibold">${cellLink(d.url, '#' + d.order_number)}</td>
                    <td class="small text-truncate" style="max-width:120px;">${escapeHtml(d.reason || '')}</td>
                    <td class="small text-muted">${escapeHtml(d.date)}</td>
                </tr>`).join('');
        }

        if (!community.length) {
            communityBody.innerHTML = emptyRow(3, 'Inbox is clear');
        } else {
            communityBody.innerHTML = community.map(c => `
                <tr>
                    <td><span class="badge text-bg-light">${escapeHtml(c.type)}</span></td>
                    <td>${cellLink(c.url, c.label)}</td>
                    <td class="small text-muted">${escapeHtml(c.date)}</td>
                </tr>`).join('');
        }

        if (!enrichment.length) {
            enrichmentBody.innerHTML = emptyRow(3, 'No failed scans');
        } else {
            enrichmentBody.innerHTML = enrichment.map(e => `
                <tr>
                    <td class="fw-semibold">${cellLink(e.url, e.site_name)}</td>
                    <td class="small text-truncate" style="max-width:120px;">${escapeHtml(e.error || '')}</td>
                    <td class="small text-muted">${escapeHtml(e.date)}</td>
                </tr>`).join('');
        }
    } catch (err) {
        depBody.innerHTML = retryRow(3, 'loadActionQueue');
        wBody.innerHTML = retryRow(3, 'loadActionQueue');
        sBody.innerHTML = retryRow(3, 'loadActionQueue');
        unpaidBody.innerHTML = retryRow(3, 'loadActionQueue');
        disputeBody.innerHTML = retryRow(3, 'loadActionQueue');
        communityBody.innerHTML = retryRow(3, 'loadActionQueue');
        enrichmentBody.innerHTML = retryRow(3, 'loadActionQueue');
        throw err;
    }
}

async function loadStalledOrders() {
    const row = document.getElementById('stalledOrdersRow');
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.stalled-orders') }}`);
        const items = json.items || [];
        if (!items.length) {
            row.classList.add('d-none');
            return;
        }

        row.classList.remove('d-none');
        document.getElementById('stalledOrdersCount').textContent = json.count;

        document.getElementById('queueStalled').innerHTML = items.map(i => `
            <tr>
                <td class="fw-semibold">${cellLink(i.order_url, '#' + i.order_number)}</td>
                <td>${escapeHtml(i.site_name)}</td>
                <td>
                    <div>${escapeHtml(i.publisher)}</div>
                    <div class="small text-muted">${escapeHtml(i.publisher_email || '')}</div>
                </td>
                <td>${escapeHtml(i.advertiser)}</td>
                <td><span class="badge text-bg-warning">${i.track === 'accept' ? 'Not accepted' : 'Not published'}</span></td>
                <td>
                    <div>${escapeHtml(i.late_label || (i.days_overdue + ' day(s)'))}</div>
                    <div class="small text-muted">${i.last_reminded_at ? 'Reminded ' + escapeHtml(i.last_reminded_at) : ''}</div>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary js-remind-publisher" data-item="${i.order_item_id}">
                            Remind now
                        </button>
                        ${i.order_url ? `<a href="${escapeHtml(i.order_url)}" class="btn btn-sm btn-outline-secondary">Open</a>` : ''}
                    </div>
                </td>
            </tr>`).join('');
    } catch (err) {
        row.classList.remove('d-none');
        document.getElementById('queueStalled').innerHTML = retryRow(7, 'loadStalledOrders');
        throw err;
    }
}

document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-remind-publisher');
    if (!btn) return;

    btn.disabled = true;
    btn.classList.add('is-loading');

    try {
        const res = await fetch(`{{ url('admin/orders/items') }}/${btn.dataset.item}/remind-publisher`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
        });
        const json = await res.json();
        btn.classList.remove('is-loading');

        if (json.success) {
            // A disabled button renders grey whatever colour class it carries, so
            // the confirmation is plain text rather than a button that looks
            // switched off at the moment it succeeded.
            btn.outerHTML = '<span class="text-success small fw-semibold">'
                + '<i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Reminder sent</span>';
        } else {
            btn.disabled = false;
            btn.textContent = 'Retry';
        }

        if (window.showAppToast) {
            window.showAppToast(json.message || (json.success ? 'Reminder sent' : 'Could not send the reminder'), json.success ? 'success' : 'error');
        }
    } catch (err) {
        btn.classList.remove('is-loading');
        btn.disabled = false;
        btn.textContent = 'Retry';
        if (window.showAppToast) {
            window.showAppToast('Could not send the reminder', 'error');
        }
    }
});

const dashboardLoaders = {
    loadStatistics,
    loadFinanceStrip,
    loadTrends,
    loadDistributions,
    loadActionQueue,
    loadStalledOrders,
};

function followKpiLink(card) {
    const href = card.getAttribute('data-href');
    if (!href) return;
    if (href.charAt(0) === '#') {
        const el = document.querySelector(href);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (history.replaceState) {
                history.replaceState(null, '', href);
            }
        }
        return;
    }
    window.location.href = href;
}

function setChartRange(days) {
    chartDays = days;
    document.querySelectorAll('.js-chart-range-label').forEach((el) => {
        el.textContent = days + ' days';
    });
    document.querySelectorAll('.js-chart-range [data-days]').forEach((btn) => {
        const active = Number(btn.dataset.days) === days;
        btn.classList.toggle('btn-primary', active);
        btn.classList.toggle('btn-outline-secondary', !active);
    });
}

document.addEventListener('click', (e) => {
    const retry = e.target.closest('.js-dashboard-retry');
    if (retry) {
        const loader = dashboardLoaders[retry.dataset.loader];
        if (typeof loader === 'function') {
            loader().catch(err => console.error('Dashboard retry failed', err));
        }
        return;
    }

    const rangeBtn = e.target.closest('.js-chart-range [data-days]');
    if (rangeBtn) {
        const days = Number(rangeBtn.dataset.days);
        if (!days || days === chartDays) return;
        setChartRange(days);
        loadTrends().catch(err => console.error('Dashboard range reload failed', err));
        return;
    }

    const kpi = e.target.closest('.js-kpi-link');
    if (!kpi || e.target.closest('a, button')) return;
    followKpiLink(kpi);
});

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const kpi = e.target.closest('.js-kpi-link');
    if (!kpi || e.target !== kpi) return;
    e.preventDefault();
    followKpiLink(kpi);
});

Promise.all([loadStatistics(), loadFinanceStrip(), loadTrends(), loadDistributions(), loadActionQueue(), loadStalledOrders()])
    .catch(err => console.error('Dashboard load failed', err));
</script>
@endsection
