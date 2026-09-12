@php
    /** @var array<string, mixed> $lander */
    $lander = $lander ?? [];
    $landerKey = $landerKey ?? '';
    $canonical = url('/'.ltrim((string) ($lander['slug'] ?? ''), '/'));
    $faqs = is_array($lander['faqs'] ?? null) ? $lander['faqs'] : [];
    $faqEntities = [];
    foreach ($faqs as $faq) {
        $faqEntities[] = [
            '@type' => 'Question',
            'name' => (string) ($faq['q'] ?? ''),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => (string) ($faq['a'] ?? ''),
            ],
        ];
    }
@endphp

@extends('layouts.app')

@section('title', $lander['meta_title'] ?? $lander['title'] ?? 'Guest posts')
@section('description', $lander['meta_description'] ?? '')
@section('canonical', $canonical)
@section('hreflang_locales', 'en')
@section('hreflang_x_default', 'en')
@section('hreflang_path', ltrim((string) ($lander['slug'] ?? ''), '/'))

@push('head')
@if($faqEntities !== [])
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => $faqEntities,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endif
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $lander['meta_title'] ?? $lander['title'] ?? '',
    'url' => $canonical,
    'description' => $lander['meta_description'] ?? '',
    'inLanguage' => 'en-GB',
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => $lander['kicker'] ?? null,
    'title' => $lander['title'] ?? '',
    'subtitle' => $lander['subtitle'] ?? null,
])

<div class="container py-5" style="max-width: 1040px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_marketplace'), 'url' => localized_url('marketplace')],
            ['name' => $lander['title'] ?? ($lander['market'] ?? ''), 'url' => $canonical],
        ],
    ])

    @if(!empty($siteCount) || !empty($priceFrom))
        <div class="row g-3 mb-5">
            @if(!empty($priceFrom))
                <div class="col-md-6">
                    <div class="h-100 p-4 rounded-4 bg-white border">
                        <div class="small text-muted mb-1">From</div>
                        <div class="h3 mb-0" style="color:#1a585e;">€{{ number_format((float) $priceFrom, 0) }}</div>
                        <p class="small text-muted mb-0 mt-2">Lowest advertiser checkout price on verified {{ $lander['market'] ?? '' }} listings right now.</p>
                    </div>
                </div>
            @endif
            @if(!empty($siteCount))
                <div class="col-md-6">
                    <div class="h-100 p-4 rounded-4 bg-white border">
                        <div class="small text-muted mb-1">Verified sites</div>
                        <div class="h3 mb-0" style="color:#1a585e;">{{ number_format((int) $siteCount) }}</div>
                        <p class="small text-muted mb-0 mt-2">Active, verified publishers whose primary country is {{ $lander['market'] ?? 'this market' }}.</p>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <div class="row g-4 mb-5">
        @foreach(range(1, 3) as $i)
            <div class="col-md-4">
                <div class="h-100 p-4 rounded-4 bg-white border">
                    <h2 class="h5" style="color:#1a585e;">{{ $lander['point_'.$i.'_title'] ?? '' }}</h2>
                    <p class="text-muted mb-0">{{ $lander['point_'.$i.'_body'] ?? '' }}</p>
                </div>
            </div>
        @endforeach
    </div>

    @if(isset($teasers) && $teasers->isNotEmpty())
        <div class="mb-4 text-center">
            <h2 class="h4 mb-1" style="color:#1a585e;">{{ $lander['teaser_title'] ?? __('messages.marketplace_teaser_title') }}</h2>
            <p class="text-muted mb-0">{{ $lander['teaser_subtitle'] ?? __('messages.marketplace_teaser_subtitle') }}</p>
        </div>
        <div class="table-responsive mb-4 rounded-4 border bg-white">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Site</th>
                        <th>Country</th>
                        <th>Language</th>
                        <th>DR</th>
                        <th>DA</th>
                        <th>From</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($teasers as $site)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $site['name'] }}</div>
                                <div class="small text-muted">{{ $site['domain_masked'] }}</div>
                            </td>
                            <td>{{ strtoupper((string) ($site['country'] ?: '—')) }}</td>
                            <td>{{ strtoupper((string) ($site['language'] ?: '—')) }}</td>
                            <td>{{ $site['dr'] ?? '—' }}</td>
                            <td>{{ $site['da'] ?? '—' }}</td>
                            <td class="fw-semibold" style="color:#1a585e;">€{{ number_format((float) $site['price'], 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <section class="mb-5" aria-labelledby="lander-how-heading">
        <h2 id="lander-how-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.how_page_adv_title') }}</h2>
        <ol class="mb-0 ps-3">
            @foreach(range(1, 4) as $i)
                <li class="mb-2">
                    <strong>{{ __('messages.how_page_adv_step_'.$i.'_title') }}</strong>
                    — {{ __('messages.how_page_adv_step_'.$i.'_body') }}
                </li>
            @endforeach
        </ol>
        <p class="mt-3 mb-0">
            <a href="{{ localized_url('how-it-works') }}">{{ __('messages.nav_how_it_works') }}</a>
        </p>
    </section>

    @if(!empty($blogLinks))
        <section class="mb-5" aria-labelledby="lander-guides-heading">
            <h2 id="lander-guides-heading" class="h4 mb-3" style="color:#1a585e;">Related guides</h2>
            <ul class="mb-0">
                @foreach($blogLinks as $link)
                    <li class="mb-2"><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if($faqs !== [])
        <section class="mb-5" aria-labelledby="lander-faq-heading">
            <h2 id="lander-faq-heading" class="h4 mb-3" style="color:#1a585e;">{{ __('messages.nav_faq') }}</h2>
            @foreach($faqs as $faq)
                <div class="mb-3">
                    <h3 class="h6 mb-1" style="color:#1a585e;">{{ $faq['q'] ?? '' }}</h3>
                    <p class="text-muted mb-0">{{ $faq['a'] ?? '' }}</p>
                </div>
            @endforeach
        </section>
    @endif

    <div class="text-center d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
        <a href="{{ localized_url('how-it-works') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.nav_how_it_works') }}</a>
    </div>
    <p class="text-center text-muted small mt-3 mb-0">{{ __('messages.marketplace_catalog_note') }}</p>

    @if(!empty($siblings))
        <nav class="mt-5 pt-4 border-top" aria-label="Other country landers">
            <h2 class="h6 text-muted mb-3">Other markets</h2>
            <ul class="list-inline mb-0">
                @foreach($siblings as $sibling)
                    <li class="list-inline-item me-3 mb-2">
                        <a href="{{ $sibling['url'] }}">{{ $sibling['market'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
</div>
@endsection
