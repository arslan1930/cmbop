@extends($layout)

@section('title', 'Notifications')

@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/pulse-badge.css') }}?v={{ @filemtime(public_path('assets/css/pulse-badge.css')) ?: '1' }}">
<link rel="stylesheet" href="{{ asset('assets/css/notification-center.css') }}?v={{ @filemtime(public_path('assets/css/notification-center.css')) ?: '5' }}">

<div class="container-fluid py-2 nc-theme nc-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 nc-page-header">
        <div>
            <h2>All notifications</h2>
            <p>
                @if($unreadCount > 0)
                    <span class="pulse-badge is-pulsing d-inline-flex align-items-center justify-content-center rounded-pill text-white me-1"
                          style="min-width:18px;height:18px;padding:0 5px;font-size:10px;font-weight:700;background:#dc3545;">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                    unread
                @else
                    All caught up
                @endif
                @if($notifications->total())
                    <span class="text-muted"> · {{ $notifications->total() }} total</span>
                @endif
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('profile.notifications', absolute: false) }}" class="btn btn-sm btn-outline-secondary" title="Notification settings" aria-label="Notification settings">
                Settings
            </a>
            <form method="POST" action="{{ route('notifications.read-all', absolute: false) }}" class="m-0" id="markAllReadForm">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">Mark all read</button>
            </form>
        </div>
    </div>

    <form method="GET" action="{{ route('notifications.all', absolute: false) }}" class="row g-2 align-items-end mb-3 nc-filter-bar">
        <div class="col-md-4">
            <x-slb-search-field name="q" id="notificationsSearch" :value="$filters['q']" placeholder="Search notifications…" />
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Category</label>
            <select name="category" class="form-select form-select-sm">
                @foreach(['all' => 'All', 'unread' => 'Unread', 'action' => 'Action needed', 'archived' => 'Archived', 'orders' => 'Orders', 'messages' => 'Messages', 'payments' => 'Payments', 'account' => 'Account', 'system' => 'System', 'support' => 'Support'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['category'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-nc w-100">Filter</button>
        </div>
    </form>

    <div class="nc-page-list" data-nc-page-list>
    @php $cards = $notificationCards ?? []; @endphp
    @forelse($cards as $card)
        @include('partials.notification-card', [
            'notification' => $card,
            'showTools' => true,
        ])
    @empty
        <div class="nc-empty">
            @if(($filters['category'] ?? '') === 'unread')
                You're all caught up. Switch to All to see earlier notifications.
            @elseif(($filters['category'] ?? '') === 'action')
                No actions needed right now.
            @elseif(($filters['category'] ?? '') === 'archived')
                No archived notifications.
            @else
                You're all caught up. New activity will show up here.
            @endif
        </div>
    @endforelse
    </div>

    <div class="mt-3">
        {{ $notifications->withQueryString()->links() }}
    </div>
</div>

<script>
(function () {
    document.getElementById('markAllReadForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        var unread = {{ (int) $unreadCount }};
        var msg = unread > 0
            ? 'Mark all ' + unread + ' notifications as read?'
            : 'Mark all notifications as read?';
        var form = this;
        window.slbConfirm({ title: 'Mark all as read?', text: msg, confirmText: 'Mark all read' }).then(function (ok) {
            if (!ok) return;
            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            }).then(function () { window.location.reload(); });
        });
    });

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function post(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        });
    }

    function closeKebabs(except) {
        document.querySelectorAll('[data-nc-kebab]').forEach(function (wrap) {
            if (except && wrap === except) return;
            var menu = wrap.querySelector('.nc-kebab-menu');
            var btn = wrap.querySelector('[data-nc-kebab-toggle]');
            if (menu) menu.hidden = true;
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-nc-kebab-toggle]');
        if (toggle) {
            e.preventDefault();
            e.stopPropagation();
            var wrap = toggle.closest('[data-nc-kebab]');
            var menu = wrap && wrap.querySelector('.nc-kebab-menu');
            var open = menu && menu.hidden;
            closeKebabs(wrap);
            if (menu) {
                menu.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    var first = menu.querySelector('[role="menuitem"]');
                    if (first) first.focus();
                }
            }
            return;
        }

        var tool = e.target.closest('[data-nc-tool]');
        if (tool) {
            e.preventDefault();
            e.stopPropagation();
            var id = tool.getAttribute('data-id');
            var action = tool.getAttribute('data-nc-tool');
            var url = action === 'delete'
                ? '/notifications/' + id
                : '/notifications/' + id + '/' + (action === 'read' ? 'read' : action === 'unread' ? 'unread' : 'archive');
            var method = action === 'delete' ? 'DELETE' : 'POST';
            fetch(url, {
                method: method,
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            }).finally(function () { window.location.reload(); });
            return;
        }

        if (!e.target.closest('[data-nc-kebab]')) closeKebabs();

        var primary = e.target.closest('[data-nc-primary-action]');
        if (primary) {
            var card = primary.closest('[data-nc-id]');
            var id = card && card.getAttribute('data-nc-id');
            if (id) {
                e.preventDefault();
                post('/notifications/' + id + '/read').finally(function () {
                    window.location.href = primary.getAttribute('href');
                });
            }
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeKebabs();
    });

    document.querySelectorAll('[data-nc-favicon]').forEach(function (img) {
        img.addEventListener('error', function () {
            img.style.display = 'none';
            var fb = img.nextElementSibling;
            if (fb) fb.hidden = false;
        });
    });
})();
</script>
@endsection
