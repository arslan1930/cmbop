<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Admin Dashboard') — SEOLinkBuildings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.favicon')

    <!-- Bootstrap 5 CSS -->
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
    <link href="{{ asset('assets/css/admin-tables.css') }}?v={{ @filemtime(public_path('assets/css/admin-tables.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/glass-tip.css') }}?v={{ @filemtime(public_path('assets/css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/pulse-badge.css') }}?v={{ @filemtime(public_path('assets/css/pulse-badge.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/notification-center.css') }}?v={{ @filemtime(public_path('assets/css/notification-center.css')) ?: '5' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/dialog-system.css') }}?v={{ @filemtime(public_path('assets/css/dialog-system.css')) ?: '1' }}" rel="stylesheet">
    {{-- Admin overrides sit before hover-system.css, which must stay last in the
         cascade; they win where needed through body.role-shell-admin specificity. --}}
    <link href="{{ asset('assets/css/admin-shell.css') }}?v={{ @filemtime(public_path('assets/css/admin-shell.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/admin-components.css') }}?v={{ @filemtime(public_path('assets/css/admin-components.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/staff-sites.css') }}?v={{ @filemtime(public_path('assets/css/staff-sites.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/slb-live-search.css') }}?v={{ @filemtime(public_path('assets/css/slb-live-search.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/hover-system.css') }}?v={{ @filemtime(public_path('assets/css/hover-system.css')) ?: '1' }}" rel="stylesheet">
    <script src="{{ asset('assets/js/pulse-badge.js') }}?v={{ @filemtime(public_path('assets/js/pulse-badge.js')) ?: '1' }}" defer></script>
    <script src="{{ asset('assets/js/glass-tip.js') }}?v={{ @filemtime(public_path('assets/js/glass-tip.js')) ?: '1' }}" defer></script>
    <script src="{{ asset('assets/js/admin-pagination.js') }}?v={{ @filemtime(public_path('assets/js/admin-pagination.js')) ?: '1' }}" defer></script>
</head>
<body class="role-shell-admin">

