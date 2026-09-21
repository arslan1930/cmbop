{{-- Homepage / social discoverability chips for closed catalog rows.
     Selection stays in Site Details; these only signal the offer exists.
     Expects $homepageOptions (array), $defaultHomepageDays (?int), $socialChannels (list),
     and optional $socialChannelLabels. --}}
@php
    $placementHomepageOptions = $homepageOptions ?? [];
    $placementFreeHomepageDays = $defaultHomepageDays ?? null;
    $placementSocialChannels = $socialChannels ?? [];
    $placementSocialLabels = $socialChannelLabels ?? [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X',
    ];
    $showHomepagePlacementChip = $placementHomepageOptions !== [];
    $showSocialPlacementChip = $placementSocialChannels !== [];
    $socialTitle = $showSocialPlacementChip
        ? ('Social share included: '.collect($placementSocialChannels)
            ->map(fn ($c) => $placementSocialLabels[$c] ?? ucfirst((string) $c))
            ->implode(', '))
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
    <span class="site-chip site-chip--social site-chip--descriptor"@if($socialTitle !== '') aria-label="{{ $socialTitle }}"@endif>
        <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
        <span>Social</span>
    </span>
@endif
