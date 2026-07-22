<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Publisher Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.favicon')

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/type-system.css') }}?v={{ @filemtime(public_path('css/type-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/brand-colors.css') }}?v={{ @filemtime(public_path('css/brand-colors.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/spacing-system.css') }}?v={{ @filemtime(public_path('css/spacing-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/button-system.css') }}?v={{ @filemtime(public_path('css/button-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/form-system.css') }}?v={{ @filemtime(public_path('css/form-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/app-shell.css') }}?v={{ @filemtime(public_path('css/app-shell.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/interaction.css') }}?v={{ @filemtime(public_path('css/interaction.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/chat.css') }}?v={{ @filemtime(public_path('css/chat.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/multi-select.css') }}?v={{ @filemtime(public_path('css/multi-select.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/single-select.css') }}?v={{ @filemtime(public_path('css/single-select.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/glass-tip.css') }}?v={{ @filemtime(public_path('css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/pulse-badge.css') }}?v={{ @filemtime(public_path('css/pulse-badge.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/notification-center.css') }}?v={{ @filemtime(public_path('css/notification-center.css')) ?: '5' }}" rel="stylesheet">
    <script src="{{ asset('js/pulse-badge.js') }}?v={{ @filemtime(public_path('js/pulse-badge.js')) ?: '1' }}"></script>
    <script src="{{ asset('js/single-select.js') }}?v={{ @filemtime(public_path('js/single-select.js')) ?: '1' }}" defer></script>

    <!-- Shell chrome lives in public/css/app-shell.css -->
</head>

<body class="role-shell-publisher">

