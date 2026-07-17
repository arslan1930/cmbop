@extends('layouts.app')

@section('title', __('messages.meta_pricing_title'))
@section('description', __('messages.meta_pricing_description'))
@section('canonical', localized_url('pricing'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.pricing_kicker'),
    'title' => __('messages.pricing_page_title'),
    'subtitle' => __('messages.pricing_page_subtitle'),
])
@include('components.pricing')
@endsection
