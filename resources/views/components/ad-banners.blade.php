@php
    $placementKey = $placement ?? 'content_top';
    $banners = collect();
    $trackPromos = $track ?? true;
    try {
        $banners = app(\App\Services\PromotionService::class)->activeBanners($placementKey, $audience ?? null);
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
            $href = $banner->link_url
                ? route('banners.click', $banner)
                : null;
        @endphp
        @if($src)
            <div class="ad-banner" style="--ad-w: {{ $banner->width }}px; --ad-h: {{ $banner->height }}px;"
                 @if($trackPromos) data-track-banner="{{ $banner->id }}" @endif>
                @if($href)
                    <a href="{{ $href }}"
                       class="ad-banner__link"
                       @if($banner->open_in_new_tab) target="_blank" rel="noopener sponsored" @endif
                       aria-label="{{ scalar_text($banner->alt_text ?: ($banner->title ?: $banner->name)) }}">
                        <img src="{{ $src }}"
                             alt="{{ scalar_text($banner->alt_text ?: ($banner->title ?: $banner->name)) }}"
                             width="{{ $banner->width }}"
                             height="{{ $banner->height }}"
                             loading="lazy"
                             class="ad-banner__img">
                    </a>
                @else
                    <img src="{{ $src }}"
                         alt="{{ scalar_text($banner->alt_text ?: ($banner->title ?: $banner->name)) }}"
                         width="{{ $banner->width }}"
                         height="{{ $banner->height }}"
                         loading="lazy"
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
