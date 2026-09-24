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
    @if(function_exists('public_locale') && public_locale() === 'pt' && class_exists(\App\Support\PortugueseMoneyLanders::class) && method_exists(\App\Support\PortugueseMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3" style="color:#1a585e;">Meios portugueses para guest posts</h2>
            <p class="text-muted">Esta página é a lista pública de publishers com país primário Portugal: nicho, idioma, DA/DR e preço em euros. Não indexamos cada combinação de filtro nem doorways de cidade (não há uma landing só de Lisboa). O catálogo completo com domínios abre-se depois do registo.</p>
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\PortugueseMoneyLanders::clusterLinks('marketplace'),
                'current' => 'marketplace',
                'title' => 'Páginas relacionadas',
            ])
            <p class="small mb-0">
                <a href="{{ url('/pt/comprar-guest-post') }}">Comprar guest post</a>
                · <a href="{{ url('/pt/comprar-backlinks') }}">Comprar backlinks</a>
                · <a href="{{ localized_url('pricing') }}">Quanto custa um guest post</a>
                · <a href="{{ url('/guest-posts-portugal') }}">Portugal inventory (English)</a>
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
