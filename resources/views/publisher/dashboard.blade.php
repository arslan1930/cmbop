@extends('publisher.layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $pendingTasks = $pendingTasks ?? 0;
    $siteCount = $siteCount ?? 0;
    $unverifiedSiteCount = $unverifiedSiteCount ?? 0;
    $primaryAction = $primaryAction ?? (($pendingTasks > 0) ? 'tasks' : 'add_site');
    $stats = $stats ?? [
        'total_orders' => 0,
        'pending_orders' => 0,
        'processing_orders' => 0,
        'review_orders' => 0,
        'completed_orders' => 0,
        'cancelled_orders' => 0,
        'total_earnings' => 0,
        'pending_earnings' => 0,
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
@endphp

<div class="container-fluid dash-page-end publisher-dashboard">

    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Publisher Dashboard</h2>
            <p class="text-muted mb-0">
                Welcome back! Here's your performance summary and recent activity.
            </p>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-3">
        @if($primaryAction === 'tasks')
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100 publisher-primary-cta">
                    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 p-4">
                        <div>
                            <div class="text-uppercase small fw-semibold mb-1" style="color:#0b6266;letter-spacing:.04em;">Do this next</div>
                            <h4 class="mb-1">You have {{ $pendingTasks }} task{{ $pendingTasks === 1 ? '' : 's' }} waiting</h4>
                            <p class="text-muted mb-0">Accept, publish, or reply so advertisers keep moving.</p>
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
        @else
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100 publisher-primary-cta">
                    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 p-4">
                        <div>
                            <div class="text-uppercase small fw-semibold mb-1" style="color:#0b6266;letter-spacing:.04em;">Do this next</div>
                            <h4 class="mb-1">{{ $siteCount === 0 ? 'Add your first website' : 'Grow your catalog' }}</h4>
                            <p class="text-muted mb-0">
                                {{ $siteCount === 0
                                    ? 'List a site to start receiving advertiser orders.'
                                    : 'You have '.$siteCount.' site'.($siteCount === 1 ? '' : 's').' listed — add another niche or market.' }}
                            </p>
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
                    <p class="small text-muted mb-3">{{ $pendingTasks }} need{{ $pendingTasks === 1 ? 's' : '' }} you</p>
                    <a href="{{ route('publisher.tasks', ['needs_action' => 1]) }}" class="btn btn-sm btn-outline-secondary w-100">View tasks</a>
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

    <!-- KPI strip (always visible) -->
    <div class="row g-3 mb-4 row-cols-2 row-cols-lg-3 row-cols-xl-5">
        <div class="col">
            <div class="kpi-tile">
                <div class="kpi-icon" style="background:#0b6266;"><i class="fa fa-euro-sign"></i></div>
                <div>
                    <span class="kpi-label">Total earnings</span>
                    <div class="kpi-value" id="totalEarnings">€{{ number_format((float) $stats['total_earnings'], 2) }}</div>
                    <div class="kpi-sub">Completed & paid</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-tile">
                <div class="kpi-icon" style="background:#3aaeb2;"><i class="fa fa-hourglass-half"></i></div>
                <div>
                    <span class="kpi-label">Pending earnings</span>
                    <div class="kpi-value" id="pendingEarnings">€{{ number_format((float) $stats['pending_earnings'], 2) }}</div>
                    <div class="kpi-sub">In advertiser review</div>
                </div>
            </div>
        </div>
        <div class="col">
            <a href="{{ route('publisher.withdraw') }}" class="kpi-tile">
                <div class="kpi-icon" style="background:#c45c26;"><i class="fa fa-wallet"></i></div>
                <div>
                    <span class="kpi-label">Available balance</span>
                    <div class="kpi-value" id="availableBalance">€{{ number_format((float) $availableBalance, 2) }}</div>
                    <div class="kpi-sub">Withdrawable €{{ number_format((float) $withdrawableBalance, 2) }}</div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="{{ route('publisher.tasks', ['needs_action' => 1]) }}" class="kpi-tile">
                <div class="kpi-icon" style="background:#64748b;"><i class="fa fa-tasks"></i></div>
                <div>
                    <span class="kpi-label">Needs you</span>
                    <div class="kpi-value" id="openTasks">{{ $pendingTasks }}</div>
                    <div class="kpi-sub">Accept, publish, or fix</div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="{{ route('publisher.websites') }}" class="kpi-tile">
                <div class="kpi-icon" style="background:{{ $unverifiedSiteCount > 0 ? '#b45309' : '#0f766e' }};"><i class="fa fa-{{ $unverifiedSiteCount > 0 ? 'exclamation' : 'check' }}"></i></div>
                <div>
                    <span class="kpi-label">Awaiting verification</span>
                    <div class="kpi-value" id="unverifiedSites">{{ $unverifiedSiteCount }}</div>
                    <div class="kpi-sub">
                        @if($unverifiedSiteCount > 0)
                            {{ $siteCount }} total site{{ $siteCount === 1 ? '' : 's' }}
                        @else
                            All listed sites verified
                        @endif
                    </div>
                </div>
            </a>
        </div>
    </div>

    @if($siteCount === 0)
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
                    <div class="card-body">
                        <canvas id="monthlyEarningsChart" height="200"></canvas>
                    </div>
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
                                <div class="progress mt-2" style="height: 4px;">
                                    <div id="successProgress" class="progress-bar bg-primary" style="width: {{ min(100, (float) $metrics['success_rate']) }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">Of completed + cancelled</div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="small text-muted">Avg. Payout</div>
                                <h4 class="mb-0" id="avgOrderValue">€{{ number_format((float) $metrics['avg_order_value'], 2) }}</h4>
                                <div class="small text-muted mt-1">Per completed order</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Completion Rate</div>
                                <h4 class="mb-0" id="completionRate">{{ number_format((float) $metrics['completion_rate'], 1) }}%</h4>
                                <div class="progress mt-2" style="height: 4px;">
                                    <div id="completionProgress" class="progress-bar bg-info" style="width: {{ min(100, (float) $metrics['completion_rate']) }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">Completed / all orders</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Open Rate</div>
                                <h4 class="mb-0" id="openRate">{{ number_format((float) $metrics['open_rate'], 1) }}%</h4>
                                <div class="progress mt-2" style="height: 4px;">
                                    <div id="openProgress" class="progress-bar bg-warning" style="width: {{ min(100, (float) $metrics['open_rate']) }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">Pending / processing / review / scheduled</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8 mb-3 dash-recent-col">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-list me-2 text-primary"></i> Recent tasks</span>
                        <a href="{{ route('publisher.tasks') }}" class="small text-decoration-none" style="color:#0b6266;">View all</a>
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
                                            @endphp
                                            <tr>
                                                <td>
                                                    <strong>#{{ $task['order_number'] }}</strong>
                                                    <div class="small text-muted">{{ $task['created_at_human'] ?? '' }}</div>
                                                </td>
                                                <td>
                                                    <div>{{ $task['site_name'] }}</div>
                                                    @if(!empty($task['site_url']))
                                                        <div class="small text-muted text-truncate" style="max-width:180px;">{{ $task['site_url'] }}</div>
                                                    @endif
                                                </td>
                                                <td><span class="status-badge {{ $badgeClass }}">{{ ucfirst($status === 'review' ? 'In review' : $status) }}</span></td>
                                                <td class="text-end fw-semibold">€{{ number_format((float) ($task['payout'] ?? 0), 2) }}</td>
                                                <td class="text-end">
                                                    <a href="{{ route('publisher.tasks') }}" class="btn btn-sm btn-outline-secondary">Open</a>
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

@if($siteCount > 0)
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
