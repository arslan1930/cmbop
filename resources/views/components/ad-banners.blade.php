@php
    $allowedPlacements = array_keys(config('promotions.banner_placements', []));
    $placementKey = in_array($placement ?? '', $allowedPlacements, true) ? $placement : 'content_top';
    $allowedAudiences = array_keys(config('promotions.audiences', []));
    $audienceKey = in_array($audience ?? '', $allowedAudiences, true) ? $audience : null;
    $banners = collect();
    $trackPromos = $track ?? true;
    try {
        $banners = app(\App\Services\PromotionService::class)->activeBanners($placementKey, $audienceKey);
    } catch (\Throwable $e) {
        $banners = collect();
    }
@endphp

@if($banners->isNotEmpty())
<link rel="stylesheet" href="{{ asset('assets/css/promotions.css') }}">
<div class="ad-banner-slot ad-banner-slot--{{ $placementKey }}" data-placement="{{ $placementKey }}"
     @if($trackPromos) data-promo-track-url="{{ route('promotions.track') }}" @endif>
    @foreach($banners as $banner)
        @php
            $src = $banner->imageSrc();
            $adW = max(1, (int) ($banner->width ?: 300));
            $adH = max(1, (int) ($banner->height ?: 250));
            $href = $banner->clickHref()
                ? ($trackPromos ? route('banners.click', $banner) : $banner->clickHref())
                : null;
        @endphp
        @if($src)
            <div class="ad-banner" style="--ad-w: {{ $adW }}px; --ad-h: {{ $adH }}px;"
                 @if($trackPromos) data-track-banner="{{ $banner->id }}" @endif>
                @if($href)
                    <a href="{{ $href }}"
                       class="ad-banner__link"
                       @if($banner->open_in_new_tab) target="_blank" rel="noopener sponsored" @endif
                       aria-label="{{ scalar_text($banner->alt_text ?: ($banner->title ?: $banner->name)) }}">
                        <img src="{{ $src }}"
                             alt="{{ scalar_text($banner->alt_text ?: ($banner->title ?: $banner->name)) }}"
                             width="{{ $adW }}"
                             height="{{ $adH }}"
                             loading="lazy"
                             decoding="async"
                             class="ad-banner__img">
                    </a>
                @else
                    <img src="{{ $src }}"
                         alt="{{ scalar_text($banner->alt_text ?: ($banner->title ?: $banner->name)) }}"
                         width="{{ $adW }}"
                         height="{{ $adH }}"
                         loading="lazy"
                         decoding="async"
                         class="ad-banner__img">
                @endif
                @if($banner->title)
                    <div class="ad-banner__caption">{{ scalar_text($banner->title) }}</div>
                @endif
            </div>
        @endif
    @endforeach
</div>
@if($trackPromos)
<script src="{{ asset('js/promotion-track.js') }}?v={{ @filemtime(public_path('js/promotion-track.js')) ?: '1' }}" defer></script>
@endif
@endif
