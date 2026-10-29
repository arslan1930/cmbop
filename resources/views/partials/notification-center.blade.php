{{-- In-app Notification Center (emails are separate and untouched) --}}
{{-- CSS/JS are loaded from advertiser/publisher layout head+footer to avoid topbar flex overlap --}}
{{-- Relative endpoint paths: absolute APP_URL mismatches break fetch(credentials: 'same-origin'). --}}
<div class="nc-bell-wrap nc-theme"
     data-notification-center
     data-index-url="{{ route('notifications.index', absolute: false) }}"
     data-unread-url="{{ route('notifications.unread-count', absolute: false) }}"
     data-read-url="/notifications/__ID__/read"
     data-unread-item-url="/notifications/__ID__/unread"
     data-read-all-url="{{ route('notifications.read-all', absolute: false) }}"
     data-archive-url="/notifications/__ID__/archive"
     data-destroy-url="/notifications/__ID__"
     data-all-url="{{ route('notifications.all', absolute: false) }}"
     data-settings-url="{{ route('profile.notifications', absolute: false) }}">

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
        <span class="nc-badge pulse-badge" data-nc-badge data-pulse-display="inline-flex">0</span>
    </button>

    <div class="nc-panel nc-theme" data-nc-panel role="dialog" aria-labelledby="ncTitle">
        <div class="nc-header">
            <div class="nc-header-row">
                <div class="nc-header-copy">
                    <p class="nc-title" id="ncTitle">Notifications</p>
                    <p class="nc-subtitle" data-nc-unread-label>0 unread</p>
                </div>
                <div class="nc-actions">
                    <button type="button" class="nc-link-btn" data-nc-mark-all>Mark all read</button>
                    <a href="{{ route('profile.notifications', absolute: false) }}"
                       class="nc-settings-btn"
                       data-nc-settings
                       title="Notification settings"
                       aria-label="Notification settings">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </a>
                </div>
            </div>
            <label class="visually-hidden" for="nc-dropdown-search">Search notifications</label>
            <input type="search"
                   id="nc-dropdown-search"
                   class="nc-search"
                   data-nc-search
                   placeholder="Search notifications…"
                   autocomplete="off"
                   enterkeyhint="search">
        </div>

        <div class="nc-filters-wrap" data-nc-filters-wrap>
            <button type="button" class="nc-filters-arrow nc-filters-arrow--prev" data-nc-filters-prev aria-label="Scroll filters left" hidden>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <div class="nc-filters" role="tablist" aria-label="Filter notifications" data-nc-filters>
                <button type="button" class="nc-filter" data-nc-filter="all" role="tab" aria-selected="false">All</button>
                <button type="button" class="nc-filter is-active" data-nc-filter="unread" role="tab" aria-selected="true">Unread</button>
                <button type="button" class="nc-filter" data-nc-filter="orders" role="tab" aria-selected="false">Orders</button>
                <button type="button" class="nc-filter" data-nc-filter="messages" role="tab" aria-selected="false"><span class="nc-filter-l">Messages</span><span class="nc-filter-s">Msgs</span></button>
                <button type="button" class="nc-filter" data-nc-filter="payments" role="tab" aria-selected="false"><span class="nc-filter-l">Payments</span><span class="nc-filter-s">Pay</span></button>
                <button type="button" class="nc-filter" data-nc-filter="account" role="tab" aria-selected="false">Account</button>
                <button type="button" class="nc-filter" data-nc-filter="system" role="tab" aria-selected="false">System</button>
            </div>
            <button type="button" class="nc-filters-arrow nc-filters-arrow--next" data-nc-filters-next aria-label="Scroll filters right" hidden>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>

        <div class="nc-body" data-nc-list>
            <div class="nc-loading">Loading…</div>
        </div>

        <div class="nc-footer" data-nc-footer>
            <a href="{{ route('notifications.all', absolute: false) }}" class="nc-show-all" data-nc-show-all>Show all</a>
            <a href="{{ route('profile.notifications', absolute: false) }}" class="nc-footer-settings" data-nc-settings>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="14" height="14">
                    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                Settings
            </a>
        </div>
    </div>
</div>
