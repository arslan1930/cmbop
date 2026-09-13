{{-- Read-only advertiser catalog row + Site Details for My Sites (View expand). --}}
@php
    $buyerPrices = $site->catalogPricesForViewer(null);
    $buyerListPrice = (float) $buyerPrices['list'];
    $buyerSalePrice = $buyerPrices['sale'];
    $buyerSalePct = $buyerPrices['sale_percent'];
    $buyerPay = $buyerSalePrice ?? $buyerListPrice;
    $buyerHomepageOptions = $site->homepagePlacementOptions();
    $buyerDefaultHomepageDays = $site->longestFreeHomepageDays();
    $buyerSocialChannels = $site->enabledSocialChannels();
    $buyerSocialLabels = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X',
    ];
    $buyerChecklist = class_exists(\App\Support\CatalogBuyerReadiness::class)
        ? \App\Support\CatalogBuyerReadiness::checklist($site)
        : [];
    $buyerViewer = auth()->user();
    $buyerCanOpenCatalog = (bool) $buyerViewer?->hasRole('advertiser');
    $buyerCatalogUrl = route('advertiser.catalog', ['site' => $site->id]);
    $urlVisibility = app(\App\Services\Catalog\SiteUrlVisibility::class);
    $displayName = (string) $site->site_name;
    $displayHost = $urlVisibility->host($site->site_url) ?: (string) ($site->domain ?? '');
    $displayRootedUrl = $urlVisibility->rootedUrl($site->site_url) ?: (string) ($site->site_url ?? '');
    $identityLabel = $displayName !== '' ? $displayName : 'this website';
    $buyerNiches = $site->nicheBadgeLabels();
    $buyerCountry = $site->primaryCountryCode() ?: $site->country;
    $buyerIsNew = $site->isRecentlyCreated();
    $previewPaths = $site->homepagePreviewUrlChain();
    $previewUrl = $previewPaths[0] ?? null;
    $sensitivePrices = is_array($site->sensitive_prices) ? $site->sensitive_prices : [];
    $hasSensitiveExtras = $sensitivePrices !== [];
    $hasPlacementExtras = $buyerHomepageOptions !== [] || $buyerSocialChannels !== [];
    $hasListingExtras = $hasSensitiveExtras || $hasPlacementExtras;
    $expandDescriptionHtml = $site->catalogDescriptionHtml();
    $hasExpandDescription = trim(strip_tags($expandDescriptionHtml)) !== '';
    $sampleUrl = safe_external_url($site->example_url);
