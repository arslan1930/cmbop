{{-- Homepage / social discoverability chips for closed catalog rows.
     Selection stays in Site Details; these only signal the offer exists.
     Expects $homepageOptions (array), $defaultHomepageDays (?int), $socialChannels (list),
     optional $socialChannelLabels, and optional $openDetailsId (site id). --}}
@php
    $placementHomepageOptions = $homepageOptions ?? [];
    $placementFreeHomepageDays = $defaultHomepageDays ?? null;
    $placementSocialChannels = $socialChannels ?? [];
    $placementSocialLabels = $socialChannelLabels ?? [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X',
    ];
    $placementOpenDetailsId = trim((string) ($openDetailsId ?? ''));
    $showHomepagePlacementChip = $placementHomepageOptions !== [];
    $showSocialPlacementChip = $placementSocialChannels !== [];
    $socialNames = collect($placementSocialChannels)
        ->map(fn ($c) => $placementSocialLabels[$c] ?? ucfirst((string) $c))
        ->filter()
        ->values();
    $socialChipLabel = $socialNames->isNotEmpty()
        ? ('Social: '.$socialNames->implode(', '))
        : 'Social promotions';
    $socialTitle = $showSocialPlacementChip
        ? ('Social promotions included: '.$socialNames->implode(', '))
        : '';
@endphp
@if($showHomepagePlacementChip)
    @if($placementFreeHomepageDays !== null)
        <span class="site-chip site-chip--homepage site-chip--descriptor"
              aria-label="Free homepage placement for up to {{ $placementFreeHomepageDays }} day{{ $placementFreeHomepageDays > 1 ? 's' : '' }} — choose duration in Details">
            <i class="fa-solid fa-house" aria-hidden="true"></i>
            <span>Free homepage</span>
        </span>
    @else
        <span class="site-chip site-chip--homepage site-chip--descriptor"
              aria-label="Optional homepage placement available — choose duration in Details">
            <i class="fa-solid fa-house" aria-hidden="true"></i>
            <span>Homepage</span>
        </span>
    @endif
@endif
@if($showSocialPlacementChip)
    @if($placementOpenDetailsId !== '')
        <button type="button"
                class="site-chip site-chip--social site-chip--descriptor"
                data-catalog-open-details="{{ $placementOpenDetailsId }}"
                data-catalog-open-section="social"
                data-no-tip
                aria-label="{{ $socialTitle }} — open Details">
            <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
            <span>{{ $socialChipLabel }}</span>
        </button>
    @else
        <span class="site-chip site-chip--social site-chip--descriptor"@if($socialTitle !== '') aria-label="{{ $socialTitle }}"@endif>
            <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
            <span>{{ $socialChipLabel }}</span>
        </span>
    @endif
@endif
