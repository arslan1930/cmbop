<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $pageTitle = trim($__env->yieldContent('title')) ?: __('messages.meta_default_title');
        $pageDescription = trim($__env->yieldContent('description'))
            ?: __('messages.meta_default_description');
        $pageCanonical = trim($__env->yieldContent('canonical')) ?: url()->current();
        $pageImage = trim($__env->yieldContent('og_image')) ?: asset('assets/brand/web/og-share-1200x630.png');
        $pageType = trim($__env->yieldContent('og_type')) ?: 'website';
        $hreflangTags = \App\Support\PublicI18n::hreflangTags(request());
    @endphp
    @include('components.favicon')
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $pageCanonical }}">
    @foreach($hreflangTags as $tag)
        <link rel="alternate" hreflang="{{ $tag['hreflang'] }}" href="{{ $tag['href'] }}">
    @endforeach
    <meta property="og:locale" content="{{ app()->getLocale() === 'en' ? 'en_US' : app()->getLocale().'_'.strtoupper(app()->getLocale()) }}">
    <meta property="og:type" content="{{ $pageType }}">
    <meta property="og:site_name" content="SEOLinkBuildings">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $pageCanonical }}">
    <meta property="og:image" content="{{ $pageImage }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $pageImage }}">
    @stack('head')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="{{ asset('css/type-system.css') }}?v={{ @filemtime(public_path('css/type-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/brand-colors.css') }}?v={{ @filemtime(public_path('css/brand-colors.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/spacing-system.css') }}?v={{ @filemtime(public_path('css/spacing-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/button-system.css') }}?v={{ @filemtime(public_path('css/button-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/form-system.css') }}?v={{ @filemtime(public_path('css/form-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/glass-tip.css') }}?v={{ @filemtime(public_path('css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/marketing-saas.css') }}?v={{ @filemtime(public_path('css/marketing-saas.css')) ?: '1' }}" rel="stylesheet">
    <script src="{{ asset('js/glass-tip.js') }}?v={{ @filemtime(public_path('js/glass-tip.js')) ?: '1' }}" defer></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        /* Optional: style for back-to-top button */
        #backToTop {
            width: 50px;
            height: 50px;
            display: none;
            position: fixed;
            /* Sit above the Help & feedback button (bottom-right) so they don't overlap */
            bottom: 96px;
            right: 24px;
            z-index: 1000;
        }
        @media (max-width: 576px) {
            #backToTop { bottom: 84px; right: 16px; }
        }
    </style>
</head>
<body class="slb-marketing">

@include('components.navbar')
@include('components.language-suggestion')

<div class="container-fluid px-3 px-md-4">
    @include('components.site-announcements', ['audience' => 'public'])
    @include('components.ad-banners', ['placement' => 'header', 'audience' => 'public'])
</div>

@yield('content')

<div class="container-fluid px-3 px-md-4">
    @include('components.ad-banners', ['placement' => 'content_bottom', 'audience' => 'public'])
    @include('components.ad-banners', ['placement' => 'footer', 'audience' => 'public'])
</div>

@include('components.footer')
@include('components.help-feedback-widget')

<!-- Back to Top Button -->
<button id="backToTop" class="btn btn-danger rounded-circle shadow-lg" aria-label="Back to top">
    <i class="fas fa-arrow-up"></i>
</button>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    // Show/hide back-to-top button
    $(window).scroll(function() {
        if ($(this).scrollTop() > 200) {
            $('#backToTop').fadeIn();
        } else {
            $('#backToTop').fadeOut();
        }
    });

    // Smooth scroll to top
    $('#backToTop').click(function() {
        $('html, body').animate({scrollTop: 0}, 600);
        return false;
    });
});
</script>

</body>
</html>