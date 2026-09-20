@extends('layouts.app')

@section('title', __('messages.meta_about_title'))
@section('description', __('messages.meta_about_description'))
@section('canonical', localized_url('about'))

@php
    $company = $company ?? config('billing.company', []);
    $companiesHouseUrl = $companiesHouseUrl
        ?? ('https://find-and-update.company-information.service.gov.uk/company/'.($company['registration_no'] ?? '16607074'));
    $stats = $stats ?? [
        'sites' => null,
        'countries' => null,
        'completed_orders' => null,
        'verified_sites' => null,
        'rated_sites' => null,
    ];
    $blogLinks = $blogLinks ?? [];
    // Empty-string env values must not produce mailto: or schema email:"".
    $supportEmail = trim((string) ($company['support_email'] ?? ''));
    if ($supportEmail === '') {
        $supportEmail = 'support@seolinkbuildings.com';
    }
    $registrationNo = $company['registration_no'] ?? '16607074';
    $legalName = $company['legal_name'] ?? 'SEOLinkBuildings Partners with (Topurlz LTD)';
    $address = implode(', ', $company['address_lines'] ?? ['20 Wenlock Road, London, England, N1 7GU']);

    $faqEntities = [];
    foreach (range(1, 6) as $i) {
        $faqEntities[] = [
            '@type' => 'Question',
            'name' => __('messages.about_page_faq_q_'.$i),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $i === 3
                    ? welcome_bonus_message('about_page_faq_a_3', 'about_page_faq_a_3_off')
                    : __('messages.about_page_faq_a_'.$i),
            ],
        ];
    }

    $hasNumericProof = ($stats['sites'] ?? null)
        || ($stats['countries'] ?? null)
        || ($stats['completed_orders'] ?? null)
        || ($stats['verified_sites'] ?? null)
        || ($stats['rated_sites'] ?? null);
@endphp

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'AboutPage',
    'name' => __('messages.meta_about_title'),
    'url' => localized_url('about'),
    'description' => __('messages.meta_about_description'),
    'inLanguage' => (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'htmlLang'))
        ? \App\Support\PublicI18n::htmlLang()
        : 'en-GB',
    'mainEntity' => class_exists(\App\Support\BrandOrganization::class)
        ? \App\Support\BrandOrganization::schema([
        'foundingLocation' => [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'London',
                'addressCountry' => 'GB',
            ],
        ],
        'areaServed' => [
            ['@type' => 'Continent', 'name' => 'Europe'],
            ['@type' => 'Country', 'name' => 'Germany'],
            ['@type' => 'Country', 'name' => 'France'],
            ['@type' => 'Country', 'name' => 'Netherlands'],
            ['@type' => 'Country', 'name' => 'United Kingdom'],
            ['@type' => 'Country', 'name' => 'United States'],
        ],
        'knowsAbout' => [
            'Guest posts',
            'Dofollow backlinks',
            'Digital PR',
            'Link building marketplace',
            'Publisher outreach',
            'European SEO',
            'Publisher ratings',
            'Order completion tracking',
            'Verified publishers',
        ],
    ])
        : ['@type' => 'Organization', 'name' => 'SEOLinkBuildings'],
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
    'kicker' => __('messages.about_page_kicker'),
    'title' => __('messages.about_page_title'),
    'subtitle' => __('messages.about_page_subtitle'),
])

