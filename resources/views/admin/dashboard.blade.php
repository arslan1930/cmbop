@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    @include('admin.partials.page-header', [
        'title' => 'Admin Dashboard',
        'subtitle' => 'Platform overview, money flow, and items that need your attention.',
    ])

    <!-- KPI cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total Users</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiUsers">—</h3>
                        <span class="badge bg-primary-subtle text-primary" id="kpiUsers7d">+0 / 7d</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiAdvertisers">0</span> advertisers ·
                        <span id="kpiPublishers">0</span> publishers
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Paid Revenue</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiRevenue">—</h3>
                        <span class="badge bg-success-subtle text-success" id="kpiRevenue7d">€0 / 7d</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiPaidOrders">0</span> paid orders
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Sites</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiSites">—</h3>
                        <span class="badge bg-warning-subtle text-warning" id="kpiUnverified">0 pending</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiVerified">0</span> verified
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Needs Attention</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiAttention">—</h3>
                        <span class="badge bg-danger-subtle text-danger">Action queue</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiDeposits">0</span> deposits ·
                        <span id="kpiWithdrawals">0</span> withdrawals
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action queues (first viewport priority) -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-wallet me-2 text-success"></i>Pending Deposits</strong>
                    <a href="{{ route('admin.deposits') }}" class="small">View all</a>
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
                    <a href="{{ route('admin.withdrawals') }}" class="small">View all</a>
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
                    <a href="{{ route('admin.sites.index') }}" class="small">View all</a>
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

    <!-- Charts -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-chart-line me-2 text-primary"></i>Revenue &amp; Orders (30 days)</strong>
                    <span class="text-muted small">Paid revenue vs order volume</span>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="110"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-user-plus me-2 text-success"></i>New Signups (30 days)</strong>
                </div>
                <div class="card-body">
                    <canvas id="signupChart" height="110"></canvas>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const money = (n) => '€' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (n) => Number(n || 0).toLocaleString();

let trendChart, signupChart, orderStatusChart, roleChart;

async function loadStatistics() {
    const res = await fetch(`{{ route('admin.dashboard.statistics') }}`);
    const json = await res.json();
    if (!json.success) return;
    const d = json.data;

    document.getElementById('kpiUsers').textContent = num(d.total_users);
    document.getElementById('kpiUsers7d').textContent = '+' + num(d.new_users_7d) + ' / 7d';
    document.getElementById('kpiAdvertisers').textContent = num(d.advertisers);
    document.getElementById('kpiPublishers').textContent = num(d.publishers);
    document.getElementById('kpiRevenue').textContent = money(d.revenue);
    document.getElementById('kpiRevenue7d').textContent = money(d.revenue_7d) + ' / 7d';
    document.getElementById('kpiPaidOrders').textContent = num(d.paid_orders);
    document.getElementById('kpiSites').textContent = num(d.total_sites);
    document.getElementById('kpiVerified').textContent = num(d.verified_sites);
    document.getElementById('kpiUnverified').textContent = num(d.unverified_sites) + ' pending';
    document.getElementById('kpiDeposits').textContent = num(d.pending_deposits);
    document.getElementById('kpiWithdrawals').textContent = num(d.pending_withdrawals);
    document.getElementById('kpiAttention').textContent = num(
        d.pending_deposits + d.pending_withdrawals + d.unverified_sites
    );
}

async function loadTrends() {
    const res = await fetch(`{{ route('admin.dashboard.trends') }}?days=30`);
    const json = await res.json();
    if (!json.success) return;

    const commonOpts = {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { display: true, position: 'bottom' } },
        scales: { y: { beginAtZero: true } }
    };

    trendChart = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: json.labels,
            datasets: [
                {
                    label: 'Revenue (€)',
                    data: json.revenue,
                    borderColor: '#185054',
                    backgroundColor: 'rgba(13,110,253,0.12)',
                    fill: true,
                    tension: 0.35,
                    yAxisID: 'y'
                },
                {
                    label: 'Orders',
                    data: json.orders,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25,135,84,0.08)',
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
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Orders' } }
            }
        }
    });

    signupChart = new Chart(document.getElementById('signupChart'), {
        type: 'bar',
        data: {
            labels: json.labels,
            datasets: [{
                label: 'New users',
                data: json.signups,
                backgroundColor: 'rgba(25,135,84,0.65)',
                borderRadius: 4
            }]
        },
        options: {
            ...commonOpts,
            plugins: { legend: { display: false } }
        }
    });
}

async function loadDistributions() {
    const res = await fetch(`{{ route('admin.dashboard.distributions') }}`);
    const json = await res.json();
    if (!json.success) return;

    const palette = ['#185054', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#20c997', '#fd7e14'];

    orderStatusChart = new Chart(document.getElementById('orderStatusChart'), {
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

    roleChart = new Chart(document.getElementById('roleChart'), {
        type: 'doughnut',
        data: {
            labels: json.roles.labels,
            datasets: [{
                data: json.roles.values,
                backgroundColor: ['#185054', '#198754', '#6c757d']
            }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
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

async function loadActionQueue() {
    const res = await fetch(`{{ route('admin.dashboard.action-queue') }}`);
    const json = await res.json();
    if (!json.success) return;

    const depBody = document.getElementById('queueDeposits');
    if (!json.deposits.length) {
        depBody.innerHTML = emptyRow(3, 'No pending deposits');
    } else {
        depBody.innerHTML = json.deposits.map(d => `
            <tr>
                <td>
                    <div class="fw-semibold">${escapeHtml(d.user)}</div>
                    <div class="small text-muted">${escapeHtml(d.email || '')}</div>
                </td>
                <td>${money(d.amount)}</td>
                <td class="small text-muted">${escapeHtml(d.date)}</td>
            </tr>`).join('');
    }

    const wBody = document.getElementById('queueWithdrawals');
    if (!json.withdrawals.length) {
        wBody.innerHTML = emptyRow(3, 'No pending withdrawals');
    } else {
        wBody.innerHTML = json.withdrawals.map(w => `
            <tr>
                <td>
                    <div class="fw-semibold">${escapeHtml(w.user)}</div>
                    <div class="small text-muted">${escapeHtml(w.email || '')}</div>
                </td>
                <td>${money(w.amount)}</td>
                <td class="small text-muted">${escapeHtml(w.date)}</td>
            </tr>`).join('');
    }

    const sBody = document.getElementById('queueSites');
    if (!json.sites.length) {
        sBody.innerHTML = emptyRow(3, 'No sites awaiting verification');
    } else {
        sBody.innerHTML = json.sites.map(s => `
            <tr>
                <td>
                    <div class="fw-semibold">${escapeHtml(s.site_name || '—')}</div>
                    <div class="small text-muted text-truncate" style="max-width:140px;">${escapeHtml(s.site_url || '')}</div>
                </td>
                <td>${escapeHtml(s.publisher)}</td>
                <td class="small text-muted">${escapeHtml(s.date)}</td>
            </tr>`).join('');
    }
}

Promise.all([loadStatistics(), loadTrends(), loadDistributions(), loadActionQueue()])
    .catch(err => console.error('Dashboard load failed', err));
</script>
@endsection
