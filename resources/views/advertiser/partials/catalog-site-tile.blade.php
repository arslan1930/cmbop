{{--
    Identity tile for a listing.

    Closed rows show the site favicon (stored, or fetched by host). Initials
    stay on screen when the row is masked or the icon fails. Click opens Details.

    @param string $label          The displayed host (may be masked)
    @param string $size           md (table) | lg (card)
    @param string|null $faviconUrl Public favicon URL when identity is shown
    @param string $openDetailsId  Site id — click opens that listing’s Details
--}}
@php
    $tileLabel = (string) ($label ?? '');
    $tileSize = ($size ?? 'md') === 'lg' ? 'lg' : 'md';
    $faviconUrl = trim((string) ($faviconUrl ?? ''));
    $openDetailsId = trim((string) ($openDetailsId ?? ''));
    $hasFavicon = $faviconUrl !== '';
    $isButton = $openDetailsId !== '';

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
        .($hasFavicon ? ' catalog-tile--favicon' : '')
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
                 onerror="window.catalogSiteFaviconOnError && window.catalogSiteFaviconOnError(this)">
        @endif
        <span class="catalog-tile__initials"
              title="Initials from the site host — not a country code"
              aria-hidden="true"
              @if($hasFavicon) hidden @endif>{{ $initials }}</span>
    </button>
@else
    <span class="{{ $tileClass }}"
          title="Initials from the site host — not a country code"
          aria-hidden="true">{{ $initials }}</span>
@endif
