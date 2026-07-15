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

    <!-- Quick Actions -->
    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                        <i class="fa fa-plus fa-2x text-primary"></i>
                    </div>
                    <h5 class="card-title">Add New Site</h5>
                    <p class="card-text text-muted small">Register a new website to start receiving orders.</p>
                    <a href="{{ route('publisher.websites') }}" class="btn btn-outline-primary btn-sm">
                        Add Site <i class="fa fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body">
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                        <i class="fa fa-tasks fa-2x text-success"></i>
                    </div>
                    <h5 class="card-title">My Tasks</h5>
                    <p class="card-text text-muted small">View and manage pending orders that need your attention.</p>
                    <a href="{{ route('publisher.tasks') }}" class="btn btn-outline-success btn-sm">
                        View Tasks <i class="fa fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body">
                    <div class="bg-info bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                        <i class="fa fa-chart-line fa-2x text-info"></i>
                    </div>
                    <h5 class="card-title">View Reports</h5>
                    <p class="card-text text-muted small">Analyze your earnings and performance metrics.</p>
                    <a href="{{ route('publisher.reports') }}" class="btn btn-outline-info btn-sm">
                        View Reports <i class="fa fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modern Small Graphs Section -->
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
                    <i class="fa fa-trend-up me-2 text-info"></i> Monthly Earnings
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
                            <h4 class="mb-0" id="conversionRate">0%</h4>
                            <div class="progress mt-2" style="height: 4px;">
                                <div id="conversionProgress" class="progress-bar bg-success" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="small text-muted">Avg. Order Value</div>
                            <h4 class="mb-0" id="avgOrderValue">€0</h4>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Completion Rate</div>
                            <h4 class="mb-0" id="completionRate">0%</h4>
                            <div class="progress mt-2" style="height: 4px;">
                                <div id="completionProgress" class="progress-bar bg-info" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Success Rate</div>
                            <h4 class="mb-0" id="successRate">0%</h4>
                            <div class="progress mt-2" style="height: 4px;">
                                <div id="successProgress" class="progress-bar bg-primary" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-chart-pie me-2 text-success"></i> Order Distribution
                    <span class="float-end text-muted small">By Status</span>
                </div>
                <div class="card-body">
                    <canvas id="orderStatusChart" height="200"></canvas>
                </div>
            </div>
        </div> -->
    </div>
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
</style>

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
    var ctx = document.getElementById('weeklyEarningsChart').getContext('2d');
    
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
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#3b82f6',
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
    var ctx = document.getElementById('orderStatusChart').getContext('2d');
    
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
    var ctx = document.getElementById('monthlyEarningsChart').getContext('2d');
    
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
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
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

@endsection