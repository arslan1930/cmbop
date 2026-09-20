@extends('layouts.app')

@section('title', __('messages.meta_home_title'))
@section('description', welcome_bonus_message('meta_home_description', 'meta_home_description_off'))
@section('canonical', localized_url('/'))

@push('head')
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
    @include('components.features')
    @include('components.how-it-works')
    @include('components.pricing')
    @include('components.testimonials')
    @include('components.newsletter')
    @include('components.cta')
@endsection
