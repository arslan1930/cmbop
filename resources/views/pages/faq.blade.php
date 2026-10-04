@extends('layouts.app')

@section('title', __('messages.meta_faq_title'))
@section('description', __('messages.meta_faq_description'))
@section('canonical', localized_url('faq'))

@php
    $usePublishedFaq = function_exists('public_locale')
        && public_locale() === 'en'
        && class_exists(\App\Support\PublicFaq::class);
    $publishedFaq = $usePublishedFaq ? \App\Support\PublicFaq::items() : [];
    $welcomeBonusCanGrant = welcome_bonus_can_grant();
    // FAQ #4 advertises a new-advertiser grant — hide it when grants will not happen.
    $faqIndexes = $welcomeBonusCanGrant ? range(1, 6) : [1, 2, 3, 5, 6];
    $faqEntities = [];
    if ($usePublishedFaq) {
        $faqSchema = \App\Support\PublicFaq::schema();
    } else {
        foreach ($faqIndexes as $i) {
            $faqEntities[] = [
                '@type' => 'Question',
                'name' => __('messages.faq_q_'.$i),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => __('messages.faq_a_'.$i),
                ],
            ];
        }
        $faqSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faqEntities,
        ];
    }
@endphp

@push('head')
<script type="application/ld+json">
{!! \App\Support\BrandOrganization::jsonLd($faqSchema) !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.faq_kicker'),
    'title' => __('messages.faq_title'),
    'subtitle' => __('messages.faq_subtitle'),
])

<div class="container py-5" style="max-width: 800px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_faq'), 'url' => localized_url('faq')],
        ],
    ])
    <div class="accordion" id="faqAccordion">
        @if($usePublishedFaq)
            @foreach($publishedFaq as $loopIndex => $item)
                <div class="accordion-item border-0 mb-3 shadow-sm rounded-3 overflow-hidden">
                    <h2 class="accordion-header" id="faqHeading{{ $loopIndex }}">
                        <button class="accordion-button {{ $loopIndex > 0 ? 'collapsed' : '' }}" type="button"
                                data-bs-toggle="collapse" data-bs-target="#faqCollapse{{ $loopIndex }}"
                                aria-expanded="{{ $loopIndex === 0 ? 'true' : 'false' }}"
                                aria-controls="faqCollapse{{ $loopIndex }}">
                            {{ $item['q'] }}
                        </button>
                    </h2>
                    <div id="faqCollapse{{ $loopIndex }}" class="accordion-collapse collapse {{ $loopIndex === 0 ? 'show' : '' }}"
                         aria-labelledby="faqHeading{{ $loopIndex }}" data-bs-parent="#faqAccordion">
                        <div class="accordion-body text-muted">
                            {{ $item['a'] }}
                        </div>
                    </div>
                </div>
            @endforeach
        @else
            @foreach($faqIndexes as $loopIndex => $i)
                <div class="accordion-item border-0 mb-3 shadow-sm rounded-3 overflow-hidden">
                    <h2 class="accordion-header" id="faqHeading{{ $i }}">
                        <button class="accordion-button {{ $loopIndex > 0 ? 'collapsed' : '' }}" type="button"
                                data-bs-toggle="collapse" data-bs-target="#faqCollapse{{ $i }}"
                                aria-expanded="{{ $loopIndex === 0 ? 'true' : 'false' }}"
                                aria-controls="faqCollapse{{ $i }}">
                            {{ __('messages.faq_q_'.$i) }}
                        </button>
                    </h2>
                    <div id="faqCollapse{{ $i }}" class="accordion-collapse collapse {{ $loopIndex === 0 ? 'show' : '' }}"
                         aria-labelledby="faqHeading{{ $i }}" data-bs-parent="#faqAccordion">
                        <div class="accordion-body text-muted">
                            {{ __('messages.faq_a_'.$i) }}
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</div>
@endsection
