@extends('layouts.app')

@section('title', 'Is SEOLinkBuildings safe? Verified publishers, held payments, honest answers')
@section('description', 'How SEOLinkBuildings works: verified publishers, no PBNs, payment held until you approve the live link, sponsored/nofollow options, Google link policy explained, company details and how to report issues.')
@section('canonical', url('/is-seolinkbuildings-safe'))

@php
$faq = [
 ['Is SEOLinkBuildings a legitimate company?', 'Yes. SEOLinkBuildings (seolinkbuildings.com) has operated since 2024 and is run by Topurlz Ltd (company no. 16607074), registered in England and Wales, at 20 Wenlock Road, London N1 7GU. You can check it on Companies House. The founder is M. Arslan.'],
 ['Are the publishers verified?', 'Every publisher site is reviewed before it goes live in the catalogue. Verified sites carry a Verified badge and can be filtered in the catalogue. Each site can also show advertiser ratings and a completion rate based on successful vs cancelled orders, so you can judge it on more than DA/DR.'],
 ['Do you sell PBN links or link packages?', 'No. You pick individual publisher sites with listed prices and metrics. We do not sell private blog networks or mystery link packages, and content is moderated before publishers publish it.'],
 ['Is my payment protected?', 'Yes. When you order, your money is held by us before the publisher starts work. The publisher is only paid after you approve the live URL (or automatically 72 hours after the publisher submits it, if you do not respond). If the publisher cannot deliver, rejects the order, or a case is upheld, wallet and card orders are returned as wallet credit and PayPal orders to the paying PayPal account.'],
 ['What if a link is removed later?', 'You can report a removed link from the completed order for 30 days after completion. If we uphold the report, the publisher payout is clawed back and refunded to you.'],
 ['Can I get sponsored or nofollow links?', 'Yes. Each listing shows its link type (dofollow or nofollow) and whether the site marks posts as sponsored, and you can filter by these. If you want your placement labelled with rel="sponsored", pick a site that offers it or ask the publisher in the order chat.'],
 ['Does buying guest posts break Google\'s rules?', 'We will be honest: Google\'s spam policies say links bought mainly to pass ranking value should be qualified with rel="sponsored" or rel="nofollow". Paid placements that do not do this carry a risk. We are a marketplace, not a ranking guarantee. We recommend relevant, editorial placements on real sites with real readers, for traffic, brand visibility and digital PR, and using sponsored attribution where it applies. You choose the site, the link type and the content, so you stay in control of your own risk.'],
 ['How do I report a problem or a suspicious site?', 'For an open order, use the order chat with the order ID. For a removed link, report it from the completed order. You can also use Help & feedback after logging in, the Contact page, or email support@seolinkbuildings.com. We review reports about publisher sites and can deactivate listings that break our rules.'],
];
$schema = ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>array_map(fn($q)=>['@type'=>'Question','name'=>$q[0],'acceptedAnswer'=>['@type'=>'Answer','text'=>$q[1]]],$faq)];
@endphp

@push('head')
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'title' => 'Is SEOLinkBuildings safe?',
    'subtitle' => 'Short answer: verified publishers, no PBNs, your money held until you approve the live link, and a UK-registered company behind it. Here are the details, including the honest part about Google\'s link rules.',
])

<div class="container py-5" style="max-width: 800px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => 'Is SEOLinkBuildings safe?', 'url' => url('/is-seolinkbuildings-safe')],
        ],
    ])
    @foreach($faq as $i => $q)
    <section class="mb-4" aria-labelledby="safe-q-{{ $i }}">
        <h2 id="safe-q-{{ $i }}" class="h5 mt-4" style="color:#1a585e;">{{ $q[0] }}</h2>
        <p class="text-muted">{{ $q[1] }}</p>
    </section>
    @endforeach
    <p class="mt-5"><a href="{{ localized_url('how-it-works') }}" class="link-teal">How it works</a> · <a href="{{ localized_url('refund-policy') }}" class="link-teal">Refund policy</a> · <a href="{{ localized_url('about') }}" class="link-teal">About us</a></p>
</div>
@endsection
