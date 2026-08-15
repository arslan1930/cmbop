@php
    $statusCounts = $statusCounts ?? [];
    $current = search_text(request('status')) ?: 'all';
    $chips = [
        'all' => 'All',
        'live' => 'Live',
        'scheduled' => 'Scheduled',
        'expired' => 'Expired',
        'paused' => 'Paused',
        'trashed' => 'Trash',
    ];
    $keep = request()->except(['status', 'page']);
@endphp
<div class="d-flex flex-wrap gap-2 mb-3">
    @foreach($chips as $key => $label)
        <a href="{{ request()->url().'?'.http_build_query(array_filter($keep + ['status' => $key === 'all' ? null : $key])) }}"
           class="btn btn-sm {{ $current === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
            {{ $label }}
            <span class="text-muted">{{ (int) ($statusCounts[$key] ?? 0) }}</span>
        </a>
    @endforeach
</div>
