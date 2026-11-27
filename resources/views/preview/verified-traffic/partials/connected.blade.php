<section class="vt-card" id="vt-connected">
    <div class="vt-card__body">
        <span class="vt-card__kicker">Connected</span>
        <h2 class="vt-card__title">Verified traffic is live on this listing</h2>
        <p class="vt-card__lead mb-2">
            Google account <strong>{{ $site['google_email'] }}</strong>
            · {{ $site['ga4_property'] }}
            · {{ $site['gsc_property'] }}
        </p>
        @include('preview.verified-traffic.partials.badge', ['via' => 'GA4'])

        <div class="vt-connected-grid">
            <div class="vt-stat">
                <span class="vt-stat__label">Listing traffic</span>
                <span class="vt-stat__value">{{ number_format($site['ga4_sessions']) }}</span>
                <span class="vt-stat__hint">GA4 organic sessions, last 30 days</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">Search Console</span>
                <span class="vt-stat__value">{{ number_format($site['gsc_clicks']) }}</span>
                <span class="vt-stat__hint">Clicks, last 28 days (not shown on catalog)</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">Last sync</span>
                <span class="vt-stat__value" style="font-size:16px">{{ $site['last_sync'] }}</span>
                <span class="vt-stat__hint">Weekly refresh {{ $site['next_refresh'] }}</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">Manual fallback</span>
                <span class="vt-stat__value">{{ number_format($site['manual_traffic']) }}</span>
                <span class="vt-stat__hint">Restored if you disconnect</span>
            </div>
        </div>

        <p class="vt-fineprint mb-0">Advertisers see the listing number and the Verified badge only — not last-sync time, not GSC clicks, not your Google email.</p>
        <div class="vt-actions">
            <button type="button" class="btn btn-outline-danger" disabled>Disconnect Google</button>
            <a class="btn btn-outline-secondary" href="{{ route('preview.verified-traffic.publisher', ['state' => 'picker']) }}">Change property</a>
        </div>
    </div>
</section>
