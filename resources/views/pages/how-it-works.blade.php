@extends('layouts.app')

@section('title', __('messages.meta_how_it_works_title'))
@section('description', __('messages.meta_how_it_works_description'))
@section('canonical', localized_url('how-it-works'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.how_it_works_kicker'),
    'title' => __('messages.how_it_works_title'),
    'subtitle' => __('messages.how_it_works_description'),
])
@include('components.how-it-works')
<div class="container pb-5 text-center">
    <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
</div>
@endsection
