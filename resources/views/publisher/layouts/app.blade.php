<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Publisher') — SEOLinkBuildings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.favicon')

    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('assets/css/type-system.css') }}?v={{ @filemtime(public_path('assets/css/type-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/brand-colors.css') }}?v={{ @filemtime(public_path('assets/css/brand-colors.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/spacing-system.css') }}?v={{ @filemtime(public_path('assets/css/spacing-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/button-system.css') }}?v={{ @filemtime(public_path('assets/css/button-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/form-system.css') }}?v={{ @filemtime(public_path('assets/css/form-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/app-shell.css') }}?v={{ @filemtime(public_path('assets/css/app-shell.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/interaction.css') }}?v={{ @filemtime(public_path('assets/css/interaction.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/chat.css') }}?v={{ @filemtime(public_path('assets/css/chat.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/single-select.css') }}?v={{ @filemtime(public_path('assets/css/single-select.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/glass-tip.css') }}?v={{ @filemtime(public_path('assets/css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/pulse-badge.css') }}?v={{ @filemtime(public_path('assets/css/pulse-badge.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/notification-center.css') }}?v={{ @filemtime(public_path('assets/css/notification-center.css')) ?: '5' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/dialog-system.css') }}?v={{ @filemtime(public_path('assets/css/dialog-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/slb-live-search.css') }}?v={{ @filemtime(public_path('assets/css/slb-live-search.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/publisher-dashboard.css') }}?v={{ @filemtime(public_path('assets/css/publisher-dashboard.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/hover-system.css') }}?v={{ @filemtime(public_path('assets/css/hover-system.css')) ?: '1' }}" rel="stylesheet">
    <script src="{{ asset('assets/js/glass-tip.js') }}?v={{ @filemtime(public_path('assets/js/glass-tip.js')) ?: '1' }}" defer></script>
    <script src="{{ asset('assets/js/pulse-badge.js') }}?v={{ @filemtime(public_path('assets/js/pulse-badge.js')) ?: '1' }}" defer></script>
    <script src="{{ asset('assets/js/single-select.js') }}?v={{ @filemtime(public_path('assets/js/single-select.js')) ?: '1' }}" defer></script>

    <!-- Shell chrome lives in public/assets/css/app-shell.css -->
</head>

<body class="role-shell-publisher">

<!-- Sidebar -->
<div id="sidebar">
    <!-- Mobile Sidebar Logo (visible only on mobile) -->
    <div class="mobile-sidebar-logo">
        <img id="mobileSidebarLogo" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="48" width="172" style="width:auto;max-width:min(280px,90%);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
    </div>
    
    <div class="menu">

        <!-- Mobile Role Switch -->
        <div class="text-center my-2 d-md-none">
            @include('partials.role-switcher')
        </div>
        

        <div class="shell-sidebar-brand text-center my-3 d-none d-md-block">
            <img id="logoSidebar" class="shell-logo-wordmark" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="48" width="172" style="width:auto;max-width:100%;object-fit:contain;background:transparent" alt="SEOLinkBuildings">
            <img class="shell-logo-mark" src="{{ asset('assets/brand/web/favicon.svg') }}" height="36" width="36" alt="" aria-hidden="true">
        </div>

        <a href="{{ route('publisher.dashboard') }}" class="{{ request()->routeIs('publisher.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt" aria-hidden="true"></i> <span class="nav-label">Dashboard</span>
        </a>
           
        <!-- Websites + number of websites simple count bg of red as a batch  -->
        <a href="{{ route('publisher.websites') }}" class="{{ request()->routeIs('publisher.websites') ? 'active' : '' }}">
            <i class="fa fa-globe" aria-hidden="true"></i>
            <span class="nav-label d-flex align-items-center w-100">
                <span>My Sites</span>
                @auth
                    @php $siteCount = auth()->user()->sites()->count(); @endphp
                    @if($siteCount > 0)
                        <span class="badge nav-count-badge rounded-pill ms-auto" title="Total sites">
                            {{ $siteCount }}
                        </span>
                    @endif
                @endauth
            </span>
        </a>

        <a href="{{ route('publisher.tasks') }}" class="{{ request()->routeIs('publisher.tasks') ? 'active' : '' }}">
            <i class="fa fa-tasks" aria-hidden="true"></i>
            <span class="nav-label d-flex align-items-center w-100">
                <span>Tasks</span>
                <span id="navNeedsActionBadge" class="nc-nav-badge pulse-badge ms-auto" style="display:none;" data-pulse-display="inline-flex">0</span>
            </span>
        </a>

        <a href="{{ route('publisher.balance') }}" class="{{ request()->routeIs('publisher.balance') ? 'active' : '' }}">
            <i class="fa fa-wallet" aria-hidden="true"></i> <span class="nav-label">Balance</span>
        </a>

        <!-- withdraw -->
        <a href="{{ route('publisher.withdraw') }}" class="{{ request()->routeIs('publisher.withdraw') || request()->routeIs('publisher.withdrawals.*') ? 'active' : '' }}">
            <i class="fa fa-money-bill-wave" aria-hidden="true"></i> <span class="nav-label">Withdraw</span>
        </a>

        <a href="{{ route('publisher.billing.index') }}" class="{{ request()->routeIs('publisher.billing.*') ? 'active' : '' }}">
            <i class="fa fa-file-invoice-dollar" aria-hidden="true"></i> <span class="nav-label">Payout docs</span>
        </a>

        <!-- Reports -->
        <a href="{{ route('publisher.reports') }}" class="{{ request()->routeIs('publisher.reports*') ? 'active' : '' }}">
            <i class="fa fa-chart-bar" aria-hidden="true"></i> <span class="nav-label">Reports</span>
        </a>
        @include('components.ad-banners', ['placement' => 'sidebar', 'audience' => 'publisher'])
    </div>
</div>

<!-- Navbar -->
<div class="top-navbar">

    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary" type="button" aria-label="Toggle sidebar navigation" title="Toggle sidebar">
            <span class="arrow" aria-hidden="true"><i class="fa fa-chevron-left"></i></span>
        </button>

        <!-- Navbar logo - will be hidden on mobile via CSS -->
        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="44" width="158" style="width:auto;max-width:min(220px,42vw);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
        </a>

        <div class="d-none d-md-block">
            @include('partials.role-switcher')
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">

        @php
            $headerUser = auth()->user();
            $headerPublisherRoleId = \App\Models\Wallet::publisherRoleId();
            $headerAdvertiserRoleId = \App\Models\Wallet::advertiserRoleId();
            $headerWallets = $headerUser->wallets()
                ->whereIn('role_id', array_filter([$headerPublisherRoleId, $headerAdvertiserRoleId]))
                ->get()
                ->keyBy(fn ($wallet) => (int) $wallet->role_id);
            $headerPublisherWallet = $headerPublisherRoleId ? $headerWallets->get((int) $headerPublisherRoleId) : null;
            $headerAdvertiserWallet = ($headerAdvertiserRoleId && $headerUser->hasRole('advertiser'))
                ? $headerWallets->get((int) $headerAdvertiserRoleId)
                : null;
            $headerWithdrawable = $headerPublisherWallet ? $headerPublisherWallet->withdrawableBalance() : 0;
            $headerReserved = (float) ($headerPublisherWallet?->reserved_balance ?? 0);
            $headerBalanceTitle = 'Earnings €'.number_format($headerWithdrawable, 2)
                .' · Withdrawable €'.number_format($headerWithdrawable, 2)
                .($headerReserved > 0 ? ' · On hold €'.number_format($headerReserved, 2) : '')
                .($headerAdvertiserWallet
                    ? ' · Advertiser spendable €'.number_format((float) $headerAdvertiserWallet->balance, 2)
                    : '');
        @endphp
        <a href="{{ route('publisher.balance') }}" class="balance-block text-decoration-none" data-glass-tip data-glass-tip-body="{{ $headerBalanceTitle }}" data-glass-tip-placement="bottom" aria-label="Publisher earnings {{ number_format($headerWithdrawable, 2) }} euros, withdrawable {{ number_format($headerWithdrawable, 2) }}">
            <span class="balance-label">Earnings</span>
            <span class="balance-amount">€{{ number_format($headerWithdrawable, 2) }}</span>
        </a>

        @include('partials.notification-center')

        <div class="dropdown">
            <button class="btn dropdown-toggle d-flex align-items-center gap-1"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="Account menu">
                @php $user = auth()->user(); @endphp
                @include('partials.user-avatar', ['user' => $user, 'size' => 36])
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2">
                    <div class="d-flex align-items-center gap-2">
                        @include('partials.user-avatar', ['user' => $user, 'size' => 32])
                        <div>
                            <strong>{{ $user->name }}</strong><br>
                            <small class="text-muted">{{ $user->email }}</small>
                        </div>
                    </div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="{{ route('profile') }}">
                        <i class="fa fa-user" aria-hidden="true"></i> Profile
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('profile.notifications') }}">
                        <i class="fa fa-bell" aria-hidden="true"></i> Email preferences
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('publisher.balance') }}">
                        <i class="fa fa-wallet" aria-hidden="true"></i> Balance
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('publisher.withdraw') }}">
                        <i class="fa fa-money-bill-wave" aria-hidden="true"></i> Withdraw
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-item text-danger" type="submit">
                            <i class="fa fa-sign-out-alt" aria-hidden="true"></i> Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

<div id="content">
    @include('components.site-announcements', ['audience' => 'publisher'])
    @include('components.ad-banners', ['placement' => 'dashboard', 'audience' => 'publisher'])
    @include('components.ad-banners', ['placement' => 'content_top', 'audience' => 'publisher'])
    @include('partials.session-flash')
    @yield('content')
    @include('components.ad-banners', ['placement' => 'content_bottom', 'audience' => 'publisher'])
</div>

<footer>
    <div class="app-shell-footer__grid">
        <div class="app-shell-footer__left">
            <div class="app-shell-footer__legal">
                <span>© {{ date('Y') }} SEOLinkBuildings</span>
                <span class="mx-1">·</span>
                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="document.getElementById('helpFeedbackToggle')?.click()">Report a problem</button>
                <span class="mx-1">·</span>
                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="document.getElementById('helpFeedbackToggle')?.click()">Suggestion box</button>
            </div>
            @include('partials.trustpilot-trust', ['compact' => true])
        </div>
        @include('partials.payment-trust', ['compact' => true, 'showMethods' => true, 'brief' => true])
    </div>
</footer>
@include('components.help-feedback-widget')

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/js/modal-stack.js') }}?v={{ @filemtime(public_path('assets/js/modal-stack.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('js/slb-confirm.js') }}?v={{ @filemtime(public_path('js/slb-confirm.js')) ?: '1' }}"></script>
<script src="{{ asset('js/slb-live-search.js') }}?v={{ @filemtime(public_path('js/slb-live-search.js')) ?: '1' }}"></script>
<script src="{{ asset('js/slb-http.js') }}?v={{ @filemtime(public_path('js/slb-http.js')) ?: '1' }}"></script>
@include('partials.app-toast')

<script>
    const toggleBtn = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const topNavbar = document.querySelector('.top-navbar');
    const footerEl = document.querySelector('footer');

    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
        content.classList.add('collapsed');
        topNavbar.classList.add('collapsed');
        footerEl.classList.add('collapsed');
        toggleBtn.classList.add('collapsed');
    }

    toggleBtn.addEventListener('click', function () {
        if (window.innerWidth > 768) {
            sidebar.classList.toggle('collapsed');
            content.classList.toggle('collapsed');
            topNavbar.classList.toggle('collapsed');
            footerEl.classList.toggle('collapsed');
            toggleBtn.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        } else {
            sidebar.classList.toggle('show');
        }
    });

    document.body.classList.remove('layout-dark');
    try { localStorage.removeItem('layoutDarkMode'); } catch (e) {}

    function refreshHeaderAlerts() {
        fetch('{{ route("chat.unread-summary") }}', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const needs = data.needs_action || 0;
            const unreadChat = data.unread_chat || 0;
            const total = needs + unreadChat;
            const navBadge = document.getElementById('navNeedsActionBadge');
            if (navBadge) {
                navBadge.title = needs + ' need action · ' + unreadChat + ' unread chat' + (unreadChat === 1 ? '' : 's');
                navBadge.setAttribute('aria-label', navBadge.title);
            }
            if (navBadge && window.PulseBadge) {
                window.PulseBadge.sync(navBadge, total, {
                    alertOnIncrease: true,
                    beep: true
                });
            } else if (navBadge) {
                if (total > 0) {
                    navBadge.style.display = 'inline-flex';
                    navBadge.innerText = total > 99 ? '99+' : total;
                    navBadge.classList.add('pulse-badge', 'is-pulsing', 'is-visible');
                } else {
                    navBadge.style.display = 'none';
                    navBadge.classList.remove('is-pulsing', 'is-visible', 'is-alerting');
                }
            }
        })
        .catch(() => {});
    }
    refreshHeaderAlerts();
    setInterval(refreshHeaderAlerts, 45000);
    window.refreshHeaderAlerts = refreshHeaderAlerts;
</script>
<script src="{{ asset('js/role-switch.js') }}?v={{ @filemtime(public_path('js/role-switch.js')) ?: '1' }}"></script>
<script src="{{ asset('js/order-chat.js') }}?v={{ @filemtime(public_path('js/order-chat.js')) ?: '1' }}" defer></script>
<script src="{{ asset('js/notification-center.js') }}?v={{ @filemtime(public_path('js/notification-center.js')) ?: '8' }}" defer></script>
@stack('scripts')

</body>
</html>