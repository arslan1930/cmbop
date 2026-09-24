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
    <p class="text-muted">Wir veröffentlichen keine feste PDF-Preisliste: der Preis ist der der Site, in Euro, am Checkout. „Backlinks-Preise“ und „Linkbuilding-Kosten Deutschland“ folgen den Listings, die Sie wählen. Die nummerierten Pakete oben sind gemanagte Digital-PR-Kampagnen, kein Sack anonymer URLs.</p>
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
@if(function_exists('public_locale') && public_locale() === 'pt' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\PortugueseMoneyLanders::class) && method_exists(\App\Support\PortugueseMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Quanto custa um guest post em Portugal</h2>
    <p class="text-muted">Não publicamos um PDF fixo nem uma tabela com IVA português inventado: o preço é o do site, em euros, no checkout. «Preços backlinks» e «custo link building» seguem os listings que escolher. Os pacotes numerados acima são campanhas de digital PR geridas, não um saco de URLs anónimos.</p>
    <p class="text-muted">Os preços em vivo estão no <a href="{{ localized_url('marketplace') }}">catálogo português</a> depois do registo. Índice europeu (página em inglês): <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\PortugueseMoneyLanders::clusterLinks('precos'),
        'current' => 'precos',
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
@if(function_exists('public_locale') && public_locale() === 'fr' && view()->exists('components.italian-seo-cluster-nav') && class_exists(\App\Support\FrenchMoneyLanders::class) && method_exists(\App\Support\FrenchMoneyLanders::class, 'clusterLinks'))
<div class="container pb-5" style="max-width: 1100px;">
    <h2 class="h4 mb-3" style="color:#1a585e;">Prix affichés au checkout</h2>
    <p class="text-muted">Nous ne publions pas un PDF figé et nous n’inventons pas de TVA française : le prix est celui du site, en euros, au checkout. Le coût du netlinking et le tarif d’un article sponsorisé suivent les listings que vous choisissez. Les forfaits chiffrés plus haut sont des campagnes de digital PR gérées, pas un sac d’URL anonymes.</p>
    <p class="text-muted">Les prix en vigueur sont dans le <a href="{{ localized_url('marketplace') }}">catalogue français</a>, après inscription. Index européen (page en anglais) : <a href="{{ url('/guest-post-prices-europe') }}">guest-post prices Europe</a>.</p>
    <p class="text-muted">Le prix dépend de l’autorité, du trafic déclaré, de la thématique, du pays, des exigences de contenu et de la relecture — selon le listing, pas selon des moyennes inventées. Le siège est à Londres (Topurlz Ltd) ; il n’y a pas de SIRET français.</p>
    @include('components.italian-seo-cluster-nav', [
        'links' => \App\Support\FrenchMoneyLanders::clusterLinks('tarifs'),
        'current' => 'tarifs',
        'title' => 'Pages liées',
    ])
</div>
@endif
@endsection
