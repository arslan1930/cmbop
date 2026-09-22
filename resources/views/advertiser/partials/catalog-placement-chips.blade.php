{{-- Homepage / social discoverability chips for closed catalog rows.
     Selection stays in Site Details; chips open that section.
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
        ? $socialNames->implode(', ')
        : 'Social';
    $socialTitle = $showSocialPlacementChip
        ? ('Social promotions included: '.$socialNames->implode(', '))
        : '';
    $homepageChipLabel = $placementFreeHomepageDays !== null ? 'Free homepage' : 'Homepage';
    $homepageAria = $placementFreeHomepageDays !== null
        ? ('Free homepage placement for up to '.$placementFreeHomepageDays.' day'.($placementFreeHomepageDays > 1 ? 's' : '').' — choose duration in Details')
        : 'Optional homepage placement available — choose duration in Details';
@endphp
@if($showHomepagePlacementChip)
    @if($placementOpenDetailsId !== '')
        <button type="button"
                class="site-chip site-chip--homepage site-chip--descriptor"
                data-catalog-open-details="{{ $placementOpenDetailsId }}"
                data-catalog-open-section="homepage"
                data-no-tip
                aria-label="{{ $homepageAria }}">
            <span>{{ $homepageChipLabel }}</span>
        </button>
    @else
        <span class="site-chip site-chip--homepage site-chip--descriptor"
              aria-label="{{ $homepageAria }}">
            <span>{{ $homepageChipLabel }}</span>
        </span>
    @endif
@endif
@if($showSocialPlacementChip)
    @php
        $socialIcon = [
            'facebook' => 'fa-facebook',
            'instagram' => 'fa-instagram',
            'x' => 'fa-x-twitter',
        ];
    @endphp
    @if($placementOpenDetailsId !== '')
        <button type="button"
                class="catalog-social-icons"
                data-catalog-open-details="{{ $placementOpenDetailsId }}"
                data-catalog-open-section="social"
                data-no-tip
                aria-label="{{ $socialTitle }} — open Details">
            @foreach($placementSocialChannels as $channel)
                <i class="fa-brands {{ $socialIcon[$channel] ?? 'fa-share-nodes' }}" aria-hidden="true"></i>
            @endforeach
        </button>
    @else
        <span class="catalog-social-icons"@if($socialTitle !== '') aria-label="{{ $socialTitle }}"@endif>
            @foreach($placementSocialChannels as $channel)
                <i class="fa-brands {{ $socialIcon[$channel] ?? 'fa-share-nodes' }}" aria-hidden="true"></i>
            @endforeach
        </span>
    @endif
@endif
