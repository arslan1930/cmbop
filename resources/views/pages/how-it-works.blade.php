@extends('layouts.app')

@section('title', __('messages.meta_how_it_works_title'))
@section('description', welcome_bonus_message('meta_how_it_works_description', 'meta_how_it_works_description_off'))
@section('canonical', localized_url('how-it-works'))

@php
    $welcomeBonusCanGrant = welcome_bonus_can_grant();
    $howToSteps = [];
    foreach (range(1, 5) as $i) {
        $howToSteps[] = [
            '@type' => 'HowToStep',
            'position' => $i,
            'name' => __('messages.how_page_adv_step_'.$i.'_title'),
            'text' => $i === 2
                ? welcome_bonus_message('how_page_adv_step_2_body', 'how_page_adv_step_2_body_off')
                : __('messages.how_page_adv_step_'.$i.'_body'),
        ];
    }

    $faqIndexes = $welcomeBonusCanGrant ? range(1, 4) : [1, 3, 4];
    $faqEntities = [];
    foreach ($faqIndexes as $i) {
        $faqEntities[] = [
            '@type' => 'Question',
            'name' => welcome_bonus_message('how_page_faq_q_'.$i),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => welcome_bonus_message('how_page_faq_a_'.$i),
            ],
        ];
    }
@endphp

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'HowTo',
    'name' => __('messages.how_page_howto_name'),
    'description' => welcome_bonus_message('meta_how_it_works_description', 'meta_how_it_works_description_off'),
    'url' => localized_url('how-it-works'),
    'inLanguage' => (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'htmlLang'))
        ? \App\Support\PublicI18n::htmlLang()
        : 'en-GB',
    'step' => $howToSteps,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => $faqEntities,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.how_it_works_kicker'),
    'title' => __('messages.how_page_title'),
    'subtitle' => __('messages.how_page_subtitle'),
])

