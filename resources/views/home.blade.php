@extends('layouts.app')

@section('title', __('messages.meta_home_title'))
@section('description', __('messages.meta_home_description'))
@section('canonical', localized_url('/'))

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'SEOLinkBuildings',
    'legalName' => config('billing.company.legal_name'),
    'alternateName' => 'Topurlz Ltd',
    'identifier' => config('billing.company.registration_no', '16607074'),
    'url' => url('/'),
    'logo' => asset('assets/img/logo1.png'),
    'sameAs' => array_values(array_filter(array_column(config('social.profiles', []), 'url'))),
    'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => '20 Wenlock Road',
        'addressLocality' => 'London',
        'postalCode' => 'N1 7GU',
        'addressCountry' => 'GB',
    ],
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'contactType' => 'customer support',
        'email' => config('billing.company.support_email', 'support@seolinkbuildings.com'),
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => 'SEOLinkBuildings',
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Web',
    'url' => url('/'),
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
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'SEOLinkBuildings',
    'url' => url('/'),
    'inLanguage' => array_map(
        fn (string $locale) => \App\Support\PublicI18n::htmlLang($locale),
        \App\Support\PublicI18n::supported()
    ),
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'SEOLinkBuildings',
        'url' => url('/'),
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
    @include('components.hero')
    @include('components.features')
    @include('components.how-it-works')
    @include('components.pricing')
    @include('components.testimonials')
    @include('components.newsletter')
    @include('components.cta')
@endsection
