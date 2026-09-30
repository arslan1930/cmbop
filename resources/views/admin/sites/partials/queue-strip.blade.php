@php
    $liveUnverifiedUrl = staff_route('sites.index', ['all' => 1, 'listing_active' => 1, 'listing_verified' => 0]);
    $strip = [
        [
            'key' => 'review',
            'label' => 'Needs review',
            'count' => (int) ($openReviewCount ?? 0),
            'url' => staff_route('sites.index', ['needs_review' => 1, 'flat' => 1]),
            'hint' => 'Not live yet. The publisher has submitted the listing.',
            'active' => ! empty($needsReviewFilterActive),
        ],
        [
            'key' => 'waiting',
            'label' => 'Waiting on publisher',
            'count' => (int) ($waitingOnPublisherCount ?? 0),
            'url' => staff_route('sites.index', ['waiting_on_publisher' => 1, 'flat' => 1]),
            'hint' => 'Still with the publisher (details or accept). Not staff work yet.',
            'active' => ! empty($waitingOnPublisherFilterActive),
        ],
        [
            'key' => 'live',
            'label' => 'Live unverified',
            'count' => (int) ($liveUnverifiedCount ?? 0),
            'url' => $liveUnverifiedUrl,
            'hint' => 'Already in the catalog, not verified.',
            'active' => ! empty($allSitesMode)
                && ($staffSiteFilters['listing_active'] ?? '') === '1'
                && ($staffSiteFilters['listing_verified'] ?? '') === '0'
                && empty($staffSiteFilters['below_quality'])
                && empty($staffSiteFilters['missing_market'])
                && empty($staffSiteFilters['scan_failed']),
        ],
        [
            'key' => 'quality',
            'label' => 'Below quality',
            'count' => (int) ($belowQualityListCount ?? 0),
            'url' => staff_route('sites.index', ['all' => 1, 'below_quality' => 1]),
            'hint' => 'DA, DR, or traffic is under the catalog bar.',
            'active' => ! empty($allSitesMode) && ! empty($staffSiteFilters['below_quality']),
        ],
        [
            'key' => 'market',
            'label' => 'Missing market',
            'count' => (int) ($missingMarketListCount ?? 0),
            'url' => staff_route('sites.index', ['all' => 1, 'listing_active' => 1, 'missing_market' => 1]),
            'hint' => 'Active sites missing market country.',
            'active' => ! empty($allSitesMode) && ! empty($staffSiteFilters['missing_market']),
        ],
        [
            'key' => 'scan',
            'label' => 'Scan failed',
            'count' => (int) ($scanFailedListCount ?? 0),
            'url' => staff_route('sites.index', ['all' => 1, 'scan_failed' => 1]),
            'hint' => 'The last enrichment scan failed.',
            'active' => ! empty($allSitesMode) && ! empty($staffSiteFilters['scan_failed']),
        ],
    ];
    $activeStrip = collect($strip)->firstWhere('active', true);
@endphp
<div class="staff-sites-strip mb-3" aria-label="Site queues">
    @foreach($strip as $cell)
        <a href="{{ $cell['url'] }}"
           class="staff-sites-strip__cell {{ $cell['active'] ? 'is-active' : '' }}"
           @if($cell['active']) aria-current="page" @endif>
            <span class="staff-sites-strip__count">{{ number_format($cell['count']) }}</span>
            <span class="staff-sites-strip__label">{{ $cell['label'] }}</span>
            @if($cell['key'] === 'market')
                <span class="visually-hidden">active sites missing market country</span>
            @endif
        </a>
    @endforeach
</div>
@if($activeStrip)
    <p class="small text-muted mb-3">{{ $activeStrip['hint'] }}</p>
@endif
