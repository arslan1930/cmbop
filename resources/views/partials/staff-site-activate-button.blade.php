{{--
    Queue / dashboard next action.
    Below the quality bar: Fix metrics is the only front button. Admins can still
    activate from More. A missing country is Set country. In-bar rows keep Activate.
    A listing that can go live but still lacks a cover or tags uses a quieter button.

    @param \App\Models\Site $site
    @param bool $iconOnly
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
    $iconOnly = ! empty($iconOnly);
    $iconClass = $iconOnly ? ' staff-action-icon-btn' : '';
@endphp
@if($offerFixMetrics)
    <a class="btn btn-sm btn-outline-warning{{ $iconClass }}" href="{{ staff_route('sites.edit', $site->id) }}#da" title="{{ $fixTitle }}" @if($iconOnly) aria-label="Fix metrics" @endif>
        @if($iconOnly)
            <i class="fa fa-chart-line" aria-hidden="true"></i>
        @else
            Fix metrics
        @endif
    </a>
@endif
@if($offerCountry)
    <a class="btn btn-sm btn-outline-danger{{ $iconClass }}" href="{{ staff_route('sites.edit', $site->id) }}#country" title="{{ $activateBlock ?: 'Set a marketplace country before activating this site.' }}" @if($iconOnly) aria-label="Set country" @endif>
        @if($iconOnly)
            <i class="fa fa-globe" aria-hidden="true"></i>
        @else
            Set country
        @endif
    </a>
@endif
@if($actor?->canActivateSites())
    @if($activateBlock === null && $offerFixMetrics)
        <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle{{ $iconClass }}" data-bs-toggle="dropdown" aria-expanded="false" @if($iconOnly) title="More" aria-label="More" @endif>
                @if($iconOnly)
                    <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
                @else
                    More
                @endif
            </button>
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
                class="btn btn-sm {{ $thinListing ? 'btn-outline-success' : 'btn-success' }} js-mkt-activate{{ $iconClass }}"
                data-id="{{ $site->id }}"
                data-name="{{ $site->site_name }}"
                data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}"
                data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}"
                title="{{ $thinListing ? 'Can go live. Cover or tags are still missing.' : 'Activate' }}"
                @if($iconOnly) aria-label="Activate" @endif>
            @if($iconOnly)
                <i class="fa fa-play" aria-hidden="true"></i>
            @else
                Activate
            @endif
        </button>
    @elseif($activateBlock !== null && ! $offerFixMetrics && ! $offerCountry)
        <button type="button"
                class="btn btn-sm btn-outline-secondary{{ $iconClass }}"
                disabled
                title="{{ $activateBlock }}"
                @if($iconOnly) aria-label="Activate" @endif>
            @if($iconOnly)
                <i class="fa fa-play" aria-hidden="true"></i>
            @else
                Activate
            @endif
        </button>
    @endif
@endif
