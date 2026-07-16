@extends('advertiser.layouts.app')

@section('content')
@php
    $a = $analytics;
    $hasSpend = $a['has_spend'];
    $sitesSort = $filters['sites_sort'] ?? 'spend';
@endphp

<link href="{{ asset('css/advertiser-analytics.css') }}?v={{ @filemtime(public_path('css/advertiser-analytics.css')) ?: '1' }}" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="an-page">
    <div class="an-hero">
        <div>
            <h2>Analytics & Insights</h2>
            <p>Understand purchasing behavior, forecast spend, and export reports for better decisions.</p>
        </div>
        <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary btn-sm">
            <i class="fa fa-list me-1"></i> Browse Websites
        </a>
    </div>

    @unless($hasSpend)
        <div class="an-empty">
            <h3>No spending history yet</h3>
            <p>Start exploring verified publishers and place your first order to unlock powerful spending analytics.</p>
            <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary">Browse Websites</a>
        </div>
    @else
        {{-- Summary KPIs --}}
        <div class="row g-3 mb-2">
            <div class="col-6 col-lg-3">
                <div class="an-card an-kpi">
                    <span class="label">Total spend</span>
                    <span class="value">€{{ number_format($a['summary']['total_spend'], 2) }}</span>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="an-card an-kpi">
                    <span class="label">Orders</span>
                    <span class="value">{{ number_format($a['summary']['total_orders']) }}</span>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="an-card an-kpi">
                    <span class="label">Avg order value</span>
                    <span class="value">€{{ number_format($a['summary']['avg_order_value'], 2) }}</span>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="an-card an-kpi">
                    <span class="label">Unique publishers</span>
                    <span class="value">{{ number_format($a['summary']['unique_publishers']) }}</span>
                </div>
            </div>
        </div>

        {{-- Smart Insights --}}
        <h4 class="an-section-title">Smart Insights</h4>
        <div class="row g-3 mb-1">
            @foreach($a['insights'] as $insight)
                <div class="col-md-6 col-xl-4">
                    <div class="an-insight">{{ $insight }}</div>
                </div>
            @endforeach
        </div>

        {{-- Comparisons --}}
        <h4 class="an-section-title">Month & Year Comparison</h4>
        <div class="row g-3">
            @foreach(['month' => $a['comparisons']['month'], 'year' => $a['comparisons']['year']] as $key => $cmp)
                <div class="col-lg-6">
                    <div class="an-card">
                        <h5>{{ $cmp['current_label'] }} vs {{ $cmp['previous_label'] }}</h5>
                        <div class="row g-3">
                            @foreach([
                                'spend' => ['Total Spend', true],
                                'orders' => ['Orders', false],
                                'aov' => ['Avg Order Value', true],
                            ] as $metric => [$label, $money])
                                @php $d = $cmp[$metric]; @endphp
                                <div class="col-4">
                                    <div class="small text-muted">{{ $label }}</div>
                                    <div class="fw-bold" style="color:#0b6266;">
                                        {{ $money ? '€'.number_format($d['current'], 2) : number_format($d['current']) }}
                                    </div>
                                    <span class="an-delta {{ $d['direction'] }}">
                                        @if($d['direction'] === 'up') ▲ @elseif($d['direction'] === 'down') ▼ @else — @endif
                                        {{ abs($d['pct']) }}%
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Forecast --}}
        <h4 class="an-section-title">Estimated Future Spending</h4>
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="an-card">
                    <h5>Spending Forecast</h5>
                    <p class="small text-muted mb-3">Based on previous spending trends (estimate only).</p>
                    <div class="an-forecast-grid">
                        <div class="an-forecast-pill">
                            <div class="label">Next Month</div>
                            <div class="value">€{{ number_format($a['forecast']['next_month'], 2) }}</div>
                        </div>
                        <div class="an-forecast-pill">
                            <div class="label">Next Quarter</div>
                            <div class="value">€{{ number_format($a['forecast']['next_quarter'], 2) }}</div>
                        </div>
                        <div class="an-forecast-pill">
                            <div class="label">Next Year</div>
                            <div class="value">€{{ number_format($a['forecast']['next_year'], 2) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="an-card">
                    <h5>Spend trend + projection</h5>
                    <canvas id="forecastChart" height="120"></canvas>
                </div>
            </div>
        </div>

        {{-- Top websites --}}
        <h4 class="an-section-title">Top Purchased Websites</h4>
        <div class="an-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0">Your most purchased placements</h5>
                <form method="get" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="calendar_year" value="{{ $filters['calendar_year'] }}">
                    <label class="small text-muted mb-0" for="sites_sort">Sort</label>
                    <select name="sites_sort" id="sites_sort" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                        <option value="spend" @selected($sitesSort === 'spend')>Highest Spend</option>
                        <option value="orders" @selected($sitesSort === 'orders')>Most Orders</option>
                        <option value="recent" @selected($sitesSort === 'recent')>Recently Purchased</option>
                    </select>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table an-table mb-0">
                    <thead>
                        <tr>
                            <th>Website</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Total Spent</th>
                            <th class="text-end">Avg Order</th>
                            <th class="text-end">Last Purchase</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($a['top_sites'] as $site)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $site['site_name'] }}</div>
                                    <div class="small text-muted text-truncate" style="max-width:280px;">{{ $site['site_url'] }}</div>
                                </td>
                                <td class="text-end">{{ $site['orders'] }}</td>
                                <td class="text-end">€{{ number_format($site['total_spend'], 2) }}</td>
                                <td class="text-end">€{{ number_format($site['aov'], 2) }}</td>
                                <td class="text-end">{{ optional($site['last_purchase'])->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">No website purchases yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Top categories --}}
        <h4 class="an-section-title">Top Categories</h4>
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="an-card">
                    <h5>Spend by category</h5>
                    <canvas id="categoryChart" height="200"></canvas>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="an-card">
                    <h5>Category breakdown</h5>
                    @foreach($a['top_categories'] as $cat)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-semibold">{{ $cat['category'] }}</span>
                                <span class="text-muted">€{{ number_format($cat['total_spend'], 2) }} · {{ $cat['pct'] }}% · {{ $cat['orders'] }} orders</span>
                            </div>
                            <div class="an-cat-bar"><span style="width: {{ min(100, $cat['pct']) }}%"></span></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Calendar heatmap --}}
        <h4 class="an-section-title">Spending Calendar</h4>
        <div class="an-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Activity heatmap</h5>
                    <p class="small text-muted mb-0">
                        {{ $a['calendar']['active_days'] }} active days · darker = higher spend
                    </p>
                </div>
                <form method="get" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="sites_sort" value="{{ $sitesSort }}">
                    <label class="small text-muted mb-0" for="calendar_year">Year</label>
                    <select name="calendar_year" id="calendar_year" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                        @foreach($a['calendar_years'] as $year)
                            <option value="{{ $year }}" @selected((int)$a['calendar_year'] === (int)$year)>{{ $year }}</option>
                        @endforeach
                        @unless(in_array(now()->year, $a['calendar_years'], true))
                            <option value="{{ now()->year }}" @selected((int)$a['calendar_year'] === now()->year)>{{ now()->year }}</option>
                        @endunless
                    </select>
                </form>
            </div>
            <div class="an-heatmap" aria-label="Spending calendar heatmap">
                @foreach($a['calendar']['days'] as $cell)
                    @if($cell['intensity'] < 0)
                        <div class="an-heat-cell i-1" aria-hidden="true"></div>
                    @else
                        <div class="an-heat-cell i{{ $cell['intensity'] }}"
                             title="{{ $cell['date'] }}: €{{ number_format($cell['spend'], 2) }}{{ $cell['spend'] <= 0 ? ' (no purchases)' : '' }}"></div>
                    @endif
                @endforeach
            </div>
            <div class="d-flex align-items-center gap-2 mt-3 small text-muted">
                <span>Less</span>
                <span class="an-heat-cell"></span>
                <span class="an-heat-cell i1"></span>
                <span class="an-heat-cell i2"></span>
                <span class="an-heat-cell i3"></span>
                <span class="an-heat-cell i4"></span>
                <span>More</span>
            </div>
        </div>

        {{-- Personal records --}}
        <h4 class="an-section-title">Personal Records</h4>
        <div class="row g-3">
            @php $r = $a['records']; @endphp
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">Highest Single Order</div><div class="value">€{{ number_format($r['highest_single_order']['amount'] ?? 0, 2) }}</div></div></div>
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">Largest Monthly Spend</div><div class="value">€{{ number_format($r['largest_monthly_spend']['amount'] ?? 0, 2) }}</div><div class="small text-muted">{{ $r['largest_monthly_spend']['month'] ?? '—' }}</div></div></div>
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">Most Expensive Website</div><div class="value">{{ $r['most_expensive_website']['name'] ?? '—' }}</div><div class="small text-muted">AOV €{{ number_format($r['most_expensive_website']['aov'] ?? 0, 2) }}</div></div></div>
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">Longest Purchasing Streak</div><div class="value">{{ $r['longest_streak_days'] ?? 0 }} day{{ ($r['longest_streak_days'] ?? 0) === 1 ? '' : 's' }}</div></div></div>
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">Most Frequent Website</div><div class="value">{{ $r['most_frequent_website']['name'] ?? '—' }}</div><div class="small text-muted">{{ $r['most_frequent_website']['orders'] ?? 0 }} orders</div></div></div>
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">Favorite Category</div><div class="value">{{ $r['favorite_category'] ?? '—' }}</div></div></div>
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">First Order</div><div class="value">{{ optional($r['first_order_date'] ?? null)->format('M j, Y') }}</div></div></div>
            <div class="col-6 col-md-4 col-xl-3"><div class="an-record"><div class="label">Latest Order</div><div class="value">{{ optional($r['latest_order_date'] ?? null)->format('M j, Y') }}</div></div></div>
        </div>

        {{-- Timeline --}}
        <h4 class="an-section-title">Order Activity Timeline</h4>
        <div class="an-card">
            <form method="get" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="sites_sort" value="{{ $sitesSort }}">
                <input type="hidden" name="calendar_year" value="{{ $filters['calendar_year'] }}">
                <div class="col-md-3">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="timeline_from" value="{{ $filters['timeline_from'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="timeline_to" value="{{ $filters['timeline_to'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Activity Type</label>
                    <select name="timeline_event" class="form-select form-select-sm">
                        <option value="">All activities</option>
                        @foreach([
                            'order.created' => 'Order Created',
                            'payment.successful' => 'Payment Successful',
                            'content.submitted' => 'Content Submitted',
                            'order.accepted' => 'Publisher Accepted',
                            'order.published' => 'Guest Post Published',
                            'order.completed' => 'Order Completed',
                            'order.rejected' => 'Order Rejected',
                            'refund.issued' => 'Refund Issued',
                            'order.modification_requested' => 'Modification Requested',
                            'chat.message' => 'Chat Message',
                        ] as $val => $label)
                            <option value="{{ $val }}" @selected(($filters['timeline_event'] ?? '') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Order</label>
                    <input type="text" name="timeline_order" value="{{ $filters['timeline_order'] }}" class="form-control form-control-sm" placeholder="Order #">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-primary btn-sm w-100" type="submit">Filter</button>
                </div>
            </form>

            <div class="an-timeline">
                @forelse($a['timeline'] as $event)
                    <div class="an-timeline-item">
                        <div class="time">{{ optional($event['timestamp'])->format('M j, Y g:i A') }}</div>
                        <div class="title">{{ $event['title'] ?: Str::headline(str_replace('.', ' ', $event['event'])) }}</div>
                        <div class="meta">
                            Order #{{ $event['order_number'] ?? $event['order_id'] }}
                            · {{ $event['website'] }}
                            · Status: {{ $event['status'] ?? '—' }}
                        </div>
                        @if($event['description'])
                            <div class="small text-muted mt-1">{{ $event['description'] }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">No activity matches your filters.</p>
                @endforelse
            </div>
        </div>

        {{-- Export Center --}}
        <h4 class="an-section-title">Export Center</h4>
        <div class="an-card">
            <p class="small text-muted mb-3">Exports respect your selected filters (sort, calendar year, and timeline filters).</p>
            <div class="an-export-grid">
                @foreach([
                    'spending' => 'Spending Reports',
                    'orders' => 'Order History',
                    'monthly' => 'Monthly Reports',
                    'websites' => 'Website Reports',
                    'categories' => 'Category Reports',
                ] as $type => $label)
                    <div class="an-export-card">
                        <strong>{{ $label }}</strong>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $fmt => $fmtLabel)
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('advertiser.analytics.export', array_filter([
                                       'type' => $type,
                                       'format' => $fmt,
                                       'sites_sort' => $filters['sites_sort'] ?? null,
                                       'calendar_year' => $filters['calendar_year'] ?? null,
                                       'timeline_from' => $filters['timeline_from'] ?? null,
                                       'timeline_to' => $filters['timeline_to'] ?? null,
                                       'timeline_event' => $filters['timeline_event'] ?? null,
                                       'timeline_order' => $filters['timeline_order'] ?? null,
                                   ])) }}">
                                    {{ $fmtLabel }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endunless
</div>

@if($hasSpend)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const history = @json($a['forecast']['series']);
    const projection = @json($a['forecast']['projection']);
    const histLabels = history.map(r => r.label);
    const histSpend = history.map(r => r.spend);
    const projLabels = projection.map(r => r.label);
    const projSpend = projection.map(r => r.spend);

    const forecastCtx = document.getElementById('forecastChart');
    if (forecastCtx) {
        new Chart(forecastCtx, {
            type: 'line',
            data: {
                labels: [...histLabels, ...projLabels],
                datasets: [
                    {
                        label: 'Actual spend',
                        data: [...histSpend, ...Array(projLabels.length).fill(null)],
                        borderColor: '#0b6266',
                        backgroundColor: 'rgba(11,98,102,0.12)',
                        tension: 0.3,
                        fill: true,
                    },
                    {
                        label: 'Estimated future spending',
                        data: [...Array(Math.max(histLabels.length - 1, 0)).fill(null), histSpend[histSpend.length - 1] ?? null, ...projSpend],
                        borderColor: '#3aaeb2',
                        borderDash: [6, 4],
                        tension: 0.3,
                        fill: false,
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: v => '€' + v }
                    }
                }
            }
        });
    }

    const cats = @json($a['top_categories']);
    const catCtx = document.getElementById('categoryChart');
    if (catCtx && cats.length) {
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: cats.map(c => c.category),
                datasets: [{
                    data: cats.map(c => c.total_spend),
                    backgroundColor: ['#0b6266', '#3aaeb2', '#7ecfcb', '#a7e0dd', '#c8ebe9', '#94a3b8', '#64748b'],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.label}: €${Number(ctx.raw).toFixed(2)}`
                        }
                    }
                }
            }
        });
    }
});
</script>
@endif
@endsection
