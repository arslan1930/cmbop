@extends('advertiser.layouts.app')

@section('title', 'Spending History')

@push('page-styles')
<link href="{{ asset('assets/css/single-select.css') }}?v={{ @filemtime(public_path('assets/css/single-select.css')) ?: '1' }}" rel="stylesheet">
<link href="{{ asset('assets/css/advertiser-analytics.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-analytics.css')) ?: '1' }}" rel="stylesheet">
@endpush

@section('content')
@php
    $a = $analytics;
    $hasSpend = $a['has_spend'];
    $view = $view ?? 'day';
    $summary = $a['summary'] ?? [];
    $dimension = $dimension ?? 'payment_method';
    $budgetStatus = $budgetStatus ?? ['has_budget' => false];
    $range = $range ?? [];
    $from = $range['from'] ?? request('from');
    $to = $range['to'] ?? request('to');
    $today = now()->toDateString();
    $last30 = now()->subDays(29)->toDateString();
    $monthStart = now()->startOfMonth()->toDateString();
    $activePreset = 'all';
    if ($from === $last30 && $to === $today) {
        $activePreset = '30d';
    } elseif ($from === $monthStart && $to === $today) {
        $activePreset = 'month';
    } elseif ($from || $to) {
        $activePreset = 'custom';
    }
    $budgetPercent = (float) ($budgetStatus['percent'] ?? 0);
    $budgetMeter = max(0, min(100, $budgetPercent));
