@php
    $mode = $mode ?? 'publisher';
    $filters = $staffSiteFilters ?? [];
    $tagOptions = $listingTagOptions ?? [];
    $countries = $marketplaceCountries ?? collect();
    $languages = $marketplaceLanguages ?? collect();
    $niches = $nicheOptions ?? [];
    $getForm = in_array($mode, ['flat', 'all', 'publishers'], true);
@endphp
@if($getForm)
<form method="GET" action="{{ staff_route('sites.index') }}" class="d-flex flex-wrap align-items-end gap-2 px-3 py-2 border-bottom bg-white" id="staff{{ ucfirst($mode) }}Filters">
@else
<div class="d-flex flex-wrap align-items-end gap-2 mb-2" id="staffPublisherFilters" data-staff-publisher-filters="1">
@endif
    @if($mode === 'flat')
        @if(!empty($needsReviewFilterActive))
            <input type="hidden" name="needs_review" value="1">
        @endif
        @if(!empty($waitingOnPublisherFilterActive))
            <input type="hidden" name="waiting_on_publisher" value="1">
        @endif
        <input type="hidden" name="flat" value="1">
        @if(($publisherSearch ?? '') !== '')
            <input type="hidden" name="q" value="{{ $publisherSearch }}">
        @endif
        @if(($waitingStage ?? '') !== '')
            <input type="hidden" name="waiting_stage" value="{{ $waitingStage }}">
        @endif
    @elseif($mode === 'all')
        <input type="hidden" name="all" value="1">
        @if(($publisherSearch ?? '') !== '')
            <input type="hidden" name="q" value="{{ $publisherSearch }}">
        @endif
    @elseif($mode === 'publishers')
        @if(!empty($needsReviewFilterActive))
            <input type="hidden" name="needs_review" value="1">
        @endif
        @if(!empty($waitingOnPublisherFilterActive))
            <input type="hidden" name="waiting_on_publisher" value="1">
        @endif
        @if(($publisherSearch ?? '') !== '')
            <input type="hidden" name="q" value="{{ $publisherSearch }}">
        @endif
        @if(($waitingStage ?? '') !== '')
            <input type="hidden" name="waiting_stage" value="{{ $waitingStage }}">
        @endif
    @endif
    <label class="small mb-0">
        Tag
        <select class="form-select form-select-sm" name="tag" data-staff-filter="tag">
            @foreach($tagOptions as $value => $label)
                <option value="{{ $value }}" @selected(($filters['tag'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Country
        <select class="form-select form-select-sm" name="country" data-staff-filter="country">
            <option value="">All</option>
            @foreach($countries as $country)
                <option value="{{ strtolower((string) $country->code) }}" @selected(strtolower((string) ($filters['country'] ?? '')) === strtolower((string) $country->code))>{{ $country->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Language
        <select class="form-select form-select-sm" name="language" data-staff-filter="language">
            <option value="">All</option>
            @foreach($languages as $language)
                <option value="{{ strtolower((string) $language->code) }}" @selected(strtolower((string) ($filters['language'] ?? '')) === strtolower((string) $language->code))>{{ $language->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Niche
        <select class="form-select form-select-sm" name="niche" data-staff-filter="niche">
            <option value="">All</option>
            @foreach($niches as $nicheName)
                <option value="{{ $nicheName }}" @selected(($filters['niche'] ?? '') === $nicheName)>{{ $nicheName }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Active
        <select class="form-select form-select-sm" name="listing_active" data-staff-filter="listing_active">
            <option value="" @selected(($filters['listing_active'] ?? '') === '')>Any</option>
            <option value="1" @selected(($filters['listing_active'] ?? '') === '1')>Active</option>
            <option value="0" @selected(($filters['listing_active'] ?? '') === '0')>Inactive</option>
        </select>
    </label>
    <label class="small mb-0">
        Verified
        <select class="form-select form-select-sm" name="listing_verified" data-staff-filter="listing_verified">
            <option value="" @selected(($filters['listing_verified'] ?? '') === '')>Any</option>
            <option value="1" @selected(($filters['listing_verified'] ?? '') === '1')>Verified</option>
            <option value="0" @selected(($filters['listing_verified'] ?? '') === '0')>Unverified</option>
        </select>
    </label>
    <label class="small mb-0">
        Sort
        <select class="form-select form-select-sm" name="sort" data-staff-filter="sort">
            <option value="" @selected(($filters['sort'] ?? '') === '')>{{ $mode === 'flat' ? 'Oldest waiting' : 'Newest' }}</option>
            <option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>Newest</option>
            <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest</option>
            <option value="price" @selected(($filters['sort'] ?? '') === 'price')>Price</option>
            <option value="traffic" @selected(($filters['sort'] ?? '') === 'traffic')>Traffic</option>
            <option value="da" @selected(($filters['sort'] ?? '') === 'da')>DA</option>
        </select>
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="below_quality" value="1" data-staff-filter="below_quality" @checked(!empty($filters['below_quality']))>
        Below quality bar
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="missing_market" value="1" data-staff-filter="missing_market" @checked(!empty($filters['missing_market']))>
        Missing market
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="placeholder" value="1" data-staff-filter="placeholder" @checked(!empty($filters['placeholder']))>
        Placeholder
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="missing_cover" value="1" data-staff-filter="missing_cover" @checked(!empty($filters['missing_cover']))>
        Missing cover
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="bulk_request" value="1" data-staff-filter="bulk_request" @checked(!empty($filters['bulk_request']))>
        Bulk request
    </label>
    @if($mode !== 'flat')
        <label class="form-check small mb-1">
            <input class="form-check-input" type="checkbox" name="archived" value="1" data-staff-filter="archived" @checked(!empty($filters['archived']))>
            Show archived
        </label>
    @endif
    @if($getForm)
        <button type="submit" class="btn btn-sm btn-outline-dark">Apply</button>
    @endif
@if($getForm)
</form>
@else
</div>
@endif
