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
        }
    </style>
</head>

<body>

<!-- Sidebar -->
<div id="sidebar">
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
            <i class="fa fa-tasks"></i> <span>Tasks</span>
        </a>

        <!-- withdraw -->
        <a href="{{ route('publisher.withdraw') }}" class="{{ request()->routeIs('publisher.withdraw') ? 'active' : '' }}">
            <i class="fa fa-money-bill-wave"></i> <span>Withdraw</span>
        </a>
        
    </div>
</div>

<!-- Navbar -->
<div class="top-navbar">

    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary">
            <span class="arrow"><i class="fa fa-chevron-left"></i></span>
        </button>

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

            <!-- Notifications Icon -->
        <!-- <div class="position-relative">
            <button id="toggleNotifications" class="btn btn-outline-secondary btn-sm" title="Notifications">
                <i class="fa fa-bell"></i>
            </button>
        </div> -->

        

        <button id="toggleDarkMode" class="btn btn-outline-secondary btn-sm" title="Toggle Dark Mode">
            <i class="fa fa-moon"></i>
            <i class="fa fa-sun d-none"></i>
        </button>

        <div class="balance-block" data-bs-toggle="tooltip" title="Balance / Reserved">
            @php
                $activeWallet = auth()->user()->activeWallet();
            @endphp
            <span>€{{ $activeWallet?->balance ?? '0.00' }}</span>
            <span>/</span>
            <span>€{{ $activeWallet?->reserved_balance ?? '0.00' }}</span>
        </div>

        <div class="dropdown">
            <button class="btn dropdown-toggle d-flex align-items-center gap-1"
                    data-bs-toggle="dropdown">
                <div class="rounded-circle text-white d-flex justify-content-center align-items-center"
                     style="width: 36px; height: 36px; font-weight: 600; background: linear-gradient(to right, #0d6efd, #6f42c1);">
                    {{ strtoupper(substr(auth()->user()->name,0,1)) }}
                </div>
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2">
                    <strong>{{ auth()->user()->name }}</strong><br>
                    <small>{{ auth()->user()->email }}</small>
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

    if (localStorage.getItem('layoutDarkMode') === 'true') {
        document.body.classList.add('layout-dark');
        moonIcon.classList.add('d-none');
        sunIcon.classList.remove('d-none');
        logoSidebar.src = "{{ asset('assets/img/logo2.png') }}";
        logoNavbar.src = "{{ asset('assets/img/logo2.png') }}";
    }

    darkModeBtn.addEventListener('click', () => {
        const isDark = document.body.classList.toggle('layout-dark');
        moonIcon.classList.toggle('d-none', isDark);
        sunIcon.classList.toggle('d-none', !isDark);
        localStorage.setItem('layoutDarkMode', isDark);
        logoSidebar.src = isDark ? "{{ asset('assets/img/logo2.png') }}" : "{{ asset('assets/img/logo1.png') }}";
        logoNavbar.src = isDark ? "{{ asset('assets/img/logo2.png') }}" : "{{ asset('assets/img/logo1.png') }}";
    });

    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    tooltipTriggerList.map(function (el) {
        return new bootstrap.Tooltip(el)
    });  
</script>

</body>
</html>