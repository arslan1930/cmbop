{{-- In-app Notification Center (emails are separate and untouched) --}}
<link rel="stylesheet" href="{{ asset('css/notification-center.css') }}?v={{ @filemtime(public_path('css/notification-center.css')) ?: '2' }}">

<div class="nc-bell-wrap"
     data-notification-center
     data-index-url="{{ route('notifications.index') }}"
     data-unread-url="{{ route('notifications.unread-count') }}"
     data-read-url="{{ url('/notifications/__ID__/read') }}"
     data-read-all-url="{{ route('notifications.read-all') }}"
     data-archive-url="{{ url('/notifications/__ID__/archive') }}"
     data-destroy-url="{{ url('/notifications/__ID__') }}">

    <button type="button"
            class="nc-bell-btn"
            data-nc-bell
            title="Notifications"
            aria-label="Open notifications"
            aria-haspopup="true"
            aria-expanded="false">
        {{-- Lucide Bell (inline SVG) --}}
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M10.268 21a2 2 0 0 0 3.464 0"/>
            <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>
        </svg>
        <span class="nc-badge" data-nc-badge>0</span>
    </button>

    <div class="nc-panel" data-nc-panel role="dialog" aria-label="Notification center">
        <div class="nc-header">
            <div class="nc-header-row">
                <h3 class="nc-title">Notifications</h3>
                <div class="nc-actions">
                    <button type="button" class="nc-link-btn" data-nc-mark-all>Mark all read</button>
                </div>
            </div>
            <input type="search"
                   class="nc-search"
                   data-nc-search
                   placeholder="Search notifications…"
                   aria-label="Search notifications">
        </div>

        <div class="nc-filters" role="tablist" aria-label="Filter notifications">
            <button type="button" class="nc-filter is-active" data-nc-filter="all">All</button>
            <button type="button" class="nc-filter" data-nc-filter="unread">Unread</button>
            <button type="button" class="nc-filter" data-nc-filter="orders">Orders</button>
            <button type="button" class="nc-filter" data-nc-filter="messages">Messages</button>
            <button type="button" class="nc-filter" data-nc-filter="payments">Payments</button>
            <button type="button" class="nc-filter" data-nc-filter="system">System</button>
            <button type="button" class="nc-filter" data-nc-filter="support">Support</button>
        </div>

        <div class="nc-body" data-nc-list>
            <div class="nc-loading">Loading…</div>
        </div>

        <div class="nc-footer" data-nc-footer style="display:none;">
            <button type="button" class="nc-load-more" data-nc-load-more>Load more</button>
        </div>
    </div>
</div>

<script src="{{ asset('js/notification-center.js') }}" defer></script>
