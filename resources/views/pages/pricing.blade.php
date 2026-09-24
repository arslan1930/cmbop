@extends('layouts.app')

@section('title', __('messages.meta_pricing_title'))
@section('description', __('messages.meta_pricing_description'))
@section('canonical', localized_url('pricing'))

@php
    $fromPrice = '50';
    try {
        $minPublisherPrice = \App\Models\Site::query()
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('verified', 1)->orWhere('verified', true);
            })
            ->min('price');
        $fromPrice = $minPublisherPrice
            ? number_format(app(\App\Services\PlatformFeeService::class)->advertiserBase((float) $minPublisherPrice), 0, '.', '')
            : '50';
    } catch (\Throwable $e) {
        $fromPrice = '50';
    }

    $offerCatalog = [
        [
            '@type' => 'Offer',
            'name' => __('messages.pricing_card_1_title'),
            'description' => __('messages.pricing_card_1_description'),
            'price' => '499',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => localized_url('pricing'),
        ],
        [
            '@type' => 'Offer',
            'name' => __('messages.pricing_card_2_title'),
            'description' => __('messages.pricing_card_2_description'),
            'price' => '1499',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => localized_url('pricing'),
        ],
        [
            '@type' => 'Offer',
            'name' => __('messages.pricing_card_3_title'),
            'description' => __('messages.pricing_card_3_description'),
            'price' => '2799',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => localized_url('pricing'),
        ],
    ];
@endphp

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'SEOLinkBuildings guest-post marketplace',
    'description' => __('messages.meta_pricing_description'),
    'provider' => [
        '@type' => 'Organization',
        'name' => 'SEOLinkBuildings',
        'url' => url('/'),
    ],
    'areaServed' => ['EU', 'GB', 'US'],
    'offers' => [
        '@type' => 'AggregateOffer',
        'priceCurrency' => 'EUR',
        'lowPrice' => $fromPrice,
        'offerCount' => 3,
        'offers' => $offerCatalog,
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.pricing_kicker'),
    'title' => __('messages.pricing_page_title'),
    'subtitle' => __('messages.pricing_page_subtitle'),
])
<div class="container pt-3" style="max-width: 1100px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_pricing'), 'url' => localized_url('pricing')],
        ],
    ])
</div>
@include('components.pricing', ['showIntro' => false])
@if(function_exists('public_locale') && public_locale() === 'it' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\ItalianMoneyLanders::class) && method_exists(\App\Support\ItalianMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Quanto costa un guest post</h2>
    <p class="text-muted">Non pubblichiamo un listino PDF fisso: il prezzo è quello del sito, in euro, al checkout. “Quanto costano i backlink” e “costo link building” dipendono dai listing che scegli. I pacchetti numerati sopra sono campagne gestite di PR digitale, non un sacco di URL anonimi.</p>
    <p class="text-muted">Per vedere i prezzi live filtra il <a href="{{ localized_url('marketplace') }}">catalogo</a> dopo la registrazione. Indice europeo (pagina in inglese): <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\ItalianMoneyLanders::clusterLinks('prezzi'),
        'current' => 'prezzi',
        'title' => 'Pagine correlate',
    ])
</div>
@endif
@if(function_exists('public_locale') && public_locale() === 'de' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\GermanMoneyLanders::class) && method_exists(\App\Support\GermanMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Was kostet ein Gastbeitrag</h2>
    <p class="text-muted">Wir veröffentlichen kein festes PDF-Listino: der Preis ist der der Site, in Euro, am Checkout. „Backlinks-Preise“ und „Linkbuilding-Kosten Deutschland“ folgen den Listings, die Sie wählen. Die nummerierten Pakete oben sind gemanagte Digital-PR-Kampagnen, kein Sack anonymer URLs.</p>
    <p class="text-muted">Live-Preise sehen Sie im <a href="{{ localized_url('marketplace') }}">Katalog</a> nach der Registrierung. Europäischer Index (englische Seite): <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    <p class="text-muted">Preis prägen Publisher-Autorität, Traffic, Nische, Land, Content-Anforderungen, permanente vs. zeitlich begrenzte Platzierung, Texterstellung und redaktionelle Prüfung — jeweils laut Listing, nicht als erfundene Durchschnittswerte.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\GermanMoneyLanders::clusterLinks('preise'),
        'current' => 'preise',
        'title' => 'Verwandte Seiten',
    ])
