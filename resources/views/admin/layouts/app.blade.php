<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body, html { min-height: 100%; margin: 0; background-color: #f8f9fa; }

        #sidebar, #content, .top-navbar, footer, #toggleSidebar span.arrow { transition: all 0.3s ease-in-out; }

        /* Sidebar */
        #sidebar {
            min-width: 220px; max-width: 220px; background-color: #fff;
            border-right: 1px solid #ddd; height: 100vh; position: fixed; top: 0; left: 0;
            display: flex; flex-direction: column; z-index: 1050;
        }

        #sidebar .menu { flex-grow: 1; }
        #sidebar a { display: flex; align-items: center; gap: 10px; padding: 12px 20px; color: #555; text-decoration: none; font-weight: 500; }
        #sidebar a.active, #sidebar a:hover { border-radius: 6px; background-color: #0d6efd; color: #fff; }
        #sidebar.collapsed { width: 70px; min-width: 70px; }
        #sidebar.collapsed a { justify-content: center; font-size: 0; }
        #sidebar.collapsed a i { font-size: 18px; }

        /* Top Navbar */
        .top-navbar {
            height: 70px; position: sticky; top: 0; left: 220px; right: 0;
            background: #fff; border-bottom: 1px solid #ddd;
            display: flex; justify-content: space-between; align-items: center; padding: 0 30px;
            z-index: 1060;
        }
        .top-navbar.collapsed { left: 70px; }

        /* Content */
        #content { margin-left: 220px; padding: 20px 30px 30px; min-height: calc(100vh - 120px); }
        #content.collapsed { margin-left: 70px; }

        footer { margin-left: 220px; padding: 15px; text-align: center; background: #fff; border-top: 1px solid #ddd; }
        footer.collapsed { margin-left: 70px; }

        #toggleSidebar span.arrow { display: inline-block; font-size: 18px; }
        #toggleSidebar.collapsed span.arrow { transform: rotate(180deg); }

        /* Dark Mode */
        body.layout-dark #sidebar { background-color: #1e1e2f !important; border-color: #333 !important; }
        body.layout-dark #sidebar a { color: #ccc; }
        body.layout-dark #sidebar a.active, body.layout-dark #sidebar a:hover { background-color: #4ECDCB; color: #fff; }
        body.layout-dark .top-navbar { background-color: #1e1e2f; border-bottom-color: #333; }
        body.layout-dark .top-navbar .btn-outline-secondary { color: #ccc; border-color: #555; }
        body.layout-dark .top-navbar .btn-outline-secondary:hover { background-color: #333; color: #fff; }
        body.layout-dark #content { background-color: #121221; color: #ddd; }

        /* Balance block */
        .balance-block { min-width: 120px; height: 40px; border-radius: 6px; display: flex; align-items: center; justify-content: space-between; font-weight: 600; padding: 0 10px; color: #fff; background-color: #0d6efd; }

        #toggleDarkMode { width: 36px; height: 36px; border-radius: 50%; display: flex; justify-content: center; align-items: center; padding: 0; }

        @media (max-width: 768px) {
            #sidebar { top: 70px; height: calc(100vh - 70px); left: -220px; }
            #sidebar.show { left: 0; }
            #content, .top-navbar, footer { margin-left: 0 !important; }
            .top-navbar { left: 0 !important; padding-left: 10px; padding-right: 10px; }
            .top-navbar .mobile-left { display: flex; align-items: center; gap: 10px; }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div id="sidebar">
    <div class="menu">
        <div class="text-center my-3">
            <img id="logoSidebar" src="{{ asset('assets/img/logo1.png') }}" height="42" alt="Logo">
        </div>

        <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>

        
        <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
            <i class="fa fa-users"></i> <span>Users</span>
        </a>
        
        <a href="{{ route('admin.sites.index') }}" class="{{ request()->routeIs('admin.sites.*') ? 'active' : '' }}">
            <i class="fa fa-globe"></i> <span>Sites</span>
        </a>

        <a href="{{ route('admin.deposits') }}" class="{{ request()->routeIs('admin.deposits') || request()->routeIs('admin.deposits.*') ? 'active' : '' }}">
    <i class="fa fa-wallet"></i> <span>Deposits</span>
</a>

        <a href="{{ route('admin.reports') }}" class="{{ request()->routeIs('admin.reports') ? 'active' : '' }}">
            <i class="fa fa-chart-bar"></i> <span>Reports</span>
        </a>

        <a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings') ? 'active' : '' }}">
            <i class="fa fa-cog"></i> <span>Settings</span>
        </a>
    </div>
</div>

<!-- Top Navbar -->
<div class="top-navbar">
    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary">
            <span class="arrow"><i class="fa fa-chevron-left"></i></span>
        </button>

        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}" height="45" alt="Logo">
        </a>

        <!-- Admin Button lable for calerification with style -->
        <div class="d-none d-md-block">
            <span  class="btn btn-sm btn-outline-primary">
                Admin Mode
            </span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 ">
        <button id="toggleDarkMode" class="btn btn-outline-secondary btn-sm" title="Toggle Dark Mode">
            <i class="fa fa-moon"></i>
            <i class="fa fa-sun d-none"></i>
        </button>

        <div class="dropdown">
            <button class="btn dropdown-toggle d-flex align-items-center gap-1" data-bs-toggle="dropdown">
                <div class="rounded-circle bg-primary text-white d-flex justify-content-center align-items-center"
                     style="width: 36px; height: 36px; font-weight: 600;">
                    {{ strtoupper(substr(auth()->user()->name,0,1)) }}
                </div>
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2">
                    <strong>{{ auth()->user()->name }}</strong><br>
                    <small>{{ auth()->user()->email }}</small>
                </li>
                <li><hr class="dropdown-divider"></li>
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

<!-- Content -->
<div id="content">
    @yield('content')
</div>

<!-- Footer -->
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
</script>
</body>
</html>