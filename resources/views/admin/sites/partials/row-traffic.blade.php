@php
    $trafficValue = (int) $site->traffic;
    $trafficLow = $trafficValue < \App\Models\Site::GOOD_MIN_TRAFFIC;
@endphp
<div>{{ number_format($trafficValue) }}</div>
@if($trafficLow)
    <div class="admin-sites-need-hint">need {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}</div>
@endif
