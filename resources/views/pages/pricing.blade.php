@extends('layouts.app')

@section('title', __('messages.meta_pricing_title'))
@section('description', __('messages.meta_pricing_description'))
@section('canonical', localized_url('pricing'))

@php
    $fromPrice = '50';
    try {
        $minPublisherPrice = \App\Models\Site::query()
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('verified', 1)->orWhere('verified', true);
            })
            ->min('price');
        $fromPrice = $minPublisherPrice
            ? number_format(app(\App\Services\PlatformFeeService::class)->advertiserBase((float) $minPublisherPrice), 0, '.', '')
            : '50';
    } catch (\Throwable $e) {
        $fromPrice = '50';
    }

    $offerCatalog = [
        [
            '@type' => 'Offer',
            'name' => __('messages.pricing_card_1_title'),
            'description' => __('messages.pricing_card_1_description'),
            'price' => '499',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => localized_url('pricing'),
        ],
        [
            '@type' => 'Offer',
            'name' => __('messages.pricing_card_2_title'),
            'description' => __('messages.pricing_card_2_description'),
            'price' => '1499',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => localized_url('pricing'),
        ],
        [
            '@type' => 'Offer',
            'name' => __('messages.pricing_card_3_title'),
            'description' => __('messages.pricing_card_3_description'),
            'price' => '2799',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => localized_url('pricing'),
        ],
    ];
@endphp

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'SEOLinkBuildings guest-post marketplace',
    'description' => __('messages.meta_pricing_description'),
    'provider' => [
        '@type' => 'Organization',
        'name' => 'SEOLinkBuildings',
        'url' => url('/'),
    ],
    'areaServed' => ['EU', 'GB', 'US'],
    'offers' => [
        '@type' => 'AggregateOffer',
        'priceCurrency' => 'EUR',
        'lowPrice' => $fromPrice,
        'offerCount' => 3,
        'offers' => $offerCatalog,
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.pricing_kicker'),
    'title' => __('messages.pricing_page_title'),
    'subtitle' => __('messages.pricing_page_subtitle'),
])
<div class="container pt-3" style="max-width: 1100px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_pricing'), 'url' => localized_url('pricing')],
        ],
    ])
</div>
@include('components.pricing', ['showIntro' => false])
@endsection
