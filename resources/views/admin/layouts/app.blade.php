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
        #sidebar a.active, #sidebar a:hover { border-radius: 6px; background-color: #4ECDCB; color: #fff; }
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

        /* Unused in admin top bar — kept for consistency */
        .balance-block { display: none; }

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

        #toggleDarkMode.topbar-icon-btn {
            width: 36px;
            height: 36px;
        }
        .top-navbar .dropdown-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

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
            <img id="logoSidebar" src="{{ asset('assets/img/logo1.png') }}" height="42" alt="SEOLinkBuildings">
        </div>

        <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>

        @if(auth()->user()->isAdmin())
        <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
            <i class="fa fa-users"></i> <span>Users</span>
        </a>
        @endif

        <a href="{{ route('admin.sites.index') }}" class="{{ request()->routeIs('admin.sites.*') ? 'active' : '' }}">
            <i class="fa fa-globe"></i>
            <span class="d-flex align-items-center w-100">
                <span>Sites</span>
                <span id="navBadgeSites" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>

        @if(auth()->user()->isAdmin())
        <!-- payments -->
         <a href="{{ route('admin.payments') }}" class="{{ request()->routeIs('admin.payments') || request()->routeIs('admin.payments.*') ? 'active' : '' }}">
            <i class="fa fa-money-bill"></i>
            <span class="d-flex align-items-center w-100">
                <span>Order Payments</span>
                <span id="navBadgePayments" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>

        <a href="{{ route('admin.deposits') }}" class="{{ request()->routeIs('admin.deposits') || request()->routeIs('admin.deposits.*') ? 'active' : '' }}">
            <i class="fa fa-wallet"></i>
            <span class="d-flex align-items-center w-100">
                <span>Deposits</span>
                <span id="navBadgeDeposits" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>

        <!-- withdrawals -->
        <a href="{{ route('admin.withdrawals') }}" class="{{ request()->routeIs('admin.withdrawals') || request()->routeIs('admin.withdrawals.*') ? 'active' : '' }}">
            <i class="fa fa-money-bill-wave"></i>
            <span class="d-flex align-items-center w-100">
                <span>Withdrawals</span>
                <span id="navBadgeWithdrawals" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>

        <!-- Blog -->
         <a class="nav-link {{ request()->routeIs('admin.blogs.*') ? 'active' : '' }}" href="{{ route('admin.blogs.index') }}">
        <i class="fa fa-blog me-2"></i>
        <span>Blogs</span>
    </a>
        @endif

        <a href="{{ route('admin.activity-logs.index') }}" class="{{ request()->routeIs('admin.activity-logs.*') ? 'active' : '' }}">
            <i class="fa fa-history"></i> <span>Activity History</span>
        </a>

    <!-- <a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings') ? 'active' : '' }}">
            <i class="fa fa-cog"></i> <span>Settings</span>
        </a> -->
    </div>
</div>

<!-- Top Navbar -->
<div class="top-navbar">
    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary">
            <span class="arrow"><i class="fa fa-chevron-left"></i></span>
        </button>

        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}" height="45" alt="SEOLinkBuildings">
        </a>

        <!-- Admin / Marketing mode label -->
        <div class="d-none d-md-block">
            <span class="btn btn-sm btn-outline-primary">
                {{ auth()->user()->isMarketing() ? 'Marketing Mode' : 'Admin Mode' }}
            </span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
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

<!-- Content -->
<div id="content">
    @yield('content')
</div>

<!-- Footer -->
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

    document.body.classList.remove('layout-dark');
    try { localStorage.removeItem('layoutDarkMode'); } catch (e) {}

    function setNavBadge(id, count) {
        const el = document.getElementById(id);
        if (!el) return;
        if (count > 0) {
            el.style.display = 'inline-block';
            el.textContent = count > 99 ? '99+' : count;
        } else {
            el.style.display = 'none';
        }
    }

    function refreshAdminQueueBadges() {
        fetch('{{ route("admin.dashboard.queue-counts") }}', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            setNavBadge('navBadgeDeposits', data.pending_deposits || 0);
            setNavBadge('navBadgeWithdrawals', data.pending_withdrawals || 0);
            setNavBadge('navBadgeSites', data.unverified_sites || 0);
            setNavBadge('navBadgePayments', data.pending_payments || 0);
        })
        .catch(() => {});
    }
    refreshAdminQueueBadges();
    setInterval(refreshAdminQueueBadges, 60000);
</script>
</body>
</html>