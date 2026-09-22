{{--
    Identity tile for a listing.

    Closed rows load a same-origin favicon. The resolver uses a real icon
    when the listing host is reachable, otherwise catalog-site-fallback.svg.
    Initials stay only when the row is masked. Click opens Details.

    @param string $label          The displayed host (may be masked)
    @param string $size           md (table) | lg (card)
    @param string|null $faviconUrl Public favicon URL when identity is shown
    @param list<string> $faviconChain Remaining URLs to try when the first fails
    @param bool $masked           Hide-mode: keep initials, never a site icon
    @param string $openDetailsId  Site id — click opens that listing’s Details
--}}
@php
    $tileLabel = (string) ($label ?? '');
    $tileSize = ($size ?? 'md') === 'lg' ? 'lg' : 'md';
    $faviconUrl = trim((string) ($faviconUrl ?? ''));
    $faviconChain = is_array($faviconChain ?? null) ? array_values(array_filter(
        $faviconChain,
        fn ($url) => is_string($url) && trim($url) !== ''
    )) : [];
    $openDetailsId = trim((string) ($openDetailsId ?? ''));
    $masked = (bool) ($masked ?? false);
    $fallbackSrc = asset('assets/img/catalog-site-fallback.svg').'?v='.((string) (@filemtime(public_path('assets/img/catalog-site-fallback.svg')) ?: '1'));
    if (! $masked) {
        $faviconChain[] = $fallbackSrc;
        $faviconChain = array_values(array_unique($faviconChain));
        if ($faviconUrl === '') {
            $faviconUrl = $fallbackSrc;
        }
    }
    $hasFavicon = ! $masked && $faviconUrl !== '';
    $isButton = $openDetailsId !== '';
    $useFallback = $hasFavicon && $faviconUrl === $fallbackSrc;

    if ($hasFavicon && ($faviconChain === [] || ($faviconChain[0] ?? '') !== $faviconUrl)) {
        array_unshift($faviconChain, $faviconUrl);
        $faviconChain = array_values(array_unique($faviconChain));
    }

    // First domain segment, split on separators and masking characters.
    $firstSegment = explode('.', $tileLabel)[0] ?? '';
    $words = array_values(array_filter(
        preg_split('/[^A-Za-z0-9]+/', $firstSegment) ?: [],
        fn ($part) => $part !== ''
    ));

    if (count($words) >= 2) {
        $initials = strtoupper(substr($words[0], 0, 1).substr($words[1], 0, 1));
    } elseif (count($words) === 1) {
        $initials = strtoupper(substr($words[0], 0, 2));
    } else {
        $initials = '—';
    }

    // Stable per listing so the same site keeps the same colour between pages.
    $tileTone = (crc32(strtolower($tileLabel)) % 6) + 1;
    $tileClass = 'catalog-tile catalog-tile--'.$tileSize.' catalog-tile--tone'.$tileTone
        .($hasFavicon && ! $useFallback ? ' catalog-tile--favicon' : '')
        .($useFallback ? ' catalog-tile--fallback' : '')
        .($isButton ? ' catalog-tile--open' : '');
@endphp

@if($isButton)
    <button type="button"
            class="{{ $tileClass }}"
            data-catalog-open-details="{{ $openDetailsId }}"
            data-no-tip
            aria-label="Open Details">
        @if($hasFavicon)
            <img src="{{ $faviconUrl }}"
                 alt=""
                 width="32"
                 height="32"
                 decoding="async"
                 referrerpolicy="no-referrer"
                 class="catalog-tile__img catalog-tile__favicon"
                 data-favicon-chain="{{ json_encode($faviconChain, JSON_UNESCAPED_SLASHES) }}"
                 data-favicon-fallback="{{ $fallbackSrc }}"
                 data-favicon-i="0"
                 onerror="window.catalogSiteFaviconOnError && window.catalogSiteFaviconOnError(this)">
        @endif
        <span class="catalog-tile__fallback"
              aria-hidden="true"
              hidden>
            <img src="{{ $fallbackSrc }}"
                 alt=""
                 width="32"
                 height="32"
                 decoding="async"
                 class="catalog-tile__fallback-img">
        </span>
        <span class="catalog-tile__initials"
              title="Initials from the site host — not a country code"
              aria-hidden="true"
              @unless($masked) hidden @endunless>{{ $initials }}</span>
    </button>
@elseif($hasFavicon)
    <span class="{{ $tileClass }}" aria-hidden="true">
        <img src="{{ $faviconUrl }}"
             alt=""
             width="32"
             height="32"
             decoding="async"
             referrerpolicy="no-referrer"
             class="catalog-tile__img catalog-tile__favicon"
             data-favicon-chain="{{ json_encode($faviconChain, JSON_UNESCAPED_SLASHES) }}"
             data-favicon-fallback="{{ $fallbackSrc }}"
             data-favicon-i="0"
             onerror="window.catalogSiteFaviconOnError && window.catalogSiteFaviconOnError(this)">
        <span class="catalog-tile__fallback"
              aria-hidden="true"
              hidden>
            <img src="{{ $fallbackSrc }}"
                 alt=""
                 width="32"
                 height="32"
                 decoding="async"
                 class="catalog-tile__fallback-img">
        </span>
    </span>
@else
    <span class="{{ $tileClass }}"
          title="Initials from the site host — not a country code"
          aria-hidden="true">{{ $initials }}</span>
@endif
