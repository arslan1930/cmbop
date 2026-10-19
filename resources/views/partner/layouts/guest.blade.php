<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Partner sign in') · SEOLinkBuildings</title>
    <link rel="stylesheet" href="{{ asset('assets/css/brand-colors.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/partner-dashboard.css') }}">
</head>
<body class="partner-shell">
<main class="partner-main">
    <div class="partner-auth">
        <a class="partner-brand partner-brand-gap" href="{{ \App\Support\PartnerDashboard::route('login') }}">
            <img src="{{ brand_shell_wordmark_url() }}" alt="SEOLinkBuildings">
        </a>
        <p class="partner-lead">Partner dashboard · Teqno Ltd</p>
        @if (session('success'))
            <div class="partner-alert ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="partner-alert err">{{ session('error') }}</div>
        @endif
        @if (session('main_site_url'))
            <p><a href="{{ session('main_site_url') }}">Open the main SEOLinkBuildings site</a></p>
        @endif
        @if ($errors->any())
            <div class="partner-alert err">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif
        @yield('content')
    </div>
</main>
<footer class="partner-footer">Teqno Ltd (company no. 17392540)</footer>
</body>
</html>