@endphp

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="container-fluid an-page">
    <div class="an-page-header">
        <div>
            <h2 class="an-page-title">Spending History</h2>
            <p class="an-page-sub">
                Solid bars are completed spend. Dim bars are paid orders still in progress — they become spent when the order completes.
            </p>
        </div>
        <div class="an-page-links">
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('advertiser.analytics.export-csv', request()->only(['from','to'])) }}">Export CSV</a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('advertiser.analytics.export-pdf', request()->only(['from','to'])) }}">Export PDF</a>
        </div>
    </div>

    <div class="an-card an-filter-card mb-3">
        <form method="GET" action="{{ route('advertiser.analytics') }}" class="an-filter-bar" id="analyticsRangeForm"
              data-today="{{ $today }}" data-last30="{{ $last30 }}" data-month-start="{{ $monthStart }}">
            <div class="an-filter-bar__row">
                <div class="an-filter-bar__select">
                    <label class="form-label fw-semibold small text-muted mb-1" for="analyticsRangeFilter">Range</label>
                    @include('advertiser.partials.library-theme-select', [
                        'selectId' => 'analyticsRangeFilter',
                        'name' => 'range_preset',
                        'label' => 'Range',
                        'current' => $activePreset,
                        'options' => [
                            ['value' => 'all', 'label' => 'All time'],
                            ['value' => '30d', 'label' => 'Last 30 days'],
                            ['value' => 'month', 'label' => 'This month'],
                            ['value' => 'custom', 'label' => 'Custom dates'],
                        ],
                    ])
                </div>
                <div class="an-filter-bar__select">
                    <label class="form-label fw-semibold small text-muted mb-1" for="analyticsViewFilter">Chart</label>
                    @include('advertiser.partials.library-theme-select', [
                        'selectId' => 'analyticsViewFilter',
                        'name' => 'view',
                        'label' => 'Chart',
                        'current' => $view,
                        'options' => [
                            ['value' => 'day', 'label' => 'By day'],
                            ['value' => 'month', 'label' => 'By month'],
                            ['value' => 'order', 'label' => 'By order'],
                        ],
                    ])
                </div>
                <div class="an-filter-bar__select">
                    <label class="form-label fw-semibold small text-muted mb-1" for="analyticsBreakdownFilter">Breakdown</label>
                    @include('advertiser.partials.library-theme-select', [
                        'selectId' => 'analyticsBreakdownFilter',
                        'name' => 'breakdown',
                        'label' => 'Breakdown',
                        'current' => $dimension,
                        'options' => [
                            ['value' => 'payment_method', 'label' => 'Method'],
                            ['value' => 'country', 'label' => 'Country'],
                            ['value' => 'category', 'label' => 'Category'],
                            ['value' => 'site', 'label' => 'Site'],
                            ['value' => 'sensitive', 'label' => 'Sensitive'],
                        ],
                    ])
                </div>
                <div class="an-filter-bar__dates">
                    <span class="form-label fw-semibold small text-muted mb-1">Date range</span>
                    <div class="an-date-range">
                        <label class="visually-hidden" for="analyticsFrom">From</label>
                        <input type="date" name="from" id="analyticsFrom" class="form-control form-control-sm" value="{{ $from }}">
                        <label class="visually-hidden" for="analyticsTo">To</label>
                        <input type="date" name="to" id="analyticsTo" class="form-control form-control-sm" value="{{ $to }}">
                    </div>
                </div>
                <div class="an-filter-bar__actions">
                    <span class="form-label fw-semibold small text-muted mb-1 an-filter-bar__action-label" aria-hidden="true">&nbsp;</span>
                    <div class="an-filter-bar__action-row">
                        <button type="submit" class="btn btn-sm btn-primary px-3">
                            <i class="fa-solid fa-filter" aria-hidden="true"></i> Filter
                        </button>
                        <a href="{{ route('advertiser.analytics') }}" class="btn btn-sm btn-cta-secondary px-3">
                            <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reset
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="an-summary mb-3">
        <div>
            <span class="label">Net spend</span>
            <span class="value">{{ format_money($summary['net'] ?? $a['total_spend'] ?? 0) }}</span>
        </div>
        <div>
            <span class="label">Gross</span>
            <span class="value">{{ format_money($summary['gross'] ?? 0) }}</span>
        </div>
        <div>
            <span class="label">Refunded</span>
            <span class="value">{{ format_money($summary['refunded'] ?? 0) }}</span>
        </div>
        <div>
            <span class="label">Spent (completed)</span>
            <span class="value">{{ format_money($summary['spent'] ?? 0) }}</span>
        </div>
        <div>
            <span class="label">In progress</span>
            <span class="value">{{ format_money($summary['in_progress'] ?? 0) }}</span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            @unless($hasSpend)
                <div class="an-empty">
                    <h3>No spending history yet</h3>
                    <p>Place your first order to start tracking spend over time.</p>
                    <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary">Browse Websites</a>
                </div>
            @else
                <div class="an-card">
                    <div class="an-toolbar">
                        <h5 class="mb-0">Spend over time</h5>
                    </div>
                    <div class="an-chart-wrap">
                        <canvas id="spendChart" height="120"></canvas>
                    </div>
                    <p class="an-hint" id="chartHint"></p>
                </div>
            @endunless
        </div>
        <div class="col-lg-4">
            <div class="an-card mb-3">
                <h5 class="mb-3">Monthly budget</h5>
                <form method="POST" action="{{ route('advertiser.analytics.budget') }}">
                    @csrf
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Monthly limit (€)</label>
                        <input type="number" step="0.01" min="0" name="monthly_limit" class="form-control form-control-sm"
                               value="{{ old('monthly_limit', $budget?->monthly_limit) }}" placeholder="e.g. 500">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Warn at %</label>
                        <input type="number" min="1" max="100" name="warn_at_percent" class="form-control form-control-sm"
                               value="{{ old('warn_at_percent', $budget?->warn_at_percent ?? 80) }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Low balance alert (€)</label>
                        <input type="number" step="0.01" min="0" name="low_balance_threshold" class="form-control form-control-sm"
                               value="{{ old('low_balance_threshold', $budget?->low_balance_threshold) }}" placeholder="e.g. 50">
                    </div>
                    <div class="form-check mb-1">
                        <input type="hidden" name="notify_bell" value="0">
                        <input class="form-check-input" type="checkbox" name="notify_bell" value="1" id="notifyBell"
                               @checked(old('notify_bell', $budget?->notify_bell ?? true))>
                        <label class="form-check-label small" for="notifyBell">Bell alerts</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="hidden" name="notify_email" value="0">
                        <input class="form-check-input" type="checkbox" name="notify_email" value="1" id="notifyEmail"
                               @checked(old('notify_email', $budget?->notify_email ?? true))>
                        <label class="form-check-label small" for="notifyEmail">Email alerts</label>
                    </div>
                    <button class="btn btn-sm btn-primary w-100 an-budget-save" type="submit">Save budget</button>
                </form>
                @if(!empty($budgetStatus['monthly_limit']))
                    <div class="an-budget-status">
                        <div class="an-budget-meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($budgetMeter) }}">
                            <span style="width: {{ $budgetMeter }}%;"></span>
                        </div>
                        <p class="small text-muted mb-0">
                            This month committed:
                            <strong>{{ format_money($budgetStatus['committed']) }}</strong>
                            / {{ format_money($budgetStatus['monthly_limit']) }}
                            ({{ number_format((float) $budgetStatus['percent'], 1) }}%)
                        </p>
                    </div>
                @endif
            </div>

            <div class="an-card">
                <h5 class="mb-3">Breakdown</h5>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 an-break-table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th class="text-end">Net</th>
                                <th class="text-end">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($breakdown as $row)
                                <tr>
                                    <td class="small">{{ $row['label'] }}</td>
                                    <td class="text-end small">{{ format_money($row['net']) }}</td>
                                    <td class="text-end small">{{ $row['orders'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted small">No breakdown yet</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if($hasSpend)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const view = @json($view);
    const rows = @json($a['series'] ?? []);
    const labels = rows.map(r => r.short_label || r.label);
    const spent = rows.map(r => Number(r.spent || 0));
    const inProgress = rows.map(r => Number(r.in_progress || 0));

    document.getElementById('chartHint').textContent =
        'Solid = completed spend. Dim = paid orders still processing — they move to spent when completed (same day/month).';

    const ctx = document.getElementById('spendChart');
    if (!ctx) return;

    function money(n) {
        const v = Number(n || 0);
        return (window.slbFormatMoney || function (x) { return '€' + Number(x).toFixed(2); })(v);
    }

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Spent (completed)',
                    data: spent,
                    backgroundColor: 'rgba(26, 88, 94, 0.88)',
                    hoverBackgroundColor: 'rgba(26, 88, 94, 1)',
                    borderRadius: 6,
                    maxBarThickness: 52,
                    stack: 'spend',
                },
                {
                    label: 'In progress (will add when completed)',
                    data: inProgress,
                    backgroundColor: 'rgba(26, 88, 94, 0.28)',
                    hoverBackgroundColor: 'rgba(63, 174, 178, 0.55)',
                    borderColor: 'rgba(26, 88, 94, 0.35)',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 52,
                    stack: 'spend',
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            layout: { padding: { top: 16 } },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            const row = rows[items[0].dataIndex];
                            if (!row) return '';
                            if (view === 'order') {
                                return (row.short_label || row.label)
                                    + (row.website && row.website !== '—' ? ' · ' + row.website : '');
                            }
                            return row.label;
                        },
                        label: (item) => {
                            const row = rows[item.dataIndex];
                            if (item.datasetIndex === 0) {
                                return money(item.raw) + ' spent'
                                    + (view !== 'order' ? ' (' + (row?.spent_orders || 0) + ' completed)' : '');
                            }
                            if (!item.raw) return null;
                            return money(item.raw) + ' in progress — adds to spent when completed'
                                + (view !== 'order' ? ' (' + (row?.in_progress_orders || 0) + ' orders)' : '');
                        }
                    }
                }
            },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => (window.slbFormatMoney || function (x) { return '€' + x; })(v)
                    }
                }
            }
        }
    });
});
</script>
@endif
@endsection

@push('scripts')
<script src="{{ asset('assets/js/single-select.js') }}?v={{ @filemtime(public_path('assets/js/single-select.js')) ?: '1' }}" defer></script>
<script src="{{ asset('assets/js/advertiser-analytics.js') }}?v={{ @filemtime(public_path('assets/js/advertiser-analytics.js')) ?: '1' }}" defer></script>
@endpush
