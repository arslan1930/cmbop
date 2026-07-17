@extends('advertiser.layouts.app')

@section('content')
@php
    $a = $analytics;
    $hasSpend = $a['has_spend'];
    $view = $view ?? 'day';
@endphp

<link href="{{ asset('css/advertiser-analytics.css') }}?v={{ @filemtime(public_path('css/advertiser-analytics.css')) ?: '1' }}" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="an-page">
    <div class="an-hero">
        <div>
            <h2>Spending History</h2>
            <p>Each bar shows how much you spent and how many orders that day, month, or order covers.</p>
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
                <canvas id="spendChart" height="120"></canvas>
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
    const labels = rows.map(r => r.short_label || r.label);
    const amounts = rows.map(r => Number(r.amount || 0));

    const hints = {
        order: 'Each bar is one paid order with its amount. Hover for website details.',
        day: 'Each bar is a day with purchases — amount spent and number of orders are shown on the bar.',
        month: 'Each bar is a month with purchases — amount spent and number of orders are shown on the bar.',
    };
    document.getElementById('chartHint').textContent = hints[view] || '';

    const ctx = document.getElementById('spendChart');
    if (!ctx) return;

    function money(n) {
        const v = Number(n || 0);
        return v % 1 === 0 ? ('€' + v.toFixed(0)) : ('€' + v.toFixed(2));
    }

    function barCaption(row) {
        if (!row) return '';
        if (view === 'order') {
            return money(row.amount);
        }
        const count = Number(row.orders || 0);
        const orderWord = count === 1 ? 'order' : 'orders';
        return money(row.amount) + ' · ' + count + ' ' + orderWord;
    }

    const spendLabelsPlugin = {
        id: 'spendLabels',
        afterDatasetsDraw(chart) {
            const { ctx: c } = chart;
            const meta = chart.getDatasetMeta(0);
            if (!meta || meta.hidden) return;

            c.save();
            c.textAlign = 'center';
            c.textBaseline = 'bottom';
            c.fillStyle = '#0b6266';
            c.font = '600 12px system-ui, -apple-system, Segoe UI, sans-serif';

            meta.data.forEach((el, index) => {
                const row = rows[index];
                if (!row || Number(row.amount) <= 0) return;

                const caption = barCaption(row);
                const { x, y } = el.getProps(['x', 'y'], true);
                // Keep label readable above the bar
                c.fillText(caption, x, Math.max(14, y - 8));
            });
            c.restore();
        }
    };

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Amount spent (€)',
                data: amounts,
                backgroundColor: 'rgba(11, 98, 102, 0.78)',
                hoverBackgroundColor: 'rgba(11, 98, 102, 0.95)',
                borderRadius: 8,
                maxBarThickness: 56,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            layout: { padding: { top: 28 } },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
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
                            if (view === 'order') {
                                return money(row?.amount) + ' spent';
                            }
                            const count = Number(row?.orders || 0);
                            const orderWord = count === 1 ? 'order' : 'orders';
                            return money(row?.amount) + ' spent across ' + count + ' ' + orderWord;
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 18,
                    },
                    grid: { display: false },
                    title: {
                        display: true,
                        text: view === 'order' ? 'Orders' : (view === 'day' ? 'Days with purchases' : 'Months with purchases'),
                        color: '#64748b',
                        font: { size: 12, weight: '600' },
                    }
                },
                y: {
                    beginAtZero: true,
                    grace: '18%',
                    ticks: { callback: (v) => '€' + v },
                    grid: { color: 'rgba(148, 163, 184, 0.25)' },
                    title: {
                        display: true,
                        text: 'Money spent',
                        color: '#64748b',
                        font: { size: 12, weight: '600' },
                    }
                }
            }
        },
        plugins: [spendLabelsPlugin]
    });
});
</script>
@endif
@endsection
