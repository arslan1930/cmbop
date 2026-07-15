<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Publisher Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

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

        body.layout-dark #content { background-color: #121221; color: #ddd; }

        .balance-block {
            min-width: 120px;
            height: 40px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            padding: 0 10px;
            color: #fff;
            background-color: #0d6efd;
        }

        #toggleDarkMode {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
        }

        #toggleNotifications {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
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
            <img id="logoSidebar" src="{{ asset('assets/img/logo1.png') }}" height="42">
        </div>

        <a href="{{ route('publisher.dashboard') }}" class="{{ request()->routeIs('publisher.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>
           
        <!-- Websites + number of websites simple count bg of red as a batch  -->
        <a href="{{ route('publisher.websites') }}" class="{{ request()->routeIs('publisher.websites') ? 'active' : '' }}">
            <!-- sites icon-->
            <i class="fa fa-globe"></i>
            <span class="d-flex align-items-center w-100">
                <span>My Sites</span>
                @auth
                    <span class="badge bg-danger rounded-pill ms-auto">
                        {{ auth()->user()->sites()->count() }}
                    </span>
                @endauth
            </span>
        </a>

        <a href="{{ route('publisher.tasks') }}" class="{{ request()->routeIs('publisher.tasks') ? 'active' : '' }}">
            <i class="fa fa-tasks"></i>
            <span class="d-flex align-items-center w-100">
                <span>Tasks</span>
                <span id="navNeedsActionBadge" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
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
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}" height="45">
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

    <div class="d-flex align-items-center gap-2 ">

        <div class="position-relative">
            <a href="{{ route('publisher.tasks') }}" id="toggleNotifications" class="btn btn-outline-secondary btn-sm" title="Unread chat & tasks needing action">
                <i class="fa fa-comments"></i>
                <span id="headerChatBadge" class="badge bg-danger rounded-pill position-absolute top-0 start-100 translate-middle" style="display:none; font-size:10px;">0</span>
            </a>
        </div>

        <button id="toggleDarkMode" class="btn btn-outline-secondary btn-sm" title="Toggle Dark Mode">
            <i class="fa fa-moon"></i>
            <i class="fa fa-sun d-none"></i>
        </button>

        <!-- link to balance route -->
         <a href="{{ route('publisher.balance') }}">
        @php
            $activeWallet = auth()->user()->activeWallet();
            $headerWithdrawable = $activeWallet ? $activeWallet->withdrawableBalance() : 0;
            $headerBonus = $activeWallet ? $activeWallet->lockedBonusBalance() : 0;
            $headerBalanceTitle = $headerBonus > 0
                ? 'Ready to use / On hold. You can withdraw €' . number_format($headerWithdrawable, 2) . '. €' . number_format($headerBonus, 2) . ' free credit is for orders only.'
                : 'Ready to use / On hold for open orders. Ready money can be withdrawn.';
        @endphp
        <div class="balance-block" data-bs-toggle="tooltip" data-bs-placement="bottom" title="{{ $headerBalanceTitle }}">
            <span>€{{ $activeWallet?->balance ?? '0.00' }}</span>
            <span>/</span>
            <span>€{{ $activeWallet?->reserved_balance ?? '0.00' }}</span>
        </div>
        </a>

        <div class="dropdown">
    <button class="btn dropdown-toggle d-flex align-items-center gap-1"
            data-bs-toggle="dropdown">
        
        @php
            $user = auth()->user();
        @endphp
        
        {{-- If user has avatar (Google avatar), display it --}}
        @if($user->avatar)
            <img src="{{ $user->avatar }}" 
                 alt="{{ $user->name }}"
                 class="rounded-circle"
                 style="width: 36px; height: 36px; object-fit: cover;">
        @else
            {{-- Otherwise show initials with gradient background --}}
            <div class="rounded-circle text-white d-flex justify-content-center align-items-center"
                 style="width: 36px; height: 36px; font-weight: 600; background: linear-gradient(to right, #0d6efd, #6f42c1);">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
        @endif
    </button>

    <ul class="dropdown-menu dropdown-menu-end">
        {{-- User info with avatar thumbnail in dropdown --}}
        <li class="px-3 py-2">
            <div class="d-flex align-items-center gap-2">
                @if($user->avatar)
                    <img src="{{ $user->avatar }}" 
                         alt="{{ $user->name }}"
                         class="rounded-circle"
                         style="width: 32px; height: 32px; object-fit: cover;">
                @else
                    <div class="rounded-circle text-white d-flex justify-content-center align-items-center"
                         style="width: 32px; height: 32px; font-weight: 600; background: linear-gradient(to right, #0d6efd, #6f42c1);">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <strong>{{ $user->name }}</strong><br>
                    <small>{{ $user->email }}</small>
                </div>
            </div>
        </li>
        <li><hr class="dropdown-divider"></li>
        <!-- Profile + icon -->
        <li>
            <a class="dropdown-item" href="{{ route('profile') }}">
                <i class="fa fa-user"></i> Profile
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="dropdown-item text-danger">
                    <i class="fa fa-sign-out-alt"></i> Logout
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
    © 2026 SEOLinkBuildings
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
            const chatBadge = document.getElementById('headerChatBadge');
            const navBadge = document.getElementById('navNeedsActionBadge');
            const totalAlert = (data.unread_chat || 0) + (data.needs_action || 0);
            if (chatBadge) {
                if (totalAlert > 0) {
                    chatBadge.style.display = 'inline-block';
                    chatBadge.innerText = totalAlert > 99 ? '99+' : totalAlert;
                } else {
                    chatBadge.style.display = 'none';
                }
            }
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