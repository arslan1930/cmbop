@extends('layouts.app')

@section('title', __('messages.meta_home_title'))
@section('description', __('messages.meta_home_description'))
@section('canonical', localized_url('/'))

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'SEOLinkBuildings',
    'url' => url('/'),
    'logo' => asset('assets/img/logo1.png'),
    'sameAs' => [
        'https://www.linkedin.com/company/seolinkbuildings',
    ],
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'contactType' => 'customer support',
        'email' => 'support@seolinkbuildings.com',
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