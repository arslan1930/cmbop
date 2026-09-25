{{-- Shared catalog results fragment (desktop table + mobile cards + pagination).
     Included by advertiser.catalog and returned by GET advertiser.catalog.results. --}}
@php
    use Illuminate\Support\Str;
    $resultTotal = 0;
    $resultFirstItem = null;
    $resultLastItem = null;
    try {
        $resultTotal = method_exists($sites, 'total') ? (int) $sites->total() : 0;
        $resultFirstItem = method_exists($sites, 'firstItem') ? $sites->firstItem() : null;
        $resultLastItem = method_exists($sites, 'lastItem') ? $sites->lastItem() : null;
        $resultCurrentPage = method_exists($sites, 'currentPage') ? (int) $sites->currentPage() : 1;
        $resultLastPage = method_exists($sites, 'lastPage') ? (int) $sites->lastPage() : 1;
    } catch (\Throwable $e) {
        report($e);
        $resultTotal = 0;
        $resultFirstItem = null;
        $resultLastItem = null;
        $resultCurrentPage = 1;
        $resultLastPage = 1;
    }
    $hasActiveFilters = $hasActiveFilters ?? (
        request()->filled('site')
        || request()->filled('search')
        || request()->filled('category')
        || request()->filled('country')
        || request()->filled('language')
        || request()->filled('price_min')
        || request()->filled('price_max')
        || request()->filled('tag')
        || request()->input('sponsored') == '1'
        || request()->input('favorites_filter') == '1'
        || request()->input('blacklist_filter') == '1'
        || request()->filled('da_min')
        || request()->filled('da_max')
        || request()->filled('dr_min')
        || request()->filled('dr_max')
        || request()->filled('traffic_min')
        || request()->filled('traffic_max')
        || request()->input('new_badge') == '1'
        || request()->input('quality') == '1'
        || request()->filled('rating_min')
        || request()->input('has_completions') == '1'
        || request()->input('bulk_deals') == '1'
        || request()->input('on_sale') == '1'
        || request()->input('featured') == '1'
        || \App\Services\Catalog\CatalogUrlQuery::perPage(request())
            !== \App\Services\Catalog\CatalogUrlQuery::DEFAULT_PER_PAGE
    );
    // Live results fragment may not inherit parent @php; compute recovery when empty.
    $catalogEmptyRecovery = $catalogEmptyRecovery ?? (
        ($resultTotal < 1 && $hasActiveFilters)
            ? app(\App\Services\Catalog\CatalogFilterStatus::class)->emptyRecovery(request())
            : null
    );
    $catalogResultsStatus = $catalogResultsStatus ?? app(\App\Services\Catalog\CatalogFilterStatus::class)->summarize(
        request(),
        $resultTotal,
        $resultFirstItem ?: null,
        $resultLastItem ?: null
    );
    $catalogEmptyHeadline = $catalogEmptyHeadline ?? (
        $resultTotal < 1
            ? (
                $hasActiveFilters
                    ? ($catalogResultsStatus['text'] ?? 'No sites match your filters')
                    : 'No publishers available yet'
            )
            : null
    );
    $inCatalogHideMode = false;
    try {
        $inCatalogHideMode = (bool) (auth()->user()?->inCatalogHideMode() ?? false);
    } catch (\Throwable $e) {
        report($e);
    }
    $currentUser = $currentUser ?? auth()->user();
    $favorites = $favorites ?? [];
    $blacklist = $blacklist ?? [];
    $inventoryFrom = $inventoryFrom ?? null;
    $cartSiteIds = [];
    try {
        $catalogCartLines = is_array($cart ?? null) ? $cart : session('cart', []);
        if (! is_array($catalogCartLines)) {
            $catalogCartLines = [];
        }
        foreach ($catalogCartLines as $line) {
            $cid = (int) (is_array($line) ? ($line['id'] ?? 0) : 0);
            if ($cid > 0) {
                $cartSiteIds[$cid] = $cid;
            }
        }
        $cartSiteIds = array_values($cartSiteIds);
    } catch (\Throwable $e) {
        report($e);
        $cartSiteIds = [];
    }
