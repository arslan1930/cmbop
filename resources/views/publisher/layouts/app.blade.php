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

    <style>
        body, html {
            min-height: 100%;
            margin: 0;
            background-color: #f8f9fa;
            font-family: 'Poppins', system-ui, sans-serif;
        }

        body.role-shell-advertiser {
            --role-accent: #0b6266;
        }
        body.role-shell-publisher {
            --role-accent: #c45c26;
        }
        body.role-shell-advertiser .top-navbar,
        body.role-shell-publisher .top-navbar {
            border-top: 3px solid var(--role-accent);
        }
        body.role-shell-advertiser #sidebar,
        body.role-shell-publisher #sidebar {
            border-left: 3px solid var(--role-accent);
        }
        .role-switch-btn {
            border-color: var(--role-accent, #c45c26) !important;
            color: var(--role-accent, #c45c26) !important;
        }
        .role-switch-btn:hover {
            background: var(--role-accent, #c45c26) !important;
            color: #fff !important;
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

        /* Quiet active/hover — brand tint (shared shell may also apply) */
        #sidebar a.active,
        #sidebar a:hover {
            border-radius: 8px;
            background-color: var(--brand-primary-bg, #e8f8f7);
            color: var(--brand-primary, #0b6266);
            border: 1px solid var(--brand-primary-border, #b8e8e6);
        }
        #sidebar a.active i,
        #sidebar a:hover i {
            color: var(--brand-primary, #0b6266);
        }

        .nav-count-badge {
            background: #e9ecef !important;
            color: #495057 !important;
            font-weight: 600;
            font-size: 11px;
        }
        #sidebar a.active .nav-count-badge,
        #sidebar a:hover .nav-count-badge {
            background: #fff !important;
            color: var(--brand-primary, #0b6266) !important;
            border-color: var(--brand-primary-border, #b8e8e6);
        }
        .nav-alert-badge {
            background: var(--brand-primary-bg, #e8f8f7) !important;
            color: var(--brand-primary, #0b6266) !important;
            border: 1px solid var(--brand-primary-border, #b8e8e6) !important;
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
            font-weight: 600;
        }


        /* Mobile Sidebar Logo Styling */
        .mobile-sidebar-logo {
            padding: 16px 0;
            text-align: center;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            margin-bottom: 8px;
            display: none; /* hidden by default, shown on mobile */
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