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
