<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Verified traffic preview') — SEOLinkBuildings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Draft mockup only. Demo data. Not a live feature.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.favicon')
    @include('partials.shell-wordmark-preload')

    <link href="{{ asset('assets/vendor/bootstrap-5.3.0/bootstrap.min.css') }}?v={{ @filemtime(public_path('assets/vendor/bootstrap-5.3.0/bootstrap.min.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/slb-icons.css') }}?v={{ @filemtime(public_path('assets/css/slb-icons.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/type-system.css') }}?v={{ @filemtime(public_path('assets/css/type-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/brand-colors.css') }}?v={{ @filemtime(public_path('assets/css/brand-colors.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/spacing-system.css') }}?v={{ @filemtime(public_path('assets/css/spacing-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/button-system.css') }}?v={{ @filemtime(public_path('assets/css/button-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/form-system.css') }}?v={{ @filemtime(public_path('assets/css/form-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/app-shell.css') }}?v={{ @filemtime(public_path('assets/css/app-shell.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/interaction.css') }}?v={{ @filemtime(public_path('assets/css/interaction.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/glass-tip.css') }}?v={{ @filemtime(public_path('assets/css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/dialog-system.css') }}?v={{ @filemtime(public_path('assets/css/dialog-system.css')) ?: '1' }}" rel="stylesheet">
    @if(($shell ?? '') === 'advertiser')
        <link href="{{ asset('assets/css/catalog.css') }}?v={{ @filemtime(public_path('assets/css/catalog.css')) ?: '1' }}" rel="stylesheet">
    @endif
    @if(($shell ?? '') === 'publisher')
        <link href="{{ asset('assets/css/publisher-dashboard.css') }}?v={{ @filemtime(public_path('assets/css/publisher-dashboard.css')) ?: '1' }}" rel="stylesheet">
    @endif
    @if(($shell ?? '') === 'admin')
        <link href="{{ asset('assets/css/admin-shell.css') }}?v={{ @filemtime(public_path('assets/css/admin-shell.css')) ?: '1' }}" rel="stylesheet">
        <link href="{{ asset('assets/css/admin-components.css') }}?v={{ @filemtime(public_path('assets/css/admin-components.css')) ?: '1' }}" rel="stylesheet">
        <link href="{{ asset('assets/css/staff-sites.css') }}?v={{ @filemtime(public_path('assets/css/staff-sites.css')) ?: '1' }}" rel="stylesheet">
    @endif
    <link href="{{ asset('assets/css/hover-system.css') }}?v={{ @filemtime(public_path('assets/css/hover-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/verified-traffic-preview.css') }}?v={{ @filemtime(public_path('assets/css/verified-traffic-preview.css')) ?: '1' }}" rel="stylesheet">
</head>
@php
    $shell = $shell ?? 'publisher';
    $bodyClass = match ($shell) {
        'advertiser' => 'role-shell-advertiser',
        'admin' => 'role-shell-admin',
        default => 'role-shell-publisher',
    };
    $nav = $nav ?? 'index';
@endphp
<body class="{{ $bodyClass }}">

