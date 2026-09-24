@extends('layouts.app')

@section('title', __('messages.meta_marketplace_title'))
@section('description', __('messages.meta_marketplace_description'))
@section('canonical', localized_url('marketplace'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.marketplace_kicker'),
    'title' => __('messages.marketplace_title'),
    'subtitle' => __('messages.marketplace_subtitle'),
])

<div class="container py-5">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_marketplace'), 'url' => localized_url('marketplace')],
        ],
    ])
    <div class="row g-4 mb-5">
        @foreach(range(1, 3) as $i)
            <div class="col-md-4">
                <div class="h-100 p-4 rounded-4 bg-white border">
                    <h2 class="h5" style="color:#1a585e;">{{ __('messages.marketplace_point_'.$i.'_title') }}</h2>
                    <p class="text-muted mb-0">{{ __('messages.marketplace_point_'.$i.'_body') }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="text-center mt-4 d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
        <a href="{{ localized_url('become-a-publisher') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.nav_become_publisher') }}</a>
        <a href="{{ localized_url('how-it-works') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.nav_how_it_works') }}</a>
    </div>
    <p class="text-center text-muted small mt-3 mb-0">{{ __('messages.marketplace_catalog_note') }}</p>
    @if(function_exists('public_locale') && public_locale() === 'it' && class_exists(\App\Support\ItalianMoneyLanders::class) && method_exists(\App\Support\ItalianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3" style="color:#1a585e;">Siti per guest post e backlink in Italia</h2>
            <p class="text-muted">Questa pagina è la lista pubblica del catalogo: nicchia, lingua, DA/DR e prezzo in euro. Non indicizziamo ogni combinazione di filtro. Il catalogo completo, con domini e filtri, si apre dopo la registrazione. ZA SEOZoom non è una colonna dei listing.</p>
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\ItalianMoneyLanders::clusterLinks('mercato'),
                'current' => 'mercato',
                'title' => 'Pagine correlate',
            ])
            <p class="small mb-0">
                <a href="{{ url('/it/comprare-guest-post') }}">Acquistare guest post</a>
                · <a href="{{ url('/it/comprare-backlink') }}">Acquistare backlink</a>
                · <a href="{{ localized_url('pricing') }}">Prezzi guest post</a>
                · <a href="{{ url('/guest-posts-italy') }}">Italy inventory (English)</a>
            </p>
        </div>
    @endif
    @if(function_exists('public_locale') && public_locale() === 'de' && class_exists(\App\Support\GermanMoneyLanders::class) && method_exists(\App\Support\GermanMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3" style="color:#1a585e;">Beste Seiten für Gastbeiträge in Deutschland</h2>
            <p class="text-muted">Diese Seite ist die öffentliche Katalogliste: Nische, Sprache, DA/DR und Preis in Euro. Wir indexieren nicht jede Filterkombination. Der vollständige Katalog mit Domains und Filtern öffnet sich nach der Registrierung. Es erscheinen nur Metriken, die am Listing existieren.</p>
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\GermanMoneyLanders::clusterLinks('marktplatz'),
                'current' => 'marktplatz',
                'title' => 'Verwandte Seiten',
            ])
            <p class="small mb-0">
                <a href="{{ url('/de/gastbeitrag-kaufen') }}">Gastbeitrag kaufen</a>
                · <a href="{{ url('/de/backlinks-kaufen') }}">Backlinks kaufen</a>
                · <a href="{{ localized_url('pricing') }}">Was kostet ein Gastbeitrag</a>
                · <a href="{{ url('/guest-posts-germany') }}">Germany inventory (English)</a>
            </p>
        </div>
    @endif
    @if(function_exists('public_locale') && public_locale() === 'at' && class_exists(\App\Support\AustrianMoneyLanders::class) && method_exists(\App\Support\AustrianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3" style="color:#1a585e;">Gastbeitrag-Portale in Österreich</h2>
            <p class="text-muted">Diese Seite ist die öffentliche Liste österreichischer Publisher: Nische, Sprache, DA/DR und Preis in Euro. Wir indexieren nicht jede Filterkombination und keine City-Doorways (kein eigenes Wien-Listing). Der vollständige Katalog mit Domains öffnet sich nach der Registrierung.</p>
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\AustrianMoneyLanders::clusterLinks('marktplatz'),
                'current' => 'marktplatz',
                'title' => 'Verwandte Seiten',
            ])
            <p class="small mb-0">
                <a href="{{ url('/at/gastbeitrag-kaufen') }}">Gastbeitrag kaufen in Österreich</a>
                · <a href="{{ url('/at/backlinks-kaufen') }}">Backlinks kaufen</a>
                · <a href="{{ localized_url('pricing') }}">Was kostet ein Gastbeitrag in Österreich</a>
                · <a href="{{ url('/guest-posts-austria') }}">Austria inventory (English)</a>
            </p>
        </div>
    @endif
    @if(function_exists('public_locale') && public_locale() === 'ch' && class_exists(\App\Support\SwissMoneyLanders::class) && method_exists(\App\Support\SwissMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3" style="color:#1a585e;">Gastbeitrag-Portale in der Schweiz</h2>
            <p class="text-muted">Diese Seite ist die öffentliche Liste schweizerischer Publisher: Nische, Sprache, DA/DR und Preis in Euro. Wir indexieren nicht jede Filterkombination und keine City-Doorways (kein eigenes Zürich-Listing). Der vollständige Katalog mit Domains öffnet sich nach der Registrierung. Checkout bleibt EUR.</p>
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\SwissMoneyLanders::clusterLinks('marktplatz'),
                'current' => 'marktplatz',
                'title' => 'Verwandte Seiten',
            ])
            <p class="small mb-0">
                <a href="{{ url('/ch/gastbeitrag-kaufen') }}">Gastbeitrag kaufen in der Schweiz</a>
                · <a href="{{ url('/ch/backlinks-kaufen') }}">Backlinks kaufen</a>
                · <a href="{{ localized_url('pricing') }}">Was kostet ein Gastbeitrag in der Schweiz</a>
                · <a href="{{ url('/guest-posts-switzerland') }}">Switzerland inventory (English)</a>
            </p>
        </div>
    @endif
    @if(function_exists('public_locale') && public_locale() === 'es' && class_exists(\App\Support\SpanishMoneyLanders::class) && method_exists(\App\Support\SpanishMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3" style="color:#1a585e;">Catálogo de medios para guest posts en España</h2>
            <p class="text-muted">Esta página es la lista pública de publishers de España: nicho, idioma, DA/DR y precio en euros. No indexamos cada combinación de filtro ni landings de ciudad (Madrid/Barcelona no tienen URL propia). El catálogo completo con dominios se abre tras registrarse.</p>
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\SpanishMoneyLanders::clusterLinks('mercado'),
                'current' => 'mercado',
                'title' => 'Páginas relacionadas',
            ])
            <p class="small mb-0">
                <a href="{{ url('/es/comprar-guest-post') }}">Comprar guest post en España</a>
                · <a href="{{ url('/es/comprar-backlinks') }}">Comprar backlinks</a>
                · <a href="{{ localized_url('pricing') }}">Qué cuesta un guest post en España</a>
                · <a href="{{ url('/guest-posts-spain') }}">Spain inventory (English)</a>
            </p>
        </div>
    @endif
    @if(function_exists('public_locale') && public_locale() === 'ro' && class_exists(\App\Support\RomanianMoneyLanders::class) && method_exists(\App\Support\RomanianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3" style="color:#1a585e;">Site-uri din România pentru guest post</h2>
            <p class="text-muted">Aceasta este lista publică de publishers din România: nișă, limbă, DA/DR și preț în euro. Nu indexăm fiecare combinație de filtru și nu avem landings de oraș (București și Cluj nu au URL propriu). Catalogul complet, cu domenii, se deschide după înregistrare.</p>
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\RomanianMoneyLanders::clusterLinks('piata'),
                'current' => 'piata',
                'title' => 'Pagini înrudite',
            ])
            <p class="small mb-0">
                <a href="{{ url('/ro/cumpara-guest-post') }}">Cumpără guest post în România</a>
                · <a href="{{ url('/ro/cumpara-backlink') }}">Cumpără backlinkuri</a>
                · <a href="{{ localized_url('pricing') }}">Cât costă un guest post în România</a>
                · <a href="{{ url('/guest-posts-romania') }}">Romania inventory (English)</a>
            </p>
        </div>
    @endif
    <p class="text-center small mt-2 mb-0">
        <a href="{{ url('/guest-post-prices-europe') }}">EU guest-post price index</a>
        — median advertiser prices by European publisher country.
    </p>

    @if(view()->exists('components.country-lander-nav'))
        @include('components.country-lander-nav', [
            'title' => 'Guest posts by market',
            'landers' => $countryLanders ?? [],
            'label' => 'Country landers',
        ])
    @endif
</div>
@endsection
