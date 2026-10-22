@php
    $occupancy = $occupancy ?? ($occupiedSites[$site->id] ?? $occupiedSites[(string) $site->id] ?? null);
    $signClass = trim('catalog-existing-article '.($signClass ?? ''));
@endphp
@if(is_array($occupancy) && ! empty($occupancy['url']))
    <p class="{{ $signClass }}" data-site-id="{{ $site->id }}" data-occupied="1">
        <span>You already have an article for this site</span>
        <a href="{{ $occupancy['url'] }}">View article</a>
    </p>
@else
    <p class="{{ $signClass }}" data-site-id="{{ $site->id }}" data-occupied="0" hidden>
        <span>You already have an article for this site</span>
        <a href="{{ route('advertiser.content-library', absolute: false) }}">View article</a>
    </p>
@endif
