@php
    $trafficValue = (int) $site->traffic;
    $trafficLow = $trafficValue < \App\Models\Site::GOOD_MIN_TRAFFIC;
    $trafficSource = \App\Support\VerifiedTraffic::schemaReady()
        ? strtoupper((string) ($site->traffic_source ?: 'manual'))
        : null;
@endphp
<div title="Organic traffic {{ number_format($trafficValue) }}/mo. Catalog bar is {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}/mo.">{{ number_format($trafficValue) }}</div>
@if(in_array($trafficSource, ['GA4', 'GSC', 'ADMIN'], true))
    <div class="small text-muted">{{ $trafficSource }}</div>
@endif
@if($trafficLow)
    <div class="admin-sites-need-hint" title="This listing is under the catalog traffic bar of {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }} visits/month.">need {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}/mo</div>
@endif
