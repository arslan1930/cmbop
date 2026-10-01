@php
    $daValue = $site->da;
    $drValue = $site->dr;
    $daLow = (int) ($daValue ?? 0) < \App\Models\Site::GOOD_MIN_DA;
    $drLow = (int) ($drValue ?? 0) < \App\Models\Site::GOOD_MIN_DR;
@endphp
<div>{{ $daValue ?? '—' }} / {{ $drValue ?? '—' }}</div>
@if($daLow || $drLow)
    <div class="admin-sites-need-hint">need {{ \App\Models\Site::GOOD_MIN_DA }}</div>
@endif
