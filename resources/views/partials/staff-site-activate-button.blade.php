{{--
    Queue / dashboard Activate. Admins skip the quality bar; marketing does not.
    Blocked listings show a disabled button with the same reason as the editor.

    @param \App\Models\Site $site
--}}
@php
    $actor = auth()->user();
    $staffIsMarketing = (bool) ($actor?->isMarketing() && ! $actor?->isAdmin());
    $activateBlock = $site->staffGoLiveBlockReason($staffIsMarketing);
    $belowBar = ! $site->hasGoodMetrics();
    $offerFixMetrics = ! (bool) $site->active && ! $site->isArchived() && $belowBar;
@endphp
@if($offerFixMetrics)
    <a class="btn btn-sm btn-outline-warning" href="{{ staff_route('sites.edit', $site->id) }}#da">Fix metrics</a>
@endif
@if($actor?->canActivateSites())
    @if($activateBlock === null)
        <button type="button"
                class="btn btn-sm {{ $belowBar ? 'btn-outline-primary' : 'btn-success' }} js-mkt-activate"
                data-id="{{ $site->id }}"
                data-name="{{ $site->site_name }}"
                data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}"
                data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}">Activate</button>
    @else
        <button type="button"
                class="btn btn-sm {{ $belowBar ? 'btn-outline-secondary' : 'btn-success' }}"
                disabled
                title="{{ $activateBlock }}">Activate</button>
    @endif
@endif
