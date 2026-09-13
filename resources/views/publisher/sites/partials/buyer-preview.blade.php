{{-- Read-only advertiser catalog preview for My Sites (View expand). --}}
@php
    $buyerPrices = $site->catalogPricesForViewer(null);
    $buyerHomepageOptions = $site->homepagePlacementOptions();
    $buyerDefaultHomepageDays = $site->longestFreeHomepageDays();
    $buyerSocialChannels = $site->enabledSocialChannels();
    $buyerChecklist = class_exists(\App\Support\CatalogBuyerReadiness::class)
        ? \App\Support\CatalogBuyerReadiness::checklist($site)
        : [];
    $buyerViewer = auth()->user();
    $buyerCanOpenCatalog = (bool) $buyerViewer?->hasRole('advertiser');
    $buyerCatalogUrl = route('advertiser.catalog', ['site' => $site->id]);
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
        Trust, chips, and the price advertisers pay — not your list-only view.
    </p>

    <div class="mysites-buyer-preview__signals">
        @include('advertiser.partials.catalog-site-trust', ['site' => $site, 'compactClass' => ''])
        @include('advertiser.partials.catalog-tag-chip', ['site' => $site, 'showNone' => true])
        @include('advertiser.partials.catalog-placement-chips', [
            'homepageOptions' => $buyerHomepageOptions,
            'defaultHomepageDays' => $buyerDefaultHomepageDays,
            'socialChannels' => $buyerSocialChannels,
        ])
        @include('advertiser.partials.catalog-price', [
            'listPrice' => (float) $buyerPrices['list'],
            'salePrice' => $buyerPrices['sale'],
            'salePercent' => $buyerPrices['sale_percent'],
            'align' => 'start',
        ])
    </div>
    @if(! $site->hasCatalogCover())
        <p class="mysites-buyer-preview__staff-note text-muted small mb-2">
            Homepage screenshot is added by staff. You do not need to upload one.
        </p>
    @endif

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