<!-- Sidebar -->
<div id="sidebar">
    <!-- Mobile Sidebar Logo (visible only on mobile) -->
    <div class="mobile-sidebar-logo">
        <img id="mobileSidebarLogo" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" alt="SEOLinkBuildings">
    </div>
    
    <div class="menu">

        <!-- Mobile Role Switch -->
        <div class="text-center my-2 d-md-none">
            @php
                $user = auth()->user();
                $otherRole = $user->roles->firstWhere('id', '!=', $user->active_role_id);
            @endphp

            @if($otherRole)
                <form method="POST" action="{{ route('switch.role') }}" class="role-switch-form">
                    @csrf
                    <input type="hidden" name="active_role_id" value="{{ $otherRole->id }}">
                    <button type="submit" class="btn btn-sm btn-outline-primary role-switch-btn"
                            data-role-name="{{ ucfirst($otherRole->name) }}">
                        Switch to {{ ucfirst($otherRole->name) }}
                    </button>
                </form>
            @endif
        </div>
        

        <div class="text-center my-3 d-none d-md-block">
            <img id="logoSidebar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="42" alt="SEOLinkBuildings">
        </div>

        <a href="{{ route('publisher.dashboard') }}" class="{{ request()->routeIs('publisher.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>
           
        <!-- Websites + number of websites simple count bg of red as a batch  -->
        <a href="{{ route('publisher.websites') }}" class="{{ request()->routeIs('publisher.websites') ? 'active' : '' }}">
            <i class="fa fa-globe"></i>
            <span class="d-flex align-items-center w-100">
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
            <i class="fa fa-tasks"></i>
            <span class="d-flex align-items-center w-100">
                <span>Tasks</span>
                <span id="navNeedsActionBadge" class="badge nav-alert-badge pulse-badge rounded-pill ms-auto" style="display:none;" data-pulse-display="inline-block">0</span>
            </span>
        </a>

        <!-- withdraw -->
        <a href="{{ route('publisher.withdraw') }}" class="{{ request()->routeIs('publisher.withdraw') ? 'active' : '' }}">
            <i class="fa fa-money-bill-wave"></i> <span>Withdraw</span>
        </a>

        <!-- Reports -->
        <a href="{{ route('publisher.reports') }}" class="{{ request()->routeIs('publisher.reports') ? 'active' : '' }}">
            <i class="fa fa-chart-bar"></i> <span>Reports</span>
        </a>
        
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
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="45" alt="SEOLinkBuildings">
        </a>

        <div class="d-none d-md-block">
            @php
                $user = auth()->user();
                $otherRole = $user->roles->firstWhere('id', '!=', $user->active_role_id);
            @endphp

            @if($otherRole)
                <form method="POST" action="{{ route('switch.role') }}" class="role-switch-form">
                    @csrf
                    <input type="hidden" name="active_role_id" value="{{ $otherRole->id }}">
                    <button type="submit" class="btn btn-sm btn-outline-primary role-switch-btn"
                            data-role-name="{{ ucfirst($otherRole->name) }}">
                        Switch to {{ ucfirst($otherRole->name) }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">

        @php
            $activeWallet = auth()->user()->activeWallet();
            $availableBalance = (float) ($activeWallet?->balance ?? 0);
            $reservedBalance = (float) ($activeWallet?->reserved_balance ?? 0);
            $headerWithdrawable = $activeWallet ? $activeWallet->withdrawableBalance() : 0;
            $headerBalanceTitle = 'Spendable: €' . number_format($availableBalance, 2)
                . ' · Withdrawable: €' . number_format($headerWithdrawable, 2)
                . ($reservedBalance > 0 ? ' · On hold: €' . number_format($reservedBalance, 2) : '');
        @endphp
        <a href="{{ route('publisher.balance') }}" class="balance-block text-decoration-none" data-bs-toggle="tooltip" data-bs-placement="bottom" title="{{ $headerBalanceTitle }}" aria-label="Spendable balance {{ number_format($availableBalance, 2) }} euros, withdrawable {{ number_format($headerWithdrawable, 2) }}">
            <span class="balance-label">Spendable</span>
            <span class="balance-amount">€{{ number_format($availableBalance, 2) }}</span>
        </a>

        @include('partials.notification-center')

        <div class="dropdown">
            <button class="btn dropdown-toggle d-flex align-items-center gap-1"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="Account menu">
                @php $user = auth()->user(); @endphp
                @if($user->avatar)
                    <img src="{{ $user->avatar }}"
                         alt=""
                         class="rounded-circle"
                         style="width: 36px; height: 36px; object-fit: cover;">
                @else
                    <div class="rounded-circle text-white d-flex justify-content-center align-items-center"
                         style="width: 36px; height: 36px; font-weight: 600; background: #4ECDCB;"
                         aria-hidden="true">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                @endif
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2">
                    <div class="d-flex align-items-center gap-2">
                        @if($user->avatar)
                            <img src="{{ $user->avatar }}"
                                 alt=""
                                 class="rounded-circle"
                                 style="width: 32px; height: 32px; object-fit: cover;">
                        @else
                            <div class="rounded-circle text-white d-flex justify-content-center align-items-center"
                                 style="width: 32px; height: 32px; font-weight: 600; background: #4ECDCB;"
                                 aria-hidden="true">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </div>
                        @endif
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
    @yield('content')
    @include('components.ad-banners', ['placement' => 'content_bottom', 'audience' => 'publisher'])
</div>

<footer>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 w-100 px-2">
        <div>
            © {{ date('Y') }} SEOLinkBuildings
            <span class="mx-2">·</span>
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="document.getElementById('helpFeedbackToggle')?.click()">Report a problem</button>
            <span class="mx-1">·</span>
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="document.getElementById('helpFeedbackToggle')?.click()">Suggestion box</button>
        </div>
        @include('partials.payment-trust', ['compact' => true, 'showMethods' => true])
    </div>
</footer>
@include('components.help-feedback-widget')

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    tooltipTriggerList.map(function (el) {
        return new bootstrap.Tooltip(el)
    });

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
                window.PulseBadge.sync(navBadge, total);
            } else if (navBadge) {
                if (total > 0) {
                    navBadge.style.display = 'inline-block';
                    navBadge.innerText = total > 99 ? '99+' : total;
                    navBadge.classList.add('pulse-badge', 'is-pulsing');
                } else {
                    navBadge.style.display = 'none';
                    navBadge.classList.remove('is-pulsing');
                }
            }
        })
        .catch(() => {});
    }
    refreshHeaderAlerts();
    setInterval(refreshHeaderAlerts, 45000);
    window.refreshHeaderAlerts = refreshHeaderAlerts;

    document.querySelectorAll('.role-switch-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = form.querySelector('.role-switch-btn');
            const roleName = (btn && btn.dataset.roleName) || 'the other role';
            Swal.fire({
                title: 'Switch role?',
                html: 'You are about to switch to <strong>' + roleName + '</strong>. Your current page will change to that workspace.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Switch to ' + roleName,
                cancelButtonText: 'Stay here',
                confirmButtonColor: '#c45c26',
                cancelButtonColor: '#6b7280',
                reverseButtons: true
            }).then(function(result) {
                if (result.isConfirmed) form.submit();
            });
        });
    });
</script>
<script src="{{ asset('js/order-chat.js') }}?v={{ @filemtime(public_path('js/order-chat.js')) ?: '1' }}" defer></script>
<script src="{{ asset('js/notification-center.js') }}?v={{ @filemtime(public_path('js/notification-center.js')) ?: '5' }}" defer></script>

</body>
</html>