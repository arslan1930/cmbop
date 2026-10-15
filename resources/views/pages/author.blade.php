@php
    $name = class_exists(\App\Support\BrandPerson::class)
        ? \App\Support\BrandPerson::publicName()
        : 'M. Arslan';
    $canonical = class_exists(\App\Support\BrandPerson::class)
        ? \App\Support\BrandPerson::url()
        : url('/author/m-arslan');
    $legalName = class_exists(\App\Support\BrandOrganization::class)
        ? \App\Support\BrandOrganization::confirmedLegalName()
        : 'Teqno Ltd';
    $registrationNo = (string) config('billing.company.registration_no', config('company.registration_no', '17392540'));
    $founded = (string) config('company.founded', '2024');
    $personJson = class_exists(\App\Support\BrandPerson::class)
        ? \App\Support\BrandOrganization::jsonLd(array_merge(
            ['@context' => 'https://schema.org'],
            \App\Support\BrandPerson::schema([
                'description' => $name.' is the founder of SEOLinkBuildings, the guest-post and backlink marketplace operated by '.$legalName.' (company no. '.$registrationNo.').',
            ])
        ))
        : '';
@endphp

@extends('layouts.app')

@section('title', $name.' — Founder of SEOLinkBuildings')
@section('description', $name.' is the founder of SEOLinkBuildings, a European guest-post and backlink marketplace operated by '.$legalName.' (UK company no. '.$registrationNo.').')
@section('canonical', $canonical)
@section('hreflang_locales', 'en')
@section('hreflang_x_default', 'en')
@section('og_type', 'profile')

@push('head')
@if($personJson !== '')
<script type="application/ld+json">
{!! $personJson !!}
</script>
@endif
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'title' => $name,
    'subtitle' => 'Founder of SEOLinkBuildings',
])

<div class="container py-5" style="max-width: 800px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => $name, 'url' => $canonical],
        ],
    ])

    <p class="text-muted">
        {{ $name }} is the founder of SEOLinkBuildings, the guest-post and backlink marketplace at seolinkbuildings.com. The product launched in {{ $founded }}.
    </p>
    <p class="text-muted">
        The operating company is {{ $legalName }} (company no. {{ $registrationNo }}), registered in England and Wales at 20 Wenlock Road, London N1 7GU. Company details are on
        <a href="{{ localized_url('about') }}" class="link-teal">About us</a>
        and at
        <a href="https://find-and-update.company-information.service.gov.uk/company/{{ $registrationNo }}" rel="noopener noreferrer" target="_blank" class="link-teal">Companies House</a>.
    </p>
    <p class="text-muted mb-0">
        Guides on this site are published under this byline. Questions about the marketplace go through
        <a href="{{ localized_url('contact') }}" class="link-teal">Contact</a>
        or support@seolinkbuildings.com.
    </p>
</div>
@endsection
