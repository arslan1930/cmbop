@extends('layouts.app')

@section('title', __('messages.meta_home_title'))
@section('description', welcome_bonus_message('meta_home_description', 'meta_home_description_off'))
@section('canonical', localized_url('/'))

@push('head')
<style>
  /* Homepage first paint: same wash as the hero so refresh is not a white splash. */
  html, body { background: #f7fafb; }
  .slb-reveal { animation: none !important; opacity: 1 !important; }
</style>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => 'SEOLinkBuildings',
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Web',
    'url' => url('/'),
    'description' => 'Guest-post and backlink marketplace connecting advertisers with publishers across Europe.',
    'image' => asset('assets/img/logo1.png'),
    'offers' => [
        '@type' => 'Offer',
        'price' => '0',
        'priceCurrency' => 'EUR',
    ],
    'provider' => [
        '@type' => 'Organization',
        'name' => 'SEOLinkBuildings',
        'legalName' => config('billing.company.legal_name'),
        'identifier' => config('billing.company.registration_no', '16607074'),
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'SEOLinkBuildings',
    'alternateName' => ['SEO Link Buildings', 'Seolink Buildings'],
    'url' => url('/'),
    'inLanguage' => (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'htmlLang'))
        ? \App\Support\PublicI18n::htmlLang()
        : 'en-GB',
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'SEOLinkBuildings',
        'url' => url('/'),
    ],
    'hasPart' => [
        [
            '@type' => 'AboutPage',
            'name' => 'About SEOLinkBuildings',
            'url' => localized_url('about'),
        ],
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
@endpush

@section('content')
    @if (view()->exists('components.hero'))
        @include('components.hero')
    @endif
    @if (function_exists('public_locale') && public_locale() === 'it' && class_exists(\App\Support\ItalianMoneyLanders::class) && method_exists(\App\Support\ItalianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\ItalianMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Pagine per chi cerca guest post e backlink in Italia',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'de' && class_exists(\App\Support\GermanMoneyLanders::class) && method_exists(\App\Support\GermanMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\GermanMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Seiten für Gastbeiträge, Backlinks und Linkbuilding',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'at' && class_exists(\App\Support\AustrianMoneyLanders::class) && method_exists(\App\Support\AustrianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\AustrianMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Seiten für Gastbeiträge, Backlinks und Linkbuilding in Österreich',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'ch' && class_exists(\App\Support\SwissMoneyLanders::class) && method_exists(\App\Support\SwissMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\SwissMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Seiten für Gastbeiträge, Backlinks und Linkbuilding in der Schweiz',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'es' && class_exists(\App\Support\SpanishMoneyLanders::class) && method_exists(\App\Support\SpanishMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\SpanishMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Páginas para guest posts, backlinks y link building en España',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'ro' && class_exists(\App\Support\RomanianMoneyLanders::class) && method_exists(\App\Support\RomanianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\RomanianMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Pagini de guest post, backlinkuri și link building în România',
            ])
        </div>
    @endif
    @include('components.features')
    @include('components.how-it-works')
    @include('components.pricing')
    @include('components.testimonials')
    @include('components.newsletter')
    @include('components.cta')
@endsection
