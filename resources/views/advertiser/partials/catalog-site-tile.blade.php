{{--
    Identity tile for a listing.

    Rows were a wall of monospace domains with nothing to fix the eye on.
    When a homepage capture exists and the row is allowed to show identity,
    the tile is that screenshot (click opens Details). Otherwise the initials
    come from the label already on screen — for a masked listing that is the
    masked label, so this never discloses a host the row is hiding.

    @param string $label          The displayed host (may be masked)
    @param string $size           md (table) | lg (card)
    @param string|null $previewUrl First usable /media or /storage URL
    @param array  $previewChain   Fallback URL chain for onerror
    @param string $openDetailsId  Site id — click opens that listing’s Details
--}}
@php
    $tileLabel = (string) ($label ?? '');
    $tileSize = ($size ?? 'md') === 'lg' ? 'lg' : 'md';
    $previewUrl = trim((string) ($previewUrl ?? ''));
    $previewChain = is_array($previewChain ?? null) ? $previewChain : [];
    $openDetailsId = trim((string) ($openDetailsId ?? ''));
    $hasPreview = $previewUrl !== '' && $openDetailsId !== '';

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
@endphp

@if($hasPreview)
    <button type="button"
            class="catalog-tile catalog-tile--{{ $tileSize }} catalog-tile--preview catalog-tile--tone{{ $tileTone }}"
            data-catalog-open-details="{{ $openDetailsId }}"
            data-no-tip
            aria-label="Homepage preview — open Details">
        <img src="{{ $previewUrl }}"
             alt=""
             decoding="async"
             class="catalog-tile__img"
             data-preview-chain="{{ json_encode($previewChain !== [] ? $previewChain : [$previewUrl], JSON_UNESCAPED_SLASHES) }}"
             data-preview-i="0"
             onerror="window.catalogSitePreviewOnError && window.catalogSitePreviewOnError(this)">
        <span class="catalog-tile__initials"
              title="Initials from the site host — not a country code"
              aria-hidden="true"
              hidden>{{ $initials }}</span>
    </button>
@else
    <span class="catalog-tile catalog-tile--{{ $tileSize }} catalog-tile--tone{{ $tileTone }}"
          title="Initials from the site host — not a country code"
          aria-hidden="true">{{ $initials }}</span>
@endif
