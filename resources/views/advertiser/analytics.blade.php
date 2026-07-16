@extends('advertiser.layouts.app')

@section('content')
@php
    $a = $analytics;
    $hasSpend = $a['has_spend'];
    $view = $view ?? 'month';
@endphp

<link href="{{ asset('css/advertiser-analytics.css') }}?v={{ @filemtime(public_path('css/advertiser-analytics.css')) ?: '1' }}" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="an-page">
    <div class="an-hero">
        <div>
            <h2>Spending History</h2>
            <p>See how much you spent on each order, day, or month — from your first purchase to now.</p>
        </div>
    </div>

    @unless($hasSpend)
        <div class="an-empty">
            <h3>No spending history yet</h3>
            <p>Place your first order to start tracking spend over time.</p>
            <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary">Browse Websites</a>
        </div>
    @else
        <div class="an-summary">
            <div>
                <span class="label">Total spent</span>
                <span class="value">€{{ number_format($a['total_spend'], 2) }}</span>
            </div>
            <div>
                <span class="label">Orders</span>
                <span class="value">{{ number_format($a['total_orders']) }}</span>
            </div>
            <div>
                <span class="label">History from</span>
                <span class="value value-sm">{{ optional($a['first_order_at'])->format('M j, Y') }}</span>
            </div>
            <div>
                <span class="label">Through</span>
                <span class="value value-sm">{{ optional($a['last_order_at'])->format('M j, Y') }}</span>
            </div>
        </div>

        <div class="an-card">
            <div class="an-toolbar">
                <h5 class="mb-0">Spend over time</h5>
                <div class="an-toggle" role="group" aria-label="Chart view">
                    <a href="{{ route('advertiser.analytics', ['view' => 'order']) }}"
                       class="{{ $view === 'order' ? 'active' : '' }}">By order</a>
                    <a href="{{ route('advertiser.analytics', ['view' => 'day']) }}"
                       class="{{ $view === 'day' ? 'active' : '' }}">By day</a>
                    <a href="{{ route('advertiser.analytics', ['view' => 'month']) }}"
                       class="{{ $view === 'month' ? 'active' : '' }}">By month</a>
                </div>
            </div>
            <div class="an-chart-wrap">
                <canvas id="spendChart" height="110"></canvas>
            </div>
            <p class="an-hint" id="chartHint"></p>
        </div>
    @endunless
</div>

@if($hasSpend)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const view = @json($view);
    const series = {
        order: @json($a['by_order']),
        day: @json($a['by_day']),
        month: @json($a['by_month']),
    };
    const rows = series[view] || [];
    const labels = rows.map(r => r.label);
    const amounts = rows.map(r => Number(r.amount || 0));

    const hints = {
        order: 'Each bar is one paid order — full history since your first purchase.',
        day: 'Daily totals for every day from your first order through your latest.',
        month: 'Monthly totals for every month from your first order through your latest.',
    };
    document.getElementById('chartHint').textContent = hints[view] || '';

    const ctx = document.getElementById('spendChart');
    if (!ctx) return;

    const isOrder = view === 'order';
    new Chart(ctx, {
        type: isOrder ? 'bar' : 'line',
        data: {
            labels,
            datasets: [{
                label: 'Amount spent (€)',
                data: amounts,
                borderColor: '#0b6266',
                backgroundColor: isOrder ? 'rgba(11, 98, 102, 0.72)' : 'rgba(11, 98, 102, 0.14)',
                fill: !isOrder,
                tension: 0.3,
                pointRadius: isOrder ? 0 : 4,
                pointHoverRadius: 6,
                borderWidth: isOrder ? 0 : 2.5,
                borderRadius: isOrder ? 6 : 0,
                maxBarThickness: 42,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            const row = rows[items[0].dataIndex];
                            if (!row) return '';
                            if (view === 'order') {
                                return row.label + (row.website && row.website !== '—' ? ' · ' + row.website : '');
                            }
                            return row.label;
                        },
                        label: (item) => '€' + Number(item.raw).toFixed(2) + ' spent'
                            + (rows[item.dataIndex]?.orders != null
                                ? ' · ' + rows[item.dataIndex].orders + ' order(s)'
                                : ''),
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: view === 'day' ? 14 : 18,
                    },
                    grid: { display: false },
                },
                y: {
                    beginAtZero: true,
                    ticks: { callback: (v) => '€' + v },
                    grid: { color: 'rgba(148, 163, 184, 0.25)' },
                }
            }
        }
    });
});
</script>
@endif
@endsection
