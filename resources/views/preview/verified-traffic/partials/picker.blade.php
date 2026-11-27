<section class="vt-card" id="vt-picker">
    <div class="vt-card__body">
        <span class="vt-card__kicker">Choose properties</span>
        <h2 class="vt-card__title">Match {{ $site['domain'] }}</h2>
        <p class="vt-card__lead">
            Signed in as <strong>{{ $site['google_email'] }}</strong>. Pick the GA4 property and/or Search Console property that belong to this site. Mismatched hostnames stay disabled.
        </p>

        <h3 class="form-section-title">Google Analytics 4</h3>
        <label class="vt-property is-match">
            <input type="radio" name="preview_ga4" value="123456789" checked>
            <span>
                <span class="vt-property__name">Health Insights</span>
                <span class="vt-property__meta d-block">properties/123456789 · healthinsights.example</span>
                <span class="vt-pill mt-1">Domain match</span>
            </span>
        </label>
        <label class="vt-property is-off">
            <input type="radio" name="preview_ga4" value="999" disabled>
            <span>
                <span class="vt-property__name">Parent company rollup</span>
                <span class="vt-property__meta d-block">properties/999888777 · otherbrand.example</span>
                <span class="vt-pill vt-pill--warn mt-1">Hostname does not match</span>
            </span>
        </label>

        <h3 class="form-section-title mt-4">Search Console</h3>
        <label class="vt-property is-match">
            <input type="radio" name="preview_gsc" value="domain" checked>
            <span>
                <span class="vt-property__name">sc-domain:healthinsights.example</span>
                <span class="vt-property__meta d-block">Domain property · last 28 days clicks</span>
                <span class="vt-pill mt-1">Domain match</span>
            </span>
        </label>
        <label class="vt-property is-match">
            <input type="radio" name="preview_gsc" value="url">
            <span>
                <span class="vt-property__name">https://www.healthinsights.example/</span>
                <span class="vt-property__meta d-block">URL-prefix property</span>
                <span class="vt-pill mt-1">Domain match</span>
            </span>
        </label>

        <p class="vt-fineprint">At least one property is required. GA4 organic sessions (30 days) is the listing number when both exist. GSC clicks are stored as a cross-check and can back the badge if GA4 is missing.</p>
        <div class="vt-actions">
            <a class="btn btn-primary" href="{{ route('preview.verified-traffic.publisher', ['state' => 'connected']) }}">Save and sync</a>
            <a class="btn btn-outline-secondary" href="{{ route('preview.verified-traffic.publisher', ['state' => 'connect']) }}">Cancel</a>
        </div>
    </div>
</section>
