@props([
    'site',
    'compact' => true,
])

@php
    $countryCode = $site->primaryCountryCode();
    $countryName = $countryCode && function_exists('fullCountry') ? fullCountry($countryCode) : ($countryCode ? strtoupper($countryCode) : null);
    $flag = '';
    if ($countryCode && function_exists('getCountryFlag')) {
        $flag = getCountryFlag($countryCode);
    } elseif ($countryCode) {
        // Inline fallback matching catalog helper style
        $code = strtoupper($countryCode === 'uk' ? 'GB' : $countryCode);
        if (strlen($code) === 2) {
            $flag = mb_convert_encoding('&#' . (127397 + ord($code[0])) . ';', 'UTF-8', 'HTML-ENTITIES')
                  . mb_convert_encoding('&#' . (127397 + ord($code[1])) . ';', 'UTF-8', 'HTML-ENTITIES');
        }
    }
    $lastUpdated = $site->lastUpdatedLabel();
    $dr = $site->dr;
    $da = $site->da;
    $trafficLabel = $site->formattedTraffic();
@endphp

<div {{ $attributes->class(['publisher-seo-panel', $compact ? 'publisher-seo-panel--compact' : 'publisher-seo-panel--full']) }}>
    <div class="publisher-seo-panel__grid" role="group" aria-label="SEO metrics">
        <div class="publisher-seo-metric" title="Domain Rating (Ahrefs)">
            <span class="publisher-seo-metric__icon" aria-hidden="true">📊</span>
            <span class="publisher-seo-metric__label">DR</span>
            <span class="publisher-seo-metric__value">{{ $dr !== null ? $dr : '—' }}</span>
        </div>
        <div class="publisher-seo-metric" title="Domain Authority (Moz)">
            <span class="publisher-seo-metric__icon" aria-hidden="true">🏛️</span>
            <span class="publisher-seo-metric__label">DA</span>
            <span class="publisher-seo-metric__value">{{ $da !== null ? $da : '—' }}</span>
        </div>
        <div class="publisher-seo-metric publisher-seo-metric--wide" title="Monthly organic traffic">
            <span class="publisher-seo-metric__icon" aria-hidden="true">📈</span>
            <span class="publisher-seo-metric__value">{{ $trafficLabel }}</span>
            <span class="publisher-seo-metric__label">Monthly Traffic</span>
        </div>
        <div class="publisher-seo-metric publisher-seo-metric--wide" title="Primary country">
            <span class="publisher-seo-metric__icon" aria-hidden="true">🌍</span>
            <span class="publisher-seo-metric__value">
                @if($flag)<span class="publisher-seo-flag" aria-hidden="true">{!! $flag !!}</span>@endif
                {{ $countryName ?: '—' }}
            </span>
        </div>
        <div class="publisher-seo-metric publisher-seo-metric--wide" title="Average publishing time">
            <span class="publisher-seo-metric__icon" aria-hidden="true">⚡</span>
            <span class="publisher-seo-metric__label">Avg. Publish:</span>
            <span class="publisher-seo-metric__value">{{ $site->averagePublishLabel() }}</span>
        </div>
    </div>
    @if($lastUpdated)
        <div class="publisher-seo-panel__updated">Last Updated: {{ $lastUpdated }}</div>
    @endif
</div>