@endphp
            <div class="card border-0 shadow-sm catalog-results-card" id="catalogResults" aria-live="polite"
                 tabindex="-1"
                 data-effective-query="{{ e(json_encode(\App\Services\Catalog\CatalogUrlQuery::fromRequest(request()))) }}"
                 data-catalog-hide-mode="{{ $inCatalogHideMode ? '1' : '0' }}"
                 data-result-total="{{ (int) $resultTotal }}"
                 data-first-item="{{ (int) ($resultFirstItem ?: 0) }}"
                 data-last-item="{{ (int) ($resultLastItem ?: 0) }}"
                 data-current-page="{{ (int) ($resultCurrentPage ?? 1) }}"
                 data-last-page="{{ (int) ($resultLastPage ?? 1) }}"
                 data-inventory-from="{{ $inventoryFrom !== null ? e(number_format((float) $inventoryFrom, 2, '.', '')) : '' }}"
                 data-status-text="{{ $catalogResultsStatus['text'] }}"
                 data-status-announce="{{ $catalogResultsStatus['announce'] }}">
                <div class="catalog-results-busy" hidden aria-hidden="true">
                    <span class="catalog-results-busy__label visually-hidden">Updating results…</span>
                </div>
                <div class="card-body p-0">
                    
                    {{-- Desktop table only. Cards own every width below xl so the
                         Buy column is never trapped behind a nested scroller.
                         Vertical scroll is the page; thead stays sticky under
                         the topbar (see catalog.css). --}}
                    <div class="table-responsive catalog-table-scroll d-none d-xl-block">
    <table class="table table-borderless align-middle mb-0 data-table catalog-table">
        <caption class="visually-hidden">Publisher catalog results with metrics, pricing and buy actions</caption>
        <thead class="table-light">
            <tr>
                <th scope="col" class="text-start catalog-th catalog-th-site">
                    <span class="catalog-th-label">
                        Site
                        <x-glass-tip
                            title="Site"
                            body="{{ $inCatalogHideMode
                                ? 'Listing names and website addresses are temporarily hidden on your catalog. Use the eye to show or hide a row — browsing, metrics, and orders still work as normal.'
                                : 'Listing name and website address for each publisher site. Mass-copying addresses can temporarily hide names and URLs on your catalog.' }}"
                            label="About Site column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        Category
                        <x-glass-tip
                            title="Category"
                            body="Topic niches this site accepts for guest posts and placements."
                            label="About Category column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        @include('advertiser.partials.metric-source', ['type' => 'traffic'])
                        <span class="catalog-th-text">Traffic</span>
                        <x-glass-tip
                            title="Monthly Traffic"
                            body="Estimated monthly visits from analytics data. Higher traffic usually means more reach for your placement."
                            label="About Traffic column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        @include('advertiser.partials.metric-source', ['type' => 'dr'])
                        <span class="catalog-th-text">DR</span>
                        <x-glass-tip
                            title="Domain Rating (DR)"
                            body="Ahrefs Domain Rating (0–100): how strong the site’s backlink profile is compared to others on the web."
                            label="About Domain Rating"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        @include('advertiser.partials.metric-source', ['type' => 'da'])
                        <span class="catalog-th-text">DA</span>
                        <x-glass-tip
                            title="Domain Authority (DA)"
                            body="Moz Domain Authority (0–100): an overall site authority score used to compare ranking potential."
                            label="About Domain Authority"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        Country
                        <x-glass-tip
                            title="Country"
                            body="Primary country / audience market for this publisher website."
                            label="About Country column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th catalog-th-action">
                    <span class="catalog-th-label">
                        Buy
                        <x-glass-tip
                            title="Buy"
                            body="See the price, add a placement to your cart, save the site to favorites, or blacklist it so it stays out of your way."
                            label="About Buy column"
                            placement="bottom" />
                    </span>
                </th>
            </tr>
        </thead>
        <tbody>
            @forelse($sites as $site)
            @php
                $isBlacklisted = in_array($site->id, $blacklist);
                $isFavorited = in_array($site->id, $favorites);
                // Decode sensitive prices (only positive numeric add-ons are selectable)
                $sensitivePrices = $site->safeJsonArray('sensitive_prices');
                $sensitivePrices = collect($sensitivePrices)
                    ->filter(fn ($amount, $type) => is_string($type) && $type !== ''
                        && is_numeric($amount) && (float) $amount > 0)
                    ->map(fn ($amount) => round((float) $amount, 2))
                    ->all();

                $homepageOptions = $site->homepagePlacementOptions();
                $defaultHomepageDays = $site->longestFreeHomepageDays();
                $socialChannels = $site->enabledSocialChannels();
                $socialChannelLabels = [
                    'facebook' => 'Facebook',
                    'instagram' => 'Instagram',
                    'x' => 'X',
                ];
                // Sensitive stays in the pricing column; homepage/social live in
                // Site Details meta (chips say “in Details”).
                $hasSensitiveExtras = $sensitivePrices !== [];
                $hasPlacementExtras = $homepageOptions !== [] || $socialChannels !== [];
                $hasListingExtras = $hasSensitiveExtras || $hasPlacementExtras;
                $hasPricingExtras = $hasSensitiveExtras;
                $expandDescriptionHtml = $site->catalogDescriptionHtml();
                $hasExpandDescription = trim(strip_tags($expandDescriptionHtml)) !== '';

                // Own listings show the entered price and cannot be ordered.
                $viewPrices = $site->catalogPricesForViewer(auth()->user());
                $isOwnedByMe = ! empty($viewPrices['owned']);
                $catalogListPrice = (float) $viewPrices['list'];
                $catalogPublisherPrice = (float) $viewPrices['publisher'];
                $catalogSalePctNominal = $viewPrices['sale_percent_nominal'];
                $catalogSalePct = $catalogSalePctNominal; // nominal for data-* / JS
                $catalogSalePctDisplay = $viewPrices['sale_percent'];
                $catalogSalePrice = $viewPrices['sale'];
                $articlePay = $catalogSalePrice ?? $catalogListPrice;
                $showAdvertiserPay = ! $isOwnedByMe;
                // Advertisers always get a Details pay line so homepage-only
                // listings cannot show publisher +€ amounts with no article total.
                $hasPricingExtras = $hasSensitiveExtras || $showAdvertiserPay;
            @endphp
            @php
                // Dynamic "new" flag — listing created within the last 30 days
                $isNew = $site->isRecentlyCreated();
                // Everyday catalog shows full identity (no eye). Mask + eye only
                // while copy-strike hide mode is active (one control for name + URL).
                $showsIdentity = true;
                $canSeeUrl = true;
                $displayHost = '';
                $displayRootedUrl = '';
                $displayName = (string) ($site->site_name ?? '');
                try {
                    $showsIdentity = $urlVisibility->showsFullIdentity($currentUser, $site);
                    $canSeeUrl = $showsIdentity; // reveal state inside hide mode; always true outside
                    $displayHost = $urlVisibility->hostFor($currentUser, $site);
                    $displayRootedUrl = $urlVisibility->rootedUrlFor($currentUser, $site);
                    $displayName = $urlVisibility->nameFor($currentUser, $site);
                } catch (\Throwable $e) {
                    report($e);
                }
                $identityLabel = $showsIdentity
                    ? (string) $site->site_name
                    : 'this website';
                $eyeShowLabel = 'Show site name and URL';
                $eyeHideLabel = 'Hide site name and URL';
                // Closed-row thumbnail only while identity is shown — a homepage
                // shot would leak the host in hide mode. Details expand still
                // loads the large preview either way.
                $previewPaths = $site->homepagePreviewUrlChain();
                $previewUrl = $previewPaths[0] ?? null;
                $expandZoomPaths = $site->zoomPreviewUrlChain();
                if ($expandZoomPaths === [] && $previewPaths !== []) {
                    $expandZoomPaths = $previewPaths;
                }
                $expandZoomUrl = $expandZoomPaths[0] ?? $previewUrl;
                $tileFaviconUrl = $showsIdentity ? $site->catalogTileFaviconUrl() : null;
                $tileFaviconChain = $tileFaviconUrl ? [$tileFaviconUrl] : [];
            @endphp
            <tr class="site-row {{ $isBlacklisted ? 'blacklisted-row' : '' }}"
                data-id="{{ $site->id }}"
                data-name="{{ $displayName }}"
                data-publisher-id="{{ (int) $site->publisher_id }}"
                @if((int) ($site->getAttribute('owner_id') ?? 0) > 0) data-owner-id="{{ (int) $site->getAttribute('owner_id') }}" @endif
                @if($isOwnedByMe) data-own-listing="1" @endif>
                <td class="catalog-site-cell">
                    @include('advertiser.partials.catalog-new-ribbon', ['isNew' => $isNew])

                    <div class="catalog-site-stack catalog-site-stack--tiled">
                        <button type="button"
                                class="expand-arrow visually-hidden"
                                id="arrow-{{ $site->id }}"
                                aria-label="Show details for {{ $identityLabel }}"
                                aria-expanded="false"
                                aria-controls="site-details-{{ $site->id }}"></button>
                        @include('advertiser.partials.catalog-site-tile', [
                            'label' => $displayHost,
                            'size' => 'md',
                            'faviconUrl' => $tileFaviconUrl,
                            'faviconChain' => $tileFaviconChain,
                            'masked' => ! $showsIdentity,
                            'openDetailsId' => (string) $site->id,
                        ])

                        <div class="catalog-site-stack__body">
                        <!-- Name + Verified + Details stay on one nowrap row.
                             NEW is the corner ribbon. Listing tags and visit live
                             on the wrapping identity row with the rooted URL. -->
                        <div class="catalog-site-title-row">
                            <span class="text-dark catalog-site-name catalog-site-name--concat"
                                  data-site-name-label
                                  title="{{ $displayName }}">
                                {{ $displayName }}
                            </span>

                            {{-- Eye only in copy-strike hide mode. --}}
                            <span class="catalog-site-controls">
                                @if($inCatalogHideMode)
                                <span class="catalog-site-actions catalog-site-actions--eye">
                                    <button type="button"
                                            class="btn btn-sm btn-link text-secondary p-0 reveal-url catalog-url-eye {{ $showsIdentity ? 'd-none' : '' }}"
                                            data-site-id="{{ $site->id }}"
                                            id="url-reveal-{{ $site->id }}"
                                            title="{{ $eyeShowLabel }}"
                                            aria-label="{{ $eyeShowLabel }}">
                                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                    </button>

                                    {{-- Sticky hide: persists until they click the eye again.
                                         The disclosure audit row stays; only display flips. --}}
                                    <button type="button"
                                            class="btn btn-sm btn-link text-secondary p-0 hide-url catalog-url-eye {{ $showsIdentity ? '' : 'd-none' }}"
                                            data-site-id="{{ $site->id }}"
                                            id="url-hide-{{ $site->id }}"
                                            title="{{ $eyeHideLabel }}"
                                            aria-label="{{ $eyeHideLabel }}">
                                        <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                                    </button>
                                </span>
                                @endif

                                <span class="catalog-site-badges">
                                    @if($site->verified)
                                        <button type="button"
                                                class="site-chip site-chip--verified site-chip--status"
                                                data-glass-tip
                                                data-glass-tip-title="Verified Publisher"
                                                data-glass-tip-body="This publisher has successfully completed our verification process and meets our platform's quality standards."
                                                data-glass-tip-placement="top"
                                                aria-label="Verified publisher">
                                            <span class="catalog-verified-lottie" data-lottie="{{ asset('assets/vendor/lottie/verified.json') }}" aria-hidden="true"></span>
                                            <span class="visually-hidden">Verified</span>
                                        </button>
                                    @endif
                                </span>

                            </span>
                        </div>

                        <div class="catalog-site-identity">
                            <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="site-open-link catalog-site-rooted-url catalog-site-url"
                               id="url-host-{{ $site->id }}"
                               data-url-open="{{ $site->id }}"
                               data-site-host
                               aria-label="Open {{ $displayRootedUrl }} in a new tab"
                               @if($showsIdentity) data-host="{{ $displayHost }}" @endif
                               @if($inCatalogHideMode && ! $showsIdentity)
                                   data-glass-tip
                                   data-glass-tip-title="Name and URL hidden"
                                   data-glass-tip-body="Site name and URL are hidden for 24 hours after repeated domain copying. Open the eye to reveal both for this listing — metrics and price stay visible."
                                   data-glass-tip-placement="top"
                               @endif>{{ $displayRootedUrl }}</a>
                            <div class="catalog-site-tag-slot">
                                @include('advertiser.partials.catalog-tag-chip', ['site' => $site])
                                @include('advertiser.partials.catalog-placement-chips', [
                                    'homepageOptions' => $homepageOptions,
                                    'defaultHomepageDays' => $defaultHomepageDays,
                                    'socialChannels' => $socialChannels,
                                    'socialChannelLabels' => $socialChannelLabels,
                                    'openDetailsId' => (string) $site->id,
                                ])
                                @include('advertiser.partials.catalog-site-trust', ['site' => $site, 'variant' => 'chip'])
                            </div>
                        </div>

                        @php
                            $dealCustomPct = null;
                            $dealBulkPct = null;
                            $showSaleChip = false;
                            $showBulkChip = false;
                            $dealSaleChipPct = null;
                            $dealBulkChipPct = null;
                            $showPlacementChips = $homepageOptions !== [] || $socialChannels !== [];
                            $inCart = in_array((int) $site->id, $cartSiteIds, true);
                            try {
                                // Better-of on pack qty: hide bulk chip when custom is ≥ bulk
                                // (bulk never wins). If bulk is stronger, keep both — custom
                                // still applies on qty 1–2 where bulk does not.
                                // Chip % labels use effective savings after the payout floor.
                                $dealCustomPct = $site->activeCustomDiscountPercent();
                                $dealBulkPct = $site->joinsBulkDiscount()
                                    ? (float) $site->bulk_discount_percent
                                    : null;
                                $showSaleChip = $dealCustomPct !== null && $catalogSalePctDisplay;
                                $showBulkChip = $dealBulkPct !== null
                                    && ($dealCustomPct === null || $dealBulkPct > (float) $dealCustomPct);
                                $dealSaleChipPct = $catalogSalePctDisplay;
                                $dealBulkChipPct = $dealBulkPct;
                                if ($showBulkChip) {
                                    // $site->price is already advertiser-facing; reprice from
                                    // the publisher base so the chip % is not fee-on-fee.
                                    $packSite = clone $site;
                                    $packSite->price = $catalogPublisherPrice;
                                    $packPricing = app(\App\Services\CartPricingService::class)
                                        ->priceForAdvertiser($packSite, null, (int) config('site_promotions.bulk.min_qty', 3));
                                    $dealBulkChipPct = (float) ($packPricing['discount_percent'] ?? $dealBulkPct);
                                    if ($dealBulkChipPct <= 0) {
                                        $showBulkChip = false;
                                    }
                                }
                            } catch (\Throwable $e) {
                                report($e);
                            }
                        @endphp
                        @if($showSaleChip)
                        <div class="catalog-site-deals">
                            @if($showSaleChip)
                                <span class="site-chip site-chip--sale site-chip--status">
                                    <i class="fa-solid fa-percent" aria-hidden="true"></i>
                                    <span>−{{ rtrim(rtrim(number_format((float) $dealSaleChipPct, 1), '0'), '.') }}%</span>
                                </span>
                            @endif

                        </div>
                        @endif

                        @if($isBlacklisted)
                        <div class="site-status-row">
                            <button type="button"
                                  class="site-chip site-chip--blacklist"
                                  data-glass-tip
                                  data-glass-tip-title="Blacklisted"
                                  data-glass-tip-body="You blacklisted this site — it stays dimmed in your catalog until you remove it."
                                  data-glass-tip-placement="top"
                                  aria-label="Blacklisted site details">
                                <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                <span>Blacklisted</span>
                            </button>
                        </div>
                        @endif

                        @include('advertiser.partials.catalog-meta-chips', [
                            'site' => $site,
                        ])
                        </div>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell catalog-category-cell">
                   @php
    $categoryArray = $site->nicheBadgeLabels();

    // Two chips max by default — three long niches overflow the fixed 12–16%
    // Category column and paint over Traffic/DR/DA when overflow is visible.
    $showLimit = 2;
    $totalCategories = count($categoryArray);
