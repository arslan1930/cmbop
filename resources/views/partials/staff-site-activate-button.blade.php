{{--
    Queue / dashboard next action.
    Below the quality bar: Fix metrics is the only front button. Admins can still
    activate from More. A missing country is Set country. In-bar rows keep Activate.
    A listing that can go live but still lacks a cover or tags uses a quieter button.

    @param \App\Models\Site $site
--}}
@php
    $actor = auth()->user();
    $staffIsMarketing = (bool) ($actor?->isMarketing() && ! $actor?->isAdmin());
    $activateBlock = $site->staffGoLiveBlockReason($staffIsMarketing);
    $belowBar = ! $site->hasGoodMetrics();
    $inactive = ! (bool) $site->active && ! $site->isArchived();
    $offerFixMetrics = $inactive && $belowBar;
    $offerCountry = $inactive && ! $belowBar && ! $site->hasMarketplaceCountry();
    $thinListing = $inactive && (! $site->hasCatalogCover() || $site->tagValue() === null);
    $fixTitle = $activateBlock ?: 'Update DA, DR, or traffic.';
@endphp
@if($offerFixMetrics)
    <a class="btn btn-sm btn-outline-warning" href="{{ staff_route('sites.edit', $site->id) }}#da" title="{{ $fixTitle }}">Fix metrics</a>
@endif
@if($offerCountry)
    <a class="btn btn-sm btn-outline-danger" href="{{ staff_route('sites.edit', $site->id) }}#country" title="{{ $activateBlock ?: 'Set a marketplace country before activating this site.' }}">Set country</a>
@endif
@if($actor?->canActivateSites())
    @if($activateBlock === null && $offerFixMetrics)
        <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">More</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <button type="button"
                            class="dropdown-item js-mkt-activate"
                            data-id="{{ $site->id }}"
                            data-name="{{ $site->site_name }}"
                            data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}"
                            data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}">Activate anyway</button>
                </li>
            </ul>
        </div>
    @elseif($activateBlock === null && ! $offerCountry)
        <button type="button"
                class="btn btn-sm {{ $thinListing ? 'btn-outline-success' : 'btn-success' }} js-mkt-activate"
                data-id="{{ $site->id }}"
                data-name="{{ $site->site_name }}"
                data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}"
                data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}"
                @if($thinListing) title="Can go live. Cover or tags are still missing." @endif>Activate</button>
    @elseif($activateBlock !== null && ! $offerFixMetrics && ! $offerCountry)
        <button type="button"
                class="btn btn-sm btn-outline-secondary"
                disabled
                title="{{ $activateBlock }}">Activate</button>
    @endif
@endif
