@php
    /** @var array<string, mixed> $page */
    $page = $page ?? [];
    $slug = (string) ($slug ?? '');
    $canonical = url('/fr/'.$slug);
    $faqs = is_array($page['faqs'] ?? null) ? $page['faqs'] : [];
    $faqEntities = [];
    foreach ($faqs as $faq) {
        $faqEntities[] = [
            '@type' => 'Question',
            'name' => (string) ($faq['q'] ?? ''),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => strip_tags((string) ($faq['a'] ?? '')),
            ],
        ];
    }
    $cluster = $cluster ?? (
        class_exists(\App\Support\FrenchMoneyLanders::class)
        && method_exists(\App\Support\FrenchMoneyLanders::class, 'clusterLinks')
            ? \App\Support\FrenchMoneyLanders::clusterLinks($slug)
            : []
    );
    $hreflangLocales = 'fr';
    $hreflangXDefault = 'fr';
    if (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'moneyLanderLocales')) {
        $hreflangLocales = implode(',', \App\Support\PublicI18n::moneyLanderLocales($slug));
        $hreflangXDefault = method_exists(\App\Support\PublicI18n::class, 'moneyLanderXDefault')
            ? \App\Support\PublicI18n::moneyLanderXDefault($slug)
            : $hreflangXDefault;
    }
@endphp

@extends('layouts.app')

@section('title', $page['meta_title'] ?? $page['h1'] ?? 'SEOLinkBuildings')
@section('description', $page['meta_description'] ?? '')
@section('canonical', $canonical)
@section('hreflang_locales', $hreflangLocales)
@section('hreflang_x_default', $hreflangXDefault)
@section('hreflang_path', $slug)

@push('head')
@if($faqEntities !== [])
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => $faqEntities,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
@endif
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $page['meta_title'] ?? $page['h1'] ?? '',
    'url' => $canonical,
    'description' => $page['meta_description'] ?? '',
    'inLanguage' => 'fr',
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => $page['kicker'] ?? null,
    'title' => $page['h1'] ?? '',
    'subtitle' => $page['subtitle'] ?? null,
])

<div class="container py-5" style="max-width: 1040px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => $page['h1'] ?? $slug, 'url' => $canonical],
        ],
    ])

    @if(view()->exists('components.italian-seo-cluster-nav'))
        @include('components.italian-seo-cluster-nav', [
            'links' => $cluster,
            'current' => $slug,
            'title' => 'Pages de guest posts, backlinks et netlinking en France',
        ])
    @endif

    @if(!empty($siteCount) || !empty($priceFrom))
        <div class="row g-3 mb-5">
            @if(!empty($priceFrom))
                <div class="col-md-6">
                    <div class="h-100 p-4 rounded-4 bg-white border">
                        <div class="small text-muted mb-1">À partir de</div>
                        <div class="h3 mb-0" style="color:#1a585e;">€{{ number_format((float) $priceFrom, 0) }}</div>
                        <p class="small text-muted mb-0 mt-2">Prix le plus bas parmi les fiches actives dont le pays principal est la France, en ce moment. Ce n’est pas une grille tarifaire fixe.</p>
                    </div>
                </div>
            @endif
            @if(!empty($siteCount))
                <div class="col-md-6">
                    <div class="h-100 p-4 rounded-4 bg-white border">
                        <div class="small text-muted mb-1">Sites dans l’aperçu</div>
                        <div class="h3 mb-0" style="color:#1a585e;">{{ number_format((int) $siteCount) }}</div>
                        <p class="small text-muted mb-0 mt-2">Éditeurs actifs dont le pays principal est la France, lorsque le nombre est disponible.</p>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @foreach(($page['intro'] ?? []) as $para)
        <p class="text-muted mb-3">{!! $para !!}</p>
    @endforeach

    @if(!empty($page['points']))
        <div class="row g-4 my-4">
            @foreach($page['points'] as $point)
                <div class="col-md-4">
                    <div class="h-100 p-4 rounded-4 bg-white border">
                        <h2 class="h5" style="color:#1a585e;">{{ $point['title'] ?? '' }}</h2>
                        <p class="text-muted mb-0">{!! $point['body'] ?? '' !!}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if(isset($teasers) && $teasers->isNotEmpty())
        <div class="mb-4 text-center">
            <h2 class="h4 mb-1" style="color:#1a585e;">{{ $page['teaser_title'] ?? __('messages.marketplace_teaser_title') }}</h2>
            <p class="text-muted mb-0">{{ $page['teaser_subtitle'] ?? __('messages.marketplace_teaser_subtitle') }}</p>
        </div>
        <div class="table-responsive mb-4 rounded-4 border bg-white">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Site</th>
                        <th>Pays</th>
                        <th>Langue</th>
                        <th>DR</th>
                        <th>DA</th>
                        <th>À partir de</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($teasers as $site)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $site['name'] }}</div>
                                <div class="small text-muted">{{ $site['domain_masked'] }}</div>
                            </td>
                            <td>{{ strtoupper((string) ($site['country'] ?: '—')) }}</td>
                            <td>{{ strtoupper((string) ($site['language'] ?: '—')) }}</td>
                            <td>{{ $site['dr'] ?? '—' }}</td>
                            <td>{{ $site['da'] ?? '—' }}</td>
                            <td class="fw-semibold" style="color:#1a585e;">€{{ number_format((float) $site['price'], 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="small text-muted mb-5">Nous affichons le DA, le DR et le prix en euros lorsqu’ils figurent sur la fiche. Nous n’inventons pas les métriques. Le tableau liste le pays principal France — pas un mélange Belgique ou Suisse.</p>
    @endif

    @foreach(($page['sections'] ?? []) as $section)
        <section class="mb-5">
            <h2 class="h4 mb-3" style="color:#1a585e;">{{ $section['h2'] ?? '' }}</h2>
            <div class="text-muted">{!! $section['body'] ?? '' !!}</div>
        </section>
    @endforeach

    @if($faqs !== [])
        <section class="mb-5" aria-labelledby="fr-money-faq">
            <h2 id="fr-money-faq" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.nav_faq') }}</h2>
            @foreach($faqs as $faq)
                <div class="mb-3">
                    <h3 class="h6 mb-1" style="color:#1a585e;">{{ $faq['q'] ?? '' }}</h3>
                    <p class="text-muted mb-0">{{ $faq['a'] ?? '' }}</p>
                </div>
            @endforeach
        </section>
    @endif

    @if(!empty($page['see_also']))
        <section class="mb-5">
            <h2 class="h5 mb-3" style="color:#1a585e;">Voir aussi</h2>
            <ul class="mb-0">
                @foreach($page['see_also'] as $link)
                    <li class="mb-2"><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="text-center d-flex flex-wrap justify-content-center gap-2">
        @if(!empty($page['cta_primary']['url']))
            <a href="{{ $page['cta_primary']['url'] }}" class="btn btn-primary btn-lg px-4">{{ $page['cta_primary']['label'] ?? __('messages.get_started') }}</a>
        @endif
        @if(!empty($page['cta_secondary']['url']))
            <a href="{{ $page['cta_secondary']['url'] }}" class="btn btn-outline-secondary btn-lg px-4">{{ $page['cta_secondary']['label'] }}</a>
        @endif
    </div>
</div>
@endsection