@endphp

@if(count($categoryArray))
    <div class="categories-wrapper">

        <div class="categories-column">

            @foreach($categoryArray as $index => $cat)

                <span class="category-badge {{ $index >= $showLimit ? 'extra-category d-none' : '' }}">
                    {{ $cat }}
                </span>

            @endforeach

        </div>

        @if($totalCategories > $showLimit)
            <button type="button"
                    class="toggle-cats-btn"
                    data-more-count="{{ $totalCategories - $showLimit }}"
                    aria-expanded="false">
                +{{ $totalCategories - $showLimit }} more
            </button>
        @endif

    </div>
@endif
                </td>

                <td class="text-center catalog-stat-cell">
                    @include('advertiser.partials.catalog-metric', [
                        'type' => 'traffic',
                        'value' => $site->traffic,
                        'inline' => false,
                    ])
                </td>

                <td class="text-center catalog-stat-cell">
                    @include('advertiser.partials.catalog-metric', [
                        'type' => 'dr',
                        'value' => $site->dr,
                        'inline' => false,
                    ])
                </td>

                <td class="text-center catalog-stat-cell">
                    @include('advertiser.partials.catalog-metric', [
                        'type' => 'da',
                        'value' => $site->da,
                        'inline' => false,
                    ])
                </td>

                <td class="text-center catalog-stat-cell">
                    @php
                        $countryCode = null;
                        $countryName = '';
                        try {
                            $countryCode = $site->primaryCountryCode();
                            $countryName = fullCountry($countryCode);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    @endphp
                    <div class="catalog-country">
                        <span class="catalog-country__flag" aria-hidden="true">{!! getCountryFlag($countryCode) !!}</span>
                        <span class="catalog-country__name text-muted small"@if($countryName !== '') title="{{ $countryName }}"@endif>{{ $countryName }}</span>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell catalog-td-action">
                    <div class="catalog-row-actions">
                        @include('advertiser.partials.catalog-price', [
                            'listPrice' => $catalogListPrice,
                            'salePrice' => $catalogSalePrice,
                            'salePercent' => $catalogSalePctDisplay,
                            'align' => 'center',
                            'bulkPercent' => $showBulkChip ? $dealBulkChipPct : null,
                            'siteId' => $site->id,
                            'featured' => $site->isFeatured(),
                        ])

                        @if($isOwnedByMe)
                            @include('advertiser.partials.catalog-own-listing', ['align' => 'center'])
                        @else
                        <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2{{ $inCart ? ' is-in-cart' : '' }}"
                                data-id="{{ $site->id }}"
                                data-base-price="{{ $catalogListPrice }}"
                                data-publisher-price="{{ $catalogPublisherPrice }}"
                                data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                                data-name="{{ $displayName }}"
                                @if($inCart) data-in-cart="1" @endif
                                aria-label="{{ $inCart ? 'Open cart — '.$identityLabel.' is already in your cart' : 'Buy placement for '.$identityLabel }}">
                            @if($inCart)
                                <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
                                <span>In cart</span>
                            @else
                                <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                                <span>Add to cart</span>
                            @endif
                        </button>
                        <div class="catalog-buy-addon-hint small text-muted mt-1" data-site-id="{{ $site->id }}" hidden></div>
                        @endif

                        <div class="catalog-row-actions__secondary">
                            <div class="catalog-row-actions-quiet">
                                <button type="button"
                                        class="btn-icon-quiet favorite-btn {{ $isFavorited ? 'is-active' : '' }}"
                                        data-id="{{ $site->id }}"
                                        data-name="{{ $displayName }}"
                                        data-glass-tip-placement="left"
                                        aria-label="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}"
                                        title="{{ $isFavorited ? 'Remove from Favorites' : 'Add to Favorites' }}">
                                    <i class="fa-{{ $isFavorited ? 'solid' : 'regular' }} fa-heart" aria-hidden="true"></i>
                                </button>

                                <button type="button"
                                        class="btn-icon-quiet blacklist-btn {{ $isBlacklisted ? 'is-active' : '' }}"
                                        data-id="{{ $site->id }}"
                                        data-name="{{ $displayName }}"
                                        data-glass-tip-placement="left"
                                        aria-label="{{ $isBlacklisted ? 'Remove from blacklist' : 'Blacklist site' }}"
                                        title="{{ $isBlacklisted ? 'Remove from Blacklist' : 'Blacklist Site' }}">
                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>

            <tr class="expanded-row-{{ $site->id }} catalog-site-details"
                id="site-details-{{ $site->id }}"
                data-id="{{ $site->id }}"
                style="display: none;">
    <td colspan="7" class="catalog-expand-cell">
        <div class="row">
            <div class="col-md-12">
                <h6 class="mb-2 catalog-expand-title">Site Details</h6>

                {{-- Preview | Description | Pricing | Tags + sample --}}
                <div class="row align-items-start g-3 catalog-expand-grid">

                    @if($previewUrl)
                    <div class="col-lg-4 col-md-5 catalog-expand-preview">
                        <p class="small text-muted mb-2 catalog-details-heading">
                            <strong>Homepage preview</strong>
                            <x-glass-tip
                                title="Homepage preview"
                                body="Recent screenshot of the publisher homepage so you can judge layout and brand before you buy."
                                label="About Homepage preview"
                                placement="top" />
                        </p>
                            <div class="site-preview-zoom"
                                 tabindex="0"
                                 role="img"
                                 aria-label="{{ $identityLabel }} homepage preview"
                                 data-zoom-src="{{ $expandZoomUrl }}"
                                 data-zoom-chain="{{ json_encode($expandZoomPaths, JSON_UNESCAPED_SLASHES) }}">
                                {{-- Deferred until expand opens (hydrateExpandScreenshots). Avoids
                                     Safari never loading lazy imgs that start inside display:none. --}}
                                <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                     data-src="{{ $previewUrl }}"
                                     alt="{{ $identityLabel }} homepage preview"
                                     decoding="async"
                                     class="catalog-deferred-preview site-image-thumbnail"
                                     data-preview-chain="{{ json_encode($previewPaths, JSON_UNESCAPED_SLASHES) }}"
                                     data-preview-i="0"
                                     onerror="window.catalogSitePreviewOnError && window.catalogSitePreviewOnError(this)">
                            </div>
                            <div class="site-preview-fallback bg-light border rounded d-none flex-column align-items-center justify-content-center gap-2 px-3" aria-hidden="true">
                                <i class="fa-solid fa-image text-muted" style="font-size: 28px;" aria-hidden="true"></i>
                                <span class="small text-muted">Screenshot not available yet</span>
                            </div>
                    </div>
                    @endif

                    <div class="{{ $hasPricingExtras ? 'col-lg-3' : ($hasPlacementExtras ? 'col-lg-4' : 'col-lg-5') }} col-md-6 catalog-expand-description">
                        @if($hasExpandDescription)
                        <p class="mb-1 catalog-details-heading">
                            <strong class="small">Description</strong>
                            <x-glass-tip
                                title="Description"
                                body="The publisher’s listing copy for this site — niche, audience, and what they accept."
                                label="About Description"
                                placement="top" />
                        </p>
                        <div class="text-muted small">
                            @if($inCatalogHideMode && ! $showsIdentity)
                                <span>Use the eye to show this listing’s name and URL, then the description appears.</span>
                            @else
                                {!! $expandDescriptionHtml !!}
                            @endif
                        </div>
                        @endif
                        @unless($hasListingExtras)
                            <p class="text-muted small mb-0 mt-2">Base guest post only — no homepage, social, or sensitive add-ons.</p>
                        @endunless

                        <div class="catalog-expand-trust mt-3">
                            <p class="mb-1 catalog-details-heading">
                                <strong class="small">Publisher trust</strong>
                                <x-glass-tip
                                    title="Publisher trust"
                                    body="Ratings from advertisers after completed orders. Completion rate is successful vs cancelled placements. Last published is the most recent completed placement on this site."
                                    label="About Publisher trust"
                                    placement="top" />
                            </p>
                            @include('advertiser.partials.catalog-site-trust', ['site' => $site, 'compactClass' => ''])
                        </div>
                    </div>

                    @if($hasPricingExtras)
                    <div class="col-lg-3 col-md-6 catalog-expand-pricing">
                        <div class="d-flex flex-column gap-2">
                                @if($hasSensitiveExtras)
                                <p class="mb-0 catalog-details-heading">
                                    <strong>Sensitive topics</strong>
                                    <x-glass-tip
                                        title="Sensitive topics"
                                        body="Optional add-on if the article is in one of these niches."
                                        label="About Sensitive topics"
                                        placement="top" />
                                </p>

                                <div class="sensitive-prices-group"
                                     data-site-id="{{ $site->id }}"
                                     data-base-price="{{ $catalogListPrice }}"
                                     data-publisher-price="{{ $catalogPublisherPrice }}"
                                     data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                                     role="radiogroup"
                                     aria-label="Sensitive topic pricing">

                                    <div class="form-check mb-2">
                                        <input class="form-check-input sensitive-price-checkbox"
                                               type="radio"
                                               name="sensitive_prices_{{ $site->id }}"
                                               value="0"
                                               data-type="none"
                                               data-additional-price="0"
                                               data-total-price="{{ $articlePay }}"
                                               data-site-id="{{ $site->id }}"
                                               id="sensitive_{{ $site->id }}_none"
                                               checked>
                                        <label class="form-check-label" for="sensitive_{{ $site->id }}_none">
                                            <strong>No sensitive topic</strong>
                                            <span class="text-muted">{{ format_money($articlePay) }}</span>
                                        </label>
                                    </div>

                                    @foreach($sensitivePrices as $type => $additionalPrice)
                                        @php
                                            $listWithAddon = round($catalogListPrice + (float) $additionalPrice, 2);
                                            $publisherFloor = round($catalogPublisherPrice + (float) $additionalPrice, 2);
                                            $totalPrice = $listWithAddon;
                                            if ($catalogSalePct) {
                                                $raw = max(0, round($listWithAddon - round($listWithAddon * ($catalogSalePct / 100), 2), 2));
                                                $totalPrice = max($publisherFloor, $raw);
                                            }
                                        @endphp

                                        <div class="form-check mb-2">
                                            <input class="form-check-input sensitive-price-checkbox"
                                                   type="radio"
                                                   name="sensitive_prices_{{ $site->id }}"
                                                   value="{{ $additionalPrice }}"
                                                   data-type="{{ $type }}"
                                                   data-additional-price="{{ $additionalPrice }}"
                                                   data-total-price="{{ $totalPrice }}"
                                                   data-site-id="{{ $site->id }}"
                                                   id="sensitive_{{ $site->id }}_{{ $loop->index }}">

                                            <label class="form-check-label"
                                                   for="sensitive_{{ $site->id }}_{{ $loop->index }}">
                                                <strong>{{ ucfirst($type) }}</strong>
                                                <span class="catalog-addon-price">add-on {{ format_money($additionalPrice, ['signed' => true]) }}</span>
                                                <span class="text-muted">→ you pay {{ format_money($totalPrice) }}</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                                @endif

                                @if($showAdvertiserPay)
                                <div class="selected-price-info mt-1"
                                     id="price-info-{{ $site->id }}">
                                    <small class="text-muted">
                                        You pay:
                                        <strong>{{ format_money($articlePay) }}</strong>
                                        @if($catalogSalePrice !== null)
                                            <span class="text-decoration-line-through">{{ format_money($catalogListPrice) }}</span>
                                            (offer price)
                                        @else
                                            (base price)
                                        @endif
                                    </small>
                                </div>
                                @endif
                        </div>
                    </div>
                    @endif

                    <div class="{{ $hasPricingExtras ? 'col-lg-3' : ($hasPlacementExtras ? 'col-lg-5' : 'col-lg-4') }} col-md-6 catalog-expand-meta">
                        @if($site->linkTypeLabel())
                        <p class="mb-1 catalog-details-heading">
                            <strong>Link type</strong>
                            <x-glass-tip
                                title="Link type"
                                body="Link attribute on the published placement."
                                label="About Link type"
                                placement="top" />
                        </p>
                        <div class="mb-3">
                                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1"
                                      style="font-size: 11px;">
                                    <i class="fa-solid fa-link me-1" aria-hidden="true"></i>{{ $site->linkTypeLabel() }}
                                </span>
                        </div>
                        @endif

                        @if($site->tagValue() !== null)
                        <p class="mb-1 catalog-details-heading">
                            <strong>{{ \App\Support\SiteTag::DETAILS_HEADING }}</strong>
                            <x-glass-tip
                                title="{{ \App\Support\SiteTag::DETAILS_HEADING }}"
                                body="{{ \App\Support\SiteTag::FILTER_TOOLTIP }}"
                                label="About {{ \App\Support\SiteTag::DETAILS_HEADING }}"
                                placement="top" />
                        </p>
                        <div class="mb-3">
                            @include('advertiser.partials.catalog-tag-chip', [
                                'site' => $site,
                                'showNone' => false,
                                'showDefinition' => false,
                            ])
                        </div>
                        @endif

                        @if($homepageOptions !== [])
                        <div data-catalog-section="homepage">
                        <p class="mb-1 catalog-details-heading">
                            <strong>Homepage promotions</strong>
                            <x-glass-tip
                                title="Homepage promotions"
                                body="Put the article on the publisher homepage for a set duration. Sale/bulk discounts do not apply to this fee."
                                label="About Homepage promotions"
                                placement="top" />
                        </p>
                            <div class="homepage-placement-group mb-3"
                                 data-site-id="{{ $site->id }}"
                                 role="radiogroup"
                                 aria-label="Homepage placement duration">
                                <div class="form-check mb-2">
                                    <input class="form-check-input homepage-placement-radio"
                                           type="radio"
                                           name="homepage_placement_{{ $site->id }}"
                                           value="none"
                                           data-days="none"
                                           data-price="0"
                                           data-site-id="{{ $site->id }}"
                                           id="homepage_{{ $site->id }}_none"
                                           {{ $defaultHomepageDays === null ? 'checked' : '' }}>
                                    <label class="form-check-label" for="homepage_{{ $site->id }}_none">
                                        <strong>No homepage placement</strong>
                                    </label>
                                </div>
                                @foreach($homepageOptions as $days => $fee)
                                    @php $isFreeHome = (float) $fee <= 0; @endphp
                                    <div class="form-check mb-2">
                                        <input class="form-check-input homepage-placement-radio"
                                               type="radio"
                                               name="homepage_placement_{{ $site->id }}"
                                               value="{{ $days }}"
                                               data-days="{{ $days }}"
                                               data-price="{{ $fee }}"
                                               data-site-id="{{ $site->id }}"
                                               id="homepage_{{ $site->id }}_{{ $days }}"
                                               {{ (int) $defaultHomepageDays === (int) $days ? 'checked' : '' }}>
                                        <label class="form-check-label" for="homepage_{{ $site->id }}_{{ $days }}">
                                            <strong>{{ $days }} day{{ $days > 1 ? 's' : '' }}</strong>
                                            @if($isFreeHome)
                                                <span class="text-success">Free</span>
                                            @else
                                                <span class="text-muted">add-on {{ format_money($fee, ['signed' => true]) }}</span>
                                            @endif
                                            @if($showAdvertiserPay)
                                                <span class="text-muted">→ you pay {{ format_money(round($articlePay + (float) $fee, 2)) }}</span>
                                            @endif
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        @if($socialChannels !== [])
                        <div data-catalog-section="social">
                        <p class="mb-1 catalog-details-heading">
                            <strong>Social promotions</strong>
                            <x-glass-tip
                                title="Social promotions"
                                body="Publisher will share the live post on these channels at no extra cost."
                                label="About Social promotions"
                                placement="top" />
                        </p>
                            <div class="d-flex flex-wrap gap-1 mb-3" aria-label="Included social channels">
                                @foreach($socialChannels as $channel)
                                    <span class="badge bg-light text-dark border">{{ $socialChannelLabels[$channel] ?? ucfirst($channel) }}</span>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        @php
                            $sampleUrl = safe_external_url($site->example_url);
                            $sampleVisit = route('advertiser.catalog.visit', ['site' => $site->id, 'sample' => 1]);
                            $hasSampleArticle = $sampleUrl !== '#';
                        @endphp
                        @if($hasSampleArticle)
                        <p class="mb-1 catalog-details-heading">
                            <strong>Sample article</strong>
                            <x-glass-tip
                                title="Sample article"
                                body="An example live placement from this publisher so you can review their writing and link style."
                                label="About Sample article"
                                placement="top" />
                        </p>
                        {{-- Sample URLs share the listing domain — only show when
                             identity is visible (always outside hide mode; after eye inside). --}}
                        <div class="d-flex flex-column gap-2 mb-3">
                            @if($inCatalogHideMode && ! $showsIdentity)
                                <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="btn btn-sm btn-outline-secondary" style="width: fit-content;">
                                    <i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>
                                    Open site
                                </a>
                                <span class="text-muted small">
                                    Use the eye to show this listing’s name and URL, then the sample article link appears.
                                </span>
                            @else
                                    <div class="d-flex align-items-center gap-2">
                                        <a href="{{ $sampleVisit }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-decoration-none catalog-site-url"
                                           style="word-break: break-all;">
                                            {{ Str::limit($site->example_url, 50) }}
                                        </a>
                                        <button type="button"
                                                class="btn btn-link p-0 copy-example-url catalog-sample-copy"
                                                data-url="{{ $sampleVisit }}"
                                                data-site-id="{{ $site->id }}"
                                                aria-label="Copy the sample article URL for {{ $identityLabel }}">
                                            <i class="fa-regular fa-copy" aria-hidden="true"></i>
                                        </button>
                                        <a href="{{ $sampleVisit }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-muted d-inline-flex align-items-center"
                                           title="Open sample article"
                                           aria-label="Open the sample article for {{ $identityLabel }} in a new tab">
                                            <i class="fa-solid fa-arrow-up-right-from-square"
                                               style="font-size: 13px;" aria-hidden="true"></i>
                                        </a>
                                    </div>
                            @endif
                        </div>
                        @endif

                        @if($site->turnaroundLabel())
                        <p class="mb-1 catalog-details-heading">
                            <strong>Turnaround</strong>
                            <x-glass-tip
                                title="Turnaround"
                                body="Typical publisher turnaround once an order is accepted."
                                label="About Turnaround"
                                placement="top" />
                        </p>
                            <span class="badge text-muted border px-2 py-1 mb-3"
                                  style="font-size: 11px;">
                                <i class="fa-solid fa-hourglass-half me-1" aria-hidden="true"></i>
                                {{ $site->turnaroundLabel() }}
                            </span>
                        @endif

                        @if($site->publicationDurationLabel())
                        <p class="mb-1 catalog-details-heading">
                            <strong>Publication duration</strong>
                            <x-glass-tip
                                title="Publication duration"
                                body="How long the published article stays live."
                                label="About Publication duration"
                                placement="top" />
                        </p>
                            <span class="badge text-muted border px-2 py-1"
                                  style="font-size: 11px;">
                                <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
                                {{ $site->publicationDurationLabel() }}
                            </span>
                        @endif

                        @include('advertiser.partials.catalog-claim', [
                            'site' => $site,
                            'displayName' => $displayName,
                            'identityLabel' => $identityLabel,
                            'canSeeUrl' => $canSeeUrl,
                            'isOwnedByMe' => $isOwnedByMe,
                        ])
                    </div>

                </div>
            </div>
        </div>
    </td>
</tr>
            @empty
            <tr>
                <td colspan="7" class="text-center py-5">
                    <div class="catalog-empty-state mx-auto">
                        @include('advertiser.partials.catalog-empty-art')
                        <h5 class="mb-2">
                            {{ $catalogEmptyHeadline ?? ($hasActiveFilters ? 'No sites match these filters' : 'No publishers available yet') }}
                        </h5>
                        @if($catalogEmptyRecovery)
                            @include('advertiser.partials.catalog-empty-recovery', ['catalogEmptyRecovery' => $catalogEmptyRecovery])
                        @elseif($hasActiveFilters)
                            <p class="text-muted mb-3">
                                Try broader filters — clear a category, widen price, or remove DA/DR limits.
                            </p>
                            <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
                                <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary btn-sm">Clear all filters</a>
                                <a href="{{ route('advertiser.catalog', ['sort' => 'dr_desc']) }}" class="btn btn-outline-secondary btn-sm">Browse top DR</a>
                                <button type="button" class="btn btn-outline-success btn-sm btn-suggest-website"
                                        data-search="{{ search_text(request('search')) }}">
                                    <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
                                </button>
                            </div>
                            <p class="small text-muted mb-0">
                                Can’t find a site you need?
                                @if(search_text(request('search')) !== '')
                                    Suggest “{{ search_text(request('search')) }}” and we’ll try to add it.
                                @else
                                    Suggest it and we’ll try to include it in the marketplace.
                                @endif
                            </p>
                        @else
                            <p class="text-muted mb-3">New verified sites show up here as publishers list them.</p>
                            <a href="{{ route('advertiser.catalog', ['new_badge' => 1]) }}" class="btn btn-outline-secondary btn-sm">Show new sites</a>
                        @endif
                    </div>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Card list for everything below xl — same buy/favorite/blacklist actions,
     plus the details the table keeps in its expand row. --}}
