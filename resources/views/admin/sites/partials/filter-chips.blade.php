@php
    $chipLabels = [];
    if ($publisherSearch !== '') {
        $chipLabels['q'] = 'Search: '.$publisherSearch;
    }
    $chipMap = [
        'tag' => 'Tag',
        'country' => 'Country',
        'language' => 'Language',
        'niche' => 'Niche',
        'listing_active' => 'Active',
        'listing_verified' => 'Verified',
        'sort' => 'Sort',
        'below_quality' => 'Below quality bar',
        'missing_market' => 'Missing market',
        'placeholder' => 'Placeholder',
        'missing_cover' => 'Missing cover',
        'bulk_request' => 'Bulk request',
        'scan_failed' => 'Scan failed',
        'copy_strike' => 'Copy-strike hide',
        'has_orders' => 'Has orders',
        'featured' => 'Featured',
        'bulk_discount' => 'Bulk discount',
        'csv_metrics' => 'CSV metrics',
        'archived' => 'Archived',
        'price_min' => 'Price from',
        'price_max' => 'Price to',
        'traffic_min' => 'Traffic from',
        'da_min' => 'DA from',
        'metrics_age' => 'Metrics age',
    ];
    foreach ($chipMap as $key => $label) {
        if (! array_key_exists($key, $listQuery)) {
            continue;
        }
        $value = $listQuery[$key];
        if (in_array($key, ['below_quality', 'missing_market', 'placeholder', 'missing_cover', 'bulk_request', 'scan_failed', 'copy_strike', 'has_orders', 'featured', 'bulk_discount', 'csv_metrics', 'archived'], true)) {
            $chipLabels[$key] = $label;
            continue;
        }
        if ($key === 'listing_active') {
            $chipLabels[$key] = ((string) $value) === '1' ? 'Active' : 'Inactive';
            continue;
        }
        if ($key === 'listing_verified') {
            $chipLabels[$key] = ((string) $value) === '1' ? 'Verified' : 'Unverified';
            continue;
        }
        if ($key === 'metrics_age') {
            $chipLabels[$key] = match ((string) $value) {
                '30' => 'Metrics older than 30 days',
                '90' => 'Metrics older than 90 days',
                'never' => 'Metrics never fetched',
                default => $label,
            };
            continue;
        }
        $chipLabels[$key] = $label.': '.$value;
    }
    $modeQuery = array_filter([
        'needs_review' => ! empty($needsReviewFilterActive) ? 1 : null,
        'waiting_on_publisher' => ! empty($waitingOnPublisherFilterActive) ? 1 : null,
        'waiting_stage' => ($waitingStage ?? '') !== '' ? $waitingStage : null,
        'flat' => ! empty($flatQueue) ? 1 : null,
        'all' => ! empty($allSitesMode) ? 1 : null,
    ], static fn ($value) => $value !== null && $value !== '');
@endphp
@if($chipLabels !== [])
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-staff-filter-chips="1">
        @foreach($chipLabels as $key => $label)
            @php
                $without = $listQuery;
                unset($without[$key]);
                $chipUrl = staff_route('sites.index', array_filter($modeQuery + $without, static fn ($value) => $value !== null && $value !== ''));
            @endphp
            <a href="{{ $chipUrl }}" class="badge rounded-pill text-bg-light border text-decoration-none">{{ $label }} ×</a>
        @endforeach
        <a href="{{ staff_route('sites.index', $modeQuery) }}" class="small">Clear filters</a>
    </div>
@endif
