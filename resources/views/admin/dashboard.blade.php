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
    @if(($opsAlerts ?? []) !== [])
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="fa fa-server mt-1" aria-hidden="true"></i>
            <div>
                <strong>Production is misconfigured.</strong>
                Register, verify-email, catalog images, or chat mail can fail silently until this is fixed.
                <ul class="mb-1 mt-2">
                    @foreach($opsAlerts as $opsAlert)
                        <li>
                            {{ $opsAlert['title'] ?? '' }}
                            @if(($opsAlert['detail'] ?? '') !== '')
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
    @if($moderationOff ?? false)
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
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance', ['period' => 'all']) }}">
                <div class="card-body">
                    <div class="text-muted small">GMV (paid orders)</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiRevenue">—</h3>
                        <span class="badge bg-success-subtle text-success" id="kpiRevenue7d">last 7 days</span>
                    </div>
                    <div class="small text-muted mt-2">
                        All-time euro order totals · <span id="kpiPaidOrders">0</span> paid
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
                    <div class="small text-muted mt-1">
                        <span id="kpiBulk">0</span> bulk ·
                        <span id="kpiMail">0</span> mail ·
                        <span id="kpiModeration">0</span> scans ·
                        <span id="kpiEnrichment">0</span> enrichment ·
                        <span id="kpiCatalogHide">0</span> hide-mode
                    </div>
                    <div class="small text-muted mt-1">
                        <span id="kpiMissingTax">0</span> invoices ·
                        <span id="kpiMissingPdf">0</span> PDFs ·
                        <span id="kpiLibrary">0</span> articles ·
                        <span id="kpiCampaigns">0</span> campaigns
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Finance strip (same numbers as /admin/finance) -->
    <div class="row g-3 mb-4">
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong class="text-muted small"><span class="text-uppercase">Finance</span> <span id="financePeriod" class="fw-normal"></span></strong>
            <a href="{{ route('admin.finance', ['period' => 'month']) }}" class="small">Open finance</a>
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
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance', ['period' => 'month']) }}">
                <div class="card-body py-3">
                    <div class="text-muted small">In publisher wallets</div>
                    <div class="fs-4 fw-semibold" id="financeInWallets">—</div>
                    <div class="small text-muted">Earned, not requested</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance', ['period' => 'month']) }}">
                <div class="card-body py-3">
                    <div class="text-muted small">Total publisher liability</div>
                    <div class="fs-4 fw-semibold" id="financeLiability">—</div>
                    <div class="small text-muted">Due now + wallets</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 cursor-pointer js-kpi-link" role="link" tabindex="0" data-href="{{ route('admin.finance', ['period' => 'month']) }}">
                <div class="card-body py-3">
                    <div class="text-muted small">Fee margin (this month)</div>
                    <div class="fs-4 fw-semibold" id="financeMargin">—</div>
                    <div class="small text-muted">Fees − fee reversals − bonuses</div>
                    <div class="small text-muted" id="financeCollected">Collected this month: —</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action queues (first viewport priority). Empty cards collapse like stalled orders. -->
    <div id="dashboardActionQueues">
    <div class="alert alert-success d-none mb-4" id="queuesAllClear" role="status">
        <i class="fa fa-circle-check me-1" aria-hidden="true"></i>
        All queues are clear. Nothing needs attention right now.
    </div>
    <div class="row g-3 mb-4 js-queue-row">
        <div class="col-12 col-lg js-queue-panel" data-queue="deposits">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-wallet me-2 text-success"></i>Pending Deposits <span class="text-muted fw-normal small" data-queue-meta="deposits"></span></strong>
                    <a href="{{ route('admin.deposits', ['status' => 'pending']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>User</th><th>Amount</th><th>Waiting</th><th class="text-end">Action</th></tr>
                            </thead>
                            <tbody id="queueDeposits">
                                <tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="withdrawals">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-money-bill-wave me-2 text-warning"></i>Pending Withdrawals <span class="text-muted fw-normal small" data-queue-meta="withdrawals"></span></strong>
                    <a href="{{ route('admin.withdrawals', ['queue' => 'open']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>User</th><th>Amount</th><th>Waiting</th><th class="text-end">Action</th></tr>
                            </thead>
                            <tbody id="queueWithdrawals">
                                <tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="sites">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-globe me-2 text-primary"></i>Sites Awaiting Verify <span class="text-muted fw-normal small" data-queue-meta="sites"></span></strong>
                    <a href="{{ route('admin.sites.index', ['needs_review' => 1]) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Site</th><th>Publisher</th><th>Waiting</th></tr>
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

    <div class="row g-3 mb-4 js-queue-row">
        <div class="col-12 col-lg js-queue-panel" data-queue="unpaid">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-money-bill me-2 text-info"></i>Unpaid orders <span class="text-muted fw-normal small" data-queue-meta="unpaid"></span></strong>
                    <a href="{{ route('admin.payments', ['payment_status' => 'unpaid']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Order</th><th>Amount</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueUnpaid">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="disputes">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-gavel me-2 text-danger"></i>Open disputes <span class="text-muted fw-normal small" data-queue-meta="disputes"></span></strong>
                    <a href="{{ route('admin.orders.index', ['dispute' => 'open']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Order</th><th>Reason</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueDisputes">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="community">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-comments me-2 text-secondary"></i>Community inbox <span class="text-muted fw-normal small" data-queue-meta="community"></span></strong>
                    <a href="{{ route('admin.community.index', ['status' => 'pending']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Type</th><th>Item</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueCommunity">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="enrichment">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-chart-line me-2 text-warning"></i>Enrichment failed <span class="text-muted fw-normal small" data-queue-meta="enrichment"></span></strong>
                    <a href="{{ route('admin.site-enrichment.index') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Site</th><th>Error</th><th>Waiting</th></tr>
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

    <div class="row g-3 mb-4 js-queue-row">
        <div class="col-12 col-lg js-queue-panel" data-queue="bulk">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-layer-group me-2 text-primary"></i>Bulk requests <span class="text-muted fw-normal small" data-queue-meta="bulk"></span></strong>
                    <a href="{{ route('admin.bulk-site-requests.index', ['status' => 'needs_marketer']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Publisher</th><th>Status</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueBulk">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="mail">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-envelope-open-text me-2 text-danger"></i>Failed mail <span class="text-muted fw-normal small" data-queue-meta="mail"></span></strong>
                    <a href="{{ route('admin.emails.index') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Job</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueMail">
                                <tr><td colspan="2" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="moderation">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-shield-alt me-2 text-danger"></i>Moderation errors <span class="text-muted fw-normal small" data-queue-meta="moderation"></span></strong>
                    <a href="{{ route('admin.moderation.index', ['status' => 'error']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Error</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueModeration">
                                <tr><td colspan="2" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="catalog_hide">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-eye-slash me-2 text-warning"></i>Catalog hide-mode <span class="text-muted fw-normal small" data-queue-meta="catalog_hide"></span></strong>
                    <a href="{{ route('admin.catalog-activity') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>User</th><th>Until</th></tr>
                            </thead>
                            <tbody id="queueCatalogHide">
                                <tr><td colspan="2" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 js-queue-row">
        <div class="col-12 col-lg js-queue-panel" data-queue="missing_tax">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-file-invoice me-2 text-danger"></i>Missing tax invoices <span class="text-muted fw-normal small" data-queue-meta="missing_tax"></span></strong>
                    <a href="{{ route('admin.invoices.index', ['queue' => 'missing']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Order</th><th>Amount</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueMissingTax">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="missing_pdf">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-file-pdf me-2 text-warning"></i>Missing PDFs <span class="text-muted fw-normal small" data-queue-meta="missing_pdf"></span></strong>
                    <a href="{{ route('admin.invoices.index', ['pdf' => 'missing']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Invoice</th><th>Amount</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueMissingPdf">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="library">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-book me-2 text-primary"></i>Articles in review <span class="text-muted fw-normal small" data-queue-meta="library"></span></strong>
                    <a href="{{ route('admin.content-library.index', ['availability' => 'evaluating']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Article</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueLibrary">
                                <tr><td colspan="2" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg js-queue-panel" data-queue="campaigns">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-bullhorn me-2 text-danger"></i>Campaigns <span class="text-muted fw-normal small" data-queue-meta="campaigns"></span></strong>
                    <a href="{{ route('admin.campaigns.index', ['status' => 'attention']) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Campaign</th><th>Status</th><th>Waiting</th></tr>
                            </thead>
                            <tbody id="queueCampaigns">
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
                        <span class="text-muted small">Paid-order euros by paid date</span>
                        <div class="btn-group btn-group-sm js-chart-range" role="group" aria-label="Chart range">
                            <button type="button" class="btn btn-outline-secondary" data-days="7">7</button>
                            <button type="button" class="btn btn-primary" data-days="30">30</button>
                            <button type="button" class="btn btn-outline-secondary" data-days="90">90</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="110"></canvas>
                    <div class="small text-muted mt-2">Rolling paid-order euros by paid date. A day on Finance uses recognized completion, so that total can differ from this point.</div>
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
                    <div class="small text-muted mt-1">Users with more than one role appear in more than one slice.</div>
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
                                Control site notices, platform changes, and sized website banners from one place.
                            </p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('admin.promotions.index') }}" class="btn btn-outline-secondary btn-sm">
                                Open Promotions
                            </a>
                        </div>
                    </div>
                    @php
                        $promoStats = [
                            'announcements_live' => 0,
                            'banners_live' => 0,
                            'banner_impressions' => 0,
                            'banner_clicks' => 0,
                        ];
                        try {
                            if (class_exists(\App\Services\PromotionService::class)
                                && method_exists(\App\Services\PromotionService::class, 'dashboardStats')) {
                                $loaded = app(\App\Services\PromotionService::class)->dashboardStats();
                                if (is_array($loaded)) {
                                    $promoStats = array_merge($promoStats, $loaded);
                                }
                            }
                        } catch (\Throwable) {
                            // Leftover Hostinger: missing promotions tables must not 500 the dashboard.
                        }
                    @endphp
                    <div class="row g-3 mt-2">
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Live announcements</div>
                                <div class="fs-4 fw-semibold">{{ (int) ($promoStats['announcements_live'] ?? 0) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Live banners</div>
                                <div class="fs-4 fw-semibold">{{ (int) ($promoStats['banners_live'] ?? 0) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Banner impressions</div>
                                <div class="fs-4 fw-semibold">{{ number_format((int) ($promoStats['banner_impressions'] ?? 0)) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Banner clicks</div>
                                <div class="fs-4 fw-semibold">{{ number_format((int) ($promoStats['banner_clicks'] ?? 0)) }}</div>
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
const financeDayUrl = @json(route('admin.finance'));
const ordersIndexUrl = @json(route('admin.orders.index'));
const usersIndexUrl = @json(route('admin.users.index'));
const queueTotalKeys = {
    deposits: 'pending_deposits',
    withdrawals: 'pending_withdrawals',
    sites: 'unverified_sites',
    unpaid: 'pending_payments',
    disputes: 'open_disputes',
    community: 'pending_community',
    enrichment: 'enrichment_failed',
    bulk: 'open_bulk_requests',
    mail: 'failed_mail',
    moderation: 'moderation_errors',
    catalog_hide: 'catalog_hide',
    missing_tax: 'missing_tax_invoices',
    missing_pdf: 'missing_pdf_invoices',
    library: 'library_evaluating',
    campaigns: 'campaigns_attention',
};

let trendChart, signupChart, orderStatusChart, roleChart;
let chartDays = 30;
let trendDates = [];

function goWithQuery(path, params) {
    const url = new URL(path, window.location.origin);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== '' && value != null) url.searchParams.set(key, value);
    });
    window.location.href = url.toString();
}

function chartPointer(event, elements) {
    const canvas = event.native && event.native.target;
    if (canvas) canvas.style.cursor = elements.length ? 'pointer' : 'default';
}

function applyAttentionCounts(d) {
    setText('kpiDeposits', num(d.pending_deposits));
    setText('kpiWithdrawals', num(d.pending_withdrawals));
    setText('kpiPayments', num(d.pending_payments));
    setText('kpiSitesReview', num(d.unverified_sites));
    setText('kpiCommunity', num(d.pending_community));
    setText('kpiDisputes', num(d.open_disputes));
    setText('kpiStalled', num(d.stalled_orders));
    setText('kpiBulk', num(d.open_bulk_requests));
    setText('kpiMail', num(d.failed_mail));
    setText('kpiModeration', num(d.moderation_errors));
    setText('kpiEnrichment', num(d.enrichment_failed));
    setText('kpiCatalogHide', num(d.catalog_hide));
    setText('kpiMissingTax', num(d.missing_tax_invoices));
    setText('kpiMissingPdf', num(d.missing_pdf_invoices));
    setText('kpiLibrary', num(d.library_evaluating));
    setText('kpiCampaigns', num(d.campaigns_attention));
    setText('kpiAttention', num(d.needs_attention));
}

function collectedLine(d) {
    const rows = Array.isArray(d.collected) ? d.collected : [];
    const parts = rows
        .filter((row) => row && row.currency)
        .map((row) => row.currency + ' ' + Number(row.amount || 0).toFixed(2));
    let text = parts.length ? 'Collected this month: ' + parts.join(' · ') : 'Collected this month: none';
    const notes = [];
    if (Number(d.orders_not_recorded) > 0) {
        notes.push(num(d.orders_not_recorded) + ' card or PayPal charges not recorded');
    }
    if (Number(d.features_not_recorded) > 0) {
        notes.push(num(d.features_not_recorded) + ' featured-site charges not recorded');
    }
    if (notes.length) text += ' · ' + notes.join(' · ');
    return text;
}

function setQueueMeta(name, shown, total) {
    const el = document.querySelector(`[data-queue-meta="${name}"]`);
    if (!el) return;
    const count = Number(total) || 0;
    el.textContent = count > shown ? (shown + ' of ' + count) : '';
}

function chargeLine(item) {
    const code = String(item && item.charge_currency || '').toUpperCase();
    if (!code || code === 'EUR' || item.charge_amount == null || item.charge_amount === '') return '';
    return `<div class="small text-muted">${escapeHtml(Number(item.charge_amount).toFixed(2) + ' ' + code)}</div>`;
}

function methodLine(item) {
    const label = (item && (item.method_label || item.method)) || '';
    if (!label) return '';
    return `<div class="small text-muted">${escapeHtml(label)}</div>`;
}

function rowMoney(item) {
    const code = String(item && item.currency || '').toUpperCase();
    if (code && code !== 'EUR') {
        return escapeHtml(Number(item.amount || 0).toFixed(2) + ' ' + code);
    }
    return money(item && item.amount);
}

async function dashboardFetch(url, options = {}) {
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
        if (!options.silent && window.showAppToast) {
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

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

async function loadStatistics() {
    const retryEl = document.getElementById('kpiRetry');
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.statistics') }}`);
        const d = json.data;

        setText('kpiUsers', num(d.total_users));
        setText('kpiUsers7d', '+' + num(d.new_users_7d) + ' / 7d');
        setText('kpiAdvertisers', num(d.advertisers));
        setText('kpiPublishers', num(d.publishers));
        setText('kpiAdmins', num(d.admins));
        setText('kpiMarketers', num(d.marketers));
        setText('kpiRevenue', money(d.revenue));
        setText('kpiRevenue7d', money(d.revenue_7d) + ' last 7 days');
        setText('kpiPaidOrders', num(d.paid_orders));
        setText('kpiSites', num(d.total_sites));
        setText('kpiVerified', num(d.live_sites ?? d.verified_sites));
        setText('kpiUnverified', num(d.unverified_sites) + ' in review');
        applyAttentionCounts(d);
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
        setText('financeCollected', collectedLine(d));
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
        trendDates = Array.isArray(json.dates) ? json.dates : [];

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
                onClick(event, elements) {
                    if (!elements.length) return;
                    const day = trendDates[elements[0].index];
                    if (!day) return;
                    goWithQuery(financeDayUrl, { date_from: day, date_to: day });
                },
                onHover: chartPointer,
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

        const orderKeys = (json.orders && json.orders.keys) || [];
        const roleKeys = (json.roles && json.roles.keys) || [];

        orderStatusChart = makeChart(orderStatusChart, 'orderStatusChart', {
            type: 'doughnut',
            data: {
                labels: json.orders.labels,
                datasets: [{
                    data: json.orders.values,
                    backgroundColor: palette
                }]
            },
            options: {
                plugins: { legend: { position: 'bottom' } },
                onClick(event, elements) {
                    if (!elements.length) return;
                    const status = orderKeys[elements[0].index];
                    if (!status) return;
                    goWithQuery(ordersIndexUrl, { status: status });
                },
                onHover: chartPointer,
            }
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
            options: {
                plugins: { legend: { position: 'bottom' } },
                onClick(event, elements) {
                    if (!elements.length) return;
                    const role = roleKeys[elements[0].index];
                    if (!role) return;
                    goWithQuery(usersIndexUrl, { role: role });
                },
                onHover: chartPointer,
            }
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

function waitingCell(item) {
    const age = item && item.age ? String(item.age) : '';
    const date = item && item.date ? String(item.date) : '';
    if (age && date) {
        return `<td class="small text-muted"><div class="text-nowrap">${escapeHtml(age)}</div><div class="text-nowrap">${escapeHtml(date)}</div></td>`;
    }
    if (age || date) {
        return `<td class="small text-muted text-nowrap">${escapeHtml(age || date)}</td>`;
    }
    return `<td class="small text-muted">—</td>`;
}

function actionCell(item) {
    if (!item || !item.action_url) return '<td></td>';
    const label = item.action_label || 'Open';
    return `<td class="text-end"><a href="${escapeHtml(item.action_url)}" class="btn btn-sm btn-outline-primary">${escapeHtml(label)}</a></td>`;
}

function setQueuePanel(name, hasItems) {
    const panel = document.querySelector(`.js-queue-panel[data-queue="${name}"]`);
    if (!panel) return;
    panel.classList.toggle('d-none', !hasItems);
}

function refreshQueueLayout() {
    document.querySelectorAll('.js-queue-row').forEach((row) => {
        const visible = [...row.querySelectorAll('.js-queue-panel')].some((panel) => !panel.classList.contains('d-none'));
        row.classList.toggle('d-none', !visible);
    });
    const stalled = document.getElementById('stalledOrdersRow');
    const anyQueue = [...document.querySelectorAll('#dashboardActionQueues .js-queue-panel')].some((panel) => !panel.classList.contains('d-none'));
    const stalledVisible = stalled && !stalled.classList.contains('d-none');
    const allClear = document.getElementById('queuesAllClear');
    if (allClear) {
        allClear.classList.toggle('d-none', anyQueue || stalledVisible);
    }
}

let actionQueueTick = 0;
let actionQueueInFlight = 0;

async function loadActionQueue(options = {}) {
    const quiet = options.quiet === true;
    // A badge refresh must not cancel the first load. If that first response
    // is dropped and the refresh then fails quietly, the tables stay on Loading.
    if (quiet && actionQueueInFlight > 0) return;
    const tick = ++actionQueueTick;
    actionQueueInFlight++;
    const depBody = document.getElementById('queueDeposits');
    const wBody = document.getElementById('queueWithdrawals');
    const sBody = document.getElementById('queueSites');
    const unpaidBody = document.getElementById('queueUnpaid');
    const disputeBody = document.getElementById('queueDisputes');
    const communityBody = document.getElementById('queueCommunity');
    const enrichmentBody = document.getElementById('queueEnrichment');
    const bulkBody = document.getElementById('queueBulk');
    const mailBody = document.getElementById('queueMail');
    const moderationBody = document.getElementById('queueModeration');
    const catalogBody = document.getElementById('queueCatalogHide');
    const taxBody = document.getElementById('queueMissingTax');
    const pdfBody = document.getElementById('queueMissingPdf');
    const libraryBody = document.getElementById('queueLibrary');
    const campaignBody = document.getElementById('queueCampaigns');

    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.action-queue') }}`, { silent: quiet });
        if (tick !== actionQueueTick) return;
        const deposits = json.deposits || [];
        const withdrawals = json.withdrawals || [];
        const sites = json.sites || [];
        const unpaid = json.unpaid || [];
        const disputes = json.disputes || [];
        const community = json.community || [];
        const enrichment = json.enrichment || [];
        const bulk = json.bulk || [];
        const mail = json.mail || [];
        const moderation = json.moderation || [];
        const catalogHide = json.catalog_hide || [];
        const missingTax = json.missing_tax || [];
        const missingPdf = json.missing_pdf || [];
        const library = json.library || [];
        const campaigns = json.campaigns || [];
        const totals = json.totals || {};
        const meta = (name, shown) => setQueueMeta(name, shown, totals[queueTotalKeys[name]]);

        setQueuePanel('deposits', deposits.length > 0);
        meta('deposits', deposits.length);
        if (!deposits.length) {
            depBody.innerHTML = emptyRow(4, 'No pending deposits');
        } else {
            depBody.innerHTML = deposits.map(d => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(d.url, d.user)}</div>
                        <div class="small text-muted">${escapeHtml(d.email || '')}</div>
                    </td>
                    <td>${money(d.amount)}${chargeLine(d)}${methodLine(d)}</td>
                    ${waitingCell(d)}
                    ${actionCell(d)}
                </tr>`).join('');
        }

        setQueuePanel('withdrawals', withdrawals.length > 0);
        meta('withdrawals', withdrawals.length);
        if (!withdrawals.length) {
            wBody.innerHTML = emptyRow(4, 'No pending withdrawals');
        } else {
            wBody.innerHTML = withdrawals.map(w => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(w.url, w.user)}</div>
                        <div class="small text-muted">${escapeHtml(w.email || '')}${w.status && w.status !== 'pending' ? ' · ' + escapeHtml(w.status) : ''}</div>
                    </td>
                    <td>${money(w.amount)}${methodLine(w)}</td>
                    ${waitingCell(w)}
                    ${actionCell(w)}
                </tr>`).join('');
        }

        setQueuePanel('sites', sites.length > 0);
        meta('sites', sites.length);
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
                    ${waitingCell(s)}
                </tr>`).join('');
        }

        setQueuePanel('unpaid', unpaid.length > 0);
        meta('unpaid', unpaid.length);
        if (!unpaid.length) {
            unpaidBody.innerHTML = emptyRow(3, 'No unpaid orders');
        } else {
            unpaidBody.innerHTML = unpaid.map(o => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(o.url, '#' + o.order_number)}</div>
                        ${methodLine(o)}
                    </td>
                    <td>${money(o.amount)}</td>
                    ${waitingCell(o)}
                </tr>`).join('');
        }

        setQueuePanel('disputes', disputes.length > 0);
        meta('disputes', disputes.length);
        if (!disputes.length) {
            disputeBody.innerHTML = emptyRow(3, 'No open disputes');
        } else {
            disputeBody.innerHTML = disputes.map(d => `
                <tr>
                    <td class="fw-semibold">${cellLink(d.url, '#' + d.order_number)}</td>
                    <td class="small text-truncate" style="max-width:120px;">${escapeHtml(d.reason || '')}</td>
                    ${waitingCell(d)}
                </tr>`).join('');
        }

        setQueuePanel('community', community.length > 0);
        meta('community', community.length);
        if (!community.length) {
            communityBody.innerHTML = emptyRow(3, 'Inbox is clear');
        } else {
            communityBody.innerHTML = community.map(c => `
                <tr>
                    <td><span class="badge text-bg-light">${escapeHtml(c.type)}</span></td>
                    <td>${cellLink(c.url, c.label)}</td>
                    ${waitingCell(c)}
                </tr>`).join('');
        }

        setQueuePanel('enrichment', enrichment.length > 0);
        meta('enrichment', enrichment.length);
        if (!enrichment.length) {
            enrichmentBody.innerHTML = emptyRow(3, 'No failed scans');
        } else {
            enrichmentBody.innerHTML = enrichment.map(e => `
                <tr>
                    <td class="fw-semibold">${cellLink(e.url, e.site_name)}</td>
                    <td class="small text-truncate" style="max-width:120px;">${escapeHtml(e.error || '')}</td>
                    ${waitingCell(e)}
                </tr>`).join('');
        }

        setQueuePanel('bulk', bulk.length > 0);
        meta('bulk', bulk.length);
        if (!bulk.length) {
            bulkBody.innerHTML = emptyRow(3, 'No bulk requests waiting');
        } else {
            bulkBody.innerHTML = bulk.map(b => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(b.url, b.publisher)}</div>
                        <div class="small text-muted">${b.count ? escapeHtml(String(b.count)) + ' sites' : ''}</div>
                    </td>
                    <td class="small">${escapeHtml(b.status || '')}</td>
                    ${waitingCell(b)}
                </tr>`).join('');
        }

        setQueuePanel('mail', mail.length > 0);
        meta('mail', mail.length);
        if (!mail.length) {
            mailBody.innerHTML = emptyRow(2, 'No failed mail');
        } else {
            mailBody.innerHTML = mail.map(m => `
                <tr>
                    <td class="small">${cellLink(m.url, m.label)}</td>
                    ${waitingCell(m)}
                </tr>`).join('');
        }

        setQueuePanel('moderation', moderation.length > 0);
        meta('moderation', moderation.length);
        if (!moderation.length) {
            moderationBody.innerHTML = emptyRow(2, 'No scan errors');
        } else {
            moderationBody.innerHTML = moderation.map(m => `
                <tr>
                    <td class="small">${cellLink(m.url, m.label)}</td>
                    ${waitingCell(m)}
                </tr>`).join('');
        }

        setQueuePanel('catalog_hide', catalogHide.length > 0);
        meta('catalog_hide', catalogHide.length);
        if (!catalogHide.length) {
            catalogBody.innerHTML = emptyRow(2, 'Nobody in hide-mode');
        } else {
            catalogBody.innerHTML = catalogHide.map(c => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(c.url, c.user)}</div>
                        <div class="small text-muted">${escapeHtml(c.email || '')}</div>
                    </td>
                    ${waitingCell(c)}
                </tr>`).join('');
        }

        setQueuePanel('missing_tax', missingTax.length > 0);
        meta('missing_tax', missingTax.length);
        if (taxBody) {
            taxBody.innerHTML = !missingTax.length
                ? emptyRow(3, 'No paid orders missing a tax invoice')
                : missingTax.map(o => `
                <tr>
                    <td>
                        <div class="fw-semibold">${cellLink(o.url, '#' + (o.order_number || o.id))}</div>
                        <div class="small text-muted">${escapeHtml(o.user || '')}</div>
                    </td>
                    <td>${money(o.amount)}</td>
                    ${waitingCell(o)}
                </tr>`).join('');
        }

        setQueuePanel('missing_pdf', missingPdf.length > 0);
        meta('missing_pdf', missingPdf.length);
        if (pdfBody) {
            pdfBody.innerHTML = !missingPdf.length
                ? emptyRow(3, 'No invoices missing a PDF')
                : missingPdf.map(inv => `
                <tr>
                    <td class="fw-semibold">${cellLink(inv.url, inv.label)}</td>
                    <td>${rowMoney(inv)}</td>
                    ${waitingCell(inv)}
                </tr>`).join('');
        }

        setQueuePanel('library', library.length > 0);
        meta('library', library.length);
        if (libraryBody) {
            libraryBody.innerHTML = !library.length
                ? emptyRow(2, 'No articles waiting for review')
                : library.map(a => `
                <tr>
                    <td class="fw-semibold">${cellLink(a.url, a.label)}</td>
                    ${waitingCell(a)}
                </tr>`).join('');
        }

        setQueuePanel('campaigns', campaigns.length > 0);
        meta('campaigns', campaigns.length);
        if (campaignBody) {
            campaignBody.innerHTML = !campaigns.length
                ? emptyRow(3, 'No queued, sending, or failed campaigns')
                : campaigns.map(c => `
                <tr>
                    <td class="fw-semibold">${cellLink(c.url, c.label)}</td>
                    <td class="small">${escapeHtml(c.status || '')}</td>
                    ${waitingCell(c)}
                </tr>`).join('');
        }

        refreshQueueLayout();
    } catch (err) {
        if (tick !== actionQueueTick) return;
        if (quiet) throw err;
        ['deposits', 'withdrawals', 'sites', 'unpaid', 'disputes', 'community', 'enrichment', 'bulk', 'mail', 'moderation', 'catalog_hide', 'missing_tax', 'missing_pdf', 'library', 'campaigns']
            .forEach((name) => setQueuePanel(name, true));
        depBody.innerHTML = retryRow(4, 'loadActionQueue');
        wBody.innerHTML = retryRow(4, 'loadActionQueue');
        sBody.innerHTML = retryRow(3, 'loadActionQueue');
        unpaidBody.innerHTML = retryRow(3, 'loadActionQueue');
        disputeBody.innerHTML = retryRow(3, 'loadActionQueue');
        communityBody.innerHTML = retryRow(3, 'loadActionQueue');
        enrichmentBody.innerHTML = retryRow(3, 'loadActionQueue');
        bulkBody.innerHTML = retryRow(3, 'loadActionQueue');
        mailBody.innerHTML = retryRow(2, 'loadActionQueue');
        moderationBody.innerHTML = retryRow(2, 'loadActionQueue');
        catalogBody.innerHTML = retryRow(2, 'loadActionQueue');
        if (taxBody) taxBody.innerHTML = retryRow(3, 'loadActionQueue');
        if (pdfBody) pdfBody.innerHTML = retryRow(3, 'loadActionQueue');
        if (libraryBody) libraryBody.innerHTML = retryRow(2, 'loadActionQueue');
        if (campaignBody) campaignBody.innerHTML = retryRow(3, 'loadActionQueue');
        refreshQueueLayout();
        throw err;
    } finally {
        actionQueueInFlight = Math.max(0, actionQueueInFlight - 1);
    }
}

async function loadStalledOrders() {
    const row = document.getElementById('stalledOrdersRow');
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.stalled-orders') }}`);
        const items = json.items || [];
        if (!items.length) {
            row.classList.add('d-none');
            refreshQueueLayout();
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
        refreshQueueLayout();
    } catch (err) {
        row.classList.remove('d-none');
        document.getElementById('queueStalled').innerHTML = retryRow(7, 'loadStalledOrders');
        refreshQueueLayout();
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

window.refreshAdminDashboardQueues = function (counts) {
    if (counts && counts.success) {
        applyAttentionCounts(counts);
    }
    loadActionQueue({ quiet: true }).catch(err => console.error('Dashboard queue refresh failed', err));
};

Promise.all([loadStatistics(), loadFinanceStrip(), loadTrends(), loadDistributions(), loadActionQueue(), loadStalledOrders()])
    .catch(err => console.error('Dashboard load failed', err));
</script>
@endsection
