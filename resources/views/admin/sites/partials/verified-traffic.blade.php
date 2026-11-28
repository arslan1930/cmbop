@php
    $vtReady = \App\Support\VerifiedTraffic::schemaReady();
    $connection = $vtReady ? $site->trafficConnection : null;
    $vtSuffix = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($formSuffix ?? 'vt')) ?: 'vt';
    $vtOverrideId = 'trafficAdminOverride-'.$vtSuffix;
    $vtCheckId = 'trafficOverrideOn-'.$vtSuffix;
@endphp
@if($vtReady)
    <div class="col-12">
        <span class="form-section-title">{{ __('messages.verified_traffic_admin_title') }}</span>
        <p class="small text-muted">{{ __('messages.verified_traffic_admin_help') }}</p>
        <div class="vt-connected-grid">
            <div class="vt-stat">
                <span class="vt-stat__label">{{ __('messages.verified_traffic_source') }}</span>
                <span class="vt-stat__value" style="font-size:16px">{{ strtoupper((string) ($site->traffic_source ?: 'manual')) }}</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">{{ __('messages.verified_traffic_last_sync') }}</span>
                <span class="vt-stat__value" style="font-size:16px">{{ $connection?->last_synced_at?->diffForHumans() ?: '—' }}</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">GA4</span>
                <span class="vt-stat__value">{{ $site->traffic_ga4 !== null ? number_format((int) $site->traffic_ga4) : '—' }}</span>
            </div>
            <div class="vt-stat">
                <span class="vt-stat__label">GSC</span>
                <span class="vt-stat__value">{{ $site->traffic_gsc_clicks !== null ? number_format((int) $site->traffic_gsc_clicks) : '—' }}</span>
            </div>
        </div>
        @if($connection?->google_account_email)
            <p class="small text-muted mt-2 mb-2">Google: {{ $connection->google_account_email }}</p>
        @endif
        <form method="POST" action="{{ staff_route('sites.traffic-override', $site->id, false) }}" class="vt-admin-override mt-2">
            @csrf
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="{{ $vtCheckId }}" {{ $site->traffic_admin_override !== null ? 'checked' : '' }}
                       onchange="document.getElementById(@json($vtOverrideId)).disabled = !this.checked; if(!this.checked){ this.form.submit(); }">
                <label class="form-check-label fw-semibold" for="{{ $vtCheckId }}">{{ __('messages.verified_traffic_override') }}</label>
            </div>
            <p class="small mb-2">{{ __('messages.verified_traffic_override_help') }}</p>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label" for="{{ $vtOverrideId }}">{{ __('messages.verified_traffic_override') }}</label>
                    <input type="number" min="0" name="traffic_admin_override" id="{{ $vtOverrideId }}" class="form-control"
                           value="{{ $site->traffic_admin_override }}"
                           {{ $site->traffic_admin_override === null ? 'disabled' : '' }}>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('messages.verified_traffic_override_save') }}</button>
                </div>
            </div>
        </form>
    </div>
@endif
