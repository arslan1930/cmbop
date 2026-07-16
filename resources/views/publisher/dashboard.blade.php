@extends('publisher.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Publisher Dashboard</h2>
            <p class="text-muted mb-0">
                Welcome back! Here's your performance summary and recent activity.
            </p>
        </div>
    </div>

    @php
        $pendingTasks = $pendingTasks ?? 0;
        $siteCount = $siteCount ?? 0;
        $primaryAction = $primaryAction ?? (($pendingTasks > 0) ? 'tasks' : 'add_site');
    @endphp

    <!-- Quick Actions: one primary CTA, two secondary -->
    <div class="row g-3 mb-2">
        @if($primaryAction === 'tasks')
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100 publisher-primary-cta">
                    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 p-4">
                        <div>
                            <div class="text-uppercase small fw-semibold mb-1" style="color:#0b6266;letter-spacing:.04em;">Do this next</div>
                            <h4 class="mb-1">You have {{ $pendingTasks }} task{{ $pendingTasks === 1 ? '' : 's' }} waiting</h4>
                            <p class="text-muted mb-0">Accept, publish, or reply so advertisers keep moving.</p>
                        </div>
                        <a href="{{ route('publisher.tasks') }}" class="btn btn-lg btn-primary px-4">
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
                                    : 'You have '.$siteCount.' site'.($siteCount === 1 ? '' : 's').' live — add another niche or market.' }}
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
                    <p class="small text-muted mb-3">{{ $pendingTasks }} pending</p>
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

    <style>
        .publisher-primary-cta {
            background: linear-gradient(135deg, #f0fbfb 0%, #ffffff 55%);
            border-left: 4px solid #4ECDCB !important;
        }
        .publisher-secondary-cta .secondary-icon {
            width: 32px; height: 32px; border-radius: 8px;
            background: #eef7f7; color: #0b6266;
            display: inline-flex; align-items: center; justify-content: center;
        }
    </style>

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
    <!-- Graphs + metrics (only when publisher has inventory) -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-chart-line me-2 text-primary"></i> Weekly Earnings
                    <span class="float-end text-muted small" id="weeklyPeriod">Last 7 days</span>
                </div>
                <div class="card-body">
                    <canvas id="weeklyEarningsChart" height="200"></canvas>
                </div>
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
                    <i class="fa fa-tachometer me-2 text-warning"></i> Performance Metrics
                    <span class="float-end text-muted small">This month</span>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="small text-muted">Conversion Rate</div>
                            <h4 class="mb-0" id="conversionRate">—</h4>
                            <div class="progress mt-2" style="height: 4px;">
                                <div id="conversionProgress" class="progress-bar bg-success" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="small text-muted">Avg. Order Value</div>
                            <h4 class="mb-0" id="avgOrderValue">—</h4>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Completion Rate</div>
                            <h4 class="mb-0" id="completionRate">—</h4>
                            <div class="progress mt-2" style="height: 4px;">
                                <div id="completionProgress" class="progress-bar bg-info" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Success Rate</div>
                            <h4 class="mb-0" id="successRate">—</h4>
                            <div class="progress mt-2" style="height: 4px;">
                                <div id="successProgress" class="progress-bar bg-primary" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

<style>
.table td, .table th {
    padding: 12px 15px;
    vertical-align: middle;
}

.card-header {
    border-bottom: 1px solid #eee;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.status-pending {
    background-color: #fef3c7;
    color: #282828;
}

.status-processing {
    background-color: #dbeafe;
    color: #282828;
}

.status-completed {
    background-color: #dcfce7;
    color: #282828;
}

.status-cancelled {
    background-color: #fee2e2;
    color: #282828;
}

.progress {
    background-color: #e9ecef;
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
    transition: width 0.6s ease;
}

/* Dark mode styles */
body.layout-dark .card-header {
    border-bottom-color: #333;
}

body.layout-dark .status-pending {
    background-color: #4a3a1e;
    color: #fbbf24;
}

body.layout-dark .status-processing {
    background-color: #1e3a5f;
    color: #60a5fa;
}

body.layout-dark .status-completed {
    background-color: #1e5a2e;
    color: #4ade80;
}

body.layout-dark .status-cancelled {
    background-color: #5a1e1e;
    color: #f87171;
}

body.layout-dark .progress {
    background-color: #2d2d3a;
}

.publisher-empty-metrics {
    padding: 1.5rem 1.75rem;
}
.publisher-onboarding-steps {
    padding-left: 1.25rem;
    color: #64748b;
    font-size: 0.925rem;
}
.publisher-onboarding-steps li + li {
    margin-top: 0.35rem;
}
</style>

@if($siteCount > 0)
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let weeklyChart, statusChart, monthlyChart;

$(document).ready(function() {
    loadDashboardData();
    loadChartData();
    
    // Auto-refresh every 30 seconds
    setInterval(function() {
        loadDashboardData();
        loadChartData();
    }, 30000);
});

function loadDashboardData() {
    // Load statistics
    $.ajax({
        url: '{{ route("publisher.dashboard.statistics") }}',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#totalOrders').text(response.data.total_orders || 0);
                $('#activeOrders').text(response.data.processing_orders || 0);
                $('#completedOrders').text(response.data.completed_orders || 0);
                $('#totalEarnings').html('€' + (response.data.total_earnings || 0).toFixed(2));
                
                // Calculate performance metrics
                var totalOrders = response.data.total_orders || 0;
                var completedOrders = response.data.completed_orders || 0;
                var cancelledOrders = response.data.cancelled_orders || 0;
                
                var completionRate = totalOrders > 0 ? (completedOrders / totalOrders * 100).toFixed(1) : 0;
                var conversionRate = totalOrders > 0 ? ((completedOrders + (response.data.processing_orders || 0)) / totalOrders * 100).toFixed(1) : 0;
                var avgOrderValue = completedOrders > 0 ? (response.data.total_earnings / completedOrders).toFixed(2) : 0;
                
                var successRate = typeof response.data.success_rate !== 'undefined'
                    ? response.data.success_rate
                    : (completedOrders + cancelledOrders > 0
                        ? ((completedOrders / (completedOrders + cancelledOrders)) * 100).toFixed(1)
                        : 0);

                $('#conversionRate').text(conversionRate + '%');
                $('#avgOrderValue').html('€' + avgOrderValue);
                $('#completionRate').text(completionRate + '%');
                $('#successRate').text(successRate + '%');
                
                $('#conversionProgress').css('width', conversionRate + '%');
                $('#completionProgress').css('width', completionRate + '%');
                $('#successProgress').css('width', successRate + '%');
            }
        },
        error: function() {
            console.error('Failed to load statistics');
        }
    });
}

