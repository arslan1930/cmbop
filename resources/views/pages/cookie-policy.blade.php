@extends('layouts.app')

@section('title', __('messages.meta_cookie_title'))
@section('description', __('messages.meta_cookie_description'))
@section('canonical', localized_url('cookie-policy'))

@section('content')
@include('components.marketing-page-hero', [
    'title' => __('messages.cookie_title'),
    'subtitle' => __('messages.cookie_subtitle'),
])

<div class="container py-5" style="max-width: 800px;">
    @foreach(range(1, 4) as $i)
        <h2 class="h5 mt-4" style="color:#185054;">{{ __('messages.cookie_section_'.$i.'_title') }}</h2>
        <p class="text-muted">{{ __('messages.cookie_section_'.$i.'_body') }}</p>
    @endforeach
</div>
@endsection
