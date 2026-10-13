{{-- Catalog listing URL. Revealed: real visit link. Hidden: not a link. --}}
@php
    $urlId = $urlId ?? ('url-host-'.$site->id);
    $urlClass = trim('catalog-site-rooted-url catalog-site-url '.($urlClass ?? ''));
@endphp
@if($canSeeUrl && $showsIdentity)
    <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
       target="_blank"
       rel="noopener nofollow"
       class="site-open-link {{ $urlClass }}"
       id="{{ $urlId }}"
       data-url-open="{{ $site->id }}"
       data-visit-href="{{ route('advertiser.catalog.visit', $site->id) }}"
       data-site-host
       aria-label="Open {{ $displayRootedUrl }} in a new tab"
       @if($showsIdentity) data-host="{{ $displayHost }}" @endif>{{ $displayRootedUrl }}</a>
@else
    <span class="{{ $urlClass }} catalog-site-url--hidden"
          id="{{ $urlId }}"
          data-visit-href="{{ route('advertiser.catalog.visit', $site->id) }}"
          data-site-host
          title="Name and URL hidden — use Reveal"
          @if($inCatalogHideMode)
              data-glass-tip
              data-glass-tip-title="Name and URL hidden"
              data-glass-tip-body="Site name and URL are hidden for 24 hours after repeated domain copying. Open the eye to reveal both for this listing — metrics and price stay visible."
              data-glass-tip-placement="top"
          @endif>
        <i class="fa-solid fa-lock" aria-hidden="true"></i>
        <span>{{ $displayRootedUrl }}</span>
        @if($inCatalogHideMode)
            <button type="button"
                    class="btn btn-sm btn-link p-0 reveal-url catalog-url-eye catalog-site-url__reveal"
                    data-site-id="{{ $site->id }}"
                    title="{{ $eyeShowLabel ?? 'Show site name and URL' }}"
                    aria-label="{{ $eyeShowLabel ?? 'Show site name and URL' }}">Reveal</button>
        @endif
    </span>
@endif
