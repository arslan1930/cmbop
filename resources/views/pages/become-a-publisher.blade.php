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
    <div class="row g-4 mb-5">
        @foreach(range(1, 3) as $i)
            <div class="col-md-4">
                <h2 class="h5" style="color:#185054;">{{ __('messages.become_publisher_point_'.$i.'_title') }}</h2>
                <p class="text-muted">{{ __('messages.become_publisher_point_'.$i.'_body') }}</p>
            </div>
        @endforeach
    </div>
    <div class="text-center">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.become_publisher_cta') }}</a>
    </div>
</div>
@endsection
