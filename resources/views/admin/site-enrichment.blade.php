@extends(staff_layout())

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Publisher Enrichment</h4>
            <p class="text-muted mb-0">SEO metrics, screenshots, scan failures, and refresh configuration.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-secondary">Sites</a>
            <button type="button" class="btn btn-sm btn-primary" id="rerunFailedBtn">
                Re-run needs attention
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
                    <div class="small text-muted">
                        @if(empty($config['has_api_keys']))
                            no API keys
                        @else
                            Fallbacks: {{ implode(', ', $config['fallback_providers'] ?? []) ?: '—' }}
                        @endif
                    </div>
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
            <a href="#stale-sites" class="text-decoration-none text-reset">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Stale / missing</div>
                        <div class="fw-semibold">{{ number_format($staleCount) }} sites</div>
                        <div class="small text-muted">Screenshot: {{ $config['screenshot_provider'] }}</div>
                    </div>
                </div>
            </a>
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
        A manual lock skips API providers until you click Allow API overwrite.
        Re-run and Queue stale enqueue jobs — <code>php artisan queue:work --queue=default,emails</code> must be running.
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold">Needs attention</span>
            <form method="get" class="admin-deposits-filters staff-site-filters d-flex flex-wrap align-items-end gap-2">
                <div>
                <label class="form-label" for="attentionStatus">Status</label>
                <select name="status" id="attentionStatus" class="form-select">
                    <option value="" @selected($status === null)>All</option>
                    <option value="failed" @selected($status === 'failed')>Failed</option>
                    <option value="partial" @selected($status === 'partial')>Partial</option>
                </select>
                </div>
                <div>
                <label class="form-label" for="attentionType">Type</label>
                <select name="type" id="attentionType" class="form-select">
                    <option value="" @selected($type === null)>All</option>
                    <option value="metrics" @selected($type === 'metrics')>Metrics</option>
                    <option value="screenshot" @selected($type === 'screenshot')>Screenshot</option>
                </select>
                </div>
                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>Site</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Provider</th>
                        <th>Error</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($attention as $run)
                        <tr>
                            <td class="small text-muted">{{ optional($run->created_at)->diffForHumans() }}</td>
                            <td>
                                @if($run->site)
                                    <a href="{{ staff_route('sites.edit', $run->site->id) }}" class="fw-semibold text-decoration-none">{{ $run->site->site_name }}</a>
                                    <div class="small text-muted">{{ $run->site->domain }}</div>
                                @else
                                    <span class="text-muted">#{{ $run->site_id }}</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $statusClass = match ($run->status) {
                                        'failed' => 'bg-danger-subtle text-danger',
                                        'partial' => 'bg-warning-subtle text-warning-emphasis',
                                        'running' => 'bg-info-subtle text-info',
                                        default => 'bg-secondary-subtle text-secondary',
                                    };
                                @endphp
                                <span class="badge {{ $statusClass }}">{{ $run->status }}</span>
                            </td>
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ $run->type }}</span></td>
                            <td>{{ $run->provider ?: '—' }}</td>
                            <td class="small text-danger" style="max-width:320px;">{{ Str::limit($run->error, 160) }}</td>
                            <td class="text-end">
                                @if($run->site && (! $marketingEditor || ! $run->site->isLockedForMarketingEdits()))
                                    <button type="button" class="btn btn-sm btn-outline-primary enrich-site-btn" data-id="{{ $run->site->id }}">
                                        Re-run
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No scans need attention.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $attention->links() }}</div>
    </div>

    <div class="card border-0 shadow-sm mt-4" id="stale-sites">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold">Stale sites</span>
            <button type="button" class="btn btn-sm btn-primary" id="queueStaleBtn" @disabled($staleCount < 1)>
                Queue stale ({{ min($staleCount, $batchLimit) }})
            </button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Site</th>
                        <th>Domain</th>
                        <th>What's missing</th>
                        <th>Last status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($staleSites as $site)
                        @php
                            $reasons = $site->enrichmentStaleReasons(! empty($placeholderSiteIds[$site->id]));
                            $latest = $site->relationLoaded('latestEnrichmentRun') ? $site->latestEnrichmentRun : null;
                        @endphp
                        <tr data-stale-site-id="{{ $site->id }}">
                            <td>
                                <a href="{{ staff_route('sites.edit', $site->id) }}" class="fw-semibold text-decoration-none">{{ $site->site_name }}</a>
                            </td>
                            <td class="small text-muted">{{ $site->domain }}</td>
                            <td>
                                @forelse($reasons as $reason)
                                    <span class="badge bg-warning-subtle text-warning-emphasis">{{ $reason }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td>
                                @if($latest)
                                    @php
                                        $staleStatusClass = match ($latest->status) {
                                            'failed' => 'bg-danger-subtle text-danger',
                                            'partial' => 'bg-warning-subtle text-warning-emphasis',
                                            'running' => 'bg-info-subtle text-info',
                                            'success' => 'bg-success-subtle text-success',
                                            default => 'bg-secondary-subtle text-secondary',
                                        };
                                    @endphp
                                    <span class="badge {{ $staleStatusClass }}">{{ $latest->status }}</span>
                                    <div class="small text-muted">{{ $latest->type }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(! $marketingEditor || ! $site->isLockedForMarketingEdits())
                                    @if($site->metrics_manual)
                                        <button type="button" class="btn btn-sm btn-outline-secondary allow-api-overwrite-btn" data-id="{{ $site->id }}">
                                            Allow API overwrite
                                        </button>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-primary enrich-site-btn" data-id="{{ $site->id }}">
                                        Queue
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No stale sites.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $staleSites->links() }}</div>
    </div>
</div>

<script>
const STAFF_BASE = @json(staff_base_path());

async function postEnrichmentJson(url, body, button) {
    if (button) {
        button.disabled = true;
    }
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(body || {}),
        });
        let data = {};
        const text = await res.text();
        try {
            data = text ? JSON.parse(text) : {};
        } catch (e) {
            data = {};
        }
        if (!res.ok || !data.success) {
            const fallback = res.status === 419
                ? 'Session expired. Refresh and try again.'
                : 'Could not queue enrichment.';
            showAppToast(data.message || fallback, 'error');
            return;
        }
        showAppToast(data.message || 'Done', 'success');
        window.location.reload();
    } catch (e) {
        showAppToast('Could not queue enrichment. Check your connection.', 'error');
    } finally {
        if (button) {
            button.disabled = false;
        }
    }
}

document.getElementById('rerunFailedBtn')?.addEventListener('click', function () {
    postEnrichmentJson(@json(staff_route('site-enrichment.rerun-failed')), { limit: 20 }, this);
});

document.getElementById('queueStaleBtn')?.addEventListener('click', function () {
    postEnrichmentJson(@json(staff_route('site-enrichment.queue-stale')), { limit: {{ (int) $batchLimit }} }, this);
});

document.querySelectorAll('.enrich-site-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const id = btn.dataset.id;
        postEnrichmentJson(`${STAFF_BASE}/sites/${id}/enrich`, { sync: false }, btn);
    });
});

document.querySelectorAll('.allow-api-overwrite-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        postEnrichmentJson(`${STAFF_BASE}/sites/${btn.dataset.id}/allow-api-metrics`, {}, btn);
    });
});
</script>
@endsection