function loadChartData() {
    // Load weekly earnings data
    $.ajax({
        url: '{{ route("publisher.dashboard.weekly-earnings") }}',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                updateWeeklyChart(response.data);
            }
        },
        error: function() {
            console.error('Failed to load weekly earnings');
        }
    });
    
    // Load order status distribution
    $.ajax({
        url: '{{ route("publisher.dashboard.order-status") }}',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                updateStatusChart(response.data);
            }
        },
        error: function() {
            console.error('Failed to load order status');
        }
    });
    
    // Load monthly earnings
    $.ajax({
        url: '{{ route("publisher.dashboard.monthly-earnings") }}',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                updateMonthlyChart(response.data);
            }
        },
        error: function() {
            console.error('Failed to load monthly earnings');
        }
    });
}

function updateWeeklyChart(data) {
    var canvas = document.getElementById('weeklyEarningsChart');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    
    if (weeklyChart) {
        weeklyChart.destroy();
    }
    
    weeklyChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels || ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
                label: 'Earnings (€)',
                data: data.values || [0, 0, 0, 0, 0, 0, 0],
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
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '€' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '€' + value;
                        }
                    }
                }
            }
        }
    });
}

function updateStatusChart(data) {
    var canvas = document.getElementById('orderStatusChart');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    
    if (statusChart) {
        statusChart.destroy();
    }
    
    statusChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.labels || ['Pending', 'Processing', 'Completed', 'Cancelled'],
            datasets: [{
                data: data.values || [0, 0, 0, 0],
                backgroundColor: ['#fbbf24', '#60a5fa', '#4ade80', '#f87171'],
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
                    labels: {
                        boxWidth: 12,
                        padding: 10
                    }
                }
            },
            cutout: '60%'
        }
    });
}

function updateMonthlyChart(data) {
    var canvas = document.getElementById('monthlyEarningsChart');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    
    if (monthlyChart) {
        monthlyChart.destroy();
    }
    
    monthlyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels || ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [{
                label: 'Earnings (€)',
                data: data.values || [0, 0, 0, 0, 0, 0],
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
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '€' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        display: true,
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return '€' + value;
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
</script>
@endif

@endsection