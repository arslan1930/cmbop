@extends('admin.layouts.app')

@section('content')
@php
    $tab = $tab ?? 'all';
    $tabs = $tabs ?? [];
    $counts = $counts ?? ['disputes' => 0, 'community' => 0, 'stalled' => 0, 'total' => 0];
    $items = $items ?? collect();
    $badgeFor = [
        'all' => (int) ($counts['total'] ?? 0),
        'disputes' => (int) ($counts['disputes'] ?? 0),
        'community' => (int) ($counts['community'] ?? 0),
        'stalled' => (int) ($counts['stalled'] ?? 0),
    ];
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Work inbox',
        'subtitle' => 'Open disputes, pending community, and stalled orders in one list',
        'actionUrl' => route('admin.dashboard'),
        'actionLabel' => 'Dashboard',
        'actionIcon' => 'fa-arrow-left',
    ])

    <ul class="nav nav-tabs mb-3">
        @foreach($tabs as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $key ? 'active' : '' }}"
                   href="{{ route('admin.inbox.index', $key === 'all' ? [] : ['tab' => $key]) }}">
                    {{ $label }}
                    @if(($badgeFor[$key] ?? 0) > 0)
                        <span class="badge bg-warning text-dark">{{ $badgeFor[$key] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Item</th>
                        <th>From</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td><span class="badge text-bg-light text-dark border">{{ $item['type'] }}</span></td>
                            <td>
                                <div class="fw-semibold">{{ $item['title'] }}</div>
                                @if(($item['detail'] ?? '') !== '')
                                    <div class="small text-muted">{{ $item['detail'] }}</div>
                                @endif
                            </td>
                            <td class="small">{{ $item['from'] }}</td>
                            <td class="small text-muted">{{ $item['date'] }}</td>
                            <td class="text-end">
                                <a href="{{ $item['url'] }}" class="btn btn-sm btn-outline-primary">
                                    {{ $item['action'] ?? 'Open' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                Nothing in this queue.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
