@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Publisher Enrichment</h4>
            <p class="text-muted mb-0">SEO metrics, screenshots, scan failures, and refresh configuration.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.sites.index') }}" class="btn btn-sm btn-outline-secondary">Sites</a>
            <button type="button" class="btn btn-sm btn-primary" id="rerunFailedBtn">
                Re-run failed scans
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Enrichment</div>
                    <div class="fw-semibold">{{ $config['enabled'] ? 'Enabled' : 'Disabled' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Metrics provider</div>
                    <div class="fw-semibold text-uppercase">{{ $config['default_provider'] }}</div>
                    <div class="small text-muted">Fallbacks: {{ implode(', ', $config['fallback_providers'] ?? []) ?: '—' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Refresh frequency</div>
                    <div class="fw-semibold text-capitalize">{{ $config['refresh_frequency'] }}</div>
                    <div class="small text-muted">Max age: {{ $config['max_age_days'] }} days</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Stale / missing</div>
                    <div class="fw-semibold">{{ number_format($staleCount) }} sites</div>
                    <div class="small text-muted">Screenshot: {{ $config['screenshot_provider'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-light border">
        Configure providers via environment variables:
        <code>SITE_METRICS_PROVIDER</code>,
        <code>AHREFS_API_KEY</code>,
        <code>MOZ_ACCESS_TOKEN</code>,
        <code>SEMRUSH_API_KEY</code>,
        <code>SITE_SCREENSHOT_PROVIDER</code>,
        <code>SITE_ENRICHMENT_FREQUENCY</code>.
        Manual metrics can be set per site from Sites Management.
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Recent scan failures</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>Site</th>
                        <th>Type</th>
                        <th>Provider</th>
                        <th>Error</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($failures as $run)
                        <tr>
                            <td class="small text-muted">{{ optional($run->created_at)->diffForHumans() }}</td>
                            <td>
                                @if($run->site)
                                    <div class="fw-semibold">{{ $run->site->site_name }}</div>
                                    <div class="small text-muted">{{ $run->site->domain }}</div>
                                @else
                                    <span class="text-muted">#{{ $run->site_id }}</span>
                                @endif
                            </td>
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ $run->type }}</span></td>
                            <td>{{ $run->provider ?: '—' }}</td>
                            <td class="small text-danger" style="max-width:320px;">{{ Str::limit($run->error, 160) }}</td>
                            <td class="text-end">
                                @if($run->site_id)
                                    <button type="button" class="btn btn-sm btn-outline-primary enrich-site-btn" data-id="{{ $run->site_id }}">
                                        Re-run
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No failed scans recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $failures->links() }}</div>
    </div>
</div>

<script>
document.getElementById('rerunFailedBtn')?.addEventListener('click', async () => {
    const res = await fetch(@json(route('admin.site-enrichment.rerun-failed')), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ limit: 20 }),
    });
    const data = await res.json();
    Swal.fire({
        icon: data.success ? 'success' : 'error',
        title: data.message || 'Done',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 2500,
    });
});

document.querySelectorAll('.enrich-site-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        btn.disabled = true;
        try {
            const res = await fetch(`/admin/sites/${id}/enrich`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ sync: true }),
            });
            const data = await res.json();
            Swal.fire({
                icon: data.success ? 'success' : 'error',
                title: data.message || 'Done',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2200,
            });
        } finally {
            btn.disabled = false;
        }
    });
});
</script>
@endsection
