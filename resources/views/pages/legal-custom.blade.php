@extends('layouts.app')

@section('title', __('messages.'.$meta['meta_title']))
@section('description', __('messages.'.$meta['meta_description']))
@section('canonical', localized_url($slug))

@section('content')
@include('components.marketing-page-hero', [
    'title' => $hero,
    'subtitle' => '',
])

<div class="container py-5" style="max-width:900px;">
    @if($slug === \App\Models\LegalPageOverride::SLUG_FAQ)
        @include('components.breadcrumbs', [
            'items' => [
                ['name' => __('messages.home'), 'url' => localized_url('/')],
                ['name' => __('messages.nav_faq'), 'url' => localized_url('faq')],
            ],
        ])
    @endif
    <div class="legal-cms-body p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3; color:#555; line-height:1.7;">
        {!! $html !!}
    </div>
</div>
@endsection
