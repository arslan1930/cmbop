@extends($layout)

@section('content')
<link rel="stylesheet" href="{{ asset('css/pulse-badge.css') }}?v={{ @filemtime(public_path('css/pulse-badge.css')) ?: '1' }}">
<link rel="stylesheet" href="{{ asset('css/notification-center.css') }}?v={{ @filemtime(public_path('css/notification-center.css')) ?: '4' }}">

<div class="container-fluid py-2 nc-theme nc-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 nc-page-header">
        <div>
            <h2>All notifications</h2>
            <p>
                @if($unreadCount > 0)
                    <span class="pulse-badge is-pulsing d-inline-flex align-items-center justify-content-center rounded-pill text-white me-1"
                          style="min-width:18px;height:18px;padding:0 5px;font-size:10px;font-weight:700;background:#dc3545;">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    unread
                @else
                    All caught up
                @endif
                @if($notifications->total())
                    <span class="text-muted"> · {{ $notifications->total() }} total</span>
                @endif
            </p>
        </div>
        <form method="POST" action="{{ route('notifications.read-all') }}" class="m-0" id="markAllReadForm">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary">Mark all read</button>
        </form>
    </div>

    <form method="GET" action="{{ route('notifications.all') }}" class="row g-2 align-items-end mb-3 nc-filter-bar">
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
            <button type="submit" class="btn btn-sm btn-nc w-100">Filter</button>
        </div>
    </form>

    <div class="nc-page-list">
        @forelse($notifications as $notification)
            @include('partials.notification-card', [
                'notification' => $notification,
                'as' => 'a',
                'showTools' => false,
                'onclick' => 'markReadThenGo(event, ' . $notification->id . ', ' . json_encode($notification->action_url ?: '') . ')',
            ])
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
    if (!url) {
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
