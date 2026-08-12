@extends('marketing.layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="container-fluid">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Marketing workspace</h1>
            <p class="text-muted mb-0">Add and edit sites, manage bulk onboarding, and track every task you’ve completed.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('marketing.sites.index') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-globe me-1"></i> Sites
            </a>
            <a href="{{ route('marketing.agency-imports.index') }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-file-csv me-1"></i> Agency CSV
                @if(($stats['open_agency_imports'] ?? 0) > 0)
                    <span class="badge text-bg-warning text-dark ms-1">{{ $stats['open_agency_imports'] }}</span>
                @endif
            </a>
            <a href="{{ route('marketing.bulk-site-requests.index') }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-layer-group me-1"></i> Bulk requests
            </a>
            <a href="{{ route('marketing.history') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-history me-1"></i> Full history
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Pending sites</div>
                    <h3 class="mb-0 text-warning">{{ $stats['pending_sites'] }}</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Open bulk requests</div>
                    <h3 class="mb-0 text-primary">{{ $stats['open_bulk_requests'] }}</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">My tasks today</div>
                    <h3 class="mb-0 text-success">{{ $stats['my_tasks_today'] }}</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">My tasks (all time)</div>
                    <h3 class="mb-0">{{ $stats['my_tasks_total'] }}</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-clock me-2 text-warning"></i>Pending sites</strong>
                    <a href="{{ route('marketing.sites.index') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Site</th>
                                    <th>Publisher</th>
                                    <th width="100">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($pendingSites as $site)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $site->site_name ?: '—' }}</div>
                                            <div class="small text-muted text-truncate" style="max-width:260px;">{{ $site->site_url }}</div>
                                        </td>
                                        <td class="small">
                                            {{ $site->publisher?->name ?? 'Unknown' }}
                                        </td>
                                        <td>
                                            <a href="{{ route('marketing.sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">No pending sites right now.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-layer-group me-2 text-primary"></i>Open bulk requests</strong>
                    <a href="{{ route('marketing.bulk-site-requests.index') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Publisher</th>
                                    <th>Status</th>
                                    <th width="90">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($openBulk as $req)
                                    <tr>
                                        <td class="small">
                                            <div class="fw-semibold">{{ $req->publisher?->name ?? '—' }}</div>
                                            <div class="text-muted">#{{ $req->id }}</div>
                                        </td>
                                        <td><span class="badge bg-secondary text-capitalize">{{ str_replace('_', ' ', $req->status) }}</span></td>
                                        <td>
                                            <a href="{{ route('marketing.bulk-site-requests.show', $req) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">No open bulk requests.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <strong><i class="fa fa-history me-2"></i>Your recent tasks</strong>
            <a href="{{ route('marketing.history') }}" class="small">See full history</a>
        </div>
        <div class="card-body p-0">
            @include('marketing.partials.history-table', ['logs' => $recentHistory])
        </div>
    </div>

    <div class="alert alert-info border-0 mt-4 mb-0">
        <i class="fa fa-info-circle me-1"></i>
        You can add/edit sites, manage bulk drafts, and delete pending (not-live) sites.
        Admin handles verify, activate, enrichment, payments, and users.
    </div>

</div>
@endsection