</div>
@endif
@if(function_exists('public_locale') && public_locale() === 'at' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\AustrianMoneyLanders::class) && method_exists(\App\Support\AustrianMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Was kostet ein Gastbeitrag in Österreich</h2>
    <p class="text-muted">Kein festes PDF und keine APA-OTS-Preisliste: der Preis ist der der Site, in Euro, am Checkout. „Backlinks Preise Österreich“ und „Linkbuilding-Kosten“ folgen den Listings, die Sie wählen. Die nummerierten Pakete oben sind gemanagte Digital-PR-Kampagnen, kein Sack anonymer URLs.</p>
    <p class="text-muted">Live-Preise sehen Sie im <a href="{{ localized_url('marketplace') }}">österreichischen Katalog</a> nach der Registrierung. Europäischer Index (englische Seite): <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    <p class="text-muted">Preis prägen Publisher-Autorität, Traffic, Nische, Land (.at vs. andere), Content-Anforderungen und redaktionelle Prüfung — jeweils laut Listing, nicht als erfundene Österreich-Durchschnittswerte.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\AustrianMoneyLanders::clusterLinks('preise'),
        'current' => 'preise',
        'title' => 'Verwandte Seiten',
    ])
</div>
@endif
@if(function_exists('public_locale') && public_locale() === 'ch' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\SwissMoneyLanders::class) && method_exists(\App\Support\SwissMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Was kostet ein Gastbeitrag in der Schweiz</h2>
    <p class="text-muted">Kein festes PDF und keine CHF-Preisliste: der Preis ist der der Site, in Euro, am Checkout. „Backlinks Preise Schweiz“ und „Linkbuilding-Kosten“ folgen den Listings, die Sie wählen. Die nummerierten Pakete oben sind gemanagte Digital-PR-Kampagnen, kein Sack anonymer URLs.</p>
    <p class="text-muted">Live-Preise sehen Sie im <a href="{{ localized_url('marketplace') }}">schweizerischen Katalog</a> nach der Registrierung. Europäischer Index (englische Seite): <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    <p class="text-muted">Preis prägen Publisher-Autorität, Traffic, Nische, Land (.ch vs. andere), Content-Anforderungen und redaktionelle Prüfung — jeweils laut Listing, nicht als erfundene CHF-Durchschnittswerte. HQ bleibt London; keine erfundene Schweizer MWST-Nummer.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\SwissMoneyLanders::clusterLinks('preise'),
        'current' => 'preise',
        'title' => 'Verwandte Seiten',
    ])
</div>
@endif
@if(function_exists('public_locale') && public_locale() === 'es' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\SpanishMoneyLanders::class) && method_exists(\App\Support\SpanishMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Qué cuesta un guest post en España</h2>
    <p class="text-muted">No publicamos un PDF fijo ni una tarifa con IVA español inventado: el precio es el del sitio, en euros, en el checkout. «Precios backlinks» y «coste link building» siguen los listings que elija. Los paquetes numerados arriba son campañas de PR digital gestionadas, no un saco de URLs anónimas.</p>
    <p class="text-muted">Los precios en vivo están en el <a href="{{ localized_url('marketplace') }}">catálogo de España</a> tras registrarse. Índice europeo (página en inglés): <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    <p class="text-muted">El precio lo marcan autoridad, tráfico, nicho, país (.es vs. otros), requisitos de contenido y revisión editorial — según el listing, no como medias españolas inventadas. Sede en Londres (Topurlz Ltd); no hay CIF español.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\SpanishMoneyLanders::clusterLinks('precios'),
        'current' => 'precios',
        'title' => 'Páginas relacionadas',
    ])
</div>
@endif
@if(function_exists('public_locale') && public_locale() === 'ro' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\RomanianMoneyLanders::class) && method_exists(\App\Support\RomanianMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Cât costă un guest post în România</h2>
    <p class="text-muted">Nu publicăm un PDF fix și nu inventăm un tarif cu TVA românesc: prețul este al site-ului, în euro, la checkout. „Preț guest post” și „cost linkbuilding” urmează listingurile pe care le alegi. Pachetele numerotate de mai sus sunt campanii gestionate de digital PR, nu un sac de URL-uri anonime.</p>
    <p class="text-muted">Prețurile în vigoare sunt în <a href="{{ localized_url('marketplace') }}">catalogul din România</a>, după înregistrare. Indexul european (pagină în engleză): <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    <p class="text-muted">Prețul îl fac autoritatea, traficul, nișa, țara, cerințele de conținut și revizia editorială — după listing, nu ca medii inventate. Sediul este la Londra (Topurlz Ltd); nu există un CUI românesc.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\RomanianMoneyLanders::clusterLinks('preturi'),
        'current' => 'preturi',
        'title' => 'Pagini înrudite',
    ])
</div>
@endif
@endsection
