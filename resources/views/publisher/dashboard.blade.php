@extends('publisher.layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $needsYou = (int) ($needsYou ?? 0);
    $waitingOnAdvertiser = (int) ($waitingOnAdvertiser ?? 0);
    $siteCount = $siteCount ?? 0;
    $listingWorkCount = (int) ($listingWorkCount ?? 0);
    $sellableSiteCount = (int) ($sellableSiteCount ?? 0);
    $primaryAction = $primaryAction ?? 'add_site';
    $stats = $stats ?? [
        'total_orders' => 0,
        'pending_orders' => 0,
        'processing_orders' => 0,
        'review_orders' => 0,
        'completed_orders' => 0,
        'cancelled_orders' => 0,
        'total_earnings' => 0,
        'pending_earnings' => 0,
        'in_progress_earnings' => 0,
        'success_rate' => 0,
    ];
    $metrics = $metrics ?? [
        'success_rate' => 0,
        'completion_rate' => 0,
        'open_rate' => 0,
        'avg_order_value' => 0,
    ];
    $availableBalance = $availableBalance ?? 0;
    $withdrawableBalance = $withdrawableBalance ?? 0;
    $recentTasks = $recentTasks ?? [];
    $weeklyEarnings = $weeklyEarnings ?? ['labels' => [], 'values' => []];
    $monthlyEarnings = $monthlyEarnings ?? ['labels' => [], 'values' => []];
    $orderStatus = $orderStatus ?? ['labels' => [], 'values' => []];
    $statusHasOrders = collect($orderStatus['values'] ?? [])->sum() > 0;
    $hasPaidOrders = (bool) ($hasPaidOrders ?? ((int) ($stats['total_orders'] ?? 0) > 0));
    $dashboardFailed = (bool) ($dashboardFailed ?? false);
    $publisherName = $publisherName ?? (auth()->user()?->name ?: 'there');
    $welcomeSituation = $welcomeSituation ?? 'you are caught up';
    $needsYouHint = $needsYou === 0
        ? 'None need you'
        : ($needsYou === 1 ? '1 needs you' : $needsYou.' need you');
@endphp

<div class="container-fluid dash-page-end publisher-dashboard">

    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Dashboard</h2>
            <p class="text-muted mb-0">
                Welcome back, {{ $publisherName }} — {{ $welcomeSituation }}.
            </p>
        </div>
    </div>

    <!-- Quick Actions -->
    @unless($dashboardFailed)
    <div class="row g-3 mb-3">
        @if($primaryAction === 'tasks')
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100 publisher-primary-cta">
                    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 p-4">
                        <div>
                            <div class="text-uppercase small fw-semibold mb-1 publisher-cta-eyebrow">Do this next</div>
                            <h4 class="mb-1">You have {{ $needsYou }} {{ $needsYou === 1 ? 'task that needs you' : 'tasks that need you' }}</h4>
                            <p class="text-muted mb-0">Accept, publish a live URL, or reply to a change request.</p>
                            @if($waitingOnAdvertiser > 0)
                                <p class="small text-muted mb-0 mt-1">{{ $waitingOnAdvertiser }} more in review, waiting on advertisers.</p>
                            @endif
                        </div>
                        <a href="{{ route('publisher.tasks', ['needs_action' => 1]) }}" class="btn btn-lg btn-primary px-4">
                            Open tasks <i class="fa fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-plus"></i></span>
                        <h6 class="mb-0">Add site</h6>
                    </div>
                    <p class="small text-muted mb-3">{{ $siteCount }} site{{ $siteCount === 1 ? '' : 's' }} listed</p>
                    <a href="{{ route('publisher.websites') }}" class="btn btn-sm btn-outline-secondary w-100">Add site</a>
                </div>
            </div>
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-chart-line"></i></span>
                        <h6 class="mb-0">Reports</h6>
                    </div>
                    <p class="small text-muted mb-3">Earnings & performance</p>
                    <a href="{{ route('publisher.reports') }}" class="btn btn-sm btn-outline-secondary w-100">View reports</a>
                </div>
            </div>
        @elseif($primaryAction === 'verify_sites')
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100 publisher-primary-cta">
                    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 p-4">
                        <div>
                            <div class="text-uppercase small fw-semibold mb-1 publisher-cta-eyebrow">Do this next</div>
                            <h4 class="mb-1">Finish your listings</h4>
                            <p class="text-muted mb-0">
                                {{ $listingWorkCount }} listing{{ $listingWorkCount === 1 ? '' : 's' }} {{ $listingWorkCount === 1 ? 'is' : 'are' }} not in the catalog yet.
                            </p>
                            @if($waitingOnAdvertiser > 0)
                                <p class="small text-muted mb-0 mt-1">{{ $waitingOnAdvertiser }} placement{{ $waitingOnAdvertiser === 1 ? '' : 's' }} in review, waiting on advertisers.</p>
                            @endif
                        </div>
                        <a href="{{ route('publisher.websites', ['status' => 'pending']) }}" class="btn btn-lg btn-primary px-4">
                            Review sites <i class="fa fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-tasks"></i></span>
                        <h6 class="mb-0">Tasks</h6>
                    </div>
                    <p class="small text-muted mb-3">{{ $needsYouHint }}</p>
                    <a href="{{ route('publisher.tasks') }}" class="btn btn-sm btn-outline-secondary w-100">View tasks</a>
                </div>
            </div>
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-chart-line"></i></span>
                        <h6 class="mb-0">Reports</h6>
                    </div>
                    <p class="small text-muted mb-3">Earnings & performance</p>
                    <a href="{{ route('publisher.reports') }}" class="btn btn-sm btn-outline-secondary w-100">View reports</a>
                </div>
            </div>
        @else
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100 publisher-primary-cta">
                    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 p-4">
                        <div>
                            <div class="text-uppercase small fw-semibold mb-1 publisher-cta-eyebrow">Do this next</div>
                            <h4 class="mb-1">{{ $primaryAction === 'add_site' ? 'Add your first website' : 'Grow your catalog' }}</h4>
                            <p class="text-muted mb-0">
                                {{ $primaryAction === 'add_site'
                                    ? 'List a site to start receiving advertiser orders.'
                                    : 'You have '.$siteCount.' site'.($siteCount === 1 ? '' : 's').' listed — add another niche or market.' }}
                            </p>
                            @if($waitingOnAdvertiser > 0)
                                <p class="small text-muted mb-0 mt-1">{{ $waitingOnAdvertiser }} placement{{ $waitingOnAdvertiser === 1 ? '' : 's' }} in review, waiting on advertisers.</p>
                            @endif
                        </div>
                        <a href="{{ route('publisher.websites') }}" class="btn btn-lg btn-primary px-4">
                            Add site <i class="fa fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-tasks"></i></span>
                        <h6 class="mb-0">Tasks</h6>
                    </div>
                    <p class="small text-muted mb-3">{{ $needsYouHint }}</p>
                    <a href="{{ route('publisher.tasks') }}" class="btn btn-sm btn-outline-secondary w-100">View tasks</a>
                </div>
            </div>
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-chart-line"></i></span>
                        <h6 class="mb-0">Reports</h6>
                    </div>
                    <p class="small text-muted mb-3">Earnings & performance</p>
                    <a href="{{ route('publisher.reports') }}" class="btn btn-sm btn-outline-secondary w-100">View reports</a>
                </div>
            </div>
        @endif
    </div>
    @endunless

    <!-- KPI strip (always visible) -->
    <div class="row g-3 mb-4 row-cols-2 row-cols-lg-3 row-cols-xl-5">
        <div class="col">
            <div class="kpi-tile">
                <div class="kpi-icon kpi-icon-earnings"><i class="fa fa-euro-sign"></i></div>
                <div>
                    <span class="kpi-label">Total earnings</span>
                    <div class="kpi-value" id="totalEarnings">€{{ number_format((float) $stats['total_earnings'], 2) }}</div>
                    <div class="kpi-sub">Completed & paid</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-tile">
                <div class="kpi-icon kpi-icon-pending"><i class="fa fa-hourglass-half"></i></div>
                <div>
                    @php
                        $pendingReview = (float) ($stats['pending_earnings'] ?? 0);
                        $pendingInProgress = (float) ($stats['in_progress_earnings'] ?? 0);
                        $pendingPayout = $pendingReview + $pendingInProgress;
                    @endphp
                    <span class="kpi-label">Pending payout</span>
                    <div class="kpi-value" id="pendingEarnings">€{{ number_format($pendingPayout, 2) }}</div>
                    <div class="kpi-sub">€{{ number_format($pendingReview, 2) }} in review · €{{ number_format($pendingInProgress, 2) }} still to publish</div>
                </div>
            </div>
        </div>
        <div class="col">
            <a href="{{ route('publisher.withdraw') }}" class="kpi-tile">
                <div class="kpi-icon kpi-icon-wallet"><i class="fa fa-wallet"></i></div>
                <div>
                    <span class="kpi-label">Withdrawable</span>
                    <div class="kpi-value" id="availableBalance">€{{ number_format((float) $withdrawableBalance, 2) }}</div>
                    <div class="kpi-sub">
                        @if(round((float) $availableBalance - (float) $withdrawableBalance, 2) > 0.009)
                            €{{ number_format((float) $availableBalance, 2) }} on balance includes promo/hold — not withdrawable
                        @else
                            Ready to withdraw
                        @endif
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="{{ $needsYou > 0 ? route('publisher.tasks', ['needs_action' => 1]) : route('publisher.tasks') }}" class="kpi-tile">
                <div class="kpi-icon kpi-icon-tasks"><i class="fa fa-tasks"></i></div>
                <div>
                    <span class="kpi-label">Needs you</span>
                    <div class="kpi-value" id="openTasks">{{ $needsYou }}</div>
                    <div class="kpi-sub">
                        @if($waitingOnAdvertiser > 0)
                            {{ $waitingOnAdvertiser }} in review with advertisers
                        @else
                            {{ (int) $stats['total_orders'] }} order{{ (int) $stats['total_orders'] === 1 ? '' : 's' }} total
                        @endif
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="{{ $listingWorkCount > 0 ? route('publisher.websites', ['status' => 'pending']) : route('publisher.websites') }}" class="kpi-tile">
                <div class="kpi-icon {{ $listingWorkCount > 0 ? 'kpi-icon-sites-work' : 'kpi-icon-sites-ok' }}"><i class="fa fa-{{ $listingWorkCount > 0 ? 'exclamation' : 'check' }}"></i></div>
                <div>
                    <span class="kpi-label">{{ $listingWorkCount > 0 ? 'Needs listing work' : 'Catalog-ready' }}</span>
                    <div class="kpi-value" id="unverifiedSites">{{ $listingWorkCount > 0 ? $listingWorkCount : $sellableSiteCount }}</div>
                    <div class="kpi-sub">
                        @if($listingWorkCount > 0)
                            Not in the catalog yet
                        @else
                            {{ $siteCount }} listed · {{ $sellableSiteCount }} catalog-ready
                        @endif
                    </div>
                </div>
            </a>
        </div>
    </div>

    @if($dashboardFailed)
        <div class="row mb-4">
            <div class="col-12">
                <div class="dash-panel publisher-dashboard-failed">
                    <h5 class="mb-1">We could not refresh every number</h5>
                    <p class="text-muted mb-0">
                        Your dashboard is still here — refresh to try loading the full summary again.
                    </p>
                </div>
            </div>
        </div>
    @elseif($siteCount === 0)
        <div class="row mb-4">
            <div class="col-12">
                <div class="dash-panel publisher-empty-metrics">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div>
                            <h5 class="mb-1">No performance data yet</h5>
                            <p class="text-muted mb-0">
                                Charts and metrics appear after you list a website and start receiving orders.
                            </p>
                        </div>
                        <a href="{{ route('publisher.websites') }}" class="btn btn-primary">
                            Add your first site
                        </a>
                    </div>
                    <ol class="publisher-onboarding-steps mt-3 mb-0">
                        <li>Add a website with niche, language, and pricing</li>
                        <li>Wait for verification so advertisers can find you</li>
                        <li>Accept tasks and earn from completed placements</li>
                    </ol>
                </div>
            </div>
        </div>
    @elseif(! $hasPaidOrders)
        <div class="row mb-4">
            <div class="col-12">
                <div class="dash-panel publisher-empty-metrics">
                    <h5 class="mb-1">No paid orders yet</h5>
                    <p class="text-muted mb-0">
                        Earnings charts appear after the first paid placement. Keep your listings complete so advertisers can find you.
                    </p>
                </div>
            </div>
        </div>
    @else
        <!-- Charts + metrics -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">
                        <i class="fa fa-chart-line me-2 text-primary"></i> Weekly Earnings
                        <span class="float-end text-muted small">Last 7 days</span>
                    </div>
                    <div class="card-body pb-2">
                        <canvas id="weeklyEarningsChart" height="200"></canvas>
                    </div>
                    <p class="small text-muted px-3 pb-3 mb-0">Recognized on completion day; clawbacks appear on the reversal day.</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">
                        <i class="fa fa-chart-area me-2 text-info"></i> Monthly Earnings
                        <span class="float-end text-muted small">Last 6 months</span>
                    </div>
                    <div class="card-body pb-2">
                        <canvas id="monthlyEarningsChart" height="200"></canvas>
                    </div>
                    <p class="small text-muted px-3 pb-3 mb-0">Recognized on completion day; clawbacks appear on the reversal day.</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">
                        <i class="fa fa-chart-pie me-2 text-warning"></i> Order Status
                        <span class="float-end text-muted small">All time</span>
                    </div>
                    <div class="card-body">
                        @if($statusHasOrders)
                            <canvas id="orderStatusChart" height="200"></canvas>
                        @else
                            <div class="publisher-chart-empty text-muted">No orders yet</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">
                        <i class="fa fa-tachometer me-2 text-warning"></i> Performance Metrics
                        <span class="float-end text-muted small">All time</span>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="small text-muted">Success Rate</div>
                                <h4 class="mb-0" id="successRate">{{ number_format((float) $metrics['success_rate'], 1) }}%</h4>
                                <div class="progress progress-slim mt-2">
                                    <div id="successProgress" class="progress-bar bg-primary" style="width: {{ min(100, (float) $metrics['success_rate']) }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">Of completed + cancelled</div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="small text-muted">Avg. Payout</div>
                                <h4 class="mb-0" id="avgOrderValue">€{{ number_format((float) $metrics['avg_order_value'], 2) }}</h4>
                                <div class="small text-muted mt-1">Per completed order</div>
                            </div>
                            <div class="col-12 publisher-metric-muted">
                                <div class="small text-muted">Completion rate</div>
                                <div class="fw-semibold" id="completionRate">{{ number_format((float) $metrics['completion_rate'], 1) }}%</div>
                                <div class="small text-muted mt-1">Completed / all orders — see Reports for history</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8 mb-3 dash-recent-col">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-list me-2 text-primary"></i> Recent tasks</span>
                        <a href="{{ route('publisher.tasks') }}" class="small text-decoration-none publisher-view-all">View all</a>
                    </div>
                    <div class="card-body p-0">
                        @if(count($recentTasks) === 0)
                            <div class="p-4 text-muted">
                                No orders yet. Once advertisers book your sites, tasks will show up here.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table mb-0 recent-tasks-table">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th>Order</th>
                                            <th>Site</th>
                                            <th>Status</th>
                                            <th class="text-end">Your payout</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($recentTasks as $task)
                                            @php
                                                $status = $task['status'] ?? 'pending';
                                                $badgeClass = match ($status) {
                                                    'processing' => 'status-processing',
                                                    'review' => 'status-review',
                                                    'scheduled' => 'status-scheduled',
                                                    'completed' => 'status-completed',
                                                    'cancelled' => 'status-cancelled',
                                                    default => 'status-pending',
                                                };
                                                $nextAction = $task['next_action'] ?? ($status === 'review' ? 'In review' : ucfirst($status));
                                            @endphp
                                            <tr>
                                                <td>
                                                    <strong>#{{ $task['order_number'] }}</strong>
                                                    <div class="small text-muted">{{ $task['created_at_human'] ?? '' }}</div>
                                                </td>
                                                <td>
                                                    <div>{{ $task['site_name'] }}</div>
                                                    @if(!empty($task['site_url']))
                                                        <div class="small text-muted text-truncate site-url-clip">{{ $task['site_url'] }}</div>
                                                    @endif
                                                </td>
                                                <td><span class="status-badge {{ $badgeClass }}">{{ $nextAction }}</span></td>
                                                <td class="text-end fw-semibold">€{{ number_format((float) ($task['payout'] ?? 0), 2) }}</td>
                                                <td class="text-end">
                                                    <a href="{{ route('publisher.tasks', ['focus' => 'order', 'order' => $task['order_id']]) }}" class="btn btn-sm btn-outline-secondary">Open</a>
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
    @endif
</div>

@if($hasPaidOrders)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
(function () {
    var weeklyData = @json($weeklyEarnings);
    var monthlyData = @json($monthlyEarnings);
    var statusData = @json($orderStatus);

    function renderWeeklyChart(data) {
        var canvas = document.getElementById('weeklyEarningsChart');
        if (!canvas) return;
        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Earnings (€)',
                    data: data.values || [],
                    borderColor: '#0b6266',
                    backgroundColor: 'rgba(11, 98, 102, 0.12)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#0b6266',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return '€' + Number(context.parsed.y).toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) { return '€' + value; }
                        }
                    }
                }
            }
        });
    }

    function renderMonthlyChart(data) {
        var canvas = document.getElementById('monthlyEarningsChart');
        if (!canvas) return;
        new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Earnings (€)',
                    data: data.values || [],
                    backgroundColor: 'rgba(58, 174, 178, 0.75)',
                    borderRadius: 8,
                    barPercentage: 0.6,
                    categoryPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return '€' + Number(context.parsed.y).toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { display: true, drawBorder: false },
                        ticks: {
                            callback: function (value) { return '€' + value; }
                        }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    function renderStatusChart(data) {
        var canvas = document.getElementById('orderStatusChart');
        if (!canvas) return;
        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: data.labels || [],
                datasets: [{
                    data: data.values || [],
                    backgroundColor: ['#fbbf24', '#60a5fa', '#a78bfa', '#c4b5fd', '#4ade80', '#f87171'],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 10 }
                    }
                },
                cutout: '60%'
            }
        });
    }

    renderWeeklyChart(weeklyData);
    renderMonthlyChart(monthlyData);
    renderStatusChart(statusData);
})();
</script>
@endif

@endsection
