@php
    // Manually set locale based on URL
    $segments = request()->segments();
    $availableLocales = ['de', 'fr', 'nl'];
    
    if (!empty($segments) && in_array($segments[0], $availableLocales)) {
        $locale = $segments[0];
        app()->setLocale($locale);
    } else {
        app()->setLocale('en');
    }
    
    // Also set in session
    session(['locale' => app()->getLocale()]);
@endphp

@extends('layouts.app')

@section('title', 'SEOLinkBuildings - Content Marketplace & Blogger Outreach Platform')

<!-- Meta Description -->
@section('description', 'SEOLinkBuildings is a leading content marketplace and blogger outreach platform that helps businesses grow their online presence through strategic link building and digital PR services.')

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