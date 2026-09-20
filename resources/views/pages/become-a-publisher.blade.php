@extends('layouts.app')

@section('title', __('messages.meta_become_publisher_title'))
@section('description', __('messages.meta_become_publisher_description'))
@section('canonical', localized_url('become-a-publisher'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.become_publisher_kicker'),
    'title' => __('messages.become_publisher_title'),
    'subtitle' => __('messages.become_publisher_subtitle'),
])

<div class="container py-5" style="max-width: 900px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_become_publisher'), 'url' => localized_url('become-a-publisher')],
        ],
    ])

    {{-- What the platform gives you --}}
    <div class="row g-4 mb-5">
        @foreach(range(1, 3) as $i)
            <div class="col-md-4">
                <h2 class="h5" style="color:#1a585e;">{{ __('messages.become_publisher_point_'.$i.'_title') }}</h2>
                <p class="text-muted mb-0">{{ __('messages.become_publisher_point_'.$i.'_body') }}</p>
            </div>
        @endforeach
    </div>

    {{-- From listing a site to getting paid --}}
    <section class="mb-5">
        <h2 class="h4 mb-3" style="color:#1a585e;">{{ __('messages.become_publisher_how_title') }}</h2>
        <p class="text-muted">{{ __('messages.become_publisher_how_intro') }}</p>
        <ol class="publisher-steps">
            @foreach(range(1, 5) as $i)
                <li>
                    <strong>{{ __('messages.become_publisher_how_step_'.$i.'_title') }}</strong>
                    <span class="text-muted d-block">{{ __('messages.become_publisher_how_step_'.$i.'_body') }}</span>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- What you earn --}}
    <section class="mb-5">
        <h2 class="h4 mb-3" style="color:#1a585e;">{{ __('messages.become_publisher_earnings_title') }}</h2>
        <p class="text-muted">{{ __('messages.become_publisher_earnings_intro') }}</p>
        <div class="row g-4">
            @foreach(range(1, 4) as $i)
                <div class="col-md-6">
                    <div class="publisher-card h-100">
                        <h3 class="h6 mb-2">{{ __('messages.become_publisher_earnings_point_'.$i.'_title') }}</h3>
                        <p class="text-muted small mb-0">{{ __('messages.become_publisher_earnings_point_'.$i.'_body') }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- When the money lands --}}
    <section class="mb-5">
        <h2 class="h4 mb-3" style="color:#1a585e;">{{ __('messages.become_publisher_when_title') }}</h2>
        <p class="text-muted">{{ __('messages.become_publisher_when_intro') }}</p>
        <ul class="publisher-list">
            @foreach(range(1, 4) as $i)
                <li>
                    <strong>{{ __('messages.become_publisher_when_point_'.$i.'_title') }}</strong>
                    <span class="text-muted d-block">{{ __('messages.become_publisher_when_point_'.$i.'_body') }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Getting the money out --}}
    <section class="mb-5">
        <h2 class="h4 mb-3" style="color:#1a585e;">{{ __('messages.become_publisher_payout_title') }}</h2>
        <p class="text-muted">{{ __('messages.become_publisher_payout_intro') }}</p>
        <ul class="publisher-list">
            @foreach(range(1, 5) as $i)
                <li>
                    <strong>{{ __('messages.become_publisher_payout_point_'.$i.'_title') }}</strong>
                    <span class="text-muted d-block">{{ __('messages.become_publisher_payout_point_'.$i.'_body') }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- The parts people ask about after signing up --}}
    <section class="mb-5">
        <h2 class="h4 mb-3" style="color:#1a585e;">{{ __('messages.become_publisher_notes_title') }}</h2>
        <div class="row g-4">
            @foreach(range(1, 4) as $i)
                <div class="col-md-6">
                    <div class="publisher-card h-100">
                        <h3 class="h6 mb-2">{{ __('messages.become_publisher_notes_point_'.$i.'_title') }}</h3>
                        <p class="text-muted small mb-0">{{ __('messages.become_publisher_notes_point_'.$i.'_body') }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="text-center">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.become_publisher_cta') }}</a>
        <p class="text-muted small mt-3 mb-0">{{ __('messages.become_publisher_cta_note') }}</p>
    </div>
</div>
@endsection