<div class="catalog-mobile-list d-xl-none p-3">
    @forelse($sites as $site)
        @php
            $isBlacklisted = in_array($site->id, $blacklist);
            $isFavorited = in_array($site->id, $favorites);
            $isNew = $site->isRecentlyCreated();
            $showsIdentity = true;
            $canSeeUrl = true;
            $displayHost = '';
            $displayRootedUrl = '';
            $displayName = (string) ($site->site_name ?? '');
            try {
                $showsIdentity = $urlVisibility->showsFullIdentity($currentUser, $site);
                $canSeeUrl = $showsIdentity;
                $displayHost = $urlVisibility->hostFor($currentUser, $site);
                $displayRootedUrl = $urlVisibility->rootedUrlFor($currentUser, $site);
                $displayName = $urlVisibility->nameFor($currentUser, $site);
            } catch (\Throwable $e) {
                report($e);
            }
            $identityLabel = $showsIdentity
                ? (string) $site->site_name
                : 'this website';
            $eyeShowLabel = 'Show site name and URL';
            $eyeHideLabel = 'Hide site name and URL';
            $mobilePreviewPaths = $site->homepagePreviewUrlChain();
            $mobilePreviewUrl = $mobilePreviewPaths[0] ?? null;
            $mobileZoomPaths = $site->zoomPreviewUrlChain();
            if ($mobileZoomPaths === [] && $mobilePreviewPaths !== []) {
                $mobileZoomPaths = $mobilePreviewPaths;
            }
            $mobileZoomUrl = $mobileZoomPaths[0] ?? $mobilePreviewUrl;
            $tileFaviconUrl = $showsIdentity ? $site->catalogTileFaviconUrl() : null;
            $tileFaviconChain = $tileFaviconUrl ? [$tileFaviconUrl] : [];
            $mobileLabels = $site->nicheBadgeLabels();
            $mobileCategory = $mobileLabels[0] ?? '—';
            $mobileSensitivePrices = $site->safeJsonArray('sensitive_prices');
            $mobileSensitivePrices = collect($mobileSensitivePrices)
                ->filter(fn ($amount, $type) => is_string($type) && $type !== ''
                    && is_numeric($amount) && (float) $amount > 0)
                ->map(fn ($amount) => round((float) $amount, 2))
                ->all();
            $homepageOptions = $site->homepagePlacementOptions();
            $defaultHomepageDays = $site->longestFreeHomepageDays();
            $socialChannels = $site->enabledSocialChannels();
            $socialChannelLabels = [
                'facebook' => 'Facebook',
                'instagram' => 'Instagram',
                'x' => 'X',
            ];
            $viewPrices = $site->catalogPricesForViewer(auth()->user());
            $isOwnedByMe = ! empty($viewPrices['owned']);
            $catalogListPrice = (float) $viewPrices['list'];
            $catalogPublisherPrice = (float) $viewPrices['publisher'];
            $catalogSalePctNominal = $viewPrices['sale_percent_nominal'];
            $catalogSalePct = $catalogSalePctNominal; // nominal for data-* / JS
            $catalogSalePctDisplay = $viewPrices['sale_percent'];
            $catalogSalePrice = $viewPrices['sale'];
            $articlePay = $catalogSalePrice ?? $catalogListPrice;
            $showAdvertiserPay = ! $isOwnedByMe;
            $inCart = in_array((int) $site->id, $cartSiteIds, true);
        @endphp
        <article class="catalog-mobile-card {{ $isBlacklisted ? 'is-blacklisted' : '' }}"
                 data-id="{{ $site->id }}"
                 data-name="{{ $displayName }}"
                 data-publisher-id="{{ (int) $site->publisher_id }}"
                 @if((int) ($site->getAttribute('owner_id') ?? 0) > 0) data-owner-id="{{ (int) $site->getAttribute('owner_id') }}" @endif
                 @if($isOwnedByMe) data-own-listing="1" @endif>
            @include('advertiser.partials.catalog-new-ribbon', ['isNew' => $isNew])
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="catalog-mobile-card__host d-flex align-items-start gap-2">
                    @include('advertiser.partials.catalog-site-tile', [
                        'label' => $displayHost,
                        'size' => 'lg',
                        'faviconUrl' => $tileFaviconUrl,
                        'faviconChain' => $tileFaviconChain,
                        'masked' => ! $showsIdentity,
                        'openDetailsId' => (string) $site->id,
                    ])

                    <div class="catalog-mobile-card__main">
                    <div class="catalog-site-title-row">
                    <div class="fw-semibold text-dark catalog-site-name catalog-site-name--concat"
                         data-site-name-label
                         title="{{ $displayName }}">{{ $displayName }}</div>
                    @if($site->verified)
                    <span class="catalog-site-badges">
                        <span class="site-chip site-chip--verified site-chip--status"><span class="catalog-verified-lottie" data-lottie="{{ asset('assets/vendor/lottie/verified.json') }}" aria-hidden="true"></span><span class="visually-hidden">Verified</span></span>
                    </span>
                    @endif
                    </div>
                    {{-- Visit sits on the rooted URL, not next to the name. --}}
                    <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="site-open-link catalog-site-rooted-url catalog-site-url text-truncate"
                       id="url-host-mobile-{{ $site->id }}"
                       data-site-host
                       aria-label="Open {{ $displayRootedUrl }} in a new tab"
                       @if($showsIdentity) data-host="{{ $displayHost }}" @endif
                       @if($inCatalogHideMode && ! $showsIdentity)
                           data-glass-tip
                           data-glass-tip-title="Name and URL hidden"
                           data-glass-tip-body="Site name and URL are hidden for 24 hours after repeated domain copying. Open the eye to reveal both for this listing — metrics and price stay visible."
                           data-glass-tip-placement="top"
                       @endif>{{ $displayRootedUrl }}</a>
                    <div class="catalog-site-tag-slot">
                        @include('advertiser.partials.catalog-tag-chip', ['site' => $site])
                        @include('advertiser.partials.catalog-placement-chips', [
                            'homepageOptions' => $homepageOptions,
                            'defaultHomepageDays' => $defaultHomepageDays,
                            'socialChannels' => $socialChannels,
                            'socialChannelLabels' => $socialChannelLabels,
                            'openDetailsId' => (string) $site->id,
                        ])
                        @include('advertiser.partials.catalog-site-trust', ['site' => $site, 'variant' => 'chip'])
                    </div>
                    @php
                        $mobileCustomPct = null;
                        $mobileBulkPct = null;
                        $showMobileSaleChip = false;
                        $showMobileBulkChip = false;
                        $mobileSaleChipPct = null;
                        $mobileBulkChipPct = null;
                        try {
                            $mobileCustomPct = $site->activeCustomDiscountPercent();
                            $mobileBulkPct = $site->joinsBulkDiscount()
                                ? (float) $site->bulk_discount_percent
                                : null;
                            $showMobileSaleChip = $mobileCustomPct !== null && $catalogSalePctDisplay;
                            $showMobileBulkChip = $mobileBulkPct !== null
                                && ($mobileCustomPct === null || $mobileBulkPct > (float) $mobileCustomPct);
                            $mobileSaleChipPct = $catalogSalePctDisplay;
                            $mobileBulkChipPct = $mobileBulkPct;
                            if ($showMobileBulkChip) {
                                // $site->price is already advertiser-facing; reprice from
                                // the publisher base so the chip % is not fee-on-fee.
                                $mobilePackSite = clone $site;
                                $mobilePackSite->price = $catalogPublisherPrice;
                                $mobilePackPricing = app(\App\Services\CartPricingService::class)
                                    ->priceForAdvertiser($mobilePackSite, null, (int) config('site_promotions.bulk.min_qty', 3));
                                $mobileBulkChipPct = (float) ($mobilePackPricing['discount_percent'] ?? $mobileBulkPct);
                                if ($mobileBulkChipPct <= 0) {
                                    $showMobileBulkChip = false;
                                }
                            }
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    @endphp
                    @if($showMobileSaleChip)
                    <div class="catalog-site-deals catalog-site-deals--mobile mt-1">
                        @if($showMobileSaleChip)
                            <span class="site-chip site-chip--sale site-chip--status">
                                <i class="fa-solid fa-percent" aria-hidden="true"></i>
                                <span>−{{ rtrim(rtrim(number_format((float) $mobileSaleChipPct, 1), '0'), '.') }}%</span>
                            </span>
                        @endif
                    </div>
                    @endif
                    <span class="category-badge mt-1">{{ $mobileCategory }}</span>
                    @include('advertiser.partials.catalog-meta-chips', [
                        'site' => $site,
                    ])
                    </div>
                </div>
                {{-- Eye only in copy-strike hide mode (normals see full identity). --}}
                @if($inCatalogHideMode)
                <button type="button"
                        class="btn btn-sm btn-link text-secondary p-0 toggle-url btn-icon-quiet"
                        data-id="{{ $site->id }}"
                        data-site-id="{{ $site->id }}"
                        data-url-prefix="mobile"
                        data-target-suffix="mobile"
                        id="url-toggle-mobile-{{ $site->id }}"
                        title="{{ $showsIdentity ? $eyeHideLabel : $eyeShowLabel }}"
                        aria-label="{{ $showsIdentity ? $eyeHideLabel : $eyeShowLabel }}">
                    <i class="fa-regular {{ $showsIdentity ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i>
                </button>
                @endif
            </div>
            @php
                $mobileCountry = null;
                $mobileCountryName = '';
                try {
                    $mobileCountry = $site->primaryCountryCode();
                    $mobileCountryName = fullCountry($mobileCountry);
                } catch (\Throwable $e) {
                    report($e);
                }
            @endphp
            <div class="catalog-mobile-metrics">
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">
                        @include('advertiser.partials.metric-source', ['type' => 'traffic', 'size' => 'sm'])
                        Traffic
                    </span>
                    @include('advertiser.partials.catalog-metric', ['type' => 'traffic', 'value' => $site->traffic, 'inline' => false])
                </div>
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">
                        @include('advertiser.partials.metric-source', ['type' => 'dr', 'size' => 'sm'])
                        DR
                    </span>
                    @include('advertiser.partials.catalog-metric', ['type' => 'dr', 'value' => $site->dr, 'inline' => false])
                </div>
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">
                        @include('advertiser.partials.metric-source', ['type' => 'da', 'size' => 'sm'])
                        DA
                    </span>
                    @include('advertiser.partials.catalog-metric', ['type' => 'da', 'value' => $site->da, 'inline' => false])
                </div>
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">Country</span>
                    <strong class="catalog-country__name" title="{{ $mobileCountryName }}">{!! getCountryFlag($mobileCountry) !!} {{ $mobileCountryName }}</strong>
                </div>
            </div>
            @if(!empty($mobileSensitivePrices))
                <div class="sensitive-prices-group mt-3"
                     data-site-id="{{ $site->id }}"
                     data-base-price="{{ $catalogListPrice }}"
                     data-publisher-price="{{ $catalogPublisherPrice }}"
                     data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                     role="radiogroup"
                     aria-label="Sensitive topic pricing">
                    <div class="small fw-semibold mb-1 catalog-details-heading">
                        Sensitive topics
                        <x-glass-tip
                            title="Sensitive topics"
                            body="Optional add-on if the article is in one of these niches."
                            label="About Sensitive topics"
                            placement="top" />
                    </div>
                    {{-- Its own radio group. Sharing the table's name made the two
                         layouts one group, so the card rendered with nothing
                         selected while the hidden table row held the checked
                         default. JS reads the group that is actually visible. --}}
                    <div class="form-check mb-1">
                        <input class="form-check-input sensitive-price-checkbox"
                               type="radio"
                               name="sensitive_prices_card_{{ $site->id }}"
                               value="0"
                               data-type="none"
                               data-additional-price="0"
                               data-total-price="{{ $articlePay }}"
                               data-site-id="{{ $site->id }}"
                               id="sensitive_mobile_{{ $site->id }}_none"
                               checked>
                        <label class="form-check-label" for="sensitive_mobile_{{ $site->id }}_none">
                            <strong>No sensitive topic</strong>
                            <span class="text-muted">{{ format_money($articlePay) }}</span>
                        </label>
                    </div>
                    @foreach($mobileSensitivePrices as $type => $additionalPrice)
                        @php
                            $listWithAddon = round($catalogListPrice + (float) $additionalPrice, 2);
                            $publisherFloor = round($catalogPublisherPrice + (float) $additionalPrice, 2);
                            $totalPrice = $listWithAddon;
                            if ($catalogSalePct) {
                                $raw = max(0, round($listWithAddon - round($listWithAddon * ($catalogSalePct / 100), 2), 2));
                                $totalPrice = max($publisherFloor, $raw);
                            }
                        @endphp
                        <div class="form-check mb-1">
                            <input class="form-check-input sensitive-price-checkbox"
                                   type="radio"
                                   name="sensitive_prices_card_{{ $site->id }}"
                                   value="{{ $additionalPrice }}"
                                   data-type="{{ $type }}"
                                   data-additional-price="{{ $additionalPrice }}"
                                   data-total-price="{{ $totalPrice }}"
                                   data-site-id="{{ $site->id }}"
                                   id="sensitive_mobile_{{ $site->id }}_{{ $loop->index }}">
                            <label class="form-check-label" for="sensitive_mobile_{{ $site->id }}_{{ $loop->index }}">
                                <strong>{{ ucfirst($type) }}</strong>
                                <span class="catalog-addon-price">add-on {{ format_money($additionalPrice, ['signed' => true]) }}</span>
                                <span class="text-muted">→ you pay {{ format_money($totalPrice) }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            @endif
            @if($showAdvertiserPay)
                {{-- Always present so homepage-only cards have a live You pay
                     target (JS rewrites this when radios change). --}}
                <div class="selected-price-info mt-1" id="price-info-mobile-{{ $site->id }}">
                    <small class="text-muted">
                        You pay:
                        <strong>{{ format_money($articlePay) }}</strong>
                        @if($catalogSalePrice !== null)
                            <span class="text-decoration-line-through">{{ format_money($catalogListPrice) }}</span>
                            (offer price)
                        @else
                            (base price)
                        @endif
                    </small>
                </div>
            @endif
            @if($homepageOptions !== [])
                <div class="homepage-placement-group mt-3"
                     data-site-id="{{ $site->id }}"
                     data-catalog-section="homepage"
                     role="radiogroup"
                     aria-label="Homepage placement duration">
                    <div class="small fw-semibold mb-1 catalog-details-heading">
                        Homepage promotions
                        <x-glass-tip
                            title="Homepage promotions"
                            body="Put the article on the publisher homepage for a set duration. Sale/bulk discounts do not apply to this fee."
                            label="About Homepage promotions"
                            placement="top" />
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input homepage-placement-radio"
                               type="radio"
                               name="homepage_placement_card_{{ $site->id }}"
                               value="none"
                               data-days="none"
                               data-price="0"
                               data-site-id="{{ $site->id }}"
                               id="homepage_mobile_{{ $site->id }}_none"
                               {{ $defaultHomepageDays === null ? 'checked' : '' }}>
                        <label class="form-check-label" for="homepage_mobile_{{ $site->id }}_none">
                            <strong>No homepage placement</strong>
                        </label>
                    </div>
                    @foreach($homepageOptions as $days => $fee)
                        @php $isFreeHome = (float) $fee <= 0; @endphp
                        <div class="form-check mb-1">
                            <input class="form-check-input homepage-placement-radio"
                                   type="radio"
                                   name="homepage_placement_card_{{ $site->id }}"
                                   value="{{ $days }}"
                                   data-days="{{ $days }}"
                                   data-price="{{ $fee }}"
                                   data-site-id="{{ $site->id }}"
                                   id="homepage_mobile_{{ $site->id }}_{{ $days }}"
                                   {{ (int) $defaultHomepageDays === (int) $days ? 'checked' : '' }}>
                            <label class="form-check-label" for="homepage_mobile_{{ $site->id }}_{{ $days }}">
                                <strong>{{ $days }} day{{ $days > 1 ? 's' : '' }}</strong>
                                @if($isFreeHome)
                                    <span class="text-success">Free</span>
                                @else
                                    <span class="text-muted">add-on {{ format_money($fee, ['signed' => true]) }}</span>
                                @endif
                                @if($showAdvertiserPay)
                                    <span class="text-muted">→ you pay {{ format_money(round($articlePay + (float) $fee, 2)) }}</span>
                                @endif
                            </label>
                        </div>
                    @endforeach
                </div>
            @endif
            @if($socialChannels !== [])
                <div class="mt-3" data-catalog-section="social">
                    <div class="small fw-semibold mb-1">Social promotions</div>
                    <div class="d-flex flex-wrap gap-1" aria-label="Included social channels">
                        @foreach($socialChannels as $channel)
                            <span class="badge bg-light text-dark border">{{ $socialChannelLabels[$channel] ?? ucfirst($channel) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="catalog-card-buy">
                @if($isOwnedByMe)
                    @include('advertiser.partials.catalog-own-listing', ['align' => 'start'])
                @else
                <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2{{ $inCart ? ' is-in-cart' : '' }}"
                        data-id="{{ $site->id }}"
                        data-base-price="{{ $catalogListPrice }}"
                        data-publisher-price="{{ $catalogPublisherPrice }}"
                        data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                        data-name="{{ $displayName }}"
                        @if($inCart) data-in-cart="1" @endif
                        aria-label="{{ $inCart ? 'Open cart — '.$identityLabel.' is already in your cart' : 'Buy placement for '.$identityLabel }}">
                    @if($inCart)
                        <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
                        <span>In cart</span>
                    @else
                        <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                        <span>Add to cart</span>
                    @endif
                </button>
                <div class="catalog-buy-addon-hint small text-muted" data-site-id="{{ $site->id }}" hidden></div>
                @endif

                @include('advertiser.partials.catalog-price', [
                    'listPrice' => $catalogListPrice,
                    'salePrice' => $catalogSalePrice,
                    'salePercent' => $catalogSalePctDisplay,
                    'align' => 'start',
                    'bulkPercent' => ! empty($showMobileBulkChip) ? $mobileBulkChipPct : null,
                    'siteId' => $site->id,
                    'featured' => $site->isFeatured(),
                ])
            </div>

            <div class="catalog-row-actions mt-2">
                {{-- Same shape as the table row: Buy owns its line, the quiet
                     controls share the next, so a narrow card does not scatter
                     them across three ragged lines. --}}
                <div class="catalog-row-actions__secondary">
                    <div class="catalog-row-actions-quiet">
                        <button type="button"
                                class="btn-icon-quiet favorite-btn {{ $isFavorited ? 'is-active' : '' }}"
                                data-id="{{ $site->id }}"
                                data-name="{{ $displayName }}"
                                data-glass-tip-placement="left"
                                aria-label="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}"
                                title="{{ $isFavorited ? 'Remove from Favorites' : 'Add to Favorites' }}">
                            <i class="fa-{{ $isFavorited ? 'solid' : 'regular' }} fa-heart" aria-hidden="true"></i>
                        </button>
                        <button type="button"
                                class="btn-icon-quiet blacklist-btn {{ $isBlacklisted ? 'is-active' : '' }}"
                                data-id="{{ $site->id }}"
                                data-name="{{ $displayName }}"
                                data-glass-tip-placement="left"
                                aria-label="{{ $isBlacklisted ? 'Remove from blacklist' : 'Blacklist site' }}"
                                title="{{ $isBlacklisted ? 'Remove from Blacklist' : 'Blacklist Site' }}">
                            <i class="fa-solid fa-ban" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>

            {{-- The table keeps this in its expand row, so before the card list
                 covered tablets it was desktop-only: no description, no sample
                 article, no publication window anywhere else. --}}
            <button type="button"
                    class="btn btn-sm btn-link text-secondary p-0 mt-2 catalog-details-toggle catalog-card-details-toggle"
                    data-card-details="card-details-{{ $site->id }}"
                    aria-expanded="false"
                    aria-controls="card-details-{{ $site->id }}">
                <span class="catalog-details-toggle__label">Details</span>
                <i class="fa-solid fa-chevron-down ms-1" aria-hidden="true"></i>
            </button>

            <dl class="catalog-card-details" id="card-details-{{ $site->id }}" hidden>
                @if($mobilePreviewUrl)
                <div class="catalog-card-details__row">
                    <dt>
                        Homepage preview
                        <x-glass-tip
                            title="Homepage preview"
                            body="Recent screenshot of the publisher homepage so you can judge layout and brand before you buy."
                            label="About Homepage preview"
                            placement="top" />
                    </dt>
                    <dd>
                            <div class="site-preview-zoom catalog-card-preview"
                                 tabindex="0"
                                 role="img"
                                 aria-label="{{ $identityLabel }} homepage preview"
                                 data-zoom-src="{{ $mobileZoomUrl }}"
                                 data-zoom-chain="{{ json_encode($mobileZoomPaths, JSON_UNESCAPED_SLASHES) }}">
                                <img class="catalog-deferred-preview site-image-thumbnail"
                                     src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                     data-src="{{ $mobilePreviewUrl }}"
                                     alt="{{ $identityLabel }} homepage preview"
                                     decoding="async"
                                     data-preview-chain="{{ json_encode($mobilePreviewPaths, JSON_UNESCAPED_SLASHES) }}"
                                     data-preview-i="0"
                                     onerror="window.catalogSitePreviewOnError && window.catalogSitePreviewOnError(this)">
                            </div>
                            <div class="site-preview-fallback bg-light border rounded d-none flex-column align-items-center justify-content-center gap-2 px-3" aria-hidden="true">
                                <i class="fa-solid fa-image text-muted" style="font-size: 24px;" aria-hidden="true"></i>
                                <span class="small text-muted">Screenshot not available yet</span>
                            </div>
                    </dd>
                </div>
                @endif
                <div class="catalog-card-details__row">
                    <dt>
                        Trust
                        <x-glass-tip
                            title="Publisher trust"
                            body="Ratings from advertisers after completed orders. Completion rate is successful vs cancelled placements. Last published is the most recent completed placement on this site."
                            label="About Publisher trust"
                            placement="top" />
                    </dt>
                    <dd>@include('advertiser.partials.catalog-site-trust', ['site' => $site, 'compactClass' => ''])</dd>
                </div>
                @if($site->turnaroundLabel())
                <div class="catalog-card-details__row">
                    <dt>
                        Turnaround
                        <x-glass-tip
                            title="Turnaround"
                            body="Typical publisher turnaround once an order is accepted."
                            label="About Turnaround"
                            placement="top" />
                    </dt>
                    <dd>{{ $site->turnaroundLabel() }}</dd>
                </div>
                @endif
                @if($site->publicationDurationLabel())
                <div class="catalog-card-details__row">
                    <dt>
                        Publication duration
                        <x-glass-tip
                            title="Publication duration"
                            body="How long the published article stays live."
                            label="About Publication duration"
                            placement="top" />
                    </dt>
                    <dd>{{ $site->publicationDurationLabel() }}</dd>
                </div>
                @endif
                @if($site->linkTypeLabel())
                <div class="catalog-card-details__row">
                    <dt>
                        Link type
                        <x-glass-tip
                            title="Link type"
                            body="Link attribute on the published placement."
                            label="About Link type"
                            placement="top" />
                    </dt>
                    <dd>{{ $site->linkTypeLabel() }}</dd>
                </div>
                @endif
                @if($site->tagValue() !== null)
                <div class="catalog-card-details__row">
                    <dt>
                        {{ \App\Support\SiteTag::DETAILS_HEADING }}
                        <x-glass-tip
                            title="{{ \App\Support\SiteTag::DETAILS_HEADING }}"
                            body="{{ \App\Support\SiteTag::FILTER_TOOLTIP }}"
                            label="About {{ \App\Support\SiteTag::DETAILS_HEADING }}"
                            placement="top" />
                    </dt>
                    <dd>
                        @include('advertiser.partials.catalog-tag-chip', [
                            'site' => $site,
                            'showNone' => false,
                            'showDefinition' => false,
                        ])
                    </dd>
                </div>
                @endif
                @if($homepageOptions !== [])
                <div class="catalog-card-details__row">
                    <dt>
                        Homepage promotions
                        <x-glass-tip
                            title="Homepage promotions"
                            body="Put the article on the publisher homepage for a set duration. Sale/bulk discounts do not apply to this fee."
                            label="About Homepage promotions"
                            placement="top" />
                    </dt>
                    <dd>
                            <ul class="list-unstyled mb-0 small">
                                @foreach($homepageOptions as $days => $fee)
                                    <li>
                                        {{ $days }} day{{ $days > 1 ? 's' : '' }}
                                        @if((float) $fee <= 0)
                                            — <span class="text-success">Free</span>
                                        @else
                                            — add-on {{ format_money($fee, ['signed' => true]) }}
                                        @endif
                                        @if($showAdvertiserPay)
                                            → you pay {{ format_money(round($articlePay + (float) $fee, 2)) }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <span class="text-muted small">Choose a duration in this panel, or change it later in the cart.</span>
                    </dd>
                </div>
                @endif
                @if($socialChannels !== [])
                <div class="catalog-card-details__row" data-catalog-section="social">
                    <dt>
                        Social promotions
                        <x-glass-tip
                            title="Social promotions"
                            body="Publisher will share the live post on these channels at no extra cost."
                            label="About Social promotions"
                            placement="top" />
                    </dt>
                    <dd>
                            <div class="d-flex flex-wrap gap-1">
                                @foreach($socialChannels as $channel)
                                    <span class="badge bg-light text-dark border">{{ $socialChannelLabels[$channel] ?? ucfirst($channel) }}</span>
                                @endforeach
                            </div>
                    </dd>
                </div>
                @endif
                @if($site->description)
                    <div class="catalog-card-details__row">
                        <dt>
                            About this site
                            <x-glass-tip
                                title="About this site"
                                body="The publisher’s listing copy for this site — niche, audience, and what they accept."
                                label="About this site"
                                placement="top" />
                        </dt>
                        {{-- Cards stay plain-text; desktop expand keeps rich HTML via safeDescriptionHtml().
                             Hide-mode rows stay gated — publishers often paste the listing URL here. --}}
                        <dd class="catalog-card-details__description text-muted small">
                            @if($inCatalogHideMode && ! $showsIdentity)
                                Use the eye to show this listing’s name and URL, then the description appears.
                            @else
                                {{ site_description_excerpt($site->description) }}
                            @endif
                        </dd>
                    </div>
                @endif
                @if($site->example_url && ($mobileSampleUrl = safe_external_url($site->example_url)) !== '#')
                <div class="catalog-card-details__row">
                    <dt>
                        Sample article
                        <x-glass-tip
                            title="Sample article"
                            body="An example live placement from this publisher so you can review their writing and link style."
                            label="About Sample article"
                            placement="top" />
                    </dt>
                    <dd>
                        {{-- Sample shares the listing domain — gate on identity. --}}
                        @if($inCatalogHideMode && ! $showsIdentity)
                            Use the eye to show this listing’s name and URL, then the sample article link appears.
                        @else
                            <a href="{{ route('advertiser.catalog.visit', ['site' => $site->id, 'sample' => 1]) }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="catalog-site-url">
                                {{ Str::limit($site->example_url, 46) }}
                            </a>
                        @endif
                    </dd>
                </div>
                @endif
                @unless($isOwnedByMe)
                <div class="catalog-card-details__row">
                    <dt>Claim listing</dt>
                    <dd>
                        @include('advertiser.partials.catalog-claim', [
                            'site' => $site,
                            'displayName' => $displayName,
                            'identityLabel' => $identityLabel,
                            'canSeeUrl' => $canSeeUrl,
                            'isOwnedByMe' => $isOwnedByMe,
                        ])
                    </dd>
                </div>
                @endunless
            </dl>
        </article>
    @empty
        <div class="catalog-empty-state mx-auto text-center py-4">
            @include('advertiser.partials.catalog-empty-art')
            <h5 class="mb-2">{{ $catalogEmptyHeadline ?? ($hasActiveFilters ? 'No sites match these filters' : 'No publishers available yet') }}</h5>
            @if($catalogEmptyRecovery)
                @include('advertiser.partials.catalog-empty-recovery', ['catalogEmptyRecovery' => $catalogEmptyRecovery])
            @elseif($hasActiveFilters)
                <p class="text-muted mb-3">
                    Try broader filters — clear a category, widen price, or remove DA/DR limits.
                </p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary btn-sm">Clear all filters</a>
                    <button type="button" class="btn btn-outline-success btn-sm btn-suggest-website"
                            data-search="{{ search_text(request('search')) }}">
                        <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
                    </button>
                </div>
            @else
                <p class="text-muted mb-3">New verified sites show up here as publishers list them.</p>
            @endif
        </div>
    @endforelse
</div>

                    <!-- Pagination — sized so Prev/Next never swallow the results text -->
                    @if($resultTotal > 0 && $sites->lastPage() > 1)
                    <div class="catalog-pagination">
                        <p class="catalog-pagination__meta">
                            Showing
                            <strong>{{ $sites->firstItem() }}–{{ $sites->lastItem() }}</strong>
                            of <strong>{{ number_format($resultTotal) }}</strong>
                            {{ Str::plural('site', $resultTotal) }}
                            <span class="catalog-pagination__page-label" aria-hidden="true">
                                · Page {{ $sites->currentPage() }} of {{ $sites->lastPage() }}
                            </span>
                        </p>
                        <div class="catalog-pagination__links">
                            {{ $sites->onEachSide(1)->links('advertiser.partials.catalog-pagination-links') }}
                        </div>
                    </div>
                    @endif

                </div>
            </div>