<!-- Sidebar -->
<div id="sidebar">
    <div class="mobile-sidebar-logo">
        <img id="mobileSidebarLogo" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="48" width="172" alt="SEOLinkBuildings">
    </div>
    <div class="menu">
        <div class="text-center my-2 d-md-none">
            @include('partials.role-switcher', ['variant' => 'outline-secondary'])
        </div>
        <div class="shell-sidebar-brand text-center my-3 d-none d-md-block">
            <img id="logoSidebar" class="shell-logo-wordmark" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="48" width="172" style="width:auto;max-width:100%;object-fit:contain;background:transparent" alt="SEOLinkBuildings">
            <img class="shell-logo-mark" src="{{ asset('assets/brand/web/favicon.svg') }}" height="36" width="36" alt="" aria-hidden="true">
        </div>

        @php
            $staffPrefix = staff_route_prefix();
        @endphp
        <div class="admin-nav-section">Overview</div>
        <a href="{{ staff_route('dashboard') }}" class="{{ request()->routeIs($staffPrefix.'dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>

        <div class="admin-nav-section">Marketplace</div>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('admin.orders.index') }}" class="{{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
            <i class="fa fa-shopping-bag"></i> <span>Orders</span>
        </a>
        @endif
        <a href="{{ staff_route('sites.index') }}" class="{{ request()->routeIs($staffPrefix.'sites.*') ? 'active' : '' }}" title="Sites Management — all publishers">
            <i class="fa fa-globe"></i>
            <span class="d-flex align-items-center w-100">
                <span>Sites</span>
                <span id="navBadgeSites" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>
        <a href="{{ staff_route('agency-imports.index') }}" class="{{ request()->routeIs($staffPrefix.'agency-imports.*') ? 'active' : '' }}" title="Agency CSV imports">
            <i class="fa fa-file-csv"></i>
            <span class="d-flex align-items-center w-100">
                <span>Agency CSV</span>
                <span id="navBadgeAgencyImports" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>
        <a href="{{ staff_route('bulk-site-requests.index') }}" class="{{ request()->routeIs($staffPrefix.'bulk-site-requests.*') ? 'active' : '' }}">
            <i class="fa fa-layer-group"></i> <span>Bulk requests</span>
        </a>
        <a href="{{ staff_route('site-enrichment.index') }}" class="{{ request()->routeIs($staffPrefix.'site-enrichment.*') ? 'active' : '' }}">
            <i class="fa fa-chart-line"></i> <span>Enrichment</span>
        </a>
        <a href="{{ staff_route('staff-handbook') }}" class="{{ request()->routeIs($staffPrefix.'staff-handbook') ? 'active' : '' }}">
            <i class="fa fa-book"></i> <span>Staff handbook</span>
        </a>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('admin.site-ratings.index') }}" class="{{ request()->routeIs('admin.site-ratings.*') ? 'active' : '' }}">
            <i class="fa fa-star"></i> <span>Ratings</span>
        </a>
        @endif

        @if(auth()->user()->isAdmin())
        <div class="admin-nav-section">Money</div>
        <a href="{{ route('admin.finance') }}" class="{{ request()->routeIs('admin.finance') || request()->routeIs('admin.finance.*') ? 'active' : '' }}">
            <i class="fa fa-chart-pie"></i> <span>Finance</span>
        </a>
        <a href="{{ route('admin.finance.ledger') }}" class="{{ request()->routeIs('admin.finance.ledger') ? 'active' : '' }}">
            <i class="fa fa-book"></i> <span>Wallet ledger</span>
        </a>
        <a href="{{ route('admin.payments') }}" class="{{ request()->routeIs('admin.payments') || request()->routeIs('admin.payments.*') ? 'active' : '' }}">
            <i class="fa fa-money-bill"></i>
            <span class="d-flex align-items-center w-100">
                <span>Order Payments</span>
                <span id="navBadgePayments" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>
        <a href="{{ route('admin.invoices.index') }}" class="{{ request()->routeIs('admin.invoices.*') ? 'active' : '' }}">
            <i class="fa fa-file-invoice-dollar"></i> <span>Invoices</span>
        </a>
        <a href="{{ route('admin.deposits') }}" class="{{ request()->routeIs('admin.deposits') || request()->routeIs('admin.deposits.*') ? 'active' : '' }}">
            <i class="fa fa-wallet"></i>
            <span class="d-flex align-items-center w-100">
                <span>Deposits</span>
                <span id="navBadgeDeposits" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>
        <a href="{{ route('admin.withdrawals') }}" class="{{ request()->routeIs('admin.withdrawals') || request()->routeIs('admin.withdrawals.*') ? 'active' : '' }}">
            <i class="fa fa-money-bill-wave"></i>
            <span class="d-flex align-items-center w-100">
                <span>Withdrawals</span>
                <span id="navBadgeWithdrawals" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>
        @endif

        @if(auth()->user()->isAdmin())
        <div class="admin-nav-section">People</div>
        <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
            <i class="fa fa-users"></i> <span>Users</span>
        </a>
        <a href="{{ route('admin.community.index') }}" class="{{ request()->routeIs('admin.community.*') ? 'active' : '' }}">
            <i class="fa fa-comments"></i>
            <span class="d-flex align-items-center w-100">
                <span>Community</span>
                <span id="navBadgeCommunity" class="badge bg-warning text-dark rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>

        <div class="admin-nav-section">Growth</div>
        <a href="{{ route('admin.blogs.index') }}" class="{{ request()->routeIs('admin.blogs.*') ? 'active' : '' }}">
            <i class="fa fa-blog"></i> <span>Blogs</span>
        </a>
        <a href="{{ route('admin.emails.index') }}" class="{{ request()->routeIs('admin.emails.*') ? 'active' : '' }}">
            <i class="fa fa-envelope-open-text"></i> <span>Email Center</span>
        </a>
        <a href="{{ route('admin.campaigns.index') }}" class="{{ request()->routeIs('admin.campaigns.*') ? 'active' : '' }}">
            <i class="fa fa-paper-plane"></i> <span>Campaigns</span>
        </a>
        <a href="{{ route('admin.audiences.index') }}" class="{{ request()->routeIs('admin.audiences.*') ? 'active' : '' }}">
            <i class="fa fa-address-book"></i> <span>Audiences</span>
        </a>
        <a href="{{ route('admin.promotions.index') }}" class="{{ request()->routeIs('admin.promotions.*') ? 'active' : '' }}">
            <i class="fa fa-bullhorn"></i> <span>Promotions</span>
        </a>
        <a href="{{ route('admin.moderation.index') }}" class="{{ request()->routeIs('admin.moderation.*') ? 'active' : '' }}">
            <i class="fa fa-shield-alt"></i> <span>Moderation</span>
        </a>
        <a href="{{ route('admin.content-library.index') }}" class="{{ request()->routeIs('admin.content-library.*') ? 'active' : '' }}">
            <i class="fa fa-folder-open"></i> <span>Content Library</span>
        </a>
        <div class="admin-nav-section">System</div>
        <a href="{{ route('admin.activity-logs.index') }}" class="{{ request()->routeIs('admin.activity-logs.*') ? 'active' : '' }}">
            <i class="fa fa-history"></i> <span>Activity History</span>
        </a>
        <a href="{{ route('admin.catalog-activity') }}" class="{{ request()->routeIs('admin.catalog-activity*') ? 'active' : '' }}" title="Who is opening publisher addresses">
            <i class="fa fa-eye"></i> <span>Catalog Activity</span>
        </a>
        @endif
    </div>
