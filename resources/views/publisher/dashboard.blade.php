@extends('publisher.layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $needsYou = (int) ($needsYou ?? 0);
    $waitingOnAdvertiser = (int) ($waitingOnAdvertiser ?? 0);
    $siteCount = (int) ($siteCount ?? 0);
    $unverifiedSiteCount = (int) ($unverifiedSiteCount ?? 0);
    $awaitingDetailsCount = (int) ($awaitingDetailsCount ?? 0);
    $inviteCount = (int) ($inviteCount ?? 0);
    $waitingOnStaffCount = (int) ($waitingOnStaffCount ?? 0);
    $liveSiteCount = (int) ($liveSiteCount ?? 0);
    $bulkBlocking = (bool) ($bulkBlocking ?? false);
    $unreadChat = (int) ($unreadChat ?? 0);
    $latestUnreadOrderId = $latestUnreadOrderId ?? null;
    $openDisputes = (int) ($openDisputes ?? 0);
    $debtBalance = (float) ($debtBalance ?? 0);
    $reservedBalance = (float) ($reservedBalance ?? 0);
    $payoutReady = (bool) ($payoutReady ?? false);
    $attentionQueues = $attentionQueues ?? [];
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
    $weeklyHasEarnings = collect($weeklyEarnings['values'] ?? [])->contains(fn ($v) => (float) $v != 0.0);
    $monthlyHasEarnings = collect($monthlyEarnings['values'] ?? [])->contains(fn ($v) => (float) $v != 0.0);
    $pendingReview = (float) ($stats['pending_earnings'] ?? 0);
    $inProgressEarnings = (float) ($stats['in_progress_earnings'] ?? 0);
    $pendingWallet = round($pendingReview + $inProgressEarnings, 2);
    $siteWorkCount = $awaitingDetailsCount + $inviteCount;
    $chatHref = route('publisher.tasks', array_filter([
        'focus' => 'messages',
        'order' => $latestUnreadOrderId,
    ]));
    $cta = match ($primaryAction) {
        'tasks' => [
            'title' => $needsYou === 1
                ? 'You have 1 task that needs you'
                : 'You have '.$needsYou.' tasks that need you',
            'body' => 'Accept, publish a live URL, or reply to a change request.',
            'href' => route('publisher.tasks', ['needs_action' => 1]),
            'button' => 'Open tasks',
        ],
        'chat' => [
            'title' => 'You have '.$unreadChat.' unread chat'.($unreadChat === 1 ? '' : 's'),
            'body' => 'An advertiser replied. Open the thread to keep the placement moving.',
            'href' => $chatHref,
            'button' => 'Open chats',
        ],
        'disputes' => [
            'title' => $openDisputes === 1 ? '1 open dispute on a placement' : $openDisputes.' open disputes on placements',
            'body' => 'An advertiser reported a live URL. Reply from Tasks so support can review it.',
            'href' => route('publisher.tasks'),
            'button' => 'View tasks',
        ],
        'debt' => [
            'title' => 'Withdrawals are blocked',
            'body' => 'Outstanding clawback debt of '.format_money($debtBalance).'. Contact support before withdrawing.',
            'href' => route('publisher.balance'),
            'button' => 'Open balance',
        ],
        'site_details' => [
            'title' => $bulkBlocking ? 'Finish your bulk listings' : 'Finish listing details',
            'body' => $awaitingDetailsCount > 0
                ? $awaitingDetailsCount.' site'.($awaitingDetailsCount === 1 ? '' : 's').' still need niche, language, or price before advertisers can find '.($awaitingDetailsCount === 1 ? 'it' : 'them').'.'
                : 'A bulk request is still waiting on you.',
            'href' => $bulkBlocking
                ? route('publisher.bulk-sites.complete')
                : route('publisher.websites', ['status' => 'pending']),
            'button' => 'Complete listings',
        ],
        'invites' => [
            'title' => $inviteCount === 1 ? 'Accept a site invite' : 'Accept site invites',
            'body' => $inviteCount.' listing'.($inviteCount === 1 ? '' : 's').' assigned to you — accept to start receiving orders.',
            'href' => route('publisher.websites', ['status' => 'invites']),
            'button' => 'Review invites',
        ],
        'verify_sites' => [
            'title' => 'Finish your listings',
            'body' => $unverifiedSiteCount.' site'.($unverifiedSiteCount === 1 ? '' : 's').' '.($unverifiedSiteCount === 1 ? 'is' : 'are').' not verified yet — advertisers cannot rely on '.($unverifiedSiteCount === 1 ? 'it' : 'them').' until '.($unverifiedSiteCount === 1 ? 'it is' : 'they are').'.',
            'href' => route('publisher.websites', ['status' => 'pending']),
            'button' => 'Review sites',
        ],
        'payout' => [
            'title' => 'Set up payout details',
            'body' => 'You have '.format_money($withdrawableBalance).' withdrawable. Save a payout method before requesting a withdrawal.',
            'href' => route('publisher.withdraw'),
            'button' => 'Set up payout',
        ],
        'add_site' => [
            'title' => 'Add your first website',
            'body' => 'List a site to start receiving advertiser orders.',
            'href' => route('publisher.websites'),
            'button' => 'Add site',
        ],
        default => [
            'title' => 'Grow your catalog',
            'body' => 'You have '.$siteCount.' site'.($siteCount === 1 ? '' : 's').' listed — add another niche or market.',
            'href' => route('publisher.websites'),
            'button' => 'Add site',
        ],
    };
    if ($siteWorkCount > 0) {
        $sitesKpiLabel = $inviteCount > 0 && $awaitingDetailsCount === 0 ? 'Site invites' : 'Need your details';
        $sitesKpiValue = $siteWorkCount;
        $sitesKpiSub = $siteCount.' total site'.($siteCount === 1 ? '' : 's');
        $sitesKpiHref = $inviteCount > 0 && $awaitingDetailsCount === 0
            ? route('publisher.websites', ['status' => 'invites'])
            : route('publisher.websites', ['status' => 'pending']);
        $sitesKpiWarn = true;
    } elseif ($waitingOnStaffCount > 0) {
        $sitesKpiLabel = 'Awaiting verification';
        $sitesKpiValue = $waitingOnStaffCount;
        $sitesKpiSub = $siteCount.' total site'.($siteCount === 1 ? '' : 's');
        $sitesKpiHref = route('publisher.websites', ['status' => 'pending']);
        $sitesKpiWarn = true;
    } else {
        $sitesKpiLabel = 'Live listings';
        $sitesKpiValue = $liveSiteCount;
        $sitesKpiSub = $unverifiedSiteCount > 0
            ? $siteCount.' total site'.($siteCount === 1 ? '' : 's')
            : 'All listed sites verified';
        $sitesKpiHref = route('publisher.websites');
        $sitesKpiWarn = false;
    }
@endphp

<div class="container-fluid dash-page-end publisher-dashboard">

    <div class="pub-dash-header">
        <div>
            <h2 class="pub-dash-title">Publisher Dashboard</h2>
            <p class="pub-dash-sub">
                Work that needs you, then earnings. Tasks and Sites still hold the full queues.
            </p>
        </div>
        <div class="pub-dash-links">
            <a href="{{ route('publisher.websites') }}" class="btn btn-sm btn-cta-secondary">My Sites</a>
            <a href="{{ route('publisher.tasks') }}" class="btn btn-sm btn-cta-secondary">Tasks</a>
            <a href="{{ route('publisher.withdraw') }}" class="btn btn-sm btn-cta-secondary">Withdraw</a>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100 publisher-primary-cta">
                <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <div class="text-uppercase small fw-semibold mb-1 pub-kicker">Do this next</div>
                        <h4 class="mb-1">{{ $cta['title'] }}</h4>
                        <p class="text-muted mb-0">{{ $cta['body'] }}</p>
                        @if($waitingOnAdvertiser > 0 && ! in_array($primaryAction, ['tasks', 'chat'], true))
                            <p class="small text-muted mb-0 mt-1">{{ $waitingOnAdvertiser }} placement{{ $waitingOnAdvertiser === 1 ? '' : 's' }} in review, waiting on advertisers.</p>
                        @elseif($waitingOnAdvertiser > 0 && $primaryAction === 'tasks')
                            <p class="small text-muted mb-0 mt-1">{{ $waitingOnAdvertiser }} more in review, waiting on advertisers.</p>
                        @endif
                    </div>
                    <a href="{{ $cta['href'] }}" class="btn btn-primary pub-cta-btn">
                        {{ $cta['button'] }} <i class="fa fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
        @if($primaryAction === 'tasks')
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-plus"></i></span>
                        <h6 class="mb-0">Add site</h6>
                    </div>
                    <p class="small text-muted mb-3">{{ $siteCount }} site{{ $siteCount === 1 ? '' : 's' }} listed</p>
                    <a href="{{ route('publisher.websites') }}" class="btn btn-sm btn-cta-secondary w-100">Add site</a>
                </div>
            </div>
        @else
            <div class="col-6 col-lg-2 flex-lg-grow-1">
                <div class="dash-panel h-100 publisher-secondary-cta">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="secondary-icon"><i class="fa fa-tasks"></i></span>
                        <h6 class="mb-0">Tasks</h6>
                    </div>
                    <p class="small text-muted mb-3">{{ $needsYou }} need you</p>
                    <a href="{{ route('publisher.tasks') }}" class="btn btn-sm btn-cta-secondary w-100">View tasks</a>
                </div>
            </div>
        @endif
        <div class="col-6 col-lg-2 flex-lg-grow-1">
            <div class="dash-panel h-100 publisher-secondary-cta">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="secondary-icon"><i class="fa fa-chart-line"></i></span>
                    <h6 class="mb-0">Reports</h6>
                </div>
                <p class="small text-muted mb-3">Earnings & performance</p>
                <a href="{{ route('publisher.reports') }}" class="btn btn-sm btn-cta-secondary w-100">View reports</a>
            </div>
        </div>
    </div>

    @if(count($attentionQueues) > 0)
        <div class="row g-3 mb-4 row-cols-1 row-cols-md-2 row-cols-xl-4" id="publisherAttentionQueues">
            @foreach($attentionQueues as $queue)
                <div class="col">
                    <a href="{{ $queue['href'] ?? route('publisher.tasks') }}" class="pub-queue-tile">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="pub-queue-icon"><i class="fa {{ $queue['icon'] ?? 'fa-circle' }}"></i></span>
                            <span class="pub-queue-label">{{ $queue['label'] ?? 'Queue' }}</span>
                            <span class="pub-queue-count ms-auto">{{ (int) ($queue['count'] ?? 0) }}</span>
                        </div>
                        <div class="pub-queue-detail">{{ $queue['detail'] ?? '' }}</div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    <!-- KPI strip (always visible) -->
    <div class="row g-3 mb-4 row-cols-2 row-cols-lg-3 row-cols-xl-5">
        <div class="col">
            <a href="{{ route('publisher.reports') }}" class="kpi-tile">
                <div class="kpi-icon kpi-icon--earnings"><i class="fa fa-euro-sign"></i></div>
                <div>
                    <span class="kpi-label">Total earnings</span>
                    <div class="kpi-value" id="totalEarnings">{{ format_money($stats['total_earnings']) }}</div>
                    <div class="kpi-sub">Lifetime completed · wallet {{ format_money($availableBalance) }}</div>
                </div>
            </a>
        </div>
        <div class="col">
            <div class="kpi-tile">
                <div class="kpi-icon kpi-icon--pending"><i class="fa fa-hourglass-half"></i></div>
                <div>
                    <span class="kpi-label">Pending earnings</span>
                    <div class="kpi-value" id="pendingEarnings">{{ format_money($pendingWallet) }}</div>
                    <div class="kpi-sub">
                        @if($inProgressEarnings > 0 && $pendingReview > 0)
                            {{ format_money($pendingReview) }} in review · {{ format_money($inProgressEarnings) }} publishing
                        @elseif($inProgressEarnings > 0)
                            Publishing, not yet in review
                        @else
                            In advertiser review
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <a href="{{ route('publisher.withdraw') }}" class="kpi-tile">
                <div class="kpi-icon kpi-icon--wallet"><i class="fa fa-wallet"></i></div>
                <div>
                    <span class="kpi-label">Available balance</span>
                    <div class="kpi-value" id="availableBalance">{{ format_money($availableBalance) }}</div>
                    <div class="kpi-sub">
                        @if($debtBalance > 0)
                            Debt {{ format_money($debtBalance) }} blocks withdrawals
                        @elseif($reservedBalance > 0)
                            Withdrawable {{ format_money($withdrawableBalance) }} · on hold {{ format_money($reservedBalance) }}
                        @else
                            Withdrawable {{ format_money($withdrawableBalance) }}
                        @endif
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="{{ $needsYou > 0 ? route('publisher.tasks', ['needs_action' => 1]) : route('publisher.tasks') }}" class="kpi-tile">
                <div class="kpi-icon kpi-icon--tasks"><i class="fa fa-tasks"></i></div>
                <div>
                    <span class="kpi-label">Needs you</span>
                    <div class="kpi-value" id="openTasks">{{ $needsYou }}</div>
                    <div class="kpi-sub">
                        @if($unreadChat > 0)
                            {{ $unreadChat }} unread chat{{ $unreadChat === 1 ? '' : 's' }}
                        @elseif($waitingOnAdvertiser > 0)
                            {{ $waitingOnAdvertiser }} in review with advertisers
                        @else
                            {{ (int) $stats['total_orders'] }} order{{ (int) $stats['total_orders'] === 1 ? '' : 's' }} total
                        @endif
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="{{ $sitesKpiHref }}" class="kpi-tile">
                <div class="kpi-icon {{ $sitesKpiWarn ? 'kpi-icon--warn' : 'kpi-icon--ok' }}"><i class="fa fa-{{ $sitesKpiWarn ? 'exclamation' : 'check' }}"></i></div>
                <div>
                    <span class="kpi-label">{{ $sitesKpiLabel }}</span>
                    <div class="kpi-value" id="unverifiedSites">{{ $sitesKpiValue }}</div>
                    <div class="kpi-sub">{{ $sitesKpiSub }}</div>
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
                        <a href="{{ route('publisher.websites') }}" class="btn btn-primary pub-cta-btn">
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
                <div class="card pub-card h-100">
                    <div class="card-header pub-card-head">
                        <span><i class="fa fa-chart-line me-2"></i> Weekly Earnings</span>
                        <span class="pub-card-meta">Last 7 days</span>
                    </div>
                    <div class="card-body pb-2">
                        @if($weeklyHasEarnings)
                            <canvas id="weeklyEarningsChart" height="200"></canvas>
                        @else
                            <div class="publisher-chart-empty text-muted">No completed payouts this week</div>
                        @endif
                    </div>
                    <p class="small text-muted px-3 pb-3 mb-0">Recognized on completion day; clawbacks appear on the reversal day.</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card pub-card h-100">
                    <div class="card-header pub-card-head">
                        <span><i class="fa fa-chart-area me-2"></i> Monthly Earnings</span>
                        <span class="pub-card-meta">Last 6 months</span>
                    </div>
                    <div class="card-body">
                        @if($monthlyHasEarnings)
                            <canvas id="monthlyEarningsChart" height="200"></canvas>
                        @else
                            <div class="publisher-chart-empty text-muted">No completed payouts yet</div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card pub-card h-100">
                    <div class="card-header pub-card-head">
                        <span><i class="fa fa-chart-pie me-2"></i> Order Status</span>
                        <span class="pub-card-meta">All time</span>
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
                <div class="card pub-card h-100">
                    <div class="card-header pub-card-head">
                        <span><i class="fa fa-tachometer me-2"></i> Performance Metrics</span>
                        <span class="pub-card-meta">All time</span>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="small text-muted">Success Rate</div>
                                <h4 class="mb-0" id="successRate">{{ number_format((float) $metrics['success_rate'], 1) }}%</h4>
                                <div class="progress pub-metric-bar mt-2">
                                    <div id="successProgress" class="progress-bar bg-primary" style="width: {{ min(100, (float) $metrics['success_rate']) }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">Finished work that completed (not cancelled)</div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="small text-muted">Avg. Payout</div>
                                <h4 class="mb-0" id="avgOrderValue">{{ format_money($metrics['avg_order_value']) }}</h4>
                                <div class="small text-muted mt-1">Per completed order</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Completion Rate</div>
                                <h4 class="mb-0" id="completionRate">{{ number_format((float) $metrics['completion_rate'], 1) }}%</h4>
                                <div class="progress pub-metric-bar mt-2">
                                    <div id="completionProgress" class="progress-bar bg-info" style="width: {{ min(100, (float) $metrics['completion_rate']) }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">Share of all orders already done</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Still open</div>
                                <h4 class="mb-0" id="openRate">{{ number_format((float) $metrics['open_rate'], 1) }}%</h4>
                                <div class="progress pub-metric-bar mt-2">
                                    <div id="openProgress" class="progress-bar bg-warning" style="width: {{ min(100, (float) $metrics['open_rate']) }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">Pending, publishing, in review, or scheduled</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8 mb-3 dash-recent-col">
                <div class="card pub-card h-100">
                    <div class="card-header pub-card-head">
                        <span><i class="fa fa-list me-2"></i> Recent tasks</span>
                        <a href="{{ $needsYou > 0 ? route('publisher.tasks', ['needs_action' => 1]) : route('publisher.tasks') }}" class="small pub-text-link">View all</a>
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
                                                $openUrl = $task['open_url'] ?? route('publisher.tasks');
                                            @endphp
                                            <tr>
                                                <td>
                                                    <strong>#{{ $task['order_number'] ?? $task['order_id'] ?? '—' }}</strong>
                                                    <div class="small text-muted">{{ $task['created_at_human'] ?? '' }}</div>
                                                </td>
                                                <td>
                                                    <div>{{ $task['site_name'] ?? 'Site' }}</div>
                                                    @if(!empty($task['site_url']))
                                                        <div class="small text-muted text-truncate recent-tasks-url">{{ $task['site_url'] }}</div>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="status-badge {{ $badgeClass }}">{{ ucfirst($status === 'review' ? 'In review' : $status) }}</span>
                                                    @if(!empty($task['needs_you']))
                                                        <div class="small text-muted mt-1">Needs you</div>
                                                    @endif
                                                </td>
                                                <td class="text-end fw-semibold">{{ format_money($task['payout'] ?? 0) }}</td>
                                                <td class="text-end">
                                                    <a href="{{ $openUrl }}" class="btn btn-sm btn-cta-secondary">Open</a>
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

@if($siteCount > 0 && ($weeklyHasEarnings || $monthlyHasEarnings || $statusHasOrders))
<script src="{{ asset('js/chart.umd.min.js') }}?v={{ @filemtime(public_path('js/chart.umd.min.js')) ?: '1' }}"></script>
<script>
(function () {
    var weeklyData = @json($weeklyEarnings);
    var monthlyData = @json($monthlyEarnings);
    var statusData = @json($orderStatus);

    function renderWeeklyChart(data) {
        var canvas = document.getElementById('weeklyEarningsChart');
        if (!canvas || typeof Chart === 'undefined') return;
        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Earnings',
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
                                return (window.slbFormatMoney || function (n) { return '€' + Number(n).toFixed(2); })(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) { return (window.slbFormatMoney || function (n) { return '€' + n; })(value); }
                        }
                    }
                }
            }
        });
    }

    function renderMonthlyChart(data) {
        var canvas = document.getElementById('monthlyEarningsChart');
        if (!canvas || typeof Chart === 'undefined') return;
        new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Earnings',
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
                                return (window.slbFormatMoney || function (n) { return '€' + Number(n).toFixed(2); })(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { display: true, drawBorder: false },
                        ticks: {
                            callback: function (value) { return (window.slbFormatMoney || function (n) { return '€' + n; })(value); }
                        }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    function renderStatusChart(data) {
        var canvas = document.getElementById('orderStatusChart');
        if (!canvas || typeof Chart === 'undefined') return;
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
