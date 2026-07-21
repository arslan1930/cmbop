@php
    $placementKey = $placement ?? 'content_top';
    $banners = collect();
    try {
        $banners = app(\App\Services\PromotionService::class)->activeBanners($placementKey, $audience ?? null);
    } catch (\Throwable $e) {
        $banners = collect();
    }
@endphp

@if($banners->isNotEmpty())
<link rel="stylesheet" href="{{ asset('css/promotions.css') }}">
<div class="ad-banner-slot ad-banner-slot--{{ $placementKey }}" data-placement="{{ $placementKey }}">
    @foreach($banners as $banner)
        @php
            $src = $banner->imageSrc();
            if ($src) {
                // Best-effort impression count without blocking render
                try { $banner->recordImpression(); } catch (\Throwable $e) {}
            }
            $href = $banner->link_url
                ? route('banners.click', $banner)
                : null;
        @endphp
        @if($src)
            <div class="ad-banner" style="--ad-w: {{ $banner->width }}px; --ad-h: {{ $banner->height }}px;">
                @if($href)
                    <a href="{{ $href }}"
                       class="ad-banner__link"
                       @if($banner->open_in_new_tab) target="_blank" rel="noopener sponsored" @endif
                       aria-label="{{ $banner->alt_text ?: ($banner->title ?: $banner->name) }}">
                        <img src="{{ $src }}"
                             alt="{{ $banner->alt_text ?: ($banner->title ?: $banner->name) }}"
                             width="{{ $banner->width }}"
                             height="{{ $banner->height }}"
                             loading="lazy"
                             class="ad-banner__img">
                    </a>
                @else
                    <img src="{{ $src }}"
                         alt="{{ $banner->alt_text ?: ($banner->title ?: $banner->name) }}"
                         width="{{ $banner->width }}"
                         height="{{ $banner->height }}"
                         loading="lazy"
                         class="ad-banner__img">
                @endif
                @if($banner->title)
                    <div class="ad-banner__caption">{{ $banner->title }}</div>
                @endif
            </div>
        @endif
    @endforeach
</div>
@endif
