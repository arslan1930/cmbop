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
    <link href="{{ asset('assets/css/hover-system.css') }}?v={{ @filemtime(public_path('assets/css/hover-system.css')) ?: '1' }}" rel="stylesheet">
    {{-- Sites list / bulk request screens are shared with the admin shell. --}}
    <link href="{{ asset('assets/css/staff-sites.css') }}?v={{ @filemtime(public_path('assets/css/staff-sites.css')) ?: '1' }}" rel="stylesheet">
    <script src="{{ asset('assets/js/pulse-badge.js') }}?v={{ @filemtime(public_path('assets/js/pulse-badge.js')) ?: '1' }}"></script>
    <script src="{{ asset('assets/js/glass-tip.js') }}?v={{ @filemtime(public_path('assets/js/glass-tip.js')) ?: '1' }}" defer></script>

    <style>
        /* Keep marketing shell widths on the shared app-shell tokens so expand/collapse stays aligned. */
        body.role-shell-marketing {
            --shell-sidebar-width: 230px;
            --shell-sidebar-collapsed: 70px;
        }
        body, html { min-height: 100%; margin: 0; background: linear-gradient(180deg, #f3faf9 0%, #f8f9fa 40%); font-family: 'Poppins', system-ui, sans-serif; }
        #sidebar {
            width: var(--shell-sidebar-width);
            min-width: var(--shell-sidebar-width);
            max-width: var(--shell-sidebar-width);
            background: #fff;
            border-right: 1px solid #e2e8f0; height: 100vh; position: fixed; top: 0; left: 0;
            display: flex; flex-direction: column; z-index: var(--shell-z-sidebar, 1050);
        }
        #sidebar .menu { flex-grow: 1; overflow-y: auto; padding-bottom: 16px; }
        #sidebar a {
            display: flex; align-items: center; gap: 10px; margin: 0 8px 2px; padding: 10px 12px;
            color: #475569; text-decoration: none; font-weight: 500; font-size: 0.9rem;
            border-radius: 8px; border: 1px solid transparent;
        }
        #sidebar a.active, #sidebar a:hover {
            background-color: var(--brand-primary-bg, #e6f5f5);
            color: var(--brand-primary, #1a585e);
            border-color: var(--brand-primary-border, #b8e4e4);
        }
        #sidebar a.active i, #sidebar a:hover i { color: var(--brand-primary, #1a585e); }
        #sidebar.collapsed {
            width: var(--shell-sidebar-collapsed);
            min-width: var(--shell-sidebar-collapsed);
            max-width: var(--shell-sidebar-collapsed);
        }
        /* Label clipping is handled by app-shell.css — do not use font-size:0 */
        #sidebar.collapsed a { justify-content: center; gap: 0; margin: 2px 6px; padding: 10px; }
        #sidebar.collapsed a i { font-size: 18px; }
        .mkt-nav-section {
            padding: 14px 20px 4px; font-size: 11px; font-weight: 600;
            letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8;
        }
        #sidebar.collapsed .mkt-nav-section { display: none; }
        .mkt-mode-badge {
            font-size: 12px; font-weight: 700; color: #1a585e;
            background: #e6f5f5; border: 1px solid #b8e4e4;
            border-radius: 999px; padding: 0.2rem 0.65rem;
        }
        .top-navbar {
            left: var(--shell-sidebar-width);
            background: rgba(255,255,255,0.92); backdrop-filter: blur(8px);
            border-bottom: 1px solid #e2e8f0;
            padding: 0 24px;
            z-index: var(--shell-z-topbar, 1060);
        }
        .top-navbar.collapsed { left: var(--shell-sidebar-collapsed); }
        #content { margin-left: var(--shell-sidebar-width); padding: 20px 30px 30px; min-height: calc(100vh - 120px); }
        #content.collapsed { margin-left: var(--shell-sidebar-collapsed); }
        footer { margin-left: var(--shell-sidebar-width); padding: 15px; text-align: center; background: #fff; border-top: 1px solid #e2e8f0; }
        footer.collapsed { margin-left: var(--shell-sidebar-collapsed); }
        #toggleSidebar span.arrow { display: inline-block; font-size: 18px; }
        #toggleSidebar.collapsed span.arrow { transform: rotate(180deg); }
        .topbar-icon-btn {
            width: 36px; height: 36px; border-radius: 8px; display: inline-flex;
            align-items: center; justify-content: center; padding: 0; color: #495057;
            border: 1px solid #dee2e6; background: #fff;
        }
        .topbar-icon-btn:hover { background: #f8f9fa; color: #1a585e; border-color: #b8e4e4; }
        @media (max-width: 768px) {
            #sidebar {
                top: var(--shell-topbar-height, 84px);
                height: calc(100vh - var(--shell-topbar-height, 84px));
                left: calc(-1 * var(--shell-sidebar-width));
                width: var(--shell-sidebar-width) !important;
                min-width: var(--shell-sidebar-width) !important;
                max-width: var(--shell-sidebar-width) !important;
            }
            #sidebar.show { left: 0; }
            #content, .top-navbar, footer { margin-left: 0 !important; }
            .top-navbar { left: 0 !important; padding-left: 10px; padding-right: 10px; }
        }
    </style>
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
            <i class="fa fa-globe"></i> <span class="nav-label">Sites</span>
        </a>
        <a href="{{ route('marketing.bulk-site-requests.index') }}" class="{{ request()->routeIs('marketing.bulk-site-requests.*') ? 'active' : '' }}">
            <i class="fa fa-layer-group"></i> <span class="nav-label">Bulk requests</span>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('js/slb-confirm.js') }}?v={{ @filemtime(public_path('js/slb-confirm.js')) ?: '1' }}"></script>
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
</script>
<script src="{{ asset('js/role-switch.js') }}?v={{ @filemtime(public_path('js/role-switch.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/notification-center.js') }}?v={{ @filemtime(public_path('assets/js/notification-center.js')) ?: '5' }}" defer></script>
@stack('scripts')
</body>
</html>
