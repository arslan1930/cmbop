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
<form method="GET" action="{{ staff_route('sites.index') }}" class="admin-deposits-filters staff-site-filters d-flex flex-wrap align-items-end gap-2 px-3 py-2 border-bottom bg-white" id="staff{{ ucfirst($mode) }}Filters">
@else
<div class="admin-deposits-filters staff-site-filters d-flex flex-wrap align-items-end gap-2 mb-2" id="staffPublisherFilters" data-staff-publisher-filters="1" data-admin-filter-live="1">
@endif
    @if($mode === 'flat')
        @if(!empty($needsReviewFilterActive))
            <input type="hidden" name="needs_review" value="1">
        @endif
        @if(!empty($waitingOnPublisherFilterActive))
            <input type="hidden" name="waiting_on_publisher" value="1">
        @endif
        <input type="hidden" name="flat" value="1">
        @if(($waitingStage ?? '') !== '')
            <input type="hidden" name="waiting_stage" value="{{ $waitingStage }}">
        @endif
    @elseif($mode === 'all')
        <input type="hidden" name="all" value="1">
    @elseif($mode === 'publishers')
        @if(!empty($needsReviewFilterActive))
            <input type="hidden" name="needs_review" value="1">
        @endif
        @if(!empty($waitingOnPublisherFilterActive))
            <input type="hidden" name="waiting_on_publisher" value="1">
        @endif
        @if(($waitingStage ?? '') !== '')
            <input type="hidden" name="waiting_stage" value="{{ $waitingStage }}">
        @endif
    @endif
    @if($getForm)
        <label class="small mb-0">
            Search
            <input class="form-control" type="search" name="q" value="{{ $publisherSearch ?? '' }}" placeholder="Publishers or sites" aria-label="Search publishers or sites">
        </label>
    @endif
    <label class="small mb-0">
        Tag
        <select class="form-select" name="tag" data-staff-filter="tag">
            @foreach($tagOptions as $value => $label)
                <option value="{{ $value }}" @selected(($filters['tag'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Country
        <select class="form-select" name="country" data-staff-filter="country">
            <option value="">All</option>
            @foreach($countries as $country)
                <option value="{{ strtolower((string) $country->code) }}" @selected(strtolower((string) ($filters['country'] ?? '')) === strtolower((string) $country->code))>{{ $country->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Language
        <select class="form-select" name="language" data-staff-filter="language">
            <option value="">All</option>
            @foreach($languages as $language)
                <option value="{{ strtolower((string) $language->code) }}" @selected(strtolower((string) ($filters['language'] ?? '')) === strtolower((string) $language->code))>{{ $language->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Niche
        <select class="form-select" name="niche" data-staff-filter="niche">
            <option value="">All</option>
            @foreach($niches as $nicheName)
                <option value="{{ $nicheName }}" @selected(($filters['niche'] ?? '') === $nicheName)>{{ $nicheName }}</option>
            @endforeach
        </select>
    </label>
    <label class="small mb-0">
        Active
        <select class="form-select" name="listing_active" data-staff-filter="listing_active">
            <option value="" @selected(($filters['listing_active'] ?? '') === '')>Any</option>
            <option value="1" @selected(($filters['listing_active'] ?? '') === '1')>Active</option>
            <option value="0" @selected(($filters['listing_active'] ?? '') === '0')>Inactive</option>
        </select>
    </label>
    <label class="small mb-0">
        Verified
        <select class="form-select" name="listing_verified" data-staff-filter="listing_verified">
            <option value="" @selected(($filters['listing_verified'] ?? '') === '')>Any</option>
            <option value="1" @selected(($filters['listing_verified'] ?? '') === '1')>Verified</option>
            <option value="0" @selected(($filters['listing_verified'] ?? '') === '0')>Unverified</option>
        </select>
    </label>
    <label class="small mb-0">
        Metrics
        <select class="form-select" name="metrics_age" data-staff-filter="metrics_age">
            <option value="" @selected(($filters['metrics_age'] ?? '') === '')>Any age</option>
            <option value="30" @selected(($filters['metrics_age'] ?? '') === '30')>Older than 30 days</option>
            <option value="90" @selected(($filters['metrics_age'] ?? '') === '90')>Older than 90 days</option>
            <option value="never" @selected(($filters['metrics_age'] ?? '') === 'never')>Never fetched</option>
        </select>
    </label>
    <label class="small mb-0">
        Price from
        <input class="form-control" type="number" min="0" step="1" name="price_min" data-staff-filter="price_min" value="{{ $filters['price_min'] ?? '' }}" aria-label="Minimum price">
    </label>
    <label class="small mb-0">
        Price to
        <input class="form-control" type="number" min="0" step="1" name="price_max" data-staff-filter="price_max" value="{{ $filters['price_max'] ?? '' }}" aria-label="Maximum price">
    </label>
    <label class="small mb-0">
        Traffic from
        <input class="form-control" type="number" min="0" step="1" name="traffic_min" data-staff-filter="traffic_min" value="{{ $filters['traffic_min'] ?? '' }}" aria-label="Minimum traffic">
    </label>
    <label class="small mb-0">
        DA from
        <input class="form-control" type="number" min="0" max="100" step="1" name="da_min" data-staff-filter="da_min" value="{{ $filters['da_min'] ?? '' }}" aria-label="Minimum DA">
    </label>
    <label class="small mb-0">
        Sort
        <select class="form-select" name="sort" data-staff-filter="sort">
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
        <input class="form-check-input" type="checkbox" name="ready_to_activate" value="1" data-staff-filter="ready_to_activate" @checked(!empty($filters['ready_to_activate']))>
        Ready to activate
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
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="scan_failed" value="1" data-staff-filter="scan_failed" @checked(!empty($filters['scan_failed']))>
        Scan failed
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="copy_strike" value="1" data-staff-filter="copy_strike" @checked(!empty($filters['copy_strike']))>
        Copy-strike hide
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="has_orders" value="1" data-staff-filter="has_orders" @checked(!empty($filters['has_orders']))>
        Has orders
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="featured" value="1" data-staff-filter="featured" @checked(!empty($filters['featured']))>
        Featured
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="bulk_discount" value="1" data-staff-filter="bulk_discount" @checked(!empty($filters['bulk_discount']))>
        Bulk discount
    </label>
    <label class="form-check small mb-1">
        <input class="form-check-input" type="checkbox" name="csv_metrics" value="1" data-staff-filter="csv_metrics" @checked(!empty($filters['csv_metrics']))>
        CSV metrics
    </label>
    @if($mode !== 'flat')
        <label class="form-check small mb-1">
            <input class="form-check-input" type="checkbox" name="archived" value="1" data-staff-filter="archived" @checked(!empty($filters['archived']))>
            Show archived
        </label>
    @endif
    @if($getForm)
        <button type="submit" class="btn btn-primary">Apply</button>
    @endif
@if($getForm)
</form>
@else
</div>
@endif
