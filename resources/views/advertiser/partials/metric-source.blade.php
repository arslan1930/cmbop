{{--
    The mark of the tool a metric comes from.

    These used to be 16px raster crops repeated beside every number, which made a
    long results table noisy without telling anyone anything new on row 40. A
    metric's source is a property of the column, not of the listing, so the mark
    belongs wherever the column is named once: the table head, and the metric
    labels on the card layout, which has no table head to carry it.

    @param string $type dr | da | traffic
    @param string $size sm (card labels) | md (table head)
--}}
@php
    $sourceType = $type ?? 'dr';
    $sourceSize = ($size ?? 'md') === 'sm' ? 'sm' : 'md';
    // Absolute px on the <img> matter: Ahrefs/Moz rasters are 225–400px. Without
    // width/height attributes, Chrome can lay the table head out at intrinsic
    // size before (or if) the tile CSS applies — blowing DR/DA headers apart.
    $sourcePx = $sourceSize === 'sm' ? 16 : 20;

    $sources = [
        'dr' => ['file' => 'assets/img/ahref.jpeg', 'name' => 'Ahrefs', 'alt' => 'Ahrefs DR', 'fit' => 'cover', 'blend' => null],
        'da' => ['file' => 'assets/img/moz_da.png', 'name' => 'Moz', 'alt' => 'Moz DA', 'fit' => 'contain', 'blend' => 'multiply'],
        'traffic' => ['file' => 'assets/img/traffic.svg', 'name' => 'Analytics', 'alt' => 'Organic traffic', 'fit' => 'contain', 'blend' => null],
    ];

    $source = $sources[$sourceType] ?? null;
@endphp

@if($source)
    {{-- Named alt for crawlers (empty alt was flagged). aria-hidden keeps the
         column heading from being announced twice to a screen reader. --}}
    <span class="metric-source metric-source--{{ $sourceSize }} metric-source--fit-{{ $source['fit'] }}{{ ! empty($source['blend']) ? ' metric-source--blend-'.$source['blend'] : '' }}"
          title="Source: {{ $source['name'] }}"
          aria-hidden="true">
        <img src="{{ asset($source['file']) }}"
             alt="{{ $source['alt'] }}"
             width="{{ $sourcePx }}"
             height="{{ $sourcePx }}"
             loading="{{ $sourceSize === 'md' ? 'eager' : 'lazy' }}"
             decoding="async"
             onerror="this.closest('.metric-source').style.display='none'">
    </span>
@endif
