@extends('layouts.app')

@section('title', __('messages.meta_about_title'))
@section('description', __('messages.meta_about_description'))
@section('canonical', localized_url('about'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.about_page_kicker'),
    'title' => __('messages.about_page_title'),
    'subtitle' => __('messages.about_page_subtitle'),
])

<div class="container py-5" style="max-width: 860px;">
    <div class="row g-4">
        <div class="col-md-6">
            <h2 class="h4" style="color:#185054;">{{ __('messages.about_page_mission_title') }}</h2>
            <p class="text-muted">{{ __('messages.about_page_mission_body') }}</p>
        </div>
        <div class="col-md-6">
            <h2 class="h4" style="color:#185054;">{{ __('messages.about_page_approach_title') }}</h2>
            <p class="text-muted">{{ __('messages.about_page_approach_body') }}</p>
        </div>
    </div>
    <div class="text-center mt-5">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
    </div>
</div>
@endsection
