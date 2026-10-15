@php
    $daValue = $site->da;
    $drValue = $site->dr;
    $daLow = (int) ($daValue ?? 0) < \App\Models\Site::GOOD_MIN_DA;
    $drLow = (int) ($drValue ?? 0) < \App\Models\Site::GOOD_MIN_DR;
@endphp
<div title="Domain Authority {{ $daValue ?? '—' }} / Domain Rating {{ $drValue ?? '—' }}. Catalog bar is DA ≥ {{ \App\Models\Site::GOOD_MIN_DA }} and DR ≥ {{ \App\Models\Site::GOOD_MIN_DR }}.">{{ $daValue ?? '—' }} / {{ $drValue ?? '—' }}</div>
@if($daLow || $drLow)
    <div class="admin-sites-need-hint" title="This listing is under the catalog quality bar of DA {{ \App\Models\Site::GOOD_MIN_DA }} and DR {{ \App\Models\Site::GOOD_MIN_DR }}.">need DA/DR {{ \App\Models\Site::GOOD_MIN_DA }}</div>
@endif
