@extends('layouts.app')

@section('title', __('messages.meta_refund_title'))
@section('description', welcome_bonus_message('meta_refund_description', 'meta_refund_description_off'))
@section('canonical', localized_url('refund-policy'))

@php
    $supportEmail = trim((string) (config('billing.company.support_email') ?? ''));
    if ($supportEmail === '') {
        $supportEmail = 'support@seolinkbuildings.com';
    }

    $faqEntities = [];
    foreach (range(1, 4) as $i) {
        $faqEntities[] = [
            '@type' => 'Question',
            'name' => $i === 4
                ? welcome_bonus_message('refund_faq_q_4', 'refund_faq_q_4_off')
                : __('messages.refund_faq_q_'.$i),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => __('messages.refund_faq_a_'.$i),
            ],
        ];
    }
@endphp

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => __('messages.meta_refund_title'),
    'url' => localized_url('refund-policy'),
    'description' => welcome_bonus_message('meta_refund_description', 'meta_refund_description_off'),
    'inLanguage' => (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'htmlLang'))
        ? \App\Support\PublicI18n::htmlLang()
        : 'en-GB',
    'dateModified' => __('messages.refund_last_updated_iso'),
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
    'kicker' => __('messages.refund_kicker'),
    'title' => __('messages.refund_title'),
    'subtitle' => __('messages.refund_subtitle'),
])

<div class="container py-5 refund-page" style="max-width: 800px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.refund_policy'), 'url' => localized_url('refund-policy')],
        ],
    ])

    <p class="text-muted small mb-4">{{ __('messages.refund_last_updated_label') }}: {{ __('messages.refund_last_updated_display') }}</p>

    @foreach(range(1, 9) as $i)
        <section class="mb-4" aria-labelledby="refund-section-{{ $i }}">
            <h2 id="refund-section-{{ $i }}" class="h5 mt-4" style="color:#1a585e;">{{ $i === 2
                ? welcome_bonus_message('refund_section_2_title', 'refund_section_2_title_off')
                : __('messages.refund_section_'.$i.'_title') }}</h2>
            <p class="text-muted mb-0">{{ $i === 2
                ? welcome_bonus_message('refund_section_2_body', 'refund_section_2_body_off')
                : __('messages.refund_section_'.$i.'_body') }}</p>
        </section>
    @endforeach

    <section class="mb-5 mt-5" aria-labelledby="refund-faq-heading">
        <h2 id="refund-faq-heading" class="h5 mb-3" style="color:#1a585e;">{{ __('messages.refund_faq_title') }}</h2>
        <div class="accordion" id="refundFaqAccordion">
            @foreach(range(1, 4) as $i)
                <div class="accordion-item border-0 mb-3 shadow-sm rounded-3 overflow-hidden">
                    <h3 class="accordion-header" id="refundFaqHeading{{ $i }}">
                        <button class="accordion-button {{ $i > 1 ? 'collapsed' : '' }}" type="button"
                                data-bs-toggle="collapse" data-bs-target="#refundFaqCollapse{{ $i }}"
                                aria-expanded="{{ $i === 1 ? 'true' : 'false' }}"
                                aria-controls="refundFaqCollapse{{ $i }}">
                            {{ $i === 4
                                ? welcome_bonus_message('refund_faq_q_4', 'refund_faq_q_4_off')
                                : __('messages.refund_faq_q_'.$i) }}
                        </button>
                    </h3>
                    <div id="refundFaqCollapse{{ $i }}" class="accordion-collapse collapse {{ $i === 1 ? 'show' : '' }}"
                         aria-labelledby="refundFaqHeading{{ $i }}" data-bs-parent="#refundFaqAccordion">
                        <div class="accordion-body text-muted">
                            {{ __('messages.refund_faq_a_'.$i) }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="refund-page-links text-muted small" aria-label="{{ __('messages.refund_related_label') }}">
        <p class="mb-2">{{ __('messages.refund_related_intro') }}</p>
        <ul class="list-unstyled mb-0">
            <li class="mb-1"><a href="{{ localized_url('how-it-works') }}" class="link-teal">{{ __('messages.nav_how_it_works') }}</a></li>
            <li class="mb-1"><a href="{{ localized_url('become-a-publisher') }}" class="link-teal">{{ __('messages.nav_become_publisher') }}</a></li>
            <li class="mb-1"><a href="{{ localized_url('terms-of-services') }}" class="link-teal">{{ __('messages.terms_of_service') }}</a></li>
            <li class="mb-1"><a href="{{ localized_url('contact') }}" class="link-teal">{{ __('messages.contact') }}</a></li>
            <li><a href="mailto:{{ $supportEmail }}" class="link-teal">{{ $supportEmail }}</a></li>
        </ul>
    </section>
</div>

<style>
  .refund-page .link-teal {
    color: #1a585e;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 0.15em;
  }
  .refund-page .link-teal:hover,
  .refund-page .link-teal:focus-visible {
    color: #147a82;
  }
</style>
@endsection
