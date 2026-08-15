<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Marketing') — SEOLinkBuildings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.favicon')

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
    {{-- Sites list / bulk request screens are shared with the admin shell.
         Marketing overrides sit before hover-system.css, which must remain last. --}}
    <link href="{{ asset('assets/css/staff-sites.css') }}?v={{ @filemtime(public_path('assets/css/staff-sites.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/marketing-shell.css') }}?v={{ @filemtime(public_path('assets/css/marketing-shell.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/slb-live-search.css') }}?v={{ @filemtime(public_path('assets/css/slb-live-search.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/hover-system.css') }}?v={{ @filemtime(public_path('assets/css/hover-system.css')) ?: '1' }}" rel="stylesheet">
    <script src="{{ asset('assets/js/pulse-badge.js') }}?v={{ @filemtime(public_path('assets/js/pulse-badge.js')) ?: '1' }}" defer></script>
    <script src="{{ asset('assets/js/glass-tip.js') }}?v={{ @filemtime(public_path('assets/js/glass-tip.js')) ?: '1' }}" defer></script>
    @stack('head')
</head>
<body class="role-shell-marketing">

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

        <div class="mkt-nav-section">Marketing</div>
        <a href="{{ route('marketing.dashboard') }}" class="{{ request()->routeIs('marketing.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span class="nav-label">Dashboard</span>
        </a>
        <a href="{{ route('marketing.history') }}" class="{{ request()->routeIs('marketing.history') ? 'active' : '' }}">
            <i class="fa fa-history"></i> <span class="nav-label">My task history</span>
        </a>

        <div class="mkt-nav-section">Catalog ops</div>
        <a href="{{ route('marketing.sites.index') }}" class="{{ request()->routeIs('marketing.sites.*') ? 'active' : '' }}">
            <i class="fa fa-globe"></i>
            <span class="d-flex align-items-center w-100">
                <span class="nav-label">Sites</span>
                @if(($mktReadySiteCount ?? 0) > 0)
                    <span class="mkt-nav-badge badge bg-warning text-dark rounded-pill ms-auto" data-nav-badge="sites" data-count="{{ $mktReadySiteCount }}">{{ $mktReadySiteCount > 99 ? '99+' : $mktReadySiteCount }}</span>
                @endif
            </span>
        </a>
        <a href="{{ route('marketing.bulk-site-requests.index') }}" class="{{ request()->routeIs('marketing.bulk-site-requests.*') ? 'active' : '' }}">
            <i class="fa fa-layer-group"></i>
            <span class="d-flex align-items-center w-100">
                <span class="nav-label">Bulk requests</span>
                @if(($mktBulkWaitingCount ?? 0) > 0)
                    <span class="mkt-nav-badge badge bg-warning text-dark rounded-pill ms-auto" data-nav-badge="bulk" data-count="{{ $mktBulkWaitingCount }}">{{ $mktBulkWaitingCount > 99 ? '99+' : $mktBulkWaitingCount }}</span>
                @endif
            </span>
        </a>
        <a href="{{ route('marketing.staff-handbook') }}" class="{{ request()->routeIs('marketing.staff-handbook') ? 'active' : '' }}">
            <i class="fa fa-book"></i> <span class="nav-label">Staff handbook</span>
        </a>
        <a href="{{ route('marketing.promotions.index') }}" class="{{ request()->routeIs('marketing.promotions.*') ? 'active' : '' }}">
            <i class="fa fa-bullhorn"></i> <span class="nav-label">Promotions</span>
        </a>
    </div>
</div>

<div class="top-navbar">
    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary" type="button" aria-label="Toggle sidebar navigation" title="Toggle sidebar">
            <span class="arrow" aria-hidden="true"><i class="fa fa-chevron-left"></i></span>
        </button>
        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="44" width="158" style="width:auto;max-width:min(220px,42vw);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
        </a>
        <div class="d-none d-md-block">
            <span class="mkt-mode-badge">Marketing workspace</span>
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
                    <strong>{{ $user->name }}</strong><br>
                    <small class="text-muted">{{ $user->email }}</small>
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
    @include('partials.session-flash')
    @yield('content')
</div>

<footer>
    © {{ date('Y') }} SEOLinkBuildings · Marketing
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
    const shellParts = [sidebar, content, topNavbar, footerEl, toggleBtn].filter(Boolean);

    function isDesktopNav() {
        return window.innerWidth > 768;
    }

    function setDesktopCollapsed(collapsed) {
        shellParts.forEach(function (el) {
            el.classList.toggle('collapsed', collapsed);
        });
    }

    function syncSidebarForViewport() {
        if (!sidebar || !toggleBtn) return;
        if (!isDesktopNav()) {
            // Mobile uses the slide-in drawer — never keep desktop collapsed chrome.
            setDesktopCollapsed(false);
            return;
        }
        sidebar.classList.remove('show');
        setDesktopCollapsed(localStorage.getItem('sidebarCollapsed') === 'true');
    }

    syncSidebarForViewport();

    toggleBtn.addEventListener('click', function () {
        if (isDesktopNav()) {
            const next = !sidebar.classList.contains('collapsed');
            setDesktopCollapsed(next);
            localStorage.setItem('sidebarCollapsed', next ? 'true' : 'false');
        } else {
            sidebar.classList.toggle('show');
        }
    });

    window.addEventListener('resize', syncSidebarForViewport);

    document.body.classList.remove('layout-dark');
    try { localStorage.removeItem('layoutDarkMode'); } catch (e) {}

    window.refreshAdminQueueBadges = function refreshMarketingQueueBadges() {
        fetch(@json(route('marketing.dashboard.queue-counts')), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then((r) => r.json())
        .then((data) => {
            if (!data || !data.success) return;
            const map = { sites: data.ready_sites || 0, bulk: data.bulk_waiting || 0 };
            Object.keys(map).forEach((key) => {
                const el = document.querySelector('[data-nav-badge="' + key + '"]');
                if (!el) return;
                const count = Number(map[key]) || 0;
                el.dataset.count = String(count);
                if (count > 0) {
                    el.style.display = '';
                    el.textContent = count > 99 ? '99+' : String(count);
                } else {
                    el.style.display = 'none';
                }
            });
        })
        .catch(() => {});
    };
    window.refreshAdminQueueBadges();
</script>
<script src="{{ asset('js/role-switch.js') }}?v={{ @filemtime(public_path('js/role-switch.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/notification-center.js') }}?v={{ @filemtime(public_path('assets/js/notification-center.js')) ?: '8' }}" defer></script>
@stack('scripts')
</body>
</html>