<div class="container py-5 how-page" style="max-width: 920px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_how_it_works'), 'url' => localized_url('how-it-works')],
        ],
    ])

    {{-- Advertiser: 5 steps --}}
    <section class="mb-5" aria-labelledby="how-adv-heading">
        <h2 id="how-adv-heading" class="h4 mb-2" style="color:#1a585e;">{{ __('messages.how_page_adv_title') }}</h2>
        <p class="text-muted mb-4">{{ __('messages.how_page_adv_intro') }}</p>
        <ol class="how-page-steps list-unstyled mb-0">
            @foreach(range(1, 5) as $i)
                <li class="how-page-step mb-4">
                    <div class="d-flex gap-3">
                        <span class="how-page-step-num" aria-hidden="true">{{ $i }}</span>
                        <div>
                            <h3 class="h5 mb-2" style="color:#1a585e;">{{ __('messages.how_page_adv_step_'.$i.'_title') }}</h3>
                            <p class="text-muted mb-0">{{ $i === 2
                                ? welcome_bonus_message('how_page_adv_step_2_body', 'how_page_adv_step_2_body_off')
                                : __('messages.how_page_adv_step_'.$i.'_body') }}</p>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Money clarity --}}
    <section class="mb-5" aria-labelledby="how-money-heading">
        <h2 id="how-money-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.how_page_money_title') }}</h2>
        <ul class="text-muted mb-0 ps-3">
            <li class="mb-2">{{ __('messages.how_page_money_1') }}</li>
            <li class="mb-2">{{ __('messages.how_page_money_2') }}</li>
            <li>{{ __('messages.how_page_money_3') }}</li>
        </ul>
    </section>

    {{-- Trust callout --}}
    <section class="mb-5 how-page-trust" aria-labelledby="how-trust-heading">
        <h2 id="how-trust-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.how_page_trust_title') }}</h2>
        <p class="text-muted mb-3">{{ __('messages.how_page_trust_intro') }}</p>
        <ul class="text-muted mb-3 ps-3">
            <li class="mb-2">{{ __('messages.how_page_trust_1') }}</li>
            <li class="mb-2">{{ __('messages.how_page_trust_2') }}</li>
            <li class="mb-2">{{ __('messages.how_page_trust_3') }}</li>
            <li>{{ __('messages.how_page_trust_4') }}</li>
        </ul>
        <a href="{{ localized_url('about') }}" class="link-teal">{{ __('messages.how_page_trust_about_link') }}</a>
    </section>

    {{-- For publishers --}}
    <section class="mb-5 how-page-publishers" aria-labelledby="how-pub-heading">
        <h2 id="how-pub-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.how_page_pub_title') }}</h2>
        <p class="text-muted mb-3">{{ __('messages.how_page_pub_intro') }}</p>
        <ul class="text-muted mb-4 ps-3">
            <li class="mb-2">{{ __('messages.how_page_pub_1') }}</li>
            <li class="mb-2">{{ __('messages.how_page_pub_2') }}</li>
            <li>{{ __('messages.how_page_pub_3') }}</li>
        </ul>
        <a href="{{ localized_url('become-a-publisher') }}" class="btn btn-outline-secondary px-4">{{ __('messages.how_page_pub_cta') }}</a>
    </section>

    {{-- Light FAQ --}}
    <section class="mb-5" aria-labelledby="how-faq-heading">
        <h2 id="how-faq-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.how_page_faq_title') }}</h2>
        <div class="accordion" id="howFaqAccordion">
            @foreach($faqIndexes as $i)
                <div class="accordion-item border-0 mb-3 shadow-sm rounded-3 overflow-hidden">
                    <h3 class="accordion-header" id="howFaqHeading{{ $i }}">
                        <button class="accordion-button {{ $i > 1 ? 'collapsed' : '' }}" type="button"
                                data-bs-toggle="collapse" data-bs-target="#howFaqCollapse{{ $i }}"
                                aria-expanded="{{ $i === 1 ? 'true' : 'false' }}"
                                aria-controls="howFaqCollapse{{ $i }}">
                            {{ welcome_bonus_message('how_page_faq_q_'.$i) }}
                        </button>
                    </h3>
                    <div id="howFaqCollapse{{ $i }}" class="accordion-collapse collapse {{ $i === 1 ? 'show' : '' }}"
                         aria-labelledby="howFaqHeading{{ $i }}" data-bs-parent="#howFaqAccordion">
                        <div class="accordion-body text-muted">
                            {{ welcome_bonus_message('how_page_faq_a_'.$i) }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-muted small mb-0">
            <a href="{{ localized_url('faq') }}" class="link-teal">{{ __('messages.how_page_more_faq') }}</a>
        </p>
    </section>

    {{-- CTAs --}}
    <section class="text-center how-page-cta" aria-labelledby="how-cta-heading">
        <h2 id="how-cta-heading" class="h4 mb-2" style="color:#1a585e;">{{ __('messages.how_page_cta_title') }}</h2>
        <p class="text-muted mb-4">{{ __('messages.how_page_cta_note') }}</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
            <a href="{{ localized_url('marketplace') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.how_page_cta_marketplace') }}</a>
            <a href="{{ localized_url('become-a-publisher') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.how_page_cta_publisher') }}</a>
        </div>
    </section>
</div>

<style>
  .how-page-step-num {
    flex: 0 0 2rem;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.95rem;
    color: #1a585e;
    background: #e6f5f5;
    border: 1px solid rgba(26, 88, 94, 0.12);
    margin-top: 0.15rem;
  }
  .how-page-trust,
  .how-page-publishers {
    padding: 1.25rem 1.35rem;
    border-radius: 0.75rem;
    border: 1px solid #e2e8f0;
    background: #f7fafb;
  }
  .how-page .link-teal {
    color: #1a585e;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 0.15em;
  }
  .how-page .link-teal:hover,
  .how-page .link-teal:focus-visible {
    color: #147a82;
  }
</style>
@endsection
