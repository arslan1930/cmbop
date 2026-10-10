{{--
    Queue / dashboard next action.
    Waiting on publisher: Active now is direct live (skip Accept / review).
    After review: Activate is not direct. Below the quality bar: Fix metrics
    is the front button; admins can still activate from More. A missing country
    is Set country. A listing that can go live but still lacks a cover or tags
    uses a quieter button.

    @param \App\Models\Site $site
    @param bool $iconOnly
--}}
@php
    $actor = auth()->user();
    $staffIsMarketing = (bool) ($actor?->isMarketing() && ! $actor?->isAdmin());
    $activateBlock = $site->staffGoLiveBlockReason($staffIsMarketing);
    $publishNowBlock = $site->staffPublishNowBlockReason();
    $offerPublishNow = $actor?->canActivateSites() && $publishNowBlock === null;
    $belowBar = ! $site->hasGoodMetrics();
    $inactive = ! (bool) $site->active && ! $site->isArchived();
    $offerFixMetrics = $inactive && $belowBar;
    $offerCountry = $inactive && ! $site->hasMarketplaceCountry();
    $thinListing = $inactive && (! $site->hasCatalogCover() || $site->tagValue() === null);
    $fixTitle = $activateBlock ?: 'Update DA, DR, or traffic.';
    $iconOnly = ! empty($iconOnly);
    $iconClass = $iconOnly ? ' staff-action-icon-btn' : '';
    $publishNowTitle = 'Active now (direct) — live now, publisher does not need to Accept';
    $activateAfterTitle = $thinListing
        ? 'Activate after review — not direct. Cover or tags are still missing.'
        : 'Activate after review — not direct';
@endphp
@if($offerCountry)
    <a class="btn btn-sm btn-outline-danger{{ $iconClass }}" href="{{ staff_route('sites.edit', $site->id) }}#country" title="{{ $publishNowBlock ?: ($activateBlock ?: 'Set a marketplace country before activating this site.') }}" @if($iconOnly) aria-label="Set country" @endif>
        @if($iconOnly)
            <i class="fa fa-globe" aria-hidden="true"></i>
        @else
            Set country
        @endif
    </a>
@endif
@if($offerFixMetrics)
    <a class="btn btn-sm btn-outline-warning{{ $iconClass }}" href="{{ staff_route('sites.edit', $site->id) }}#da" title="{{ $fixTitle }}" @if($iconOnly) aria-label="Fix metrics" @endif>
        @if($iconOnly)
            <i class="fa fa-chart-line" aria-hidden="true"></i>
        @else
            Fix metrics
        @endif
    </a>
@endif
@php
    $offerAfterReview = $actor?->canActivateSites() && $activateBlock === null && ! $offerCountry;
    $showGoLiveMenu = $offerPublishNow || $offerAfterReview;
@endphp
@if($showGoLiveMenu)
    <div class="dropdown">
        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle{{ $iconClass }}" data-bs-toggle="dropdown" aria-expanded="false" title="More" aria-label="More">
            @if($iconOnly)
                <i class="fa fa-ellipsis-v" aria-hidden="true"></i>
            @else
                More
            @endif
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            @if($offerPublishNow)
                <li>
                    <button type="button"
                            class="dropdown-item js-staff-publish-now"
                            data-id="{{ $site->id }}"
                            data-name="{{ $site->site_name }}"
                            data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}"
                            data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}"
                            title="{{ $publishNowTitle }}">Active now</button>
                </li>
            @endif
            @if($offerAfterReview)
                <li>
                    <button type="button"
                            class="dropdown-item js-mkt-activate"
                            data-id="{{ $site->id }}"
                            data-name="{{ $site->site_name }}"
                            data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}"
                            data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}"
                            title="{{ $activateAfterTitle }}">After review</button>
                </li>
            @endif
        </ul>
    </div>
@elseif($actor?->canActivateSites() && $activateBlock !== null && ! $offerFixMetrics && ! $offerCountry)
    <button type="button"
            class="btn btn-sm btn-outline-secondary{{ $iconClass }}"
            disabled
            title="{{ $activateBlock }}"
            aria-label="After review">
        @if($iconOnly)
            <i class="fa fa-play" aria-hidden="true"></i>
        @else
            After review
        @endif
    </button>
@endif
