{{-- Shared catalog results fragment (desktop table + mobile cards + pagination).
     Included by advertiser.catalog and returned by GET advertiser.catalog.results. --}}
@php
    use Illuminate\Support\Str;
    $resultTotal = $sites->total();
    $hasActiveFilters = $hasActiveFilters ?? (
        request()->filled('site')
        || request()->filled('search')
        || request()->filled('category')
        || request()->filled('country')
        || request()->filled('language')
        || request()->filled('price_min')
        || request()->filled('price_max')
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
        $sites->firstItem() ?: null,
        $sites->lastItem() ?: null
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
    $inCatalogHideMode = (bool) (auth()->user()?->inCatalogHideMode() ?? false);
    $currentUser = $currentUser ?? auth()->user();
    $favorites = $favorites ?? [];
    $blacklist = $blacklist ?? [];
@endphp
            <div class="card border-0 shadow-sm catalog-results-card" id="catalogResults" aria-live="polite"
                 tabindex="-1"
                 data-result-total="{{ (int) $resultTotal }}"
                 data-first-item="{{ (int) ($sites->firstItem() ?: 0) }}"
                 data-last-item="{{ (int) ($sites->lastItem() ?: 0) }}"
                 data-current-page="{{ (int) $sites->currentPage() }}"
                 data-last-page="{{ (int) $sites->lastPage() }}"
                 data-status-text="{{ $catalogResultsStatus['text'] }}"
                 data-status-announce="{{ $catalogResultsStatus['announce'] }}">
                <div class="catalog-results-busy" hidden aria-hidden="true">
                    <span class="catalog-results-busy__spinner"></span>
                    <span class="catalog-results-busy__label">Updating results…</span>
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
                $isOwnedByMe = (int) $site->publisher_id === (int) auth()->id();
                // Decode sensitive prices (only positive numeric add-ons are selectable)
                $sensitivePrices = $site->sensitive_prices;
                if (is_string($sensitivePrices)) {
                    $sensitivePrices = json_decode($sensitivePrices, true);
                }
                $sensitivePrices = is_array($sensitivePrices) ? $sensitivePrices : [];
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

                // List price is the advertiser-facing base (already fee-marked-up).
                // data-discount-percent keeps the nominal configured sale so JS can
                // re-apply (base + sensitive) × (1 − %) then floor — same as
                // CartPricingService. Chips / “X% off” use the effective % after
                // the publisher-payout floor so the label never oversells.
                $catalogListPrice = round((float) $site->price, 2);
                // CatalogController sets original_price to the publisher-entered base
                // before applying the portal fee markup onto $site->price.
                $catalogPublisherPrice = round((float) ($site->original_price ?? $site->price), 2);
                $catalogSalePctNominal = $site->activeCustomDiscountPercent();
                $catalogSalePct = $catalogSalePctNominal; // nominal for data-* / JS
                $catalogSalePctDisplay = null;
                $catalogSalePrice = null;
                if ($catalogSalePctNominal) {
                    $rawSale = max(0, round($catalogListPrice - round($catalogListPrice * ($catalogSalePctNominal / 100), 2), 2));
                    $flooredSale = max($catalogPublisherPrice, $rawSale);
                    if ($flooredSale < $catalogListPrice) {
                        $catalogSalePrice = $flooredSale;
                        $catalogSalePctDisplay = \App\Services\CartPricingService::effectiveDiscountPercent(
                            $catalogListPrice,
                            round($catalogListPrice - $flooredSale, 2)
                        );
                    }
                }
            @endphp
            @php
                // Dynamic "new" flag — listing created within the last 30 days
                $isNew = $site->created_at->gt(now()->subDays(30));
                // Everyday catalog shows full identity (no eye). Mask + eye only
                // while copy-strike hide mode is active (one control for name + URL).
                $showsIdentity = $urlVisibility->showsFullIdentity($currentUser, $site);
                $canSeeUrl = $showsIdentity; // reveal state inside hide mode; always true outside
                $displayHost = $urlVisibility->hostFor($currentUser, $site);
                $displayRootedUrl = $urlVisibility->rootedUrlFor($currentUser, $site);
                $displayName = $urlVisibility->nameFor($currentUser, $site);
                $identityLabel = $showsIdentity
                    ? (string) $site->site_name
                    : 'this website';
                $eyeShowLabel = 'Show site name and URL';
                $eyeHideLabel = 'Hide site name and URL';
            @endphp
            <tr class="site-row {{ $isBlacklisted ? 'blacklisted-row' : '' }}" data-id="{{ $site->id }}" data-name="{{ $displayName }}">
                
                <td class="catalog-site-cell">

                    <div class="catalog-site-stack catalog-site-stack--tiled">
                        @include('advertiser.partials.catalog-site-tile', [
                            'label' => $displayHost,
                            'size' => 'md',
                        ])

                        <div class="catalog-site-stack__body">
                        <!-- Name + Verified/NEW + actions stay on one row.
                             Rooted URL sits under the name in muted type.
                             Deal chips sit below so a sale/bulk message cannot
                             push status chips down. -->
                        <div class="catalog-site-title-row">
                            <span class="text-dark catalog-site-name"
                                  data-site-name-label
                                  @if($showsIdentity) title="{{ $displayName }}" @endif>
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
                                    @if($isNew)
                                        <button type="button"
                                                class="site-badge-new"
                                                data-glass-tip
                                                data-glass-tip-title="New Listing"
                                                data-glass-tip-body="Added in the last 30 days — fresh inventory worth reviewing early."
                                                data-glass-tip-placement="top"
                                                aria-label="New listing">
                                            NEW
                                        </button>
                                    @endif

                                    @if($site->verified)
                                        <button type="button"
                                                class="site-chip site-chip--verified"
                                                data-glass-tip
                                                data-glass-tip-title="Verified Publisher"
                                                data-glass-tip-body="This publisher has successfully completed our verification process and meets our platform's quality standards."
                                                data-glass-tip-placement="top"
                                                aria-label="Verified publisher">
                                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                            <span>Verified</span>
                                        </button>
                                    @endif
                                </span>

                                <span class="catalog-site-actions">
                                    {{-- Visit goes through our redirect so outbound
                                         clicks are logged; the rooted URL is already
                                         on the row outside hide mode. --}}
                                    <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-muted site-open-link"
                                       id="url-open-{{ $site->id }}"
                                       title="Open site in a new tab"
                                       aria-label="Open site in a new tab">
                                        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                    </a>

                                    <button type="button"
                                            class="btn btn-sm btn-link text-secondary p-0 expand-arrow catalog-details-toggle"
                                            id="arrow-{{ $site->id }}"
                                            aria-label="Show details for {{ $identityLabel }}"
                                            aria-expanded="false"
                                            aria-controls="site-details-{{ $site->id }}">
                                        <span class="catalog-details-toggle__label">Details</span>
                                        <i class="fa-solid fa-chevron-down ms-1" aria-hidden="true"></i>
                                    </button>
                                </span>
                            </span>
                        </div>

                        <div class="catalog-site-rooted-url catalog-site-url"
                             id="url-host-{{ $site->id }}"
                             data-site-host
                             title="{{ $displayRootedUrl }}"
                             @if($showsIdentity) data-host="{{ $displayHost }}" @endif
                             @if($inCatalogHideMode && ! $showsIdentity)
                                 data-glass-tip
                                 data-glass-tip-title="Name and URL hidden"
                                 data-glass-tip-body="Site name and URL are hidden for 24 hours after repeated domain copying. Open the eye to reveal both for this listing — metrics and price stay visible."
                                 data-glass-tip-placement="top"
                             @endif>{{ $displayRootedUrl }}</div>

                        @php
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
                                $packPricing = app(\App\Services\CartPricingService::class)
                                    ->priceForAdvertiser($site, null, (int) config('site_promotions.bulk.min_qty', 3));
                                $dealBulkChipPct = (float) ($packPricing['discount_percent'] ?? $dealBulkPct);
                                if ($dealBulkChipPct <= 0) {
                                    $showBulkChip = false;
                                }
                            }
                        @endphp
                        @if($site->isFeatured() || $showSaleChip || $showBulkChip)
                        <div class="catalog-site-deals">
                            @if($site->isFeatured())
                                <span class="site-chip site-chip--featured"
                                      title="Featured placement — higher visibility in the catalog">
                                    <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                                    <span>Featured</span>
                                </span>
                            @endif

                            @if($showSaleChip)
                                <span class="site-chip site-chip--sale"
                                      title="Limited-time publisher discount on each article (after fee floor)">
                                    <i class="fa-solid fa-percent" aria-hidden="true"></i>
                                    <span>−{{ rtrim(rtrim(number_format((float) $dealSaleChipPct, 1), '0'), '.') }}%</span>
                                </span>
                            @endif

                            @if($showBulkChip)
                                <span class="site-chip site-chip--bulk"
                                      title="Better rate when you buy {{ (int) config('site_promotions.bulk.min_qty', 3) }}–{{ (int) config('site_promotions.bulk.max_qty', 5) }} articles — exclusive better-of with a site sale, not stacked">
                                    <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                                    <span>Bulk −{{ rtrim(rtrim(number_format((float) $dealBulkChipPct, 1), '0'), '.') }}%</span>
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
                    onclick="
                        const wrapper = this.closest('.categories-wrapper');
                        const hiddenItems = wrapper.querySelectorAll('.extra-category');

                        hiddenItems.forEach(el => el.classList.toggle('d-none'));

                        this.innerText = this.innerText.includes('more')
                            ? 'Show less'
                            : '+{{ $totalCategories - $showLimit }} more';
                    ">
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
                        $countryCode = $site->primaryCountryCode() ?: $site->country;
                    @endphp
                    <div class="catalog-country">
                        <span class="catalog-country__flag" aria-hidden="true">{!! getCountryFlag($countryCode) !!}</span>
                        <span class="catalog-country__name text-muted small"
                              title="{{ fullCountry($countryCode) }}">{{ fullCountry($countryCode) }}</span>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell catalog-td-action">
                    <div class="catalog-row-actions">
                        @include('advertiser.partials.catalog-price', [
                            'listPrice' => $catalogListPrice,
                            'salePrice' => $catalogSalePrice,
                            'salePercent' => $catalogSalePctDisplay,
                            'align' => 'center',
                        ])

                        <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2"
                                data-id="{{ $site->id }}"
                                data-base-price="{{ $catalogListPrice }}"
                                data-publisher-price="{{ $catalogPublisherPrice }}"
                                data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                                data-name="{{ $displayName }}"
                                aria-label="Buy placement for {{ $identityLabel }}">
                            <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                            <span>Add to cart</span>
                        </button>

                        <div class="catalog-row-actions__secondary">
                            <div class="catalog-row-actions-quiet">
                                <button type="button"
                                        class="btn-icon-quiet favorite-btn {{ $isFavorited ? 'is-active' : '' }}"
                                        data-id="{{ $site->id }}"
                                        data-name="{{ $displayName }}"
                                        aria-label="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}"
                                        title="{{ $isFavorited ? 'Remove from Favorites' : 'Add to Favorites' }}">
                                    <i class="fa-{{ $isFavorited ? 'solid' : 'regular' }} fa-heart" aria-hidden="true"></i>
                                </button>

                                <button type="button"
                                        class="btn-icon-quiet blacklist-btn {{ $isBlacklisted ? 'is-active' : '' }}"
                                        data-id="{{ $site->id }}"
                                        data-name="{{ $displayName }}"
                                        aria-label="{{ $isBlacklisted ? 'Remove from blacklist' : 'Blacklist site' }}"
                                        title="{{ $isBlacklisted ? 'Remove from Blacklist' : 'Blacklist Site' }}">
                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                </button>
                            </div>

                        @unless($isOwnedByMe)
                            <button type="button"
                                    class="btn-claim-site"
                                    data-site-id="{{ $site->id }}"
                                    data-site-name="{{ $displayName }}"
                                    data-site-url="{{ $canSeeUrl ? $site->site_url : '' }}"
                                    title="Claim this website if you own it"
                                    aria-label="Claim website {{ $identityLabel }}">
                                Claim
                            </button>
                        @endunless
                        </div>
                    </div>
                </td>
            </tr>

            <tr class="expanded-row-{{ $site->id }}" id="site-details-{{ $site->id }}" style="display: none;">
    <td colspan="7" class="catalog-expand-cell">
        <div class="row">
            <div class="col-md-12">
                <h6 class="mb-3">Site Details</h6>

                {{-- Preview | Description | Pricing | Tags + sample --}}
                <div class="row align-items-start g-4 catalog-expand-grid">

                    <div class="col-lg-3 col-md-6 text-center catalog-expand-preview">
                        <p class="small text-muted mb-2"><strong>Homepage preview</strong></p>
                        @php
                            // Homepage capture first (full → thumb), then admin/marketing upload.
                            // Matches Site::screenshot_* accessors so expand previews stay filled.
                            $previewUrl = $site->screenshot_url ?: $site->screenshot_thumb_url;
                        @endphp
                        @if($previewUrl)
                            <div class="site-preview-zoom">
                                {{-- Eager: Safari often never loads loading=lazy images that start inside display:none expand rows. --}}
                                <img src="{{ $previewUrl }}"
                                     alt="{{ $identityLabel }} homepage preview"
                                     loading="eager"
                                     decoding="async"
                                     class="site-image-thumbnail"
                                     onerror="this.onerror=null;var z=this.closest('.site-preview-zoom');if(z){z.classList.add('is-broken');var f=z.nextElementSibling;if(f){f.classList.remove('d-none');f.classList.add('d-inline-flex');}}">
                            </div>
                            <div class="site-preview-fallback bg-light border rounded d-none flex-column align-items-center justify-content-center gap-2 px-3" aria-hidden="true">
                                <i class="fa-solid fa-image text-muted" style="font-size: 28px;" aria-hidden="true"></i>
                                <span class="small text-muted">Screenshot not available yet</span>
                            </div>
                        @else
                            <div class="site-preview-fallback bg-light border rounded d-inline-flex flex-column align-items-center justify-content-center gap-2 px-3" role="img" aria-label="Screenshot not available yet">
                                <i class="fa-solid fa-image text-muted" style="font-size: 28px;" aria-hidden="true"></i>
                                <span class="small text-muted">Screenshot not available yet</span>
                            </div>
                        @endif
                    </div>

                    <div class="col-lg-4 col-md-6 catalog-expand-description">
                        <p class="mb-1"><strong class="small">Description</strong></p>
                        <div class="text-muted small">
                            {!! $site->safeDescriptionHtml() !!}
                        </div>
                        @if($site->lastPublicationLabel())
                            <p class="text-muted small mb-0 mt-1" style="color:#94a3b8 !important;">
                                {{ $site->lastPublicationLabel() }}
                            </p>
                        @endif

                        @include('advertiser.partials.catalog-site-trust', ['site' => $site])
                    </div>

                    <div class="col-lg-3 col-md-6 catalog-expand-pricing">
                        <div class="d-flex flex-column gap-2">
                            @if(!empty($sensitivePrices))
                                <p class="mb-0"><strong>Sensitive topics</strong></p>
                                <p class="small text-muted mb-1">Additional charge on top of the base price.</p>

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
                                               data-total-price="{{ $catalogSalePrice ?? $catalogListPrice }}"
                                               data-site-id="{{ $site->id }}"
                                               id="sensitive_{{ $site->id }}_none"
                                               checked>
                                        <label class="form-check-label" for="sensitive_{{ $site->id }}_none">
                                            <strong>No sensitive topic</strong>
                                            <span class="text-muted">€{{ number_format($catalogSalePrice ?? $catalogListPrice, 2) }}</span>
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
                                                <span class="text-danger">+€{{ number_format($additionalPrice, 2) }}</span>
                                                <span class="text-muted">→ €{{ number_format($totalPrice, 2) }}</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="selected-price-info mt-1"
                                     id="price-info-{{ $site->id }}">
                                    <small class="text-muted">
                                        You pay:
                                        <strong>€{{ number_format($catalogSalePrice ?? $catalogListPrice, 2) }}</strong>
                                        @if($catalogSalePrice !== null)
                                            <span class="text-decoration-line-through">€{{ number_format($catalogListPrice, 2) }}</span>
                                            (offer price)
                                        @else
                                            (base price)
                                        @endif
                                    </small>
                                </div>
                            @endif

                            @if($homepageOptions !== [])
                                <p class="{{ !empty($sensitivePrices) ? 'mt-2' : '' }} mb-1"><strong>Homepage placement</strong> <span class="text-muted fw-normal">(optional)</span></p>
                                <p class="small text-muted mb-2">Put the article on the publisher homepage for a set duration. Sale/bulk discounts do not apply to this fee.</p>
                                <div class="homepage-placement-group"
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
                                                    <span class="text-muted">+€{{ number_format($fee, 2) }}</span>
                                                @endif
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if($socialChannels !== [])
                                <p class="mt-2 mb-1"><strong>Social promotion included</strong></p>
                                <p class="small text-muted mb-2">Publisher will share the live post on these channels at no extra cost.</p>
                                <div class="d-flex flex-wrap gap-1" aria-label="Included social channels">
                                    @foreach($socialChannels as $channel)
                                        <span class="badge bg-light text-dark border">{{ $socialChannelLabels[$channel] ?? ucfirst($channel) }}</span>
                                    @endforeach
                                </div>
                            @endif

                            @if(empty($sensitivePrices) && $homepageOptions === [] && $socialChannels === [])
                                <p class="small text-muted mb-0">No extra pricing options for this listing.</p>
                            @endif
                        </div>
                    </div>

                    <div class="col-lg-2 col-md-6 catalog-expand-meta">
                        <p class="mb-1"><strong>Tags</strong></p>
                        <div class="d-flex flex-column gap-2 mb-3">
                            <div>
                                @if($site->linkTypeLabel())
                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Link type">
                                        <i class="fa-solid fa-link me-1" aria-hidden="true"></i>{{ $site->linkTypeLabel() }}
                                    </span>
                                @else
                                    <span class="text-muted small">No link type specified</span>
                                @endif
                            </div>

                            <div class="d-flex flex-wrap gap-1">
                                @if($site->sponsored)
                                    <span class="badge bg-warning-subtle text-dark border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Sponsored placement">
                                        <i class="fa-solid fa-star me-1" aria-hidden="true"></i>Sponsored
                                    </span>
                                @endif

                                @if($site->partner_material)
                                    <span class="badge bg-success-subtle text-success border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Partner content allowed">
                                        <i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>Partner
                                    </span>
                                @endif

                                @if($site->as_you_prefer ?? false)
                                    <span class="badge bg-primary-subtle text-primary border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Flexible placement">
                                        <i class="fa-solid fa-sliders-h me-1" aria-hidden="true"></i>As You Prefer
                                    </span>
                                @endif

                                @if(!$site->sponsored && !$site->partner_material && !($site->as_you_prefer ?? false))
                                    <span class="text-muted small">No additional tags</span>
                                @endif
                            </div>
                        </div>

                        <p class="mb-1"><strong>Sample article</strong></p>
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
                                @php
                                    $sampleUrl = safe_external_url($site->example_url);
                                @endphp
                                @if($sampleUrl !== '#')
                                    <div class="d-flex align-items-center gap-2">
                                        <a href="{{ $sampleUrl }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-decoration-none"
                                           style="word-break: break-all;">
                                            {{ Str::limit($site->example_url, 50) }}
                                        </a>
                                        <a href="{{ $sampleUrl }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-muted d-inline-flex align-items-center"
                                           title="Open sample article"
                                           aria-label="Open the sample article for {{ $identityLabel }} in a new tab">
                                            <i class="fa-solid fa-arrow-up-right-from-square"
                                               style="font-size: 13px;" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary copy-example-url"
                                            data-url="{{ $site->example_url }}"
                                            aria-label="Copy the sample article URL for {{ $identityLabel }}"
                                            style="width: fit-content;">
                                        <i class="fa-regular fa-copy" aria-hidden="true"></i> Copy URL
                                    </button>
                                @else
                                    <span class="text-muted small">No sample article yet</span>
                                @endif
                            @endif
                        </div>

                        <p class="mb-1"><strong title="How long the published article stays live">Publication duration</strong></p>
                        @if($site->publicationDurationLabel())
                            <span class="badge text-muted border px-2 py-1"
                                  style="font-size: 11px;"
                                  title="How long the published article stays live">
                                <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
                                {{ $site->publicationDurationLabel() }}
                            </span>
                        @else
                            <span class="text-muted small">Not specified</span>
                        @endif
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
                                        data-search="{{ request('search') }}">
                                    <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
                                </button>
                            </div>
                            <p class="small text-muted mb-0">
                                Can’t find a site you need?
                                @if(request('search'))
                                    Suggest “{{ request('search') }}” and we’ll try to add it.
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
            $isOwnedByMe = (int) $site->publisher_id === (int) auth()->id();
            $isNew = $site->created_at->gt(now()->subDays(30));
            $showsIdentity = $urlVisibility->showsFullIdentity($currentUser, $site);
            $canSeeUrl = $showsIdentity;
            $displayHost = $urlVisibility->hostFor($currentUser, $site);
            $displayRootedUrl = $urlVisibility->rootedUrlFor($currentUser, $site);
            $displayName = $urlVisibility->nameFor($currentUser, $site);
            $identityLabel = $showsIdentity
                ? (string) $site->site_name
                : 'this website';
            $eyeShowLabel = 'Show site name and URL';
            $eyeHideLabel = 'Hide site name and URL';
            $mobileLabels = $site->nicheBadgeLabels();
            $mobileCategory = $mobileLabels[0] ?? '—';
            $mobileSensitivePrices = $site->sensitive_prices;
            if (is_string($mobileSensitivePrices)) {
                $mobileSensitivePrices = json_decode($mobileSensitivePrices, true);
            }
            $mobileSensitivePrices = is_array($mobileSensitivePrices) ? $mobileSensitivePrices : [];
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
            $catalogListPrice = round((float) $site->price, 2);
            $catalogPublisherPrice = round((float) ($site->original_price ?? $site->price), 2);
            $catalogSalePctNominal = $site->activeCustomDiscountPercent();
            $catalogSalePct = $catalogSalePctNominal; // nominal for data-* / JS
            $catalogSalePctDisplay = null;
            $catalogSalePrice = null;
            if ($catalogSalePctNominal) {
                $rawSale = max(0, round($catalogListPrice - round($catalogListPrice * ($catalogSalePctNominal / 100), 2), 2));
                $flooredSale = max($catalogPublisherPrice, $rawSale);
                if ($flooredSale < $catalogListPrice) {
                    $catalogSalePrice = $flooredSale;
                    $catalogSalePctDisplay = \App\Services\CartPricingService::effectiveDiscountPercent(
                        $catalogListPrice,
                        round($catalogListPrice - $flooredSale, 2)
                    );
                }
            }
        @endphp
        <article class="catalog-mobile-card {{ $isBlacklisted ? 'is-blacklisted' : '' }}" data-id="{{ $site->id }}" data-name="{{ $displayName }}">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="catalog-mobile-card__host d-flex align-items-start gap-2">
                    @include('advertiser.partials.catalog-site-tile', [
                        'label' => $displayHost,
                        'size' => 'lg',
                    ])

                    <div class="catalog-mobile-card__main">
                    <div class="d-flex align-items-center gap-2">
                        <div class="fw-semibold text-dark text-truncate catalog-site-name"
                             data-site-name-label
                             @if($showsIdentity) title="{{ $displayName }}" @endif>{{ $displayName }}</div>
                        <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                           target="_blank" rel="noopener noreferrer"
                           class="text-muted small"
                           title="Open site in a new tab" aria-label="Open site in a new tab">
                            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                        </a>
                    </div>
                    {{-- data-host only when identity is shown. Hide-mode tip only
                         while the row is still masked. --}}
                    <div class="catalog-site-rooted-url catalog-site-url text-truncate"
                         id="url-host-mobile-{{ $site->id }}"
                         data-site-host
                         title="{{ $displayRootedUrl }}"
                         @if($showsIdentity) data-host="{{ $displayHost }}" @endif
                         @if($inCatalogHideMode && ! $showsIdentity)
                             data-glass-tip
                             data-glass-tip-title="Name and URL hidden"
                             data-glass-tip-body="Site name and URL are hidden for 24 hours after repeated domain copying. Open the eye to reveal both for this listing — metrics and price stay visible."
                             data-glass-tip-placement="top"
                         @endif>{{ $displayRootedUrl }}</div>
                    <div class="catalog-site-badges catalog-site-badges--mobile mt-1">
                        @if($site->verified)
                            <span class="site-chip site-chip--verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Verified</span></span>
                        @endif
                        @if($isNew)
                            <span class="site-badge-new" aria-label="New listing">NEW</span>
                        @endif
                        @php
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
                                $mobilePackPricing = app(\App\Services\CartPricingService::class)
                                    ->priceForAdvertiser($site, null, (int) config('site_promotions.bulk.min_qty', 3));
                                $mobileBulkChipPct = (float) ($mobilePackPricing['discount_percent'] ?? $mobileBulkPct);
                                if ($mobileBulkChipPct <= 0) {
                                    $showMobileBulkChip = false;
                                }
                            }
                        @endphp
                        @if($showMobileSaleChip)
                            <span class="site-chip site-chip--sale" title="Limited-time publisher discount on each article (after fee floor)">
                                <i class="fa-solid fa-percent" aria-hidden="true"></i>
                                <span>−{{ rtrim(rtrim(number_format((float) $mobileSaleChipPct, 1), '0'), '.') }}%</span>
                            </span>
                        @endif
                        @if($showMobileBulkChip)
                            <span class="site-chip site-chip--bulk"
                                  title="Better rate when you buy {{ (int) config('site_promotions.bulk.min_qty', 3) }}–{{ (int) config('site_promotions.bulk.max_qty', 5) }} articles — exclusive better-of with a site sale, not stacked">
                                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                                <span>Bulk −{{ rtrim(rtrim(number_format((float) $mobileBulkChipPct, 1), '0'), '.') }}%</span>
                            </span>
                        @endif
                        <span class="category-badge">{{ $mobileCategory }}</span>
                    </div>
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
                $mobileCountry = $site->primaryCountryCode() ?: $site->country;
                $mobileCountryName = fullCountry($mobileCountry);
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
                    <strong title="{{ $mobileCountryName }}">{!! getCountryFlag($mobileCountry) !!} {{ $mobileCountryName }}</strong>
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
                    <div class="small fw-semibold mb-1">Additional charges</div>
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
                               data-total-price="{{ $catalogSalePrice ?? $catalogListPrice }}"
                               data-site-id="{{ $site->id }}"
                               id="sensitive_mobile_{{ $site->id }}_none"
                               checked>
                        <label class="form-check-label" for="sensitive_mobile_{{ $site->id }}_none">
                            <strong>No sensitive topic</strong>
                            <span class="text-muted">€{{ number_format($catalogSalePrice ?? $catalogListPrice, 2) }}</span>
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
                                <span class="text-danger">+€{{ number_format($additionalPrice, 2) }}</span>
                                <span class="text-muted">→ €{{ number_format($totalPrice, 2) }}</span>
                            </label>
                        </div>
                    @endforeach
                    {{-- Rendered server-side like the table's copy. It used to be
                         an empty div until the shopper touched a radio. --}}
                    <div class="selected-price-info mt-1" id="price-info-mobile-{{ $site->id }}">
                        <small class="text-muted">
                            Current price:
                            <strong>€{{ number_format($catalogSalePrice ?? $catalogListPrice, 2) }}</strong>
                            @if($catalogSalePrice !== null)
                                <span class="text-decoration-line-through">€{{ number_format($catalogListPrice, 2) }}</span>
                                (offer price)
                            @else
                                (Base price)
                            @endif
                        </small>
                    </div>
                </div>
            @endif
            @if($homepageOptions !== [])
                <div class="homepage-placement-group mt-3"
                     data-site-id="{{ $site->id }}"
                     role="radiogroup"
                     aria-label="Homepage placement duration">
                    <div class="small fw-semibold mb-1">Homepage placement (optional)</div>
                    <p class="small text-muted mb-2">Sale/bulk discounts do not apply to this fee.</p>
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
                                    <span class="text-muted">+€{{ number_format($fee, 2) }}</span>
                                @endif
                            </label>
                        </div>
                    @endforeach
                </div>
            @endif
            @if($socialChannels !== [])
                <div class="mt-3">
                    <div class="small fw-semibold mb-1">Social promotion included</div>
                    <div class="d-flex flex-wrap gap-1" aria-label="Included social channels">
                        @foreach($socialChannels as $channel)
                            <span class="badge bg-light text-dark border">{{ $socialChannelLabels[$channel] ?? ucfirst($channel) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="catalog-card-buy">
                @include('advertiser.partials.catalog-price', [
                    'listPrice' => $catalogListPrice,
                    'salePrice' => $catalogSalePrice,
                    'salePercent' => $catalogSalePctDisplay,
                    'align' => 'start',
                ])

                <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2"
                        data-id="{{ $site->id }}"
                        data-base-price="{{ $catalogListPrice }}"
                        data-publisher-price="{{ $catalogPublisherPrice }}"
                        data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                        data-name="{{ $displayName }}"
                        aria-label="Buy placement for {{ $identityLabel }}">
                    <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                    <span>Add to cart</span>
                </button>
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
                                aria-label="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}">
                            <i class="fa-{{ $isFavorited ? 'solid' : 'regular' }} fa-heart" aria-hidden="true"></i>
                        </button>
                        <button type="button"
                                class="btn-icon-quiet blacklist-btn {{ $isBlacklisted ? 'is-active' : '' }}"
                                data-id="{{ $site->id }}"
                                data-name="{{ $displayName }}"
                                aria-label="{{ $isBlacklisted ? 'Remove from blacklist' : 'Blacklist site' }}">
                            <i class="fa-solid fa-ban" aria-hidden="true"></i>
                        </button>
                    </div>
                    @unless($isOwnedByMe)
                        <button type="button"
                                class="btn-claim-site"
                                data-site-id="{{ $site->id }}"
                                data-site-name="{{ $displayName }}"
                                data-site-url="{{ $canSeeUrl ? $site->site_url : '' }}"
                                title="Claim this website if you own it"
                                aria-label="Claim website {{ $identityLabel }}">
                            Claim
                        </button>
                    @endunless
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
                @php
                    $mobilePreviewUrl = $site->screenshot_url ?: $site->screenshot_thumb_url;
                @endphp
                <div class="catalog-card-details__row">
                    <dt>Homepage preview</dt>
                    <dd>
                        @if($mobilePreviewUrl)
                            <div class="site-preview-zoom catalog-card-preview">
                                <img class="catalog-deferred-preview site-image-thumbnail"
                                     data-src="{{ $mobilePreviewUrl }}"
                                     alt="{{ $identityLabel }} homepage preview"
                                     decoding="async"
                                     onerror="this.onerror=null;var z=this.closest('.site-preview-zoom');if(z){z.classList.add('is-broken');var f=z.nextElementSibling;if(f){f.classList.remove('d-none');f.classList.add('d-inline-flex');}}">
                            </div>
                            <div class="site-preview-fallback bg-light border rounded d-none flex-column align-items-center justify-content-center gap-2 px-3" aria-hidden="true">
                                <i class="fa-solid fa-image text-muted" style="font-size: 24px;" aria-hidden="true"></i>
                                <span class="small text-muted">Screenshot not available yet</span>
                            </div>
                        @else
                            <span class="text-muted small">Screenshot not available yet</span>
                        @endif
                    </dd>
                </div>
                <div class="catalog-card-details__row">
                    <dt>Trust</dt>
                    <dd>@include('advertiser.partials.catalog-site-trust', ['site' => $site, 'compactClass' => ''])</dd>
                </div>
                <div class="catalog-card-details__row">
                    <dt>Turnaround</dt>
                    <dd>{{ $site->turnaroundLabel('Not specified') }}</dd>
                </div>
                <div class="catalog-card-details__row">
                    <dt>Publication duration</dt>
                    <dd>{{ $site->publicationDurationLabel('Not specified') }}</dd>
                </div>
                <div class="catalog-card-details__row">
                    <dt>Link type</dt>
                    <dd>{{ $site->linkTypeLabel('Not specified') }}</dd>
                </div>
                <div class="catalog-card-details__row">
                    <dt>Tags</dt>
                    <dd class="d-flex flex-wrap gap-1">
                        @if($site->sponsored)
                            <span class="badge bg-warning-subtle text-dark border">Sponsored</span>
                        @endif
                        @if($site->partner_material)
                            <span class="badge bg-success-subtle text-success border">Partner</span>
                        @endif
                        @if($site->as_you_prefer ?? false)
                            <span class="badge bg-primary-subtle text-primary border">As You Prefer</span>
                        @endif
                        @if(!$site->sponsored && !$site->partner_material && !($site->as_you_prefer ?? false))
                            <span class="text-muted small">No additional tags</span>
                        @endif
                    </dd>
                </div>
                @if($site->description)
                    <div class="catalog-card-details__row">
                        <dt>About this site</dt>
                        <dd class="catalog-card-details__description text-muted small">
                            {!! $site->safeDescriptionHtml() !!}
                        </dd>
                    </div>
                @endif
                @if($socialChannels !== [])
                    <div class="catalog-card-details__row">
                        <dt>Social promotion</dt>
                        <dd class="d-flex flex-wrap gap-1">
                            @foreach($socialChannels as $channel)
                                <span class="badge bg-light text-dark border">{{ $socialChannelLabels[$channel] ?? ucfirst($channel) }}</span>
                            @endforeach
                        </dd>
                    </div>
                @endif
                <div class="catalog-card-details__row">
                    <dt>Sample article</dt>
                    <dd>
                        {{-- Sample shares the listing domain — gate on identity. --}}
                        @if($inCatalogHideMode && ! $showsIdentity)
                            Use the eye to show this listing’s name and URL, then the sample article link appears.
                        @elseif($site->example_url && ($mobileSampleUrl = safe_external_url($site->example_url)) !== '#')
                            <a href="{{ $mobileSampleUrl }}" target="_blank" rel="noopener noreferrer">
                                {{ Str::limit($site->example_url, 46) }}
                            </a>
                        @else
                            No sample article yet
                        @endif
                    </dd>
                </div>
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
                            data-search="{{ request('search') }}">
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