<div class="container py-5 about-page" style="max-width: 920px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_about'), 'url' => localized_url('about')],
        ],
    ])

    {{-- Mission + approach --}}
    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <h2 class="h4" style="color:#1a585e;">{{ __('messages.about_page_mission_title') }}</h2>
            <p class="text-muted mb-0">{{ __('messages.about_page_mission_body') }}</p>
        </div>
        <div class="col-md-6">
            <h2 class="h4" style="color:#1a585e;">{{ __('messages.about_page_approach_title') }}</h2>
            <p class="text-muted mb-0">{{ __('messages.about_page_approach_body') }}</p>
        </div>
    </div>

    {{-- Who we serve --}}
    <section class="mb-5" aria-labelledby="about-who-heading">
        <h2 id="about-who-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_who_title') }}</h2>
        <div class="row g-4">
            <div class="col-md-6">
                <h3 class="h6" style="color:#1a585e;">{{ __('messages.about_page_who_advertisers_title') }}</h3>
                <p class="text-muted mb-0">{{ welcome_bonus_message('about_page_who_advertisers_body', 'about_page_who_advertisers_body_off') }}</p>
            </div>
            <div class="col-md-6">
                <h3 class="h6" style="color:#1a585e;">{{ __('messages.about_page_who_publishers_title') }}</h3>
                <p class="text-muted mb-0">{{ __('messages.about_page_who_publishers_body') }}</p>
            </div>
        </div>
    </section>

    {{-- Europe focus --}}
    <section class="mb-5 about-europe" aria-labelledby="about-europe-heading">
        <h2 id="about-europe-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_europe_title') }}</h2>
        <p class="text-muted">{{ __('messages.about_page_europe_body') }}</p>
        <ul class="text-muted mb-0 ps-3">
            <li class="mb-2">{{ __('messages.about_page_europe_example_1') }}</li>
            <li class="mb-2">{{ __('messages.about_page_europe_example_2') }}</li>
            <li>{{ __('messages.about_page_europe_example_3') }}</li>
        </ul>
    </section>

    {{-- Trust & delivery signals: mechanics first, live counts only when available --}}
    <section class="mb-5 about-proof" aria-labelledby="about-proof-heading">
        <h2 id="about-proof-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_proof_title') }}</h2>
        <p class="text-muted">{{ __('messages.about_page_proof_intro') }}</p>
        <ul class="text-muted mb-4 ps-3">
            <li class="mb-2">{{ __('messages.about_page_trust_ratings') }}</li>
            <li class="mb-2">{{ __('messages.about_page_trust_completions') }}</li>
            <li class="mb-2">{{ __('messages.about_page_trust_verified') }}</li>
            <li>{{ __('messages.about_page_trust_reach') }}</li>
        </ul>
        <ul class="about-proof-list list-unstyled row g-3 mb-0">
            @if(!empty($stats['sites']))
                <li class="col-sm-6 col-lg-4">
                    <div class="about-proof-item">
                        <span class="about-proof-value">{{ number_format($stats['sites']) }}</span>
                        <span class="about-proof-label">{{ __('messages.about_page_proof_sites') }}</span>
                    </div>
                </li>
            @endif
            @if(!empty($stats['verified_sites']))
                <li class="col-sm-6 col-lg-4">
                    <div class="about-proof-item">
                        <span class="about-proof-value">{{ number_format($stats['verified_sites']) }}</span>
                        <span class="about-proof-label">{{ __('messages.about_page_proof_verified') }}</span>
                    </div>
                </li>
            @endif
            @if(!empty($stats['rated_sites']))
                <li class="col-sm-6 col-lg-4">
                    <div class="about-proof-item">
                        <span class="about-proof-value">{{ number_format($stats['rated_sites']) }}</span>
                        <span class="about-proof-label">{{ __('messages.about_page_proof_rated') }}</span>
                    </div>
                </li>
            @endif
            @if(!empty($stats['countries']))
                <li class="col-sm-6 col-lg-4">
                    <div class="about-proof-item">
                        <span class="about-proof-value">{{ number_format($stats['countries']) }}</span>
                        <span class="about-proof-label">{{ __('messages.about_page_proof_countries') }}</span>
                    </div>
                </li>
            @endif
            @if(!empty($stats['completed_orders']))
                <li class="col-sm-6 col-lg-4">
                    <div class="about-proof-item">
                        <span class="about-proof-value">{{ number_format($stats['completed_orders']) }}</span>
                        <span class="about-proof-label">{{ __('messages.about_page_proof_orders') }}</span>
                    </div>
                </li>
            @endif
            <li class="col-sm-6 col-lg-4">
                <div class="about-proof-item about-proof-item--text">
                    <span class="about-proof-label">{{ __('messages.about_page_proof_wallet') }}</span>
                </div>
            </li>
            @if(welcome_bonus_can_grant())
            <li class="col-sm-6 col-lg-4">
                <div class="about-proof-item about-proof-item--text">
                    <span class="about-proof-label">{{ welcome_bonus_message('about_page_proof_bonus') }}</span>
                </div>
            </li>
            @endif
            <li class="col-sm-6 col-lg-4">
                <div class="about-proof-item about-proof-item--text">
                    <span class="about-proof-label">{{ __('messages.about_page_proof_legal') }}</span>
                </div>
            </li>
        </ul>
        @unless($hasNumericProof)
            <p class="visually-hidden">{{ __('messages.about_page_proof_fallback_note') }}</p>
        @endunless
    </section>

    {{-- Workflow --}}
    <section class="mb-5" aria-labelledby="about-workflow-heading">
        <h2 id="about-workflow-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_workflow_title') }}</h2>
        <p class="text-muted">{{ __('messages.about_page_workflow_body') }}</p>
        <p class="text-muted">{{ __('messages.about_page_workflow_trust') }}</p>
        <a href="{{ localized_url('how-it-works') }}" class="link-teal">{{ __('messages.nav_how_it_works') }}</a>
    </section>

    {{-- Dual CTAs --}}
    <section class="mb-5 text-center about-cta" aria-labelledby="about-cta-heading">
        <h2 id="about-cta-heading" class="h4 mb-2" style="color:#1a585e;">{{ __('messages.about_page_cta_title') }}</h2>
        <p class="text-muted mb-4">{{ __('messages.about_page_cta_note') }}</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
            <a href="{{ localized_url('marketplace') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.about_page_cta_marketplace') }}</a>
            <a href="{{ localized_url('become-a-publisher') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.about_page_cta_publisher') }}</a>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="mb-5" aria-labelledby="about-faq-heading">
        <h2 id="about-faq-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_faq_title') }}</h2>
        <div class="accordion" id="aboutFaqAccordion">
            @foreach(range(1, 6) as $i)
                <div class="accordion-item border-0 mb-3 shadow-sm rounded-3 overflow-hidden">
                    <h3 class="accordion-header" id="aboutFaqHeading{{ $i }}">
                        <button class="accordion-button {{ $i > 1 ? 'collapsed' : '' }}" type="button"
                                data-bs-toggle="collapse" data-bs-target="#aboutFaqCollapse{{ $i }}"
                                aria-expanded="{{ $i === 1 ? 'true' : 'false' }}"
                                aria-controls="aboutFaqCollapse{{ $i }}">
                            {{ __('messages.about_page_faq_q_'.$i) }}
                        </button>
                    </h3>
                    <div id="aboutFaqCollapse{{ $i }}" class="accordion-collapse collapse {{ $i === 1 ? 'show' : '' }}"
                         aria-labelledby="aboutFaqHeading{{ $i }}" data-bs-parent="#aboutFaqAccordion">
                        <div class="accordion-body text-muted">
                            {{ $i === 3
                                ? welcome_bonus_message('about_page_faq_a_3', 'about_page_faq_a_3_off')
                                : __('messages.about_page_faq_a_'.$i) }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Blog pillars --}}
    @if(count($blogLinks) > 0)
        <section class="mb-5" aria-labelledby="about-blog-heading">
            <h2 id="about-blog-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_blog_title') }}</h2>
            <p class="text-muted">{{ __('messages.about_page_blog_intro') }}</p>
            <ul class="list-unstyled mb-0">
                @foreach($blogLinks as $link)
                    <li class="mb-2">
                        <a href="{{ $link['url'] }}" class="link-teal">{{ $link['title'] }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Team / operator (no invented bios) --}}
    <section class="mb-5" aria-labelledby="about-team-heading">
        <h2 id="about-team-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_team_title') }}</h2>
        <p class="text-muted mb-2">{{ __('messages.about_page_team_body') }}</p>
        <p class="mb-0 fw-semibold" style="color:#1a585e;">{{ __('messages.about_page_operated_by') }}</p>
    </section>

    {{-- Legal / Companies House --}}
    <div class="mt-4 p-4 rounded-3 about-company" style="background:#f7fafb; border:1px solid #e2e8f0;">
        <h2 class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_company_title') }}</h2>
        <p class="text-muted mb-3">{{ __('messages.about_page_company_body') }}</p>
        <ul class="list-unstyled text-muted mb-0 small">
            <li class="mb-2"><strong>{{ __('messages.about_page_legal_label') }}:</strong> {{ $legalName }}</li>
            <li class="mb-2">
                <strong>{{ __('messages.about_page_reg_label') }}:</strong>
                {{ $registrationNo }}
                —
                <a href="{{ $companiesHouseUrl }}" rel="noopener noreferrer" target="_blank" class="link-teal">
                    {{ __('messages.about_page_companies_house') }}
                </a>
            </li>
            <li class="mb-2"><strong>{{ __('messages.about_page_address_label') }}:</strong> {{ $address }}</li>
            <li class="mb-2">
                <strong>{{ __('messages.about_page_email_label') }}:</strong>
                <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
            </li>
            <li><strong>{{ __('messages.about_page_markets_label') }}:</strong> {{ __('messages.about_page_markets_body') }}</li>
        </ul>
    </div>

    <section class="mt-4 p-4 rounded-3 about-security" style="background:#f7fafb; border:1px solid #e2e8f0;" aria-labelledby="about-security-heading">
        <h2 id="about-security-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_security_title') }}</h2>
        <p class="text-muted mb-3">{{ __('messages.about_page_security_body') }}</p>
        @include('partials.payment-trust', ['compact' => false, 'showMethods' => true])
        <p class="mb-0 mt-3 small">
            <a href="{{ localized_url('privacy-policy') }}" class="link-teal">{{ __('messages.privacy_policy') }}</a>
            <span class="text-muted"> · </span>
            <a href="{{ localized_url('refund-policy') }}" class="link-teal">{{ __('messages.refund_policy') }}</a>
        </p>
    </section>
</div>

<style>
  .about-proof-item {
    height: 100%;
    padding: 1rem 1.1rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: #fff;
  }
  .about-proof-value {
    display: block;
    font-size: 1.5rem;
    font-weight: 800;
    color: #1a585e;
    line-height: 1.2;
    margin-bottom: 0.25rem;
  }
  .about-proof-label {
    display: block;
    color: var(--brand-ink-muted, #75787B);
    font-size: 0.92rem;
    line-height: 1.4;
  }
  .about-proof-item--text {
    display: flex;
    align-items: center;
  }
  .about-page .link-teal {
    color: #1a585e;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 0.15em;
  }
  .about-page .link-teal:hover,
  .about-page .link-teal:focus-visible {
    color: #147a82;
  }
</style>
@endsection
