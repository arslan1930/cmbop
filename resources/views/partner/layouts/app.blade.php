<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Partner dashboard') · SEOLinkBuildings</title>
    <link rel="stylesheet" href="{{ asset('assets/css/brand-colors.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/partner-dashboard.css') }}">
</head>
<body class="partner-shell">
@php
    $scoped = session('partner_scope_level') === 'order';
    $nav = [
        ['home', 'Home', 'partner.home'],
        ['orders', 'Orders', 'partner.orders.index'],
        ['sites', 'My sites', 'partner.sites.index'],
        ['money', 'Money', 'partner.money.index'],
        ['settings', 'Settings', 'partner.settings.index'],
    ];
    $current = trim(request()->path(), '/');
@endphp
<header class="partner-top">
    <a class="partner-brand" href="{{ \App\Support\PartnerDashboard::route('home') }}">
        <img src="{{ brand_shell_wordmark_url() }}" alt="SEOLinkBuildings">
        <span>Partner dashboard<small>Teqno Ltd</small></span>
    </a>
    <nav class="partner-nav" aria-label="Partner">
        @foreach ($nav as [$key, $label, $route])
            @php
                $href = \App\Support\PartnerDashboard::route(str_replace('partner.', '', $route));
            @endphp
            <a href="{{ $href }}" class="{{ str_contains($current, explode('.', $route)[1] ?? $key) || ($key === 'home' && ($current === '' || str_ends_with($current, 'partners'))) ? 'is-active' : '' }}">{{ $label }}</a>
        @endforeach
        @auth
            <form method="post" action="{{ \App\Support\PartnerDashboard::route('logout') }}">
                @csrf
                <button class="partner-btn secondary" type="submit">Sign out</button>
            </form>
        @endauth
    </nav>
</header>
@if ($scoped)
    <div class="partner-scope-banner">You opened this order from an email link. Sign in for Money, Settings, and other orders.</div>
@endif
<main class="partner-main">
    @if (session('success'))
        <div class="partner-alert ok" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="partner-alert err" role="alert">{{ session('error') }}</div>
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
</main>
<footer class="partner-footer">Teqno Ltd (company no. 17392540) · English only · No fees for partners</footer>
</body>
</html>
