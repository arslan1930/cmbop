@extends('layouts.app')

@section('title', __('messages.meta_home_title'))
@section('description', __('messages.meta_home_description'))
@section('canonical', localized_url('/'))

@push('head')
<script type="application/ld+json">
{!! json_encode(array_merge([
    '@@context' => 'https://schema.org',
], \App\Support\BrandOrganization::schema()), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
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
    'alternateName' => ['SEO Link Buildings', 'Seolink Buildings'],
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
