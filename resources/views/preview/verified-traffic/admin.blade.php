@extends('preview.verified-traffic.layout')

@section('title', 'Admin site — Verified traffic preview')

@section('content')
<div class="vt-site-head">
    <div>
        <h4 class="mb-1 fw-bold">Edit site</h4>
        <p class="text-muted mb-0 small">{{ $site['publisher_name'] }} · {{ $site['publisher_email'] }}</p>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('preview.verified-traffic.index') }}">← Back</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="adminTraffic">Traffic</label>
                <input type="number" id="adminTraffic" class="form-control" value="{{ $site['listing_traffic'] }}">
                <div class="help-text mt-1">Number advertisers see. Same <code>sites.traffic</code> column as today.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="adminDa">DA</label>
                <input type="number" id="adminDa" class="form-control" value="42">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="adminDr">DR</label>
                <input type="number" id="adminDr" class="form-control" value="38">
            </div>
        </div>

        <hr class="my-4">

        <span class="form-section-title">Verified traffic (Google)</span>
        <p class="small text-muted">Separate from Ahrefs/Moz/Semrush enrichment and the existing “Manual lock skips API providers” toggle.</p>

        <div class="vt-connected-grid">
            <div class="vt-stat">
                <span class="vt-stat__label">Source</span>
                <span class="vt-stat__value" style="font-size:16px">GA4</span>
                <span class="vt-stat__hint">Connected · {{ $site['ga4_property_id'] }}</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">Last sync</span>
                <span class="vt-stat__value" style="font-size:16px">{{ $site['last_sync'] }}</span>
                <span class="vt-stat__hint">GSC clicks {{ number_format($site['gsc_clicks']) }} (28d)</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">Publisher fallback</span>
                <span class="vt-stat__value">{{ number_format($site['manual_traffic']) }}</span>
                <span class="vt-stat__hint">Used if Google is disconnected</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">Google account</span>
                <span class="vt-stat__value" style="font-size:14px">{{ $site['google_email'] }}</span>
                <span class="vt-stat__hint">Admin only — never shown to advertisers</span>
            </div>
        </div>

        <div class="vt-admin-override mt-3">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="adminOverride">
                <label class="form-check-label fw-semibold" for="adminOverride">Admin override</label>
            </div>
            <p class="small mb-2">When set, this number wins over GA4/GSC and the listing drops the Verified badge (or keeps it only if you choose “keep badge” — default is drop). Publisher cannot overwrite an admin override until you clear it.</p>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label" for="adminOverrideValue">Override traffic</label>
                    <input type="number" id="adminOverrideValue" class="form-control" placeholder="Leave empty to use Google">
                </div>
            </div>
        </div>

        <div class="alert alert-warning border-0 py-2 mt-3 mb-0">
            Existing SEO “Manual lock skips API providers” stays for DA/DR/Ahrefs. Do not reuse <code>metrics_manual</code> for Google traffic.
        </div>
    </div>
</div>
@endsection
