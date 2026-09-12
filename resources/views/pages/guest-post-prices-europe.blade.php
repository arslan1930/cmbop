@php
    /** @var array{generated_at: ?string, europe: array{median: ?float, listings: int}, countries: list<array{code: string, name: string, median: float, listings: int, lander_url: ?string}>, has_index: bool} $index */
    $index = $index ?? [
        'generated_at' => null,
        'europe' => ['median' => null, 'listings' => 0],
        'countries' => [],
        'has_index' => false,
    ];
    $canonical = url('/guest-post-prices-europe');
    $metaTitle = 'Guest Post Prices in Europe — Median by Country | SEOLinkBuildings';
    $metaDescription = 'Median guest post prices in Europe by publisher country, calculated from the live SEOLinkBuildings catalog. Advertiser checkout prices — not quotes or bids.';
    $faqs = [
        [
            'q' => 'What does the EU guest-post price index measure?',
            'a' => 'The median advertiser checkout price of catalog-visible publisher listings whose primary country is in Europe. That is the publisher list price plus the platform fee — the same number you see after you register.',
        ],
        [
            'q' => 'Why do some European countries not appear?',
            'a' => 'A country row needs at least three live, verified listings. Thin markets stay off the table rather than showing a one-site “median”. The Europe figure uses every European listing, and only appears once that sample also reaches three.',
        ],
        [
            'q' => 'Is this what I will pay for a guest post in Europe?',
            'a' => 'No. These are catalog medians, not a quote. Niche, metrics, turnaround, and homepage extras move the price on each listing. Register to filter the catalog and check out.',
        ],
        [
            'q' => 'How often does the index update?',
            'a' => 'Whenever the catalog changes. The page reads live inventory; it is not a static blog post with invented numbers.',
        ],
    ];
    $faqEntities = [];
    foreach ($faqs as $faq) {
        $faqEntities[] = [
            '@type' => 'Question',
            'name' => $faq['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['a'],
            ],
        ];
    }
@endphp

@extends('layouts.app')

@section('title', $metaTitle)
@section('description', $metaDescription)
@section('canonical', $canonical)
@section('hreflang_locales', 'en')
@section('hreflang_x_default', 'en')
@section('hreflang_path', 'guest-post-prices-europe')

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => $faqEntities,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'Dataset',
    'name' => 'EU guest-post price index',
    'description' => $metaDescription,
    'url' => $canonical,
    'creator' => \App\Support\BrandOrganization::schema(),
    'isAccessibleForFree' => true,
    'spatialCoverage' => 'Europe',
    'variableMeasured' => 'Median advertiser guest-post checkout price (EUR)',
    'measurementTechnique' => 'Median of advertiser checkout prices on catalog-visible listings, grouped by primary country. Country rows require at least 3 listings.',
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $metaTitle,
    'url' => $canonical,
    'description' => $metaDescription,
    'inLanguage' => 'en-GB',
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => 'Live catalog medians',
    'title' => 'EU guest-post price index',
    'subtitle' => 'Median guest post prices in Europe by publisher country — from verified listings on SEOLinkBuildings, not a survey or a made-up rate card.',
])

<div class="container py-5" style="max-width: 1040px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_marketplace'), 'url' => localized_url('marketplace')],
            ['name' => 'EU guest-post price index', 'url' => $canonical],
        ],
    ])

    @if(!empty($index['has_index']))
        <div class="row g-3 mb-5">
            @if($index['europe']['median'] !== null)
                <div class="col-md-6">
                    <div class="h-100 p-4 rounded-4 bg-white border">
                        <div class="small text-muted mb-1" data-price-index-kpi="europe-median">Europe median</div>
                        <div class="h3 mb-0" style="color:#1a585e;">€{{ number_format((float) $index['europe']['median'], 0) }}</div>
                        <p class="small text-muted mb-0 mt-2">Median advertiser checkout price across {{ number_format((int) $index['europe']['listings']) }} European catalog listings.</p>
                    </div>
                </div>
            @endif
            <div class="col-md-6">
                <div class="h-100 p-4 rounded-4 bg-white border">
                    <div class="small text-muted mb-1">European listings in the sample</div>
                    <div class="h3 mb-0" style="color:#1a585e;">{{ number_format((int) $index['europe']['listings']) }}</div>
                    <p class="small text-muted mb-0 mt-2">Active, verified publishers whose primary country is in Europe. US and other regions stay out of this index.</p>
                </div>
            </div>
        </div>
    @else
        <div class="p-4 rounded-4 bg-white border mb-5">
            <h2 class="h5 mb-2" style="color:#1a585e;">Index updates as the catalog grows</h2>
            <p class="text-muted mb-0">We need at least three European catalog listings before a median is honest. No placeholder prices — browse the <a href="{{ localized_url('marketplace') }}">marketplace</a> or <a href="{{ url('/register') }}">register</a> to see live inventory.</p>
        </div>
    @endif

    @if(!empty($index['countries']))
        <div class="mb-4">
            <h2 class="h4 mb-1" style="color:#1a585e;">Median price by country</h2>
            <p class="text-muted mb-3">Countries with fewer than three listings are omitted. Prices are advertiser checkout (EUR), not publisher payouts.</p>
        </div>
        <div class="table-responsive mb-5 rounded-4 border bg-white">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Country</th>
                        <th>Median</th>
                        <th>Listings</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($index['countries'] as $row)
                        <tr>
                            <td>
                                @if(!empty($row['lander_url']))
                                    <a href="{{ $row['lander_url'] }}">{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] }}
                                @endif
                            </td>
                            <td class="fw-semibold" style="color:#1a585e;">€{{ number_format((float) $row['median'], 0) }}</td>
                            <td>{{ number_format((int) $row['listings']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <section class="mb-5" aria-labelledby="price-index-how-heading">
        <h2 id="price-index-how-heading" class="h4 mb-3" style="color:#1a585e;">How this index is calculated</h2>
        <ul class="mb-0">
            <li class="mb-2">Only <strong>catalog-visible</strong> sites: active, verified, and not archived.</li>
            <li class="mb-2">Country is the listing’s <strong>primary country</strong> — the same rule as the advertiser catalog. A US-primary site with a Germany tag does not count as Germany.</li>
            <li class="mb-2">Prices are <strong>advertiser checkout</strong> (publisher list price plus the platform fee), in EUR. They are not what publishers withdraw.</li>
            <li class="mb-2">Each country uses the <strong>median</strong>, not the mean, so one expensive listing does not pull the number.</li>
            <li class="mb-2">A country row needs <strong>at least three</strong> listings. The Europe-wide figure uses every European listing and also waits for a sample of three.</li>
        </ul>
    </section>

    <section class="mb-5" aria-labelledby="price-index-faq-heading">
        <h2 id="price-index-faq-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.nav_faq') }}</h2>
        @foreach($faqs as $faq)
            <div class="mb-3">
                <h3 class="h6 mb-1" style="color:#1a585e;">{{ $faq['q'] }}</h3>
                <p class="text-muted mb-0">{{ $faq['a'] }}</p>
            </div>
        @endforeach
    </section>

    <div class="text-center d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
        <a href="{{ localized_url('become-a-publisher') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.nav_become_publisher') }}</a>
        <a href="{{ localized_url('marketplace') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.nav_marketplace') }}</a>
    </div>
    <p class="text-center text-muted small mt-3 mb-0">{{ __('messages.marketplace_catalog_note') }}</p>

    @include('components.country-lander-nav', [
        'title' => 'Guest posts by market',
        'landers' => $countryLanders ?? [],
        'label' => 'Country landers',
    ])
</div>
@endsection