</div>

<!-- Top Navbar -->
<div class="top-navbar">
    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary" type="button" aria-label="Toggle sidebar navigation" title="Toggle sidebar">
            <span class="arrow" aria-hidden="true"><i class="fa fa-chevron-left"></i></span>
        </button>

        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="44" width="158" style="width:auto;max-width:min(220px,42vw);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
        </a>

        <div class="d-none d-md-block">
            <span class="admin-mode-badge">
                {{ auth()->user()->isMarketing() ? 'Marketing' : 'Admin' }}
            </span>
            <span class="ms-2">
                @include('partials.role-switcher', ['variant' => 'outline-secondary'])
            </span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
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
    @include('partials.session-flash')
    @yield('content')
</div>

<!-- Footer -->
<footer>
    © {{ date('Y') }} SEOLinkBuildings
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/js/modal-stack.js') }}?v={{ @filemtime(public_path('assets/js/modal-stack.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/admin-manage-dropdown.js') }}?v={{ @filemtime(public_path('assets/js/admin-manage-dropdown.js')) ?: '1' }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('js/slb-confirm.js') }}?v={{ @filemtime(public_path('js/slb-confirm.js')) ?: '1' }}"></script>
<script src="{{ asset('js/slb-live-search.js') }}?v={{ @filemtime(public_path('js/slb-live-search.js')) ?: '1' }}"></script>
@include('partials.app-toast')
<script src="{{ asset('js/slb-http.js') }}?v={{ @filemtime(public_path('js/slb-http.js')) ?: '1' }}"></script>


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

    @if(auth()->user()->isAdmin())
    window.refreshAdminQueueBadges = function refreshAdminQueueBadges() {
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
            setNavBadge('navBadgeCommunity', data.pending_claims || 0);
            setNavBadge('navBadgeAgencyImports', data.pending_agency_imports || 0);
        })
        .catch(() => {});
    }
    window.refreshAdminQueueBadges();
    setInterval(window.refreshAdminQueueBadges, 60000);
    @endif
</script>
<script src="{{ asset('js/role-switch.js') }}?v={{ @filemtime(public_path('js/role-switch.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/notification-center.js') }}?v={{ @filemtime(public_path('assets/js/notification-center.js')) ?: '8' }}" defer></script>
</body>
</html>