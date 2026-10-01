@extends('admin.layouts.app')

@section('content')
@php
    $days = $days ?? 7;
    $copyFilter = $copyFilter ?? 'attention';
    $q = $q ?? '';
    $focusUserId = (int) ($focusUserId ?? 0);
    $hideHours = (int) ($hideHours ?? 24);
    $exemptionMinutes = (int) ($exemptionMinutes ?? 60);
    $noOrdersThreshold = (int) ($noOrdersThreshold ?? 100);
    $copyAttentionDays = (int) ($copyAttentionDays ?? 14);
    $unlockLimit = (int) ($unlockLimit ?? \App\Support\AdminCatalogActivity::UNLOCK_LIMIT);
    $copyLimit = (int) ($copyLimit ?? \App\Support\AdminCatalogActivity::COPY_LIMIT);
    $dayChips = $dayChips ?? \App\Support\AdminCatalogActivity::DAY_CHIPS;
    $queryBase = array_filter([
        'days' => $days,
        'copy' => $copyFilter === 'all' ? 'all' : null,
        'q' => $q !== '' ? $q : null,
        'user' => $focusUserId > 0 ? $focusUserId : null,
    ], fn ($v) => $v !== null && $v !== '');
@endphp
<div class="container-fluid">

    @include('admin.partials.page-header', [
        'title' => 'Catalog activity',
        'subtitle' => 'Who is in catalog hide mode or on a copy warning. Open-catalog browsing is not logged.',
    ])

    <form method="GET" action="{{ route('admin.catalog-activity') }}" class="admin-deposits-filters mb-3">
        <input type="hidden" name="days" value="{{ $days }}">
        @if($copyFilter === 'all')
            <input type="hidden" name="copy" value="all">
        @endif
        <div class="d-flex flex-wrap align-items-end gap-2" style="max-width: 28rem;">
            <div class="flex-grow-1">
                <x-slb-search-field name="q" id="adminCatalogActivitySearch" :value="$q" placeholder="Search email or name" label="Search accounts" label-class="form-label" input-class="form-control" />
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
        </div>
    </form>

    @if($copyStrikesAvailable ?? false)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>Copy strikes &amp; hide mode</strong>
                    <div class="small text-muted mb-0">
                        @if($copyFilter === 'all')
                            All accounts still on the strike ladder.
                        @else
                            Needs attention: in hide now, or warned / served hide in the last {{ $copyAttentionDays }} days.
                        @endif
                    </div>
                </div>
                <div class="btn-group btn-group-sm">
                    <a href="{{ route('admin.catalog-activity', array_filter(array_merge($queryBase, ['copy' => null]), fn ($v) => $v !== null && $v !== '')) }}"
                       class="btn btn-outline-secondary {{ $copyFilter !== 'all' ? 'active' : '' }}">Needs attention</a>
                    <a href="{{ route('admin.catalog-activity', array_merge($queryBase, ['copy' => 'all'])) }}"
                       class="btn btn-outline-secondary {{ $copyFilter === 'all' ? 'active' : '' }}">All</a>
                </div>
            </div>
            <div class="card-body p-0">
                @if($copyStrikeCapped ?? false)
                    <div class="small text-muted px-3 pt-2">Showing {{ number_format($copyLimit) }}. Narrow with search.</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Account</th>
                                <th class="text-end">Strikes</th>
                                <th>Status</th>
                                <th>Warned</th>
                                <th>Hide until</th>
                                <th class="text-end">Copies (24h)</th>
                                <th class="text-end">Unlocks ({{ $days }}d)</th>
                                <th class="text-end">Paid orders</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($copyStrikeRows as $row)
                                @php $uid = (int) $row['user']->id; @endphp
                                <tr id="user-{{ $uid }}" class="{{ $focusUserId === $uid ? 'table-warning' : '' }}">
                                    <td>
                                        <div class="fw-semibold">
                                            <a href="{{ $row['user_url'] }}" class="link-dark">{{ $row['user']->name ?: '—' }}</a>
                                        </div>
                                        <div class="small text-muted">{{ $row['user']->email }}</div>
                                        <div class="small text-muted">{{ $row['account_age_days'] }} days old</div>
                                    </td>
                                    <td class="text-end fw-semibold">{{ $row['strike_count'] }}</td>
                                    <td>
                                        @if($row['status'] === \App\Models\User::CATALOG_COPY_HIDDEN)
                                            <span class="badge bg-danger">Hide mode</span>
                                            @if($row['hide_remaining'])
                                                <div class="small text-muted mt-1">{{ $row['hide_remaining'] }}</div>
                                            @endif
                                        @elseif($row['status'] === \App\Models\User::CATALOG_COPY_POST_HIDE)
                                            <span class="badge bg-secondary">Served hide</span>
                                            <div class="small text-muted mt-1">Next wave re-hides immediately.</div>
                                        @elseif($row['status'] === \App\Models\User::CATALOG_COPY_WARNED)
                                            <span class="badge bg-warning text-dark">Warned</span>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                        @if($row['exempt'])
                                            <div class="mt-1"><span class="badge bg-light text-dark">Trusted</span></div>
                                            @if($row['exempt_until'])
                                                <div class="small text-muted">Until {{ $row['exempt_until']->timezone(config('app.timezone'))->format('H:i') }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="small text-muted">
                                        {{ $row['warned_at']
                                            ? $row['warned_at']->timezone(config('app.timezone'))->format('M j, H:i')
                                            : '—' }}
                                    </td>
                                    <td class="small text-muted">
                                        @if($row['hide_until'] && $row['in_hide_mode'])
                                            {{ $row['hide_until']->timezone(config('app.timezone'))->format('M j, H:i') }}
                                        @elseif($row['hide_until'] && $row['status'] === \App\Models\User::CATALOG_COPY_POST_HIDE)
                                            Ended {{ $row['hide_until']->timezone(config('app.timezone'))->format('M j, H:i') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format($row['copies_24h']) }}</td>
                                    <td class="text-end">{{ number_format($row['total']) }}</td>
                                    <td class="text-end">
                                        {{ number_format($row['orders']) }}
                                        @if($row['established'])
                                            <div class="small text-muted">Established</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex flex-wrap justify-content-end gap-1">
                                            <a href="{{ route('admin.catalog-activity.show', $uid) }}" class="btn btn-sm btn-outline-secondary">Details</a>
                                            @if($row['in_hide_mode'])
                                                <form method="POST"
                                                      action="{{ route('admin.catalog-activity.lift-hide', $uid) }}"
                                                      class="d-inline"
                                                      data-slb-confirm="Lift hide mode? They stay on strike {{ $row['strike_count'] }}; the next copy wave can re-hide them. Copy history is kept."
                                                      data-slb-confirm-title="Lift hide mode?">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Lift hide</button>
                                                </form>
                                                <form method="POST"
                                                      action="{{ route('admin.catalog-activity.exempt', $uid) }}"
                                                      class="d-inline"
                                                      data-slb-confirm="{{ $row['exempt']
                                                            ? 'Put this account back under the usual pace checks now?'
                                                            : 'Trust this account for '.$exemptionMinutes.' minutes? Pace checks pause while hide mode is on.' }}"
                                                      data-slb-confirm-title="{{ $row['exempt'] ? 'Remove trust?' : 'Trust for '.$exemptionMinutes.' minutes?' }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        {{ $row['exempt'] ? 'Remove exemption' : 'Mark as trusted' }}
                                                    </button>
                                                </form>
                                            @endif
                                            @if($row['strike_count'] > 0)
                                                <form method="POST"
                                                      action="{{ route('admin.catalog-activity.reset-strikes', $uid) }}"
                                                      class="d-inline"
                                                      data-slb-confirm="Reset the strike ladder? Copy history is kept.{{ $row['in_hide_mode'] ? ' Hide mode stays on until you lift it.' : '' }}"
                                                      data-slb-confirm-title="Reset strikes?">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Reset strikes</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        @if($q !== '')
                                            No accounts match this search.
                                        @elseif($copyFilter === 'all')
                                            No accounts on the strike ladder.
                                        @else
                                            No warned or hide-mode accounts right now.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if(! $available)
        <div class="alert alert-warning">
            Domain disclosures are not being recorded yet — run migrations to create
            <code>site_url_reveals</code>.
        </div>
    @else
        @unless($enforcing)
            <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
                <i class="fa fa-circle-info mt-1" aria-hidden="true"></i>
                <div>
                    <strong>Watching only.</strong>
                    Pace checks are recording and reporting but never restricting anyone.
                    Leave it this way until this table tells you what normal looks like here,
                    then set the thresholds from your own data rather than from a guess.
                </div>
            </div>
        @endunless

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong>Hide-mode unlocks (eye / visit / cart) &middot; last {{ $days }} days</strong>
                <div class="btn-group btn-group-sm">
                    @foreach($dayChips as $option)
                        <a href="{{ route('admin.catalog-activity', array_merge($queryBase, ['days' => $option])) }}"
                           class="btn btn-outline-secondary {{ (int) $days === (int) $option ? 'active' : '' }}">
                            {{ $option }}d
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="card-body p-0">
                @if($unlockCapped ?? false)
                    <div class="small text-muted px-3 pt-2">Showing {{ number_format($unlockLimit) }}. Narrow with search.</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Account</th>
                                <th class="text-end">Unlocks</th>
                                <th class="text-end">Last hour</th>
                                <th>Last unlock</th>
                                <th class="text-end">Paid orders</th>
                                <th class="text-end">Per order</th>
                                <th>Signal</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                @php $uid = (int) $row['user']->id; @endphp
                                <tr class="{{ $focusUserId === $uid ? 'table-warning' : '' }}">
                                    <td>
                                        <div class="fw-semibold">
                                            <a href="{{ route('admin.users.show', $uid) }}" class="link-dark">{{ $row['user']->name ?: '—' }}</a>
                                        </div>
                                        <div class="small text-muted">{{ $row['user']->email }}</div>
                                        <div class="small text-muted">{{ $row['account_age_days'] }} days old</div>
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($row['total']) }}</td>
                                    <td class="text-end">{{ number_format($row['last_hour']) }}</td>
                                    <td class="small text-muted">
                                        {{ $row['last_at']
                                            ? $row['last_at']->timezone(config('app.timezone'))->format('M j, H:i')
                                            : '—' }}
                                    </td>
                                    <td class="text-end">
                                        {{ number_format($row['orders']) }}
                                        @if(($row['orders_lifetime'] ?? 0) !== $row['orders'])
                                            <div class="small text-muted">{{ number_format($row['orders_lifetime']) }} lifetime</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        {{ $row['per_order'] !== null ? $row['per_order'] : '—' }}
                                    </td>
                                    <td>
                                        @if($row['metronomic'])
                                            <span class="badge bg-warning text-dark">
                                                <i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Even timing (last hour)
                                            </span>
                                        @elseif(! $row['established'] && $row['total'] >= $noOrdersThreshold)
                                            <span class="badge bg-warning text-dark">
                                                <i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>No orders
                                            </span>
                                        @else
                                            <span class="text-muted small">Looks normal</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.catalog-activity.show', $uid) }}" class="btn btn-sm btn-outline-secondary">Details</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No hide-mode unlocks in this period. Everyday catalog browsing does not create rows here.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