<div id="sidebar">
    <div class="mobile-sidebar-logo">
        <img src="{{ brand_shell_wordmark_url() }}" height="48" width="172" style="width:auto;max-width:min(280px,90%);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
    </div>
    <div class="menu">
        <div class="shell-sidebar-brand text-center my-3 d-none d-md-block">
            <img class="shell-logo-wordmark" src="{{ brand_shell_wordmark_url() }}" height="48" width="172" style="width:auto;max-width:100%;object-fit:contain;background:transparent" alt="SEOLinkBuildings">
            <img class="shell-logo-mark" src="{{ asset('assets/brand/web/favicon.svg') }}" height="36" width="36" alt="" aria-hidden="true">
        </div>

        @if($shell === 'publisher')
            <a href="{{ route('preview.verified-traffic.index') }}"><i class="fa fa-tachometer-alt" aria-hidden="true"></i> <span class="nav-label">Dashboard</span></a>
            <a href="{{ route('preview.verified-traffic.publisher') }}" class="{{ $nav === 'publisher' ? 'active' : '' }}"><i class="fa fa-globe" aria-hidden="true"></i> <span class="nav-label">My Sites</span></a>
            <a href="#"><i class="fa fa-tasks" aria-hidden="true"></i> <span class="nav-label">Tasks</span></a>
            <a href="#"><i class="fa fa-wallet" aria-hidden="true"></i> <span class="nav-label">Balance</span></a>
        @elseif($shell === 'advertiser')
            <a href="{{ route('preview.verified-traffic.index') }}"><i class="fa fa-tachometer-alt" aria-hidden="true"></i> <span class="nav-label">Dashboard</span></a>
            <a href="{{ route('preview.verified-traffic.catalog') }}" class="{{ $nav === 'catalog' ? 'active' : '' }}"><i class="fa fa-store" aria-hidden="true"></i> <span class="nav-label">Catalog</span></a>
            <a href="#"><i class="fa fa-shopping-cart" aria-hidden="true"></i> <span class="nav-label">Cart</span></a>
            <a href="#"><i class="fa fa-list" aria-hidden="true"></i> <span class="nav-label">Orders</span></a>
        @else
            <a href="{{ route('preview.verified-traffic.index') }}"><i class="fa fa-tachometer-alt" aria-hidden="true"></i> <span class="nav-label">Dashboard</span></a>
            <a href="{{ route('preview.verified-traffic.admin') }}" class="{{ $nav === 'admin' ? 'active' : '' }}"><i class="fa fa-globe" aria-hidden="true"></i> <span class="nav-label">Sites</span></a>
            <a href="#"><i class="fa fa-users" aria-hidden="true"></i> <span class="nav-label">Users</span></a>
        @endif
    </div>
</div>

<div class="top-navbar">
    <button type="button" id="toggleSidebar" class="btn btn-link text-decoration-none" aria-label="Toggle sidebar">
        <i class="fa fa-bars" aria-hidden="true"></i>
    </button>
    <div class="ms-auto d-flex align-items-center gap-3">
        <span class="small text-muted d-none d-md-inline">Preview user</span>
        <span class="rounded-circle bg-light border d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px" aria-hidden="true">
            <i class="fa fa-user text-muted"></i>
        </span>
    </div>
</div>

<div id="content">
    <div class="vt-preview-banner" role="status">
        <span>DRAFT PREVIEW — demo data only. Not live. Does not connect to Google.</span>
        <a href="{{ route('preview.verified-traffic.index') }}">All mockups</a>
    </div>
    <div class="container-fluid py-3">
        <nav class="vt-preview-nav" aria-label="Preview screens">
            <a href="{{ route('preview.verified-traffic.index') }}" class="{{ $nav === 'index' ? 'is-active' : '' }}">Overview</a>
            <a href="{{ route('preview.verified-traffic.publisher', ['state' => 'connect']) }}" class="{{ request('state') === 'connect' ? 'is-active' : '' }}">Publisher · connect</a>
            <a href="{{ route('preview.verified-traffic.publisher', ['state' => 'picker']) }}" class="{{ request('state') === 'picker' ? 'is-active' : '' }}">Publisher · picker</a>
            <a href="{{ route('preview.verified-traffic.publisher', ['state' => 'connected']) }}" class="{{ request('state') === 'connected' ? 'is-active' : '' }}">Publisher · connected</a>
            <a href="{{ route('preview.verified-traffic.catalog') }}" class="{{ $nav === 'catalog' && ! request()->routeIs('preview.verified-traffic.catalog-detail') ? 'is-active' : '' }}">Catalog row</a>
            <a href="{{ route('preview.verified-traffic.catalog-detail') }}" class="{{ request()->routeIs('preview.verified-traffic.catalog-detail') ? 'is-active' : '' }}">Catalog detail</a>
            <a href="{{ route('preview.verified-traffic.admin') }}" class="{{ $nav === 'admin' ? 'is-active' : '' }}">Admin</a>
        </nav>
        @yield('content')
    </div>
</div>

<script src="{{ asset('assets/vendor/bootstrap-5.3.0/bootstrap.bundle.min.js') }}?v={{ @filemtime(public_path('assets/vendor/bootstrap-5.3.0/bootstrap.bundle.min.js')) ?: '1' }}"></script>
<script>
    const toggleBtn = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const topNavbar = document.querySelector('.top-navbar');
    if (toggleBtn && sidebar && content && topNavbar) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth > 768) {
                sidebar.classList.toggle('collapsed');
                content.classList.toggle('collapsed');
                topNavbar.classList.toggle('collapsed');
            } else {
                sidebar.classList.toggle('show');
            }
        });
    }
</script>
</body>
</html>
