{{--
    One catalog metric: label, value and a bar showing where the value sits.

    A bare "55" says nothing about whether 55 is good. The bar gives the number a
    scale to sit against, so a column of listings can be compared at a glance.

    @param string $type   dr | da | traffic
    @param mixed  $value
    @param bool   $inline Table cells stack label above value; cards repeat the
                          label as their own heading, so they pass inline=false.
--}}
@php
    $metricType = $type ?? 'dr';
    $metricRaw = (float) ($value ?? 0);
    $inline = $inline ?? true;

    if ($metricType === 'traffic') {
        // Log scale: traffic spans several orders of magnitude, so a linear bar
        // would leave everything under ~100k indistinguishable from zero.
        $ceiling = log10(2000000);
        $fill = $metricRaw > 0 ? min(100, (log10($metricRaw + 1) / $ceiling) * 100) : 0;
        $display = $metricRaw >= 1000000
            ? rtrim(rtrim(number_format($metricRaw / 1000000, 1), '0'), '.').'M'
            : ($metricRaw >= 1000
                ? rtrim(rtrim(number_format($metricRaw / 1000, 1), '0'), '.').'k'
                : number_format($metricRaw));
        $title = number_format($metricRaw).' monthly visits';
        $label = 'Traffic';
    } else {
        $fill = max(0, min(100, $metricRaw));
        $display = (string) (int) $metricRaw;
        $label = strtoupper($metricType);
        $title = $label === 'DR'
            ? 'Ahrefs Domain Rating '.$display.' out of 100'
            : 'Moz Domain Authority '.$display.' out of 100';
    }

    // One hue, length carries the magnitude. Grading the bar red/amber/green
    // would put a judgement on publisher inventory that the marketplace does
    // not make; a deeper fill past 70 still marks the standouts.
    $isStandout = $fill >= 70;
@endphp

<div class="catalog-metric catalog-metric--{{ $metricType }} {{ $isStandout ? 'is-standout' : '' }}" title="{{ $title }}">
    @if($inline)
        <span class="catalog-metric__label" aria-hidden="true">{{ $label }}</span>
    @endif
    <span class="catalog-metric__value">{{ $display }}</span>
    <span class="catalog-metric__bar" aria-hidden="true">
        <span class="catalog-metric__fill" style="width: {{ round($fill, 1) }}%"></span>
    </span>
    <span class="visually-hidden">{{ $title }}</span>
</div>
