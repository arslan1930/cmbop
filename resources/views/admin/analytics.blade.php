@extends('admin.layouts.app')

@section('title', 'Marketplace analytics')

@section('content')
@php
    $kpis = $data['kpis'] ?? [];
    $euro = fn ($n) => '€'.number_format((float) $n, 2);
    $keepQuery = fn ($value) => $value !== null && $value !== '';
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Marketplace analytics</h1>
            <p class="text-muted mb-0">Period mix for listings and paid GMV — not the finance ledger. Use Finance for cash and liability.</p>
        </div>
        <a href="{{ route('admin.analytics.export', $exportQuery) }}" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-file-csv me-1"></i> Export CSV
        </a>
    </div>

    <form method="GET" action="{{ route('admin.analytics') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <div class="btn-group btn-group-sm" role="group">
                        @foreach(['week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $key => $label)
                            <a href="{{ route('admin.analytics', array_filter(['period' => $key], $keepQuery)) }}"
                               class="btn {{ $periodKey === $key && !$dateFrom && !$dateTo ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="analyticsDateFrom">From</label>
                    <input type="date" id="analyticsDateFrom" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="analyticsDateTo">To</label>
                    <input type="date" id="analyticsDateTo" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Apply range</button>
                </div>
                <div class="col-auto ms-md-auto">
                    <span class="badge text-bg-light border">Period: {{ $data['period']['label'] ?? $periodKey }}</span>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">Paid GMV</div>
                    <div class="fs-4 fw-semibold">{{ $euro($kpis['paid_gmv'] ?? 0) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">Paid orders</div>
                    <div class="fs-4 fw-semibold">{{ number_format((int) ($kpis['paid_orders'] ?? 0)) }}</div>
                    <div class="small text-muted">{{ number_format((int) ($kpis['completed_orders'] ?? 0)) }} completed</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">New users</div>
                    <div class="fs-4 fw-semibold">{{ number_format((int) ($kpis['new_users'] ?? 0)) }}</div>
                    <div class="small text-muted">{{ (int) ($kpis['new_advertisers'] ?? 0) }} advertisers · {{ (int) ($kpis['new_publishers'] ?? 0) }} publishers</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">New sites</div>
                    <div class="fs-4 fw-semibold">{{ number_format((int) ($kpis['new_sites'] ?? 0)) }}</div>
                    <div class="small text-muted">{{ number_format((int) ($kpis['live_sites'] ?? 0)) }} live now</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Live listings by niche</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            @forelse($data['niches'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="text-end">{{ $row['count'] }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-muted px-3 py-3">No live listings.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Live listings by country</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            @forelse($data['countries'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="text-end">{{ $row['count'] }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-muted px-3 py-3">No live listings.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Paid GMV by niche</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            @forelse($data['niche_gmv'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="text-end">{{ $euro($row['gmv']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-muted px-3 py-3">No paid orders in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
