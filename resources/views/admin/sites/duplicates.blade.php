@extends('admin.layouts.app')

@section('content')
@php
    $groups = $groups ?? collect();
    $groupCount = (int) ($groupCount ?? $groups->count());
    $siteCount = (int) ($siteCount ?? 0);
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Duplicate domains',
        'subtitle' => 'Listings that share the same normalised host (www, ports, and case folded)',
        'actionUrl' => route('admin.sites.records'),
        'actionLabel' => 'Records sheet',
        'actionIcon' => 'fa-arrow-left',
    ])

    <p class="small text-muted mb-3">
        {{ $groupCount }} domain{{ $groupCount === 1 ? '' : 's' }}
        · {{ $siteCount }} listing{{ $siteCount === 1 ? '' : 's' }}
    </p>

    @forelse($groups as $group)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between gap-2">
                <strong class="font-monospace">{{ $group['domain'] }}</strong>
                <span class="badge text-bg-warning">{{ $group['count'] }} listings</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Site</th>
                                <th>Stored domain</th>
                                <th>Publisher</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group['sites'] as $site)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $site['site_name'] }}</div>
                                        @if($site['site_url'] !== '')
                                            <a class="small" href="{{ $site['site_url'] }}" target="_blank" rel="noopener">{{ $site['site_url'] }}</a>
                                        @endif
                                    </td>
                                    <td class="font-monospace small">{{ $site['domain'] !== '' ? $site['domain'] : '—' }}</td>
                                    <td class="small">
                                        @if($site['publisher_url'])
                                            <a href="{{ $site['publisher_url'] }}" class="link-dark">{{ $site['publisher_name'] }}</a>
                                        @else
                                            {{ $site['publisher_name'] }}
                                        @endif
                                        <div class="text-muted">{{ $site['publisher_email'] }}</div>
                                    </td>
                                    <td>
                                        @if($site['archived'])
                                            <span class="badge text-bg-secondary">Archived</span>
                                        @endif
                                        <span class="badge {{ $site['verified'] ? 'text-bg-success' : 'text-bg-warning' }}">
                                            {{ $site['verified'] ? 'Verified' : 'Unverified' }}
                                        </span>
                                        <span class="badge {{ $site['active'] ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                            {{ $site['active'] ? 'Active' : 'Off' }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ $site['edit_url'] }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                No duplicate domains right now.
            </div>
        </div>
    @endforelse
</div>
@endsection