@endphp
<section class="mysites-buyer-preview" aria-labelledby="buyer-preview-{{ $site->id }}">
    <div class="mysites-buyer-preview__head">
        <h3 class="mysites-buyer-preview__title" id="buyer-preview-{{ $site->id }}">How advertisers see this</h3>
        @if($buyerCanOpenCatalog)
            <a href="{{ $buyerCatalogUrl }}" class="mysites-buyer-preview__catalog-link">
                Open in catalog
            </a>
        @endif
    </div>
    <p class="mysites-buyer-preview__lede text-muted small mb-2">
        Catalog row and Site Details — including the homepage screenshot buyers see.
    </p>

    <div class="mysites-catalog-preview catalog-page" aria-hidden="false">
        <div class="mysites-catalog-preview__headrow" aria-hidden="true">
            <span>Site</span>
            <span>Category</span>
            <span>Traffic</span>
            <span>DR</span>
            <span>DA</span>
            <span>Country</span>
            <span>Buy</span>
        </div>

        <div class="mysites-catalog-preview__row">
            <div class="mysites-catalog-preview__site">
                <div class="catalog-site-stack catalog-site-stack--tiled">
                    @include('advertiser.partials.catalog-site-tile', [
                        'label' => $displayHost,
                        'size' => 'md',
                    ])
                    <div class="catalog-site-stack__body">
                        <div class="catalog-site-title-row">
                            <span class="text-dark catalog-site-name">{{ $displayName }}</span>
                            <span class="catalog-site-badges">
                                @if($buyerIsNew)
                                    <span class="site-badge-new">NEW</span>
                                @endif
                                @if($site->verified)
                                    <span class="site-chip site-chip--verified site-chip--status">
                                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                        <span>Verified</span>
                                    </span>
                                @else
                                    @include('advertiser.partials.catalog-staff-reviewed-chip', ['site' => $site])
                                @endif
                            </span>
                        </div>
                        <div class="catalog-site-identity">
                            <span class="catalog-site-rooted-url catalog-site-url">{{ $displayRootedUrl }}</span>
                            <span class="catalog-site-status-row">
                                @include('advertiser.partials.catalog-tag-chip', ['site' => $site])
                            </span>
                        </div>
                        @if($buyerHomepageOptions !== [] || $buyerSocialChannels !== [] || $site->isFeatured())
                        <div class="catalog-site-deals">
                            @if($site->isFeatured())
                                <span class="site-chip site-chip--featured site-chip--descriptor">
                                    <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                                    <span>Featured</span>
                                </span>
                            @endif
                            @include('advertiser.partials.catalog-placement-chips', [
                                'homepageOptions' => $buyerHomepageOptions,
                                'defaultHomepageDays' => $buyerDefaultHomepageDays,
                                'socialChannels' => $buyerSocialChannels,
                                'socialChannelLabels' => $buyerSocialLabels,
                            ])
                        </div>
                        @endif
                        @include('advertiser.partials.catalog-meta-chips', ['site' => $site])
                        @include('advertiser.partials.catalog-site-trust', ['site' => $site, 'compactClass' => 'catalog-site-trust--row mt-1'])
                    </div>
                </div>
            </div>

            <div class="mysites-catalog-preview__stat">
                @if($buyerNiches !== [])
                    <div class="categories-wrapper">
                        <div class="categories-column">
                            @foreach(array_slice($buyerNiches, 0, 2) as $cat)
                                <span class="category-badge">{{ $cat }}</span>
                            @endforeach
                        </div>
                    </div>
                @else
                    <span class="text-muted small">No niches</span>
                @endif
            </div>
            <div class="mysites-catalog-preview__stat">
                @include('advertiser.partials.catalog-metric', ['type' => 'traffic', 'value' => $site->traffic, 'inline' => false])
            </div>
            <div class="mysites-catalog-preview__stat">
                @include('advertiser.partials.catalog-metric', ['type' => 'dr', 'value' => $site->dr, 'inline' => false])
            </div>
            <div class="mysites-catalog-preview__stat">
                @include('advertiser.partials.catalog-metric', ['type' => 'da', 'value' => $site->da, 'inline' => false])
            </div>
            <div class="mysites-catalog-preview__stat">
                <div class="catalog-country">
                    <span class="catalog-country__flag" aria-hidden="true">{!! getCountryFlag($buyerCountry) !!}</span>
                    <span class="catalog-country__name text-muted small">{{ fullCountry($buyerCountry) }}</span>
                </div>
            </div>
            <div class="mysites-catalog-preview__buy">
                @include('advertiser.partials.catalog-price', [
                    'listPrice' => $buyerListPrice,
                    'salePrice' => $buyerSalePrice,
                    'salePercent' => $buyerSalePct,
                    'align' => 'center',
                ])
                <button type="button" class="btn btn-sm btn-primary d-inline-flex justify-content-center align-items-center gap-2" disabled tabindex="-1">
                    <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                    <span>Add to cart</span>
                </button>
            </div>
        </div>

        <div class="catalog-expand-cell mysites-catalog-preview__details">
            <h6 class="mb-3">Site Details</h6>
            @include('advertiser.partials.catalog-placeholder-warning', ['site' => $site])

            <div class="row align-items-start g-4 catalog-expand-grid">
                <div class="col-lg-3 col-md-6 catalog-expand-preview">
                    <p class="small text-muted mb-2"><strong>Homepage preview</strong></p>
                    @if($previewUrl)
                        <div class="site-preview-zoom" role="img" aria-label="{{ $identityLabel }} homepage preview">
                            <img src="{{ $previewUrl }}"
                                 alt="{{ $identityLabel }} homepage preview"
                                 decoding="async"
                                 class="site-image-thumbnail"
                                 data-preview-chain="{{ json_encode($previewPaths, JSON_UNESCAPED_SLASHES) }}"
                                 data-preview-i="0"
                                 onerror="var z=this.closest('.site-preview-zoom');if(z){z.classList.add('is-broken');}">
                        </div>
                        <div class="site-preview-fallback bg-light border rounded d-none flex-column align-items-center justify-content-center gap-2 px-3" aria-hidden="true">
                            <i class="fa-solid fa-image text-muted" style="font-size: 28px;" aria-hidden="true"></i>
                            <span class="small text-muted">No cover yet</span>
                        </div>
                    @else
                        @include('advertiser.partials.catalog-cover-empty', [
                            'label' => $displayHost,
                            'size' => 'lg',
                        ])
                    @endif
                    @if(! $site->hasCatalogCover())
                        <p class="mysites-buyer-preview__staff-note text-muted small mb-0 mt-2">
                            Staff will add a homepage screenshot. You do not need to upload one.
                        </p>
                    @endif
                </div>

                <div class="col-lg-3 col-md-6 catalog-expand-description">
                    <p class="mb-1"><strong class="small">Description</strong></p>
                    <div class="text-muted small">
                        @if($hasExpandDescription)
                            {!! $expandDescriptionHtml !!}
                        @else
                            <span>No description yet</span>
                        @endif
                    </div>
                    @unless($hasListingExtras)
                        <p class="text-muted small mb-0 mt-2">Base guest post only — no homepage, social, or sensitive add-ons.</p>
                    @endunless
                    <div class="catalog-expand-trust mt-3">
                        <p class="mb-1"><strong class="small">Publisher trust</strong></p>
                        @include('advertiser.partials.catalog-site-trust', ['site' => $site, 'compactClass' => ''])
                    </div>
                </div>

                <div class="col-lg-3 col-md-6 catalog-expand-pricing">
                    <small class="text-muted">
                        You pay:
                        <strong>€{{ number_format((float) $buyerPay, 2) }}</strong>
                        @if($buyerSalePrice !== null)
                            <span class="text-decoration-line-through">€{{ number_format($buyerListPrice, 2) }}</span>
                            (offer price)
                        @else
                            (base price)
                        @endif
                    </small>
                    @if($hasSensitiveExtras)
                        <p class="mb-1 mt-3"><strong>Sensitive topics</strong></p>
                        <p class="small text-muted mb-1">Additional charge on top of the base price.</p>
                        <ul class="list-unstyled small mb-0">
                            @foreach($sensitivePrices as $type => $additionalPrice)
                                <li>
                                    {{ ucfirst((string) $type) }}
                                    <span class="text-danger">add-on +€{{ number_format((float) $additionalPrice, 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="col-lg-3 col-md-6 catalog-expand-meta">
                    <p class="mb-1"><strong>Link type</strong></p>
                    <div class="mb-3">
                        @if($site->linkTypeLabel())
                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1" style="font-size: 11px;">
                                <i class="fa-solid fa-link me-1" aria-hidden="true"></i>{{ $site->linkTypeLabel() }}
                            </span>
                        @else
                            <span class="text-muted small">No link type specified</span>
                        @endif
                    </div>

                    <p class="mb-1"><strong>{{ \App\Support\SiteTag::DETAILS_HEADING }}</strong></p>
                    <div class="mb-3">
                        @include('advertiser.partials.catalog-tag-chip', [
                            'site' => $site,
                            'showNone' => true,
                            'showDefinition' => true,
                        ])
                    </div>

                    <p class="mb-1"><strong>Homepage promotions</strong></p>
                    @if($buyerHomepageOptions !== [])
                        <ul class="list-unstyled small mb-3">
                            @foreach($buyerHomepageOptions as $days => $fee)
                                <li>
                                    {{ $days }} day{{ $days > 1 ? 's' : '' }}
                                    @if((float) $fee <= 0)
                                        — <span class="text-success">Free</span>
                                    @else
                                        — add-on +€{{ number_format((float) $fee, 2) }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small text-muted mb-3">Not offered on this listing.</p>
                    @endif

                    <p class="mb-1"><strong>Social</strong></p>
                    @if($buyerSocialChannels !== [])
                        <div class="d-flex flex-wrap gap-1 mb-3">
                            @foreach($buyerSocialChannels as $channel)
                                <span class="badge bg-light text-dark border">{{ $buyerSocialLabels[$channel] ?? ucfirst($channel) }}</span>
                            @endforeach
                        </div>
                    @else
                        <p class="small text-muted mb-3">No social sharing included on this listing.</p>
                    @endif

                    <p class="mb-1"><strong>Sample article</strong></p>
                    @if($sampleUrl !== '#')
                        <span class="text-muted small" style="word-break: break-all;">{{ \Illuminate\Support\Str::limit((string) $site->example_url, 50) }}</span>
                    @else
                        <span class="text-muted small">No sample article yet</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($buyerChecklist !== [])
        <h4 class="mysites-buyer-preview__checklist-title">Listing checklist</h4>
        <ul class="mysites-buyer-preview__checklist">
            @foreach($buyerChecklist as $item)
                <li class="mysites-buyer-preview__item {{ $item['ok'] ? 'is-ok' : 'is-gap' }}">
                    <span class="mysites-buyer-preview__mark" aria-hidden="true">
                        <i class="fa-solid {{ $item['ok'] ? 'fa-circle-check' : 'fa-circle-exclamation' }}"></i>
                    </span>
                    <span>
                        <strong>{{ $item['label'] }}</strong>
                        @if(! $item['ok'])
                            <span class="mysites-buyer-preview__hint">{{ $item['hint'] }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
