@extends($layout)

@section('content')
<link rel="stylesheet" href="{{ asset('css/notification-center.css') }}?v={{ @filemtime(public_path('css/notification-center.css')) ?: '3' }}">

<div class="container-fluid py-2">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1 fw-semibold">All notifications</h2>
            <p class="text-muted small mb-0">
                {{ $unreadCount }} unread
                @if($notifications->total())
                    · {{ $notifications->total() }} total
                @endif
            </p>
        </div>
        <form method="POST" action="{{ route('notifications.read-all') }}" class="m-0" id="markAllReadForm">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary">Mark all read</button>
        </form>
    </div>

    <form method="GET" action="{{ route('notifications.all') }}" class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Search</label>
            <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Search notifications…">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Category</label>
            <select name="category" class="form-select form-select-sm">
                @foreach(['all' => 'All', 'unread' => 'Unread', 'orders' => 'Orders', 'messages' => 'Messages', 'payments' => 'Payments', 'system' => 'System', 'support' => 'Support'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['category'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-primary w-100" style="background:#0b6266;border-color:#0b6266;">Filter</button>
        </div>
    </form>

    <div class="border rounded-3 overflow-hidden bg-white">
        @forelse($notifications as $notification)
            @php
                $item = $notification->toApiArray();
                $icon = $item['icon'] ?? 'bell';
            @endphp
            <a href="{{ $item['action_url'] ?: '#' }}"
               class="nc-item text-decoration-none {{ $item['is_unread'] ? 'is-unread' : '' }}"
               style="display:grid;"
               onclick="markReadThenGo(event, {{ $item['id'] }}, '{{ $item['action_url'] ?? '' }}')">
                <div class="nc-icon" aria-hidden="true">
                    @include('partials.notification-icon', ['name' => $icon])
                </div>
                <div class="nc-item-main">
                    <p class="nc-item-title mb-1">{{ $item['title'] }}</p>
                    @if(!empty($item['message']))
                        <p class="nc-item-msg">{{ $item['message'] }}</p>
                    @endif
                    <div class="nc-item-meta">
                        <span>{{ optional($notification->created_at)->diffForHumans() }}</span>
                        @if(!empty($item['action_url']))
                            <span class="nc-item-action">{{ $item['action_label'] }} →</span>
                        @endif
                    </div>
                </div>
                <div class="nc-item-aside">
                    <span class="nc-dot" aria-hidden="true"></span>
                </div>
            </a>
        @empty
            <div class="nc-empty">You're all caught up. New activity will show up here.</div>
        @endforelse
    </div>

    <div class="mt-3">
        {{ $notifications->withQueryString()->links() }}
    </div>
</div>

<script>
document.getElementById('markAllReadForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    fetch(this.action, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    }).then(function () { window.location.reload(); });
});

function markReadThenGo(e, id, url) {
    if (!url || url === '#') {
        e.preventDefault();
        return;
    }
    e.preventDefault();
    fetch('/notifications/' + id + '/read', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    }).finally(function () {
        window.location.href = url;
    });
}
</script>
@endsection
