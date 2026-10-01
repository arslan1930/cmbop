@php
    $previewChain = [];
    $zoomChain = [];
    try {
        $previewChain = $site->listingPreviewUrlChain();
        $zoomChain = $site->zoomPreviewUrlChain();
    } catch (\Throwable $e) {
        $previewChain = [];
        $zoomChain = [];
    }
    $previewThumb = $previewChain[0] ?? null;
    $previewZoom = $zoomChain[0] ?? $previewThumb;
    $catalogDomain = trim((string) ($site->domain ?: ''));
    $catalogUrl = $catalogDomain !== '' ? route('advertiser.catalog', ['search' => $catalogDomain]) : null;
    $reasonText = '';
    $reasonWhen = '';
    $reasonWho = '';
    try {
        $reasonText = trim((string) ($site->status_reason ?? ''));
        if ($site->status_reason_at instanceof \DateTimeInterface) {
            $reasonWhen = \Illuminate\Support\Carbon::parse($site->status_reason_at)->timezone(config('app.timezone'))->format('M j, Y');
        }
        $reasonWho = trim((string) ($site->statusReasonAuthor->name ?? ''));
    } catch (\Throwable $e) {
        $reasonText = '';
    }
@endphp
<div class="d-flex gap-2 align-items-start">
    @if($previewThumb)
        <span class="site-row-preview" role="img" aria-label="{{ $site->site_name ?: 'Site' }} preview" @if($previewZoom) data-zoom-src="{{ $previewZoom }}" tabindex="0" @endif>
            <img src="{{ $previewThumb }}"
                 alt="{{ $site->site_name ?: 'Site' }} preview"
                 loading="lazy"
                 decoding="async"
                 data-preview-chain="{{ json_encode(array_values(array_unique(array_filter(array_merge($previewChain, $zoomChain))))) }}"
                 data-preview-i="0"
                 onerror="sitePreviewImgOnError(this)">
        </span>
    @else
        <span class="site-row-preview is-empty" aria-label="No preview"><i class="fa fa-image" aria-hidden="true"></i></span>
    @endif
    <div class="min-w-0 queue-site-copy">
        <div class="fw-semibold queue-site-name" title="{{ $site->site_name ?: '' }}">{{ $site->site_name ?: '—' }}</div>
        <div class="small text-muted queue-site-url" title="{{ $site->site_url }}">{{ $site->site_url }}</div>
        <div class="d-flex flex-wrap gap-1 mt-1">
            @if($site->verified)
                <span class="badge rounded-pill bg-success">Verified</span>
            @else
                <span class="badge rounded-pill bg-secondary">Unverified</span>
            @endif
            @if($site->active)
                <span class="badge rounded-pill bg-primary">Active</span>
            @endif
            @php
                $showFeatured = false;
                $showBulk = false;
                $missingMarket = false;
                $belowQuality = false;
                $archived = false;
                $awaitingDetails = false;
                $publisherReviewing = false;
                $awaitingAccept = false;
                $fromBulkRequest = false;
                $missingCover = false;
                $missingTags = false;
                $qualityBadge = 'Below quality bar';
                try {
                    $showFeatured = $site->isFeatured();
                    $showBulk = $site->joinsBulkDiscount();
                    $missingMarket = ! $site->hasMarketplaceCountry();
                    $belowQuality = ! $site->hasGoodMetrics();
                    $archived = $site->isArchived();
                    $awaitingDetails = $site->awaitsPublisherDetails();
                    $publisherReviewing = $site->hasDetailsComplete();
                    $awaitingAccept = $site->isPendingPublisherAcceptance();
                    $fromBulkRequest = $site->wasAddedFromBulkRequest();
                    $missingCover = ! $site->hasCatalogCover();
                    $missingTags = $site->tagValue() === null;
                    if ($belowQuality) {
                        $qualityBadge = $site->qualityBarBadgeText();
                    }
                } catch (\Throwable $e) {
                    $showFeatured = false;
                    $showBulk = false;
                }
            @endphp
            @if($showFeatured)
                <span class="badge text-bg-primary">Featured</span>
            @endif
            @if($showBulk)
                <span class="badge text-bg-info">Bulk</span>
            @endif
            @if($missingMarket)
                <span class="badge text-bg-danger">Missing market</span>
            @endif
            @if($belowQuality)
                <span class="badge text-bg-warning text-dark" title="{{ $qualityBadge }}">{{ !empty($compactSiteCell) ? 'Below quality bar' : $qualityBadge }}</span>
            @endif
            @if($missingCover)
                <a href="{{ staff_route('sites.edit', $site->id) }}#site_image" class="badge text-bg-warning text-dark text-decoration-none" title="Add a cover. This does not block going live.">No cover</a>
            @endif
            @if($missingTags && empty($compactSiteCell))
                <a href="{{ staff_route('sites.edit', $site->id) }}#site_tag" class="badge text-bg-warning text-dark text-decoration-none" title="Choose a tag. This does not block going live.">No tags</a>
            @endif
            @if($archived)
                <span class="badge text-bg-secondary">Archived</span>
            @endif
            @if($awaitingDetails)
                <span class="badge text-bg-secondary">Awaiting publisher</span>
            @endif
            @if($publisherReviewing)
                <span class="badge text-bg-secondary">Publisher reviewing</span>
            @endif
            @if($awaitingAccept)
                <span class="badge text-bg-info">Awaiting accept</span>
            @endif
            @if($fromBulkRequest)
                <span class="badge text-bg-light border">Bulk request</span>
            @endif
            @php
                $csvSpotCheck = false;
                $scanFailed = false;
                try {
                    $csvSpotCheck = $site->isFromAgencyCsvImport() && (bool) $site->metrics_manual;
                    $scanFailed = (string) ($site->enrichment_status ?? '') === 'failed';
                } catch (\Throwable $e) {
                    $csvSpotCheck = false;
                    $scanFailed = false;
                }
            @endphp
            @if($csvSpotCheck)
                <span class="badge text-bg-light border" title="Publisher-supplied DA/DR/traffic from agency CSV — spot-check before activate">CSV metrics — spot-check</span>
            @endif
            @if($scanFailed)
                <span class="badge text-bg-danger">Scan failed</span>
            @endif
        </div>
        @if($reasonText !== '')
            <div class="small text-muted mt-1">
                Last decision: {{ $reasonText }}
                @if($reasonWho !== '')
                    · {{ $reasonWho }}
                @endif
                @if($reasonWhen !== '')
                    · {{ $reasonWhen }}
                @endif
            </div>
        @endif
        @if($catalogUrl)
            <a href="{{ $catalogUrl }}" class="small" target="_blank" rel="noopener">View in catalog</a>
        @endif
    </div>
</div>
