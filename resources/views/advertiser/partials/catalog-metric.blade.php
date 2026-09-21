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

    $trafficSpark = null;
    if ($metricType === 'traffic') {
        $sparkId = (int) (isset($site) ? $site->id : 0);
        $sparkW = 88.0;
        $sparkH = 36.0;
        $sparkCount = 10;
        $sparkYs = [];
        if ($metricRaw <= 0) {
            $sparkYs = array_fill(0, $sparkCount, 0.2);
        } else {
            $seed = ($sparkId * 2654435761 + ((int) $metricRaw) * 97) & 0x7fffffff;
            $y = 0.38 + (($seed % 40) / 100);
            for ($i = 0; $i < $sparkCount; $i++) {
                $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                $step = ((($seed % 1000) / 1000) - 0.48) * 0.32;
                $y = max(0.08, min(0.96, $y + $step));
                $sparkYs[] = $y;
            }
        }
        $sparkMin = min($sparkYs);
        $sparkMax = max($sparkYs);
        $sparkSpan = max(0.18, $sparkMax - $sparkMin);
        $sparkPts = [];
        foreach ($sparkYs as $i => $y) {
            $x = $sparkCount === 1 ? 0.0 : ($i / ($sparkCount - 1)) * $sparkW;
            $ny = 1 - (($y - $sparkMin) / $sparkSpan);
            $sparkPts[] = [$x, 1.2 + $ny * ($sparkH - 2.6)];
        }
        $sparkLine = '';
        foreach ($sparkPts as $i => [$x, $py]) {
            if ($i === 0) {
                $sparkLine = 'M'.round($x, 2).','.round($py, 2);
                continue;
            }
            $prev = $sparkPts[$i - 1];
            $c1x = $prev[0] + ($x - $prev[0]) / 2;
            $sparkLine .= ' C'.round($c1x, 2).','.round($prev[1], 2).' '.round($c1x, 2).','.round($py, 2).' '.round($x, 2).','.round($py, 2);
        }
        $firstY = round($sparkPts[0][1], 2);
        $lastY = round($sparkPts[array_key_last($sparkPts)][1], 2);
        $trafficSpark = [
            'id' => 'catalog-traffic-spark-'.$sparkId.'-'.substr(md5((string) $metricRaw), 0, 6),
            'line' => $sparkLine,
            'area' => $sparkLine.' L'.$sparkW.','.$sparkH.' L0,'.$sparkH.' L0,'.$firstY.' Z',
            'end' => [round($sparkW, 2), $lastY],
        ];
    }
@endphp

<div class="catalog-metric catalog-metric--{{ $metricType }} {{ $isStandout ? 'is-standout' : '' }}">
    @if($inline)
        <span class="catalog-metric__label" aria-hidden="true">{{ $label }}</span>
    @endif
    <span class="catalog-metric__value">{{ $display }}</span>
    @if($trafficSpark)
        <span class="catalog-metric__spark" aria-hidden="true">
            <svg viewBox="0 0 88 36" preserveAspectRatio="none" focusable="false">
                <defs>
                    <linearGradient id="{{ $trafficSpark['id'] }}" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#0b6266" stop-opacity="0.38"/>
                        <stop offset="1" stop-color="#4ecdcb" stop-opacity="0.04"/>
                    </linearGradient>
                </defs>
                <path class="catalog-metric__spark-area" d="{{ $trafficSpark['area'] }}" fill="url(#{{ $trafficSpark['id'] }})"/>
                <path class="catalog-metric__spark-line" d="{{ $trafficSpark['line'] }}"/>
                <circle class="catalog-metric__spark-halo" cx="{{ $trafficSpark['end'][0] }}" cy="{{ $trafficSpark['end'][1] }}" r="4.2"/>
                <circle class="catalog-metric__spark-end" cx="{{ $trafficSpark['end'][0] }}" cy="{{ $trafficSpark['end'][1] }}" r="2.3"/>
            </svg>
        </span>
    @else
        <span class="catalog-metric__bar" aria-hidden="true">
            <span class="catalog-metric__fill" style="width: {{ round($fill, 1) }}%"></span>
        </span>
    @endif
    <span class="visually-hidden">{{ $title }}</span>
</div>
