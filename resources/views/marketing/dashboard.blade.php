@extends('marketing.layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $waitingPublisherSitesUrl = route('marketing.sites.index', ['waiting_on_publisher' => 1, 'flat' => 1]);
    $readySitesUrl = route('marketing.sites.index', ['needs_review' => 1, 'flat' => 1]);
    $bulkWaitingUrl = route('marketing.bulk-site-requests.index', ['status' => \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER]);
    $queueAgeClass = function ($model) {
        $at = $model->created_at ?? null;
        if (! $at) {
            return 'small text-nowrap text-muted';
        }

        return $at->lte(now()->subDays(3))
            ? 'small text-nowrap text-warning'
            : 'small text-nowrap text-muted';
    };
@endphp
<div class="container-fluid mkt-dashboard">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Marketing workspace</h1>
            <p class="text-muted mb-0">Add and edit sites, manage bulk onboarding, and track every task you’ve completed.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap mkt-dashboard-toolbar">
            <form method="GET" action="{{ route('marketing.sites.index') }}" class="mkt-dashboard-jump" role="search">
                <input type="hidden" name="publishers" value="1">
                <x-slb-search-field
                    name="q"
                    id="mkt-dashboard-q"
                    :value="''"
                    placeholder="Find a publisher…"
                    label="Find a publisher"
                    label-class="visually-hidden"
                />
            </form>
            <a href="{{ route('marketing.sites.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> Add site for publisher
            </a>
            <a href="{{ route('marketing.sites.index') }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-globe me-1"></i> Sites
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
            <a href="{{ $readySitesUrl }}" class="text-decoration-none text-reset" data-stat="ready-to-activate">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Ready to activate</div>
                        <h3 class="mb-0 text-warning" data-stat-value="{{ $stats['ready_to_activate'] }}">{{ $stats['ready_to_activate'] }}</h3>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ $bulkWaitingUrl }}" class="text-decoration-none text-reset" data-stat="bulk-waiting-on-you">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Waiting on you (bulk)</div>
                        <h3 class="mb-0 text-primary" data-stat-value="{{ $stats['bulk_waiting_on_you'] }}">{{ $stats['bulk_waiting_on_you'] }}</h3>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100" data-stat="waiting-on-publisher">
                <div class="card-body">
                    <div class="text-muted small">Waiting on publisher</div>
                    <h3 class="mb-1">
                        <a href="{{ $waitingPublisherSitesUrl }}" class="text-decoration-none text-reset" data-stat-sites="{{ $stats['sites_waiting_on_publisher'] }}">{{ $stats['sites_waiting_on_publisher'] }}</a>
                    </h3>
                    <div class="small text-muted mb-1" data-stat-sites-label>
                        {{ (int) $stats['sites_waiting_on_publisher'] === 1 ? 'site' : 'sites' }}
                    </div>
                    <a href="{{ route('marketing.bulk-site-requests.index', ['status' => \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER]) }}" class="small text-muted" data-stat-bulk="{{ $stats['bulk_waiting_on_publisher'] }}">
                        {{ $stats['bulk_waiting_on_publisher'] }}
                        bulk request{{ (int) $stats['bulk_waiting_on_publisher'] === 1 ? '' : 's' }}
                    </a>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('marketing.history', ['from' => $historyToday, 'to' => $historyToday]) }}" class="text-decoration-none text-reset" data-stat="my-tasks-today">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">My tasks today</div>
                        <h3 class="mb-1 text-success" data-stat-value="{{ $stats['my_tasks_today'] }}">{{ $stats['my_tasks_today'] }}</h3>
                        <div class="small text-muted" data-stat-total="{{ $stats['my_tasks_total'] }}">{{ $stats['my_tasks_total'] }} all time</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100 mkt-dashboard-queue" data-queue="ready-sites">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <strong><i class="fa fa-bolt me-2 text-warning"></i>Ready to activate</strong>
                    <span class="d-flex align-items-center gap-2">
                        @if((int) $stats['ready_to_activate'] > 0)
                            <span class="small text-muted" data-queue-remainder="ready-sites">Showing {{ $readySites->count() }} of {{ $stats['ready_to_activate'] }}</span>
                        @endif
                        <a href="{{ $readySitesUrl }}" class="small">View all</a>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Site</th>
                                    <th>Publisher</th>
                                    <th>State</th>
                                    <th>Age</th>
                                    <th class="mkt-dashboard-actions-col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($readySites as $site)
                                    @php
                                        $readyOpenUrl = staff_route('sites.index', array_filter([
                                            'publisher' => $site->publisher_id,
                                            'site' => $site->id,
                                        ]));
                                    @endphp
                                    <tr data-ready-site="{{ $site->id }}">
                                        <td>
                                            <div class="fw-semibold">{{ $site->site_name ?: '—' }}</div>
                                            <div class="small text-muted text-truncate" style="max-width:260px;">{{ $site->site_url }}</div>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                @if(! $site->hasMarketplaceCountry())
                                                    <span class="badge text-bg-danger">Missing market</span>
                                                @endif
                                                @if(! $site->hasGoodMetrics())
                                                    <span class="badge text-bg-warning text-dark">{{ $site->qualityBarBadgeText() }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="small">
                                            {{ $site->publisher?->name ?? 'Unknown' }}
                                        </td>
                                        <td class="small">{{ \App\Support\MarketingOpsQueues::siteQueueLabel($site) }}</td>
                                        <td class="{{ $queueAgeClass($site) }}">
                                            <div>{{ $site->created_at?->diffForHumans() }}</div>
                                            <span class="d-block">{{ $site->created_at?->format('d M Y') }}</span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1 mkt-dashboard-actions">
                                                <a href="{{ $readyOpenUrl }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                                <a href="{{ staff_route('sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary">{{ $site->isLockedForMarketingEdits() && ! $site->marketingCanEditDescription() ? 'View' : 'Edit' }}</a>
                                                @include('partials.staff-site-activate-button', ['site' => $site])
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <div class="mb-2">No sites ready to activate.</div>
                                            <a href="{{ route('marketing.sites.create') }}" class="btn btn-sm btn-outline-primary">Add site for publisher</a>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100 mkt-dashboard-queue" data-queue="open-bulk">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <strong><i class="fa fa-layer-group me-2 text-primary"></i>Waiting on you</strong>
                    <span class="d-flex align-items-center gap-2">
                        @if((int) $stats['bulk_waiting_on_you'] > 0)
                            <span class="small text-muted" data-queue-remainder="open-bulk">Showing {{ $openBulk->count() }} of {{ $stats['bulk_waiting_on_you'] }}</span>
                        @endif
                        <a href="{{ $bulkWaitingUrl }}" class="small">View all</a>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Publisher</th>
                                    <th>Status</th>
                                    <th>Rows</th>
                                    <th style="width:90px">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($openBulk as $req)
                                    <tr>
                                        <td class="small">
                                            <div class="fw-semibold">{{ $req->publisher?->name ?? '—' }}</div>
                                            <div class="text-muted">#{{ $req->id }}</div>
                                            @if($req->handler?->name)
                                                <div class="text-muted">{{ $req->handler->name }}</div>
                                            @endif
                                        </td>
                                        <td><span class="badge bg-secondary">{{ $req->statusLabel() }}</span></td>
                                        <td class="small text-muted">{{ $req->pending_items_count }}</td>
                                        <td>
                                            <a href="{{ route('marketing.bulk-site-requests.show', $req) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <div class="mb-2">No bulk requests waiting on you.</div>
                                            <a href="{{ route('marketing.bulk-site-requests.index') }}" class="btn btn-sm btn-outline-primary">Open bulk requests</a>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 mkt-dashboard-queue" data-queue="waiting-sites">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong><i class="fa fa-hourglass-half me-2 text-muted"></i>Waiting on publisher</strong>
            <span class="d-flex align-items-center gap-2">
                <span class="small text-muted d-none d-md-inline">Details or accept — not staff work yet</span>
                @if((int) $stats['sites_waiting_on_publisher'] > 0)
                    <span class="small text-muted" data-queue-remainder="waiting-sites">Showing {{ $waitingSites->count() }} of {{ $stats['sites_waiting_on_publisher'] }}</span>
                @endif
                <a href="{{ $waitingPublisherSitesUrl }}" class="small">View all</a>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Site</th>
                            <th>Publisher</th>
                            <th>State</th>
                            <th>Age</th>
                            <th style="width:90px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($waitingSites as $site)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $site->site_name ?: '—' }}</div>
                                    <div class="small text-muted text-truncate" style="max-width:260px;">{{ $site->site_url }}</div>
                                </td>
                                <td class="small">{{ $site->publisher?->name ?? 'Unknown' }}</td>
                                <td class="small">{{ \App\Support\MarketingOpsQueues::siteQueueLabel($site) }}</td>
                                <td class="{{ $queueAgeClass($site) }}">
                                    <div>{{ $site->created_at?->diffForHumans() }}</div>
                                    <span class="d-block">{{ $site->created_at?->format('d M Y') }}</span>
                                </td>
                                <td>
                                    <a href="{{ staff_route('sites.index', array_filter(['publisher' => $site->publisher_id, 'site' => $site->id])) }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <div class="mb-2">No listings waiting on a publisher.</div>
                                    <a href="{{ route('marketing.sites.create') }}" class="btn btn-sm btn-outline-primary">Add site for publisher</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong><i class="fa fa-history me-2"></i>Your recent tasks</strong>
            <span class="d-flex align-items-center gap-2">
                @if((int) $stats['my_tasks_total'] > 0)
                    <span class="small text-muted" data-queue-remainder="recent-history">Showing {{ $recentHistory->count() }} of {{ $stats['my_tasks_total'] }}</span>
                @endif
                <a href="{{ route('marketing.history') }}" class="small">See full history</a>
            </span>
        </div>
        <div class="card-body p-0">
            @include('marketing.partials.history-table', ['logs' => $recentHistory])
        </div>
    </div>

    <div class="alert alert-info border-0 mt-4 mb-0">
        <i class="fa fa-info-circle me-1"></i>
        You can add and edit listings (metrics, geo, niches, images), seed bulk requests, activate or deactivate sites, and delete pending (not-live) sites.
        Admin handles verification, payments, and users. Metrics/geo/niche edits do not email the publisher.
        <span class="d-inline-block ms-1">
            <a href="{{ route('marketing.promotions.index') }}">Promotions</a>
            ·
            <a href="{{ route('marketing.staff-handbook') }}">Staff handbook</a>
        </span>
    </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
    const csrf = @json(csrf_token());
    const activateUrl = @json(staff_route('sites.active', '__ID__', false));
    const staffBase = @json(staff_base_path());
    document.querySelectorAll('.js-mkt-activate').forEach((btn) => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const name = this.dataset.name || 'this site';
            const go = (typeof window.slbConfirmActivate === 'function')
                ? window.slbConfirmActivate({
                    looksEnglish: this.dataset.descriptionEnglish !== '0',
                    excerpt: this.dataset.descriptionExcerpt || '',
                    name: name,
                    confirmText: 'Activate',
                    editUrl: staffBase + '/sites/' + encodeURIComponent(id) + '/edit#description',
                })
                : (typeof window.slbConfirm === 'function')
                    ? window.slbConfirm({
                        title: 'Activate Site?',
                        text: 'Make "' + name + '" live in the catalog?',
                        icon: 'question',
                        confirmText: 'Activate',
                    })
                    : (typeof Swal !== 'undefined' && Swal.fire)
                        ? Swal.fire({
                            title: 'Activate Site?',
                            text: 'Make "' + name + '" live in the catalog?',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Activate',
                        }).then((r) => !!(r && r.isConfirmed))
                        : Promise.resolve(false);
            go.then((ok) => {
                if (!ok) return;
                fetch(activateUrl.replace('__ID__', encodeURIComponent(id)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ active: 1 }),
                })
                .then((res) => res.json().then((data) => ({ ok: res.ok, status: res.status, data: data || {} })).catch(() => ({ ok: false, status: res.status, data: {} })))
                .then(({ ok, status, data }) => {
                    if (ok && data && data.success) {
                        if (data.warning && typeof window.showAppToast === 'function') {
                            window.showAppToast(String(data.warning), 'warning');
                            setTimeout(function () { window.location.reload(); }, 1600);
                            return;
                        }
                        const row = document.querySelector('[data-ready-site="' + String(id).replace(/[^0-9]/g, '') + '"]');
                        if (row) row.remove();
                        if (typeof window.refreshAdminQueueBadges === 'function') {
                            window.refreshAdminQueueBadges({ refillReady: true });
                        }
                        return;
                    }
                    const msg = (typeof window.slbHttpMessage === 'function')
                        ? window.slbHttpMessage({ status: status, data: data }, 'Could not activate site')
                        : ((data && data.message) || 'Could not activate site');
                    window.slbAlert({ icon: 'error', title: 'Error', text: msg });
                })
                .catch(() => {
                    const msg = (typeof window.slbHttpMessage === 'function')
                        ? window.slbHttpMessage({ status: 0 }, 'Could not activate site')
                        : 'Request failed';
                    window.slbAlert({ icon: 'error', title: 'Error', text: msg });
                });
            });
        });
    });
})();
</script>
@endpush
