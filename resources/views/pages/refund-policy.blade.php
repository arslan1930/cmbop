@extends('layouts.app')

@section('title', __('messages.meta_refund_title'))
@section('description', __('messages.meta_refund_description'))
@section('canonical', localized_url('refund-policy'))

@section('content')
@include('components.marketing-page-hero', [
    'title' => __('messages.refund_title'),
    'subtitle' => __('messages.refund_subtitle'),
])

<div class="container py-5" style="max-width: 800px;">
    @foreach(range(1, 4) as $i)
        <h2 class="h5 mt-4" style="color:#0b6266;">{{ __('messages.refund_section_'.$i.'_title') }}</h2>
        <p class="text-muted">{{ __('messages.refund_section_'.$i.'_body') }}</p>
    @endforeach
</div>
@endsection
