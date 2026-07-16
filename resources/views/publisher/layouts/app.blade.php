<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Publisher Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body, html {
            min-height: 100%;
            margin: 0;
            background-color: #f8f9fa;
        }

        #sidebar,
        #content,
        .top-navbar,
        footer,
        #toggleSidebar span.arrow {
            transition: all 0.3s ease-in-out;
        }

        #sidebar {
            min-width: 220px;
            max-width: 220px;
            background-color: #fff;
            border-right: 1px solid #ddd;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            z-index: 1050;
        }

        #sidebar .menu { flex-grow: 1; }

        #sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: #555;
            text-decoration: none;
            font-weight: 500;
        }

        #sidebar a.active,
        #sidebar a:hover {
            border-radius: 6px;
            background-color: #4ECDCB;
            color: #fff;
        }

        .nav-count-badge {
            background: #e9ecef !important;
            color: #495057 !important;
            font-weight: 600;
            font-size: 11px;
        }
        #sidebar a.active .nav-count-badge,
        #sidebar a:hover .nav-count-badge {
            background: rgba(255,255,255,0.25) !important;
            color: #fff !important;
        }
        .nav-alert-badge {
            background: #ffc107 !important;
            color: #212529 !important;
            font-weight: 700;
            font-size: 11px;
        }

        .topbar-icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            position: relative;
            color: #495057;
            border: 1px solid #dee2e6;
            background: #fff;
        }
        .topbar-icon-btn:hover {
            background: #f8f9fa;
            color: #0b6266;
            border-color: #b8e8e6;
        }
        body.layout-dark .topbar-icon-btn {
            background: #1e1e2f;
            border-color: #444;
            color: #ccc;
        }
        .topbar-icon-btn .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #dc3545;
            color: #fff;
            border-radius: 999px;
            min-width: 16px;
            height: 16px;
            font-size: 10px;
            font-weight: 700;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            line-height: 1;
        }

        #sidebar.collapsed { width: 70px; min-width: 70px; }
        #sidebar.collapsed a { justify-content: center; font-size: 0; }
        #sidebar.collapsed a i { font-size: 18px; }

        .top-navbar {
            height: 70px;
            position: sticky;
            top: 0;
            left: 220px;
            right: 0;
            background: #fff;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
            z-index: 1060;
        }

        .top-navbar.collapsed { left: 70px; }

        #content {
            margin-left: 220px;
            padding: 20px 30px 30px;
            min-height: calc(100vh - 120px);
        }

        #content.collapsed { margin-left: 70px; }

        footer {
            margin-left: 220px;
            padding: 15px;
            text-align: center;
            background: #fff;
            border-top: 1px solid #ddd;
        }

        footer.collapsed { margin-left: 70px; }

        #toggleSidebar span.arrow { display: inline-block; font-size: 18px; }
        #toggleSidebar.collapsed span.arrow { transform: rotate(180deg); }

        body.layout-dark #sidebar {
            background-color: #1e1e2f !important;
            border-color: #333 !important;
        }

        body.layout-dark #sidebar a { color: #ccc; }
        body.layout-dark #sidebar a.active,
        body.layout-dark #sidebar a:hover {
            background-color: #4ECDCB;
            color: #fff;
        }

        body.layout-dark .top-navbar { background-color: #1e1e2f; border-bottom-color: #333; }
        body.layout-dark .top-navbar .btn-outline-secondary {
            color: #ccc;
            border-color: #555;
        }
        body.layout-dark .top-navbar .btn-outline-secondary:hover {
            background-color: #333;
            color: #fff;
        }
        body.layout-dark .balance-block {
            background-color: #24353a;
            border-color: #3a5558;
            color: #9fe7e4;
        }
        body.layout-dark .balance-block .balance-label,
        body.layout-dark .balance-block .balance-amount {
            color: #9fe7e4;
        }

        body.layout-dark #content { background-color: #121221; color: #ddd; }

        .balance-block {
            min-width: auto;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            padding: 0 12px;
            color: #0b6266;
            background-color: #e8f8f7;
            border: 1px solid #b8e8e6;
            text-decoration: none;
            white-space: nowrap;
        }
        .balance-block:hover {
            background-color: #d7f3f1;
            color: #0b6266;
        }
        .balance-block .balance-label {
            font-size: 11px;
            font-weight: 500;
            color: #3aaeb2;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .balance-block .balance-amount {
            font-size: 14px;
            color: #0b6266;
        }

        #toggleDarkMode.topbar-icon-btn {
            width: 36px;
            height: 36px;
        }

        /* Mobile Sidebar Logo Styling */
        .mobile-sidebar-logo {
            padding: 16px 0;
            text-align: center;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            margin-bottom: 8px;
            display: none; /* hidden by default, shown on mobile */
        }
        
        body.layout-dark .mobile-sidebar-logo {
            border-bottom-color: rgba(255,255,255,0.1);
        }
        
        .mobile-sidebar-logo img {
            height: 40px;
            width: auto;
        }

        @media (max-width: 768px) {
            #sidebar {
                top: 70px;
                height: calc(100vh - 70px);
                left: -220px;
            }

            #sidebar.show { left: 0; }

            #content,
            .top-navbar,
            footer { margin-left: 0 !important; }

            .top-navbar { left: 0 !important; padding-left: 10px; padding-right: 10px; }
            .top-navbar .mobile-left { display: flex; align-items: center; gap: 10px; }
            
            /* Hide navbar logo on mobile */
            .top-navbar .mobile-left a.d-flex.align-items-center {
                display: none !important;
            }
            
            /* Show logo in sidebar on mobile */
            .mobile-sidebar-logo {
                display: block;
            }
            
            /* Hide desktop sidebar logo image if exists (the one in .menu) */
            #sidebar .menu > .text-center.d-none.d-md-block {
                display: none !important;
            }
        }
    </style>
