@extends('layouts.app')

@section('title', __('messages.meta_why_choose_title'))
@section('description', __('messages.meta_why_choose_description'))
@section('canonical', localized_url('why-choose-us'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.why_choose_kicker'),
    'title' => __('messages.why_choose_title'),
    'subtitle' => __('messages.why_choose_subtitle'),
])

<div class="container py-5">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.why_choose_title'), 'url' => localized_url('why-choose-us')],
        ],
    ])
    <div class="row g-4">
        @foreach(range(1, 4) as $i)
            <div class="col-md-6">
                <div class="p-4 h-100 rounded-4 border bg-white shadow-sm">
                    <h2 class="h5" style="color:#1a585e;">{{ __('messages.why_choose_point_'.$i.'_title') }}</h2>
                    <p class="text-muted mb-0">{{ __('messages.why_choose_point_'.$i.'_body') }}</p>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
