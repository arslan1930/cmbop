@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    @include('admin.partials.page-header', [
        'title' => $account->name ?: $account->email,
        'subtitle' => 'Copy events and hide-mode unlocks for this account. Open-catalog browsing is not logged.',
    ])

    @php
        $exemptionMinutes = max(1, (int) config('catalog.url_reveal.pace.exemption_minutes', 60));
        $inHide = $status === \App\Models\User::CATALOG_COPY_HIDDEN;
        $exempt = $account->catalog_reveal_exempt_until && $account->catalog_reveal_exempt_until->isFuture();
        $existingSiteIds = $existingSiteIds ?? [];
    @endphp
    <div class="mb-3 d-flex flex-wrap gap-2">
        <a href="{{ $queueUrl ?? route('admin.catalog-activity', ['user' => $account->id]) }}" class="btn btn-sm btn-outline-secondary">Back to queue</a>
        <a href="{{ $userUrl ?? route('admin.users.show', $account->id) }}" class="btn btn-sm btn-outline-secondary">Open user</a>
        <a href="{{ $historyUrl ?? route('admin.activity-logs.index', ['user_id' => $account->id]) }}" class="btn btn-sm btn-outline-secondary">History</a>
        @if($inHide)
            <form method="POST" action="{{ route('admin.catalog-activity.lift-hide', $account->id) }}" class="d-inline"
                  data-slb-confirm="Lift hide mode? They stay on strike {{ (int) ($account->catalog_copy_strike_count ?? 0) }}; the next copy wave can re-hide them. Copy history is kept."
                  data-slb-confirm-title="Lift hide mode?">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary">Lift hide</button>
            </form>
            <form method="POST" action="{{ route('admin.catalog-activity.exempt', $account->id) }}" class="d-inline"
                  data-slb-confirm="{{ $exempt ? 'Put this account back under the usual pace checks now?' : 'Trust this account for '.$exemptionMinutes.' minutes? Pace checks pause while hide mode is on.' }}"
                  data-slb-confirm-title="{{ $exempt ? 'Remove trust?' : 'Trust for '.$exemptionMinutes.' minutes?' }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ $exempt ? 'Remove exemption' : 'Mark as trusted' }}</button>
            </form>
        @endif
        @if((int) ($account->catalog_copy_strike_count ?? 0) > 0)
            <form method="POST" action="{{ route('admin.catalog-activity.reset-strikes', $account->id) }}" class="d-inline"
                  data-slb-confirm="Reset the strike ladder? Copy history is kept.{{ $inHide ? ' Hide mode stays on until you lift it.' : '' }}"
                  data-slb-confirm-title="Reset strikes?">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">Reset strikes</button>
            </form>
        @endif
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="small text-muted">{{ $account->email }}</div>
            <div class="mt-2">
                @if($status === \App\Models\User::CATALOG_COPY_HIDDEN)
                    <span class="badge bg-danger">Hide mode</span>
                    @if($account->catalog_hide_until)
                        <span class="small text-muted ms-1">until {{ $account->catalog_hide_until->timezone(config('app.timezone'))->format('M j, H:i') }}</span>
                    @endif
                @elseif($status === \App\Models\User::CATALOG_COPY_POST_HIDE)
                    <span class="badge bg-secondary">Served hide</span>
                    <span class="small text-muted ms-1">Next wave re-hides immediately.</span>
                @elseif($status === \App\Models\User::CATALOG_COPY_WARNED)
                    <span class="badge bg-warning text-dark">Warned</span>
                @else
                    <span class="text-muted small">No active copy strike</span>
                @endif
                <span class="small text-muted ms-2">{{ (int) ($account->catalog_copy_strike_count ?? 0) }} strikes</span>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0">
            <strong>Recent copies</strong>
            <div class="small text-muted">Last 50 recorded clipboard hosts.</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Host</th>
                            <th>Site</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($copyEvents as $event)
                            <tr>
                                <td>{{ $event->normalized_host }}</td>
                                <td class="small text-muted">
                                    @php $copySiteId = (int) ($event->site_id ?? 0); @endphp
                                    @if($event->site || ($copySiteId > 0 && !empty($existingSiteIds[$copySiteId])))
                                        <a href="{{ route('admin.sites.edit', $copySiteId ?: $event->site->id) }}">{{ $event->site?->site_name ?: '#'.$copySiteId }}</a>
                                    @elseif($copySiteId > 0)
                                        #{{ $copySiteId }} <span class="badge bg-secondary">Removed</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $event->created_at?->timezone(config('app.timezone'))->format('M j, H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No copy events on file.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0">
            <strong>Hide-mode unlocks</strong>
            <div class="small text-muted">Last 50 eye / visit / cart disclosures.</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Site</th>
                            <th>Source</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reveals as $reveal)
                            <tr>
                                <td>
                                    @php $revealSiteId = (int) ($reveal->site_id ?? 0); @endphp
                                    @if($reveal->site || ($revealSiteId > 0 && !empty($existingSiteIds[$revealSiteId])))
                                        <a href="{{ route('admin.sites.edit', $revealSiteId ?: $reveal->site->id) }}">{{ $reveal->site?->domain ?: ($reveal->site?->site_name ?: '#'.$revealSiteId) }}</a>
                                    @elseif($revealSiteId > 0)
                                        #{{ $revealSiteId }} <span class="badge bg-secondary">Removed</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $reveal->source ?: '—' }}</td>
                                <td class="small text-muted">{{ $reveal->created_at?->timezone(config('app.timezone'))->format('M j, H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No hide-mode unlocks recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