</head>

<body>

<!-- Sidebar -->
<div id="sidebar">
    <!-- Mobile Sidebar Logo (visible only on mobile) -->
    <div class="mobile-sidebar-logo">
        <img id="mobileSidebarLogo" src="{{ asset('assets/img/logo1.png') }}" alt="Logo">
    </div>
    
    <div class="menu">

        <!-- Mobile Role Switch -->
        <div class="text-center my-2 d-md-none">
            @php
                $user = auth()->user();
                $otherRole = $user->roles->firstWhere('id', '!=', $user->active_role_id);
            @endphp

            @if($otherRole)
                <form method="POST" action="{{ route('switch.role') }}">
                    @csrf
                    <input type="hidden" name="active_role_id" value="{{ $otherRole->id }}">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        Switch to {{ ucfirst($otherRole->name) }}
                    </button>
                </form>
            @endif
        </div>
        

        <div class="text-center my-3 d-none d-md-block">
            <img id="logoSidebar" src="{{ asset('assets/img/logo1.png') }}" height="42" alt="SEOLinkBuildings">
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
                <span id="navNeedsActionBadge" class="badge nav-alert-badge rounded-pill ms-auto" style="display:none;">0</span>
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
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary">
            <span class="arrow"><i class="fa fa-chevron-left"></i></span>
        </button>

        <!-- Navbar logo - will be hidden on mobile via CSS -->
        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}" height="45" alt="SEOLinkBuildings">
        </a>

        <div class="d-none d-md-block">
            @php
                $user = auth()->user();
                $otherRole = $user->roles->firstWhere('id', '!=', $user->active_role_id);
            @endphp

            @if($otherRole)
                <form method="POST" action="{{ route('switch.role') }}">
                    @csrf
                    <input type="hidden" name="active_role_id" value="{{ $otherRole->id }}">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        Switch to {{ ucfirst($otherRole->name) }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">

        <!-- Dark mode — icon only -->
        <button type="button" id="toggleDarkMode" class="topbar-icon-btn" title="Dark mode" aria-label="Toggle dark mode">
            <i class="fa fa-moon" aria-hidden="true"></i>
            <i class="fa fa-sun d-none" aria-hidden="true"></i>
        </button>

        @php
            $activeWallet = auth()->user()->activeWallet();
            $availableBalance = (float) ($activeWallet?->balance ?? 0);
            $reservedBalance = (float) ($activeWallet?->reserved_balance ?? 0);
            $headerWithdrawable = $activeWallet ? $activeWallet->withdrawableBalance() : 0;
            $headerBonus = $activeWallet ? $activeWallet->lockedBonusBalance() : 0;
            $headerBalanceTitle = 'Available: €' . number_format($availableBalance, 2)
                . ($reservedBalance > 0 ? ' · On hold: €' . number_format($reservedBalance, 2) : '')
                . ' · Withdrawable: €' . number_format($headerWithdrawable, 2)
                . ($headerBonus > 0 ? ' · €' . number_format($headerBonus, 2) . ' free credit (orders only)' : '');
        @endphp
        <a href="{{ route('publisher.balance') }}" class="balance-block text-decoration-none" data-bs-toggle="tooltip" data-bs-placement="bottom" title="{{ $headerBalanceTitle }}" aria-label="Wallet balance {{ number_format($availableBalance, 2) }} euros available">
            <span class="balance-label">Available</span>
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
    @yield('content')
</div>

<footer>
    © {{ date('Y') }} SEOLinkBuildings
</footer>

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

    const darkModeBtn = document.getElementById('toggleDarkMode');
    const moonIcon = darkModeBtn.querySelector('.fa-moon');
    const sunIcon = darkModeBtn.querySelector('.fa-sun');
    const logoSidebar = document.getElementById('logoSidebar');
    const logoNavbar = document.getElementById('logoNavbar');
    const mobileSidebarLogo = document.getElementById('mobileSidebarLogo');

    if (localStorage.getItem('layoutDarkMode') === 'true') {
        document.body.classList.add('layout-dark');
        moonIcon.classList.add('d-none');
        sunIcon.classList.remove('d-none');
        logoSidebar.src = "{{ asset('assets/img/logo2.png') }}";
        logoNavbar.src = "{{ asset('assets/img/logo2.png') }}";
        if (mobileSidebarLogo) mobileSidebarLogo.src = "{{ asset('assets/img/logo2.png') }}";
        darkModeBtn.setAttribute('title', 'Light mode');
        darkModeBtn.setAttribute('aria-label', 'Switch to light mode');
    }

    darkModeBtn.addEventListener('click', () => {
        const isDark = document.body.classList.toggle('layout-dark');
        moonIcon.classList.toggle('d-none', isDark);
        sunIcon.classList.toggle('d-none', !isDark);
        localStorage.setItem('layoutDarkMode', isDark);
        const logoSrc = isDark ? "{{ asset('assets/img/logo2.png') }}" : "{{ asset('assets/img/logo1.png') }}";
        logoSidebar.src = logoSrc;
        logoNavbar.src = logoSrc;
        if (mobileSidebarLogo) mobileSidebarLogo.src = logoSrc;
        darkModeBtn.setAttribute('title', isDark ? 'Light mode' : 'Dark mode');
        darkModeBtn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Toggle dark mode');
    });

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
            const navBadge = document.getElementById('navNeedsActionBadge');
            if (navBadge) {
                if (data.needs_action > 0) {
                    navBadge.style.display = 'inline-block';
                    navBadge.innerText = data.needs_action > 99 ? '99+' : data.needs_action;
                } else {
                    navBadge.style.display = 'none';
                }
            }
        })
        .catch(() => {});
    }
    refreshHeaderAlerts();
    setInterval(refreshHeaderAlerts, 45000);
</script>

</body>
</html>