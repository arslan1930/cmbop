@extends('admin.layouts.app')

@section('content')
@php
    $filterQuery = $filterQuery ?? [];
    $filters = $filters ?? ['verified' => 'all', 'registered_from' => '', 'registered_to' => '', 'country' => '', 'marketing' => 'all', 'exclude_dual_role' => false, 'sort' => 'name', 'dir' => 'asc'];
    $hasActiveFilters = $search !== '' || ($filters['verified'] ?? 'all') !== 'all' || ($filters['registered_from'] ?? '') !== '' || ($filters['registered_to'] ?? '') !== '' || ($filters['country'] ?? '') !== '' || ($filters['marketing'] ?? 'all') !== 'all' || !empty($filters['exclude_dual_role']);
    $statAll = fn (string $key) => (int) ($stats[$key.'_all'] ?? $stats[$key] ?? 0);
    $statVerified = fn (string $key) => (int) ($stats[$key.'_verified'] ?? 0);
    $tabUrl = fn (string $tabSlug) => route('admin.audiences.index', array_merge($filterQuery, ['tab' => $tabSlug]));
    $exportLabel = \App\Services\AudienceInventoryService::exportLabel($tab);
    $userUrl = fn ($user) => $user->adminShowUrl();
@endphp
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Audience Inventory</h1>
            <p class="text-muted mb-0">Registered advertisers and publishers — download lists or use them for email campaigns. Campaigns email verified users only unless you tick “include unverified”.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-paper-plane me-1"></i> Updates / Campaigns
            </a>
            <a href="{{ route('admin.promotions.index') }}" class="btn btn-sm btn-outline-secondary">
                Site Promotions
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach([
            ['tab' => 'advertisers', 'stat' => 'advertisers', 'title' => 'Advertisers', 'hint' => 'Users with advertiser role'],
            ['tab' => 'publishers', 'stat' => 'publishers', 'title' => 'Publishers', 'hint' => 'Users with publisher role'],
            ['tab' => 'both', 'stat' => 'both_unique', 'title' => 'Unique (either role)', 'hint' => 'Combined reach without duplicates'],
            ['tab' => 'no_orders', 'stat' => 'advertisers_never_checked_out', 'title' => 'Never checked out', 'hint' => 'Advertisers with no order row'],
            ['tab' => 'no_paid_orders', 'stat' => 'advertisers_no_paid_orders', 'title' => 'No paid orders', 'hint' => 'Advertisers with no paid or refunded order'],
            ['tab' => 'paid_orders', 'stat' => 'advertisers_paid_orders', 'title' => 'Paid customers', 'hint' => 'Advertisers with a paid or refunded order'],
            ['tab' => 'no_sites', 'stat' => 'publishers_no_sites', 'title' => 'No sites', 'hint' => 'Publishers who never listed a site'],
            ['tab' => 'no_active_sites', 'stat' => 'publishers_no_active_sites', 'title' => 'No active sites', 'hint' => 'Publishers with no catalog-visible listing'],
            ['tab' => 'never_deposited', 'stat' => 'advertisers_never_deposited', 'title' => 'Never deposited', 'hint' => 'Advertisers who never funded a wallet'],
            ['tab' => 'deposited_no_orders', 'stat' => 'advertisers_deposited_no_orders', 'title' => 'Deposited, no paid orders', 'hint' => 'Funded a wallet but never became a customer'],
        ] as $card)
            <div class="col-md-6 col-xl-3">
                <a href="{{ $tabUrl($card['tab']) }}" class="text-decoration-none text-reset">
                    <div class="card border-0 shadow-sm h-100 {{ $tab === $card['tab'] ? 'border-primary' : '' }}">
                        <div class="card-body">
                            <div class="text-muted small">{{ $card['title'] }}</div>
                            <h3 class="mb-0">{{ number_format($statAll($card['stat'])) }}</h3>
                            <div class="small text-muted mt-1">{{ number_format($statVerified($card['stat'])) }} emailable (verified)</div>
                            <div class="small text-muted">{{ $card['hint'] }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <p class="small text-muted mb-3">Never checked out ⊂ No paid orders ⊂ Advertisers. Deposited, no paid orders is a credited deposit ∩ no paid/refunded order (abandoned checkout stays in). Never deposited is independent (wallet funding, not checkout). Email sends the whole segment, not the filtered table.</p>

    <ul class="nav nav-tabs mb-3 flex-wrap">
        @foreach([
            ['tab' => 'advertisers', 'stat' => 'advertisers', 'icon' => 'fa-bullseye', 'label' => 'Advertisers'],
            ['tab' => 'publishers', 'stat' => 'publishers', 'icon' => 'fa-globe', 'label' => 'Publishers'],
            ['tab' => 'both', 'stat' => 'both_unique', 'icon' => 'fa-users', 'label' => 'Unique'],
            ['tab' => 'no_orders', 'stat' => 'advertisers_never_checked_out', 'icon' => 'fa-shopping-bag', 'label' => 'Never checked out'],
            ['tab' => 'no_paid_orders', 'stat' => 'advertisers_no_paid_orders', 'icon' => 'fa-receipt', 'label' => 'No paid orders'],
            ['tab' => 'paid_orders', 'stat' => 'advertisers_paid_orders', 'icon' => 'fa-check-circle', 'label' => 'Paid customers'],
            ['tab' => 'no_sites', 'stat' => 'publishers_no_sites', 'icon' => 'fa-link', 'label' => 'No sites'],
            ['tab' => 'no_active_sites', 'stat' => 'publishers_no_active_sites', 'icon' => 'fa-unlink', 'label' => 'No active sites'],
            ['tab' => 'never_deposited', 'stat' => 'advertisers_never_deposited', 'icon' => 'fa-wallet', 'label' => 'Never deposited'],
            ['tab' => 'deposited_no_orders', 'stat' => 'advertisers_deposited_no_orders', 'icon' => 'fa-piggy-bank', 'label' => 'Deposited, no paid orders'],
        ] as $nav)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $nav['tab'] ? 'active' : '' }}" href="{{ $tabUrl($nav['tab']) }}">
                    <i class="fa {{ $nav['icon'] }} me-1"></i> {{ $nav['label'] }}
                    <span class="badge bg-primary-subtle text-primary ms-1">{{ $statAll($nav['stat']) }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card border-0 shadow-sm admin-deposits-filter-card">
        <div class="card-header bg-white border-0">
            <form method="GET" class="admin-deposits-filters admin-orders-filters" action="{{ route('admin.audiences.index') }}">
                <div class="admin-orders-filters__grid">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div class="admin-orders-filters__search">
                    <x-slb-search-field name="q" id="adminAudiencesSearch" :value="$search" placeholder="Search name or email" input-class="form-control" label-class="form-label" />
                </div>
                <div>
                    <label class="form-label" for="audienceVerified">Verified</label>
                    <select name="verified" id="audienceVerified" class="form-select">
                        <option value="all" @selected(($filters['verified'] ?? 'all') === 'all')>All</option>
                        <option value="yes" @selected(($filters['verified'] ?? '') === 'yes')>Verified</option>
                        <option value="no" @selected(($filters['verified'] ?? '') === 'no')>Unverified</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="audienceFrom">Registered from</label>
                    <input type="date" name="registered_from" id="audienceFrom" class="form-control" value="{{ $filters['registered_from'] ?? '' }}">
                </div>
                <div>
                    <label class="form-label" for="audienceTo">Registered to</label>
                    <input type="date" name="registered_to" id="audienceTo" class="form-control" value="{{ $filters['registered_to'] ?? '' }}">
                </div>
                <div>
                    <label class="form-label" for="audienceCountry">Country</label>
                    <input type="text" name="country" id="audienceCountry" class="form-control" value="{{ $filters['country'] ?? '' }}" maxlength="64" placeholder="DE">
                </div>
                <div>
                    <label class="form-label" for="audienceMarketing">Marketing</label>
                    <select name="marketing" id="audienceMarketing" class="form-select">
                        <option value="all" @selected(($filters['marketing'] ?? 'all') === 'all')>All</option>
                        <option value="opted_in" @selected(($filters['marketing'] ?? '') === 'opted_in')>Opted in</option>
                        <option value="opted_out" @selected(($filters['marketing'] ?? '') === 'opted_out')>Opted out</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="audienceSort">Sort</label>
                    <select name="sort" id="audienceSort" class="form-select">
                        <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Name</option>
                        <option value="registered" @selected(($filters['sort'] ?? '') === 'registered')>Registered</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="audienceDir">Dir</label>
                    <select name="dir" id="audienceDir" class="form-select">
                        <option value="asc" @selected(($filters['dir'] ?? 'asc') === 'asc')>Asc</option>
                        <option value="desc" @selected(($filters['dir'] ?? '') === 'desc')>Desc</option>
                    </select>
                </div>
                <div>
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="exclude_dual_role" value="1" id="audienceExcludeDual" @checked(!empty($filters['exclude_dual_role']))>
                        <label class="form-check-label small" for="audienceExcludeDual">Exclude dual-role users</label>
                    </div>
                </div>
                <div class="admin-deposits-filters__actions admin-orders-filters__actions">
                    <button class="btn btn-primary" type="submit">Apply</button>
                    @if($hasActiveFilters)
                        <a class="btn btn-outline-secondary" href="{{ route('admin.audiences.index', ['tab' => $tab]) }}">Clear</a>
                    @endif
                </div>
                </div>
            </form>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a href="{{ route('admin.audiences.export', array_merge($filterQuery, ['audience' => $tab])) }}" class="btn btn-sm btn-outline-success">
                    <i class="fa fa-download me-1"></i>
                    {{ $search !== '' || $hasActiveFilters ? 'Download filtered CSV' : 'Download '.$exportLabel.' CSV' }}
                </a>
                <a href="{{ route('admin.campaigns.index', ['audience' => $campaignAudience]) }}" class="btn btn-sm btn-primary" title="Emails the full segment (verified by default), not the filtered table.">
                    <i class="fa fa-envelope me-1"></i> Email this audience
                </a>
                <span class="small text-muted align-self-center">Showing {{ number_format($users->total()) }} of {{ number_format($statAll(\App\Services\AudienceInventoryService::statKeyForTab($tab))) }} in this census.</span>
            </div>
            @if($hasActiveFilters)
                <div class="alert alert-warning mt-3 mb-0" role="alert">
                    Filters apply to this table and CSV only. <strong>Email this audience</strong> still sends the full segment (verified by default), not the filtered rows.
                </div>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Roles</th>
                            <th>Active role</th>
                            <th>Verified</th>
                            <th>Country</th>
                            <th>Paid orders</th>
                            <th>Sites</th>
                            <th>Deposits</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td>{{ $user->id }}</td>
                                <td class="fw-semibold">
                                    <a href="{{ $userUrl($user) }}" class="link-dark">{{ $user->name }}</a>
                                </td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    @foreach($user->roles as $role)
                                        <span class="badge bg-light text-dark">{{ $role->name }}</span>
                                    @endforeach
                                    @if($user->roles->contains('name', 'publisher') && $user->roles->contains('name', 'advertiser'))
                                        <span class="badge bg-info-subtle text-info">Dual role</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $user->activeRole() ?: '—' }}</td>
                                <td>
                                    @if($user->hasVerifiedEmail())
                                        <span class="badge bg-success">Yes</span>
                                    @else
                                        <span class="badge bg-secondary">No</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $user->country ?: '—' }}</td>
                                <td>{{ (int) ($user->paid_orders_count ?? 0) }}</td>
                                <td>{{ (int) ($user->sites_count ?? 0) }}</td>
                                <td>{{ (int) ($user->completed_deposits_count ?? 0) }}</td>
                                <td class="small text-muted">{{ optional($user->created_at)->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-5">
                                    @if($hasActiveFilters)
                                        No users match these filters.
                                        <a href="{{ route('admin.audiences.index', ['tab' => $tab]) }}">Clear filters</a>
                                    @else
                                        No users in this audience yet.
                                        <a href="{{ route('admin.users.index') }}">Open Users</a>
                                        or
                                        <a href="{{ route('admin.campaigns.index') }}">Campaigns</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($users->hasPages())
            <div class="card-footer bg-white">{{ $users->links() }}</div>
        @endif
    </div>
</div>
@endsection
