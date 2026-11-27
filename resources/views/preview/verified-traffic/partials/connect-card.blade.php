<section class="vt-card" id="vt-connect">
    <div class="vt-card__body">
        <span class="vt-card__kicker">Google</span>
        <h2 class="vt-card__title">Connect Google Analytics / Search Console</h2>
        <p class="vt-card__lead">
            Pull monthly visits for <strong>{{ $site['domain'] }}</strong> from the publisher’s own Google account.
            Advertisers then see a <strong>Verified traffic</strong> badge on the listing. Manual traffic stays as a fallback if you disconnect.
        </p>
        <a class="vt-google-btn" href="{{ route('preview.verified-traffic.publisher', ['state' => 'picker']) }}" role="button">
            @include('preview.verified-traffic.partials.google-icon')
            Connect Google Analytics / Search Console
        </a>
        <div class="vt-fineprint">
            Read-only. We request
            <code>analytics.readonly</code> and
            <code>webmasters.readonly</code>
            only.
            <ul>
                <li>One Google account per site. You can disconnect at any time.</li>
                <li>We store tokens encrypted. Advertisers never see your Google email.</li>
                <li>If Google access fails, the listing keeps the last number and drops the Verified badge.</li>
            </ul>
        </div>
        <div class="form-section mt-4 mb-0">
            <span class="form-section-title">Manual traffic (fallback)</span>
            <div class="row bg-light p-3 rounded g-3 g-form">
                <div class="col-md-4">
                    <label class="form-label" for="previewManualTraffic">Traffic</label>
                    <input type="number" id="previewManualTraffic" class="form-control" value="{{ $site['manual_traffic'] }}" disabled>
                    <div class="help-text mt-1">Visitors / month. Used until Google is connected, and again if you disconnect.</div>
                </div>
            </div>
        </div>
    </div>
</section>
