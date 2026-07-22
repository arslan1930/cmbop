<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Advertiser Dashboard</title>
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
    <link href="{{ asset('css/cart.css') }}?v={{ @filemtime(public_path('css/cart.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/chat.css') }}?v={{ @filemtime(public_path('css/chat.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/multi-select.css') }}?v={{ @filemtime(public_path('css/multi-select.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/glass-tip.css') }}?v={{ @filemtime(public_path('css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/pulse-badge.css') }}?v={{ @filemtime(public_path('css/pulse-badge.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('css/notification-center.css') }}?v={{ @filemtime(public_path('css/notification-center.css')) ?: '5' }}" rel="stylesheet">
    <script src="{{ asset('js/pulse-badge.js') }}?v={{ @filemtime(public_path('js/pulse-badge.js')) ?: '1' }}"></script>
    <script src="{{ asset('js/glass-tip.js') }}?v={{ @filemtime(public_path('js/glass-tip.js')) ?: '1' }}" defer></script>

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
            border-color: var(--role-accent, #0b6266) !important;
            color: var(--role-accent, #0b6266) !important;
        }
        .role-switch-btn:hover {
            background: var(--role-accent, #0b6266) !important;
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

        #sidebar .nav-group {
            margin: 0 10px 8px;
        }
        #sidebar .nav-sub {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin: 4px 0 2px 12px;
            padding: 6px 0 2px 10px;
            border-left: 2px solid var(--brand-primary-border, #b8e8e6);
        }
        #sidebar .nav-sub-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px !important;
            border-radius: 8px;
            color: var(--brand-primary, #0b6266) !important;
            background: transparent;
            border: 1px solid transparent;
            font-size: 0.86rem;
            font-weight: 500;
            text-decoration: none;
            transition: background .18s ease, border-color .18s ease, color .18s ease;
        }
        #sidebar .nav-sub-link:hover {
            background: var(--brand-primary-bg, #e8f8f7) !important;
            border-color: var(--brand-primary-border, #b8e8e6);
            color: var(--brand-primary, #0b6266) !important;
        }
        #sidebar .nav-sub-link i {
            color: var(--brand-primary-soft, #3aaeb2);
            width: 14px;
            text-align: center;
        }
        #sidebar .nav-sub-soon {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 8px;
            color: #94a3b8;
            font-size: 0.86rem;
            font-weight: 500;
            cursor: default;
            user-select: none;
        }
        #sidebar .nav-sub-soon .soon-pill {
            font-size: 0.65rem;
            letter-spacing: .03em;
            text-transform: uppercase;
            color: var(--brand-primary, #0b6266);
            background: var(--brand-primary-bg, #e8f8f7);
            border: 1px solid var(--brand-primary-border, #b8e8e6);
            border-radius: 999px;
            padding: 2px 7px;
            white-space: nowrap;
        }
        #sidebar.collapsed .nav-sub { display: none; }

        /* Neutral count vs actionable alert badges (N3) */
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

        .cart-total-label {
            font-size: 12px;
            font-weight: 600;
            color: #0b6266;
            white-space: nowrap;
        }

        .topbar-action {
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            font-size: 13px;
            font-weight: 500;
            position: relative;
        }

        #toggleCart {
            width: auto;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 0 12px;
            position: relative;
            gap: 6px;
        }

        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            min-width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            padding: 0 4px;
        }

        /* Dark mode uses .topbar-icon-btn; keep IDs for JS */

        /* Cart Sidebar */
        .cart-sidebar {
            position: fixed;
            top: 0;
            right: -30%;
            width: 30%;
            height: 100vh;
            background-color: #fff;
            box-shadow: -2px 0 5px rgba(0,0,0,0.1);
            z-index: 1100;
            transition: right 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
        }


        .cart-sidebar.open {
            right: 0;
        }

        .cart-header {
            padding: 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        .cart-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .cart-item-sensitive {
            font-size: 11px;
            color: #dc3545;
            margin-top: 2px;
        }

        .cart-item-price {
            font-size: 13px;
            color: #666;
        }


        .cart-item-quantity {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cart-item-quantity button {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.2s ease;
        }

        .cart-item-quantity button:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }

        .cart-item-quantity .quantity-number {
            min-width: 25px;
            text-align: center;
            font-weight: 500;
        }



        .cart-item-remove {
            color: #dc3545;
            cursor: pointer;
            margin-left: 10px;
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            border: 0;
            background: transparent;
            padding: 0;
            transition: all 0.2s ease;
        }

        .cart-item-remove:hover {
            background-color: #dc3545;
            color: white;
        }

        .cart-item {
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .cart-item-article {
            flex: 1 1 100%;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #e2e8f0;
        }

        .cart-item-article label {
            display: block;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #64748b;
            margin-bottom: 4px;
        }

        .cart-item-article select {
            width: 100%;
            font-size: 0.82rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 8px;
            background: #fff;
        }

        .cart-item-article-empty {
            font-size: 0.78rem;
            color: #b45309;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 8px 10px;
            line-height: 1.35;
        }

        .cart-item-article-empty a {
            color: #0b6266;
            font-weight: 600;
        }

        .cart-ready-note {
            font-size: 0.78rem;
            color: #64748b;
            margin-bottom: 10px;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1099;
            display: none;
        }

        .overlay.show {
            display: block;
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
            .cart-sidebar {
                width: 80%;
                right: -80%;
            }
            
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

<body class="role-shell-advertiser">

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

        <a href="{{ route('advertiser.dashboard') }}" class="{{ request()->routeIs('advertiser.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>

        <!-- Catalog -->
        <a href="{{ route('advertiser.catalog') }}" class="{{ request()->routeIs('advertiser.catalog') ? 'active' : '' }}">
            <i class="fa fa-list"></i> 
            <span>Catalog</span>
        </a>

        <a href="{{ route('advertiser.saved-sites') }}" class="{{ request()->routeIs('advertiser.saved-sites*') ? 'active' : '' }}">
            <i class="fa fa-heart"></i>
            <span>Saved Sites</span>
        </a>

        <a href="{{ route('advertiser.content-library') }}" class="{{ request()->routeIs('advertiser.content-library*') ? 'active' : '' }}">
            <i class="fa fa-file-word"></i>
            <span>Content Library</span>
        </a>

        <!-- Orders -->
        <a href="{{ route('advertiser.orders') }}" class="{{ request()->routeIs('advertiser.orders') ? 'active' : '' }}">
            <i class="fa fa-shopping-cart"></i>
            <span class="d-flex align-items-center w-100">
                <span>Orders</span>
                <span id="navNeedsActionBadge" class="badge nav-alert-badge pulse-badge rounded-pill ms-auto" style="display:none;" data-pulse-display="inline-block">0</span>
            </span>
        </a>

        <a href="{{ route('advertiser.scheduled-orders') }}" class="{{ request()->routeIs('advertiser.scheduled-orders*') ? 'active' : '' }}">
            <i class="fa fa-calendar-alt"></i>
            <span>Scheduled</span>
        </a>

        <!-- Add Funds -->
        <a href="{{ route('advertiser.add-funds') }}" class="{{ request()->routeIs('advertiser.add-funds*') || request()->routeIs('advertiser.balance*') ? 'active' : '' }}">
            <i class="fa fa-coins"></i> <span>Add Funds</span>
        </a>

        <a href="{{ route('advertiser.billing.index') }}" class="{{ request()->routeIs('advertiser.billing*') ? 'active' : '' }}">
            <i class="fa fa-file-invoice"></i>
            <span>Billing &amp; Invoices</span>
        </a>
        
        <!-- Spending History -->
        <a href="{{ route('advertiser.analytics') }}" class="{{ request()->routeIs('advertiser.analytics*') ? 'active' : '' }}">
            <i class="fa fa-chart-area"></i> <span>Spending</span>
        </a>

        <!-- Reports -->
        <a href="{{ route('advertiser.reports') }}" class="{{ request()->routeIs('advertiser.reports') ? 'active' : '' }}">
            <i class="fa fa-chart-line"></i> <span>Reports</span>
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

        <!-- Cart — count + estimated total while browsing -->
        @php
            $headerCart = session('cart', []);
            $headerCartCount = (int) array_sum(array_map(fn ($row) => (int) ($row['quantity'] ?? 0), $headerCart));
            $headerCartTotal = round(array_sum(array_map(
                fn ($row) => ((float) ($row['price'] ?? 0)) * ((int) ($row['quantity'] ?? 0)),
                $headerCart
            )), 2);
        @endphp
        <button id="toggleCart" class="btn btn-outline-secondary btn-sm topbar-action" type="button" aria-label="Open cart" title="Cart">
            <i class="fa fa-shopping-cart" aria-hidden="true"></i>
            <span class="d-none d-sm-inline">Cart</span>
            <span id="cartTotalBadge" class="cart-total-label {{ $headerCartCount > 0 ? '' : 'd-none' }}">€{{ number_format($headerCartTotal, 2) }}</span>
            <span id="cartBadge" class="cart-badge" style="{{ $headerCartCount > 0 ? 'display:flex;' : 'display:none;' }}">{{ $headerCartCount > 0 ? $headerCartCount : 0 }}</span>
        </button>

        <!-- Balance -->
        @php
            $activeWallet = auth()->user()->activeWallet();
            $spendableBalance = (float) ($activeWallet?->balance ?? 0);
            $reservedBalance = (float) ($activeWallet?->reserved_balance ?? 0);
            $headerBalanceTitle = 'Spendable €' . number_format($spendableBalance, 2)
                . ($reservedBalance > 0 ? ' · On hold: €' . number_format($reservedBalance, 2) : '');
        @endphp
        <a href="{{ route('advertiser.add-funds') }}" class="balance-block text-decoration-none" data-bs-toggle="tooltip" data-bs-placement="bottom" title="{{ $headerBalanceTitle }}" aria-label="Spendable balance {{ number_format($spendableBalance, 2) }} euros">
            <span class="balance-label">Spendable</span>
            <span class="balance-amount">€{{ number_format($spendableBalance, 2) }}</span>
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
                @elseif($user->profile_avatar ?? false)
                    <img src="{{ asset('storage/' . $user->profile_avatar) }}"
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
                    <strong>{{ auth()->user()->name }}</strong><br>
                    <small class="text-muted">{{ auth()->user()->email }}</small>
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

<!-- Overlay -->
<div id="cartOverlay" class="overlay"></div>

<!-- Cart Sidebar -->
<div id="cartSidebar" class="cart-sidebar">
    <div class="cart-header">
        <div>
            <h5 class="mb-0">Your Cart</h5>
            <div class="small text-muted mt-1">Each website needs its own approved article.</div>
        </div>
        <button id="closeCart" class="btn btn-sm btn-outline-secondary" type="button" aria-label="Close cart">
            <i class="fa fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <div class="cart-body">
        <div id="cartChecklist" class="cart-checklist d-none" aria-live="polite"></div>
        <div id="cartItemsContainer">
            <div class="text-center text-muted">Your cart is empty</div>
        </div>
    </div>
    <div class="cart-footer">
        <div id="cartReadyNote" class="cart-ready-note d-none"></div>
        <div class="d-flex justify-content-between mb-3">
            <strong>Total:</strong>
            <strong id="cartTotalAmount">€0.00</strong>
        </div>
        <button id="checkoutFromCart" class="btn btn-primary w-100" type="button">
            <i class="fa fa-credit-card"></i> Proceed to Checkout
        </button>
        <button id="keepBrowsingCatalog" class="btn btn-outline-secondary w-100 mt-2" type="button">
            <i class="fa fa-list"></i> Keep browsing publishers
        </button>
        <div id="cartProceedHint" class="small text-muted mt-2 d-none">
            Finish the checklist above when you are ready to pay — you can keep browsing the catalog anytime.
        </div>
    </div>
</div>

<div id="content">
    @include('components.site-announcements', ['audience' => 'advertiser'])
    @include('components.ad-banners', ['placement' => 'dashboard', 'audience' => 'advertiser'])
    @include('components.ad-banners', ['placement' => 'content_top', 'audience' => 'advertiser'])
    @yield('content')
    @include('components.ad-banners', ['placement' => 'content_bottom', 'audience' => 'advertiser'])
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
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    // Sidebar Toggle
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

    // Dark mode removed — ensure light theme
    document.body.classList.remove('layout-dark');
    try { localStorage.removeItem('layoutDarkMode'); } catch (e) {}

    // Bootstrap tooltips (skip glass-tip triggers — they use GlassTip)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]:not([data-glass-tip])'))
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

    // Cart Functionality with Sensitive Price Support
    let cart = [];
    
    // Generate unique key for cart item (includes sensitive type)
    function getCartItemKey(item) {
        return `${item.id}_${item.sensitive_type || 'standard'}`;
    }
    
    let approvedArticles = [];
    let contentLibraryUploadUrl = @json(route('advertiser.content-library', ['upload' => 1]));
    let catalogUrl = @json(route('advertiser.catalog'));

    function applyCartPayload(data) {
        if (Array.isArray(data)) {
            cart = data;
            return;
        }
        cart = Array.isArray(data?.cart) ? data.cart : [];
        approvedArticles = Array.isArray(data?.approved_articles) ? data.approved_articles : [];
        if (data?.content_library_url) {
            contentLibraryUploadUrl = data.content_library_url;
        }
    }

    function articlesForCartLine(item) {
        const siteLang = String(item.language || '').toLowerCase();
        const selectedId = parseInt(item.content_submission_id || 0, 10) || 0;
        const usedElsewhere = new Set(
            cart
                .filter((row) => getCartItemKey(row) !== getCartItemKey(item))
                .map((row) => parseInt(row.content_submission_id || 0, 10))
                .filter((id) => id > 0)
        );
        return approvedArticles.filter((article) => {
            if (usedElsewhere.has(article.id) && article.id !== selectedId) return false;
            return true;
        });
    }

    function cartLinesMissingArticles() {
        return cart.filter((item) => !parseInt(item.content_submission_id || 0, 10));
    }

    // Load cart from session on page load
    function loadCart() {
        $.ajax({
            url: '{{ route("advertiser.cart.get") }}',
            method: 'GET',
            success: function(data) {
                applyCartPayload(data);
                updateCartDisplay();
            },
            error: function() {
                console.error('Failed to load cart');
            }
        });
    }
    
    // Save cart to session
    function saveCart() {
        $.ajax({
            url: '{{ route("advertiser.cart.save") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            contentType: 'application/json',
            data: JSON.stringify({ cart: cart }),
            success: function() {
                loadCart();
            },
            error: function() {
                console.error('Failed to save cart');
            }
        });
    }

    function assignCartArticle(siteId, sensitiveType, submissionId) {
        $.ajax({
            url: '{{ route("advertiser.cart.assign-article") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            data: {
                id: siteId,
                sensitive_type: sensitiveType || '',
                content_submission_id: submissionId || ''
            },
            success: function(data) {
                if (!data.success) {
                    showToast(data.error || 'Could not assign article.', 'error');
                    loadCart();
                    return;
                }
                applyCartPayload(data);
                updateCartDisplay();
                if (data.message) showToast(data.message, 'success');
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.error || xhr.responseJSON?.message || 'Could not assign article.';
                showToast(msg, 'error');
                loadCart();
            }
        });
    }
    
    // Update cart display
    function updateCartDisplay() {
        const cartCount = cart.reduce((sum, item) => sum + (parseInt(item.quantity, 10) || 0), 0);
        const cartTotal = cart.reduce((sum, item) => sum + ((parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 0)), 0);
        
        const badge = document.getElementById('cartBadge');
        if (cartCount > 0) {
            badge.style.display = 'flex';
            badge.innerText = cartCount;
        } else {
            badge.style.display = 'none';
        }

        const totalBadge = document.getElementById('cartTotalBadge');
        if (totalBadge) {
            if (cartCount > 0) {
                totalBadge.classList.remove('d-none');
                totalBadge.textContent = '€' + cartTotal.toFixed(2);
            } else {
                totalBadge.classList.add('d-none');
                totalBadge.textContent = '€0.00';
            }
        }
        
        // Update cart sidebar
        const container = document.getElementById('cartItemsContainer');
        const readyNote = document.getElementById('cartReadyNote');
        const checklistEl = document.getElementById('cartChecklist');
        const proceedBtn = document.getElementById('checkoutFromCart');
        const proceedHint = document.getElementById('cartProceedHint');
        if (cart.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted px-2">
                    <p class="mb-2">Your cart is empty.</p>
                    <p class="small mb-0">
                        Browse the <a href="${catalogUrl}">catalog</a> for publishers,
                        or <a href="${contentLibraryUploadUrl}">upload an article</a> in Content Library first.
                    </p>
                </div>`;
            if (readyNote) {
                readyNote.classList.add('d-none');
                readyNote.textContent = '';
            }
            if (checklistEl) {
                checklistEl.classList.add('d-none');
                checklistEl.innerHTML = '';
            }
            if (proceedBtn) {
                proceedBtn.disabled = true;
            }
            if (proceedHint) {
                proceedHint.classList.add('d-none');
            }
        } else {
            let html = '';
            const sortedCart = [...cart].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
            const missing = cartLinesMissingArticles().length;
            if (checklistEl) {
                let list = '<div class="small fw-semibold mb-1">Before Pay</div><ul class="mb-0 ps-0">';
                sortedCart.forEach((item) => {
                    const assigned = !!parseInt(item.content_submission_id || 0, 10);
                    const lang = String(item.language || '').toUpperCase();
                    const cls = assigned ? 'is-ok' : 'is-todo';
                    const mark = assigned ? '✓' : '!';
                    const detail = assigned
                        ? 'Article assigned'
                        : ('Needs ' + (lang ? lang + ' ' : '') + 'article');
                    list += `<li class="${cls}"><span class="mark" aria-hidden="true">${mark}</span><span><strong>${escapeHtml(item.name || 'Website')}</strong> — ${escapeHtml(detail)}</span></li>`;
                });
                list += '</ul>';
                checklistEl.innerHTML = list;
                checklistEl.classList.remove('d-none');
            }
            if (readyNote) {
                if (missing > 0) {
                    readyNote.classList.remove('d-none');
                    readyNote.innerHTML = missing === 1
                        ? '1 website still needs an approved article before checkout. You can keep browsing and finish later.'
                        : (missing + ' websites still need approved articles before checkout. You can keep browsing and finish later.');
                } else {
                    readyNote.classList.remove('d-none');
                    readyNote.textContent = 'Checklist complete — proceed to pay, or keep browsing to add more sites.';
                }
            }
            if (proceedBtn) {
                proceedBtn.disabled = missing > 0;
            }
            if (proceedHint) {
                proceedHint.classList.toggle('d-none', missing === 0);
            }
            
            sortedCart.forEach((item) => {
                const itemKey = getCartItemKey(item);
                const sensitiveDisplay = item.sensitive_type ? 
                    `<div class="cart-item-sensitive"><small>+ ${escapeHtml(item.sensitive_type)} (€${(parseFloat(item.additional_price) || 0).toFixed(2)})</small></div>` : '';
                const options = articlesForCartLine(item);
                const selectedId = parseInt(item.content_submission_id || 0, 10) || 0;
                let articleBlock = '';
                if (options.length === 0 && !selectedId) {
                    articleBlock = `
                        <div class="cart-item-article">
                            <div class="cart-item-article-empty">
                                No approved article available yet.
                                <a href="${contentLibraryUploadUrl}">Upload or approve an article</a>, then assign it here (any language).
                            </div>
                        </div>`;
                } else {
                    let opts = `<option value="">— Select approved article —</option>`;
                    options.forEach((article) => {
                        const label = (article.title || 'Article')
                            + ' (' + String(article.language || '').toUpperCase()
                            + (article.country ? '/' + String(article.country).toUpperCase() : '')
                            + ')';
                        opts += `<option value="${article.id}" ${article.id === selectedId ? 'selected' : ''}>${escapeHtml(label)}</option>`;
                    });
                    if (selectedId && !options.some((a) => a.id === selectedId)) {
                        opts += `<option value="${selectedId}" selected>Assigned article #${selectedId}</option>`;
                    }
                    articleBlock = `
                        <div class="cart-item-article">
                            <label>Article for this website</label>
                            <select class="cart-article-select"
                                    data-id="${item.id}"
                                    data-sensitive-type="${item.sensitive_type || ''}"
                                    data-prev-value="${selectedId || ''}">
                                ${opts}
                            </select>
                        </div>`;
                }
                
                html += `
                    <div class="cart-item" data-key="${itemKey}">
                        <div class="cart-item-info">
                            <div class="cart-item-name">${escapeHtml(item.name)}</div>
                            ${sensitiveDisplay}
                            <div class="cart-item-price">€${(parseFloat(item.price) || 0).toFixed(2)} each</div>
                        </div>
                        <div class="cart-item-quantity">
                            <button type="button" class="decrease-qty" data-id="${item.id}" data-sensitive-type="${item.sensitive_type || ''}" aria-label="Decrease quantity">
                                <i class="fa fa-minus" aria-hidden="true"></i>
                            </button>
                            <span class="quantity-number" aria-label="Quantity ${item.quantity}">${item.quantity}</span>
                            <button type="button" class="increase-qty" data-id="${item.id}" data-sensitive-type="${item.sensitive_type || ''}" aria-label="Increase quantity">
                                <i class="fa fa-plus" aria-hidden="true"></i>
                            </button>
                        </div>
                        <button type="button" class="cart-item-remove" data-id="${item.id}" data-sensitive-type="${item.sensitive_type || ''}" aria-label="Remove ${escapeHtml(item.name)} from cart">
                            <i class="fa fa-times" aria-hidden="true"></i>
                        </button>
                        ${articleBlock}
                    </div>
                `;
            });
            container.innerHTML = html;
        }
        
        document.getElementById('cartTotalAmount').innerHTML = `€${cartTotal.toFixed(2)}`;
    }
    
    // Escape HTML
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    // Add to cart via server so Content Library article rules apply.
    window.addToCart = function(id, name, price, sensitiveType = null, additionalPrice = 0, basePrice = null) {
        $.ajax({
            url: '{{ route("advertiser.cart.add") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            data: {
                id: id,
                sensitive_type: sensitiveType || ''
            },
            success: function(data) {
                if (!data.success) {
                    showToast(data.error || 'Could not add to cart.', 'error');
                    return;
                }
                applyCartPayload(data);
                updateCartDisplay();
                const label = sensitiveType
                    ? `${name} + ${sensitiveType}`
                    : name;
                showToast(data.message || `${label} added to cart.`, 'success');
                // Keep browsing the catalog — cart stays available in the header to finish payment later.
                updateCartDisplay();
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.error || xhr.responseJSON?.message || 'Could not add to cart.';
                showToast(msg, 'error');
            }
        });
    };
    
    // Show toast
    function showToast(message, type = 'success') {
        // Create toast element if not exists
        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.className = 'position-fixed bottom-0 end-0 p-3';
            toastContainer.style.zIndex = '1100';
            document.body.appendChild(toastContainer);
        }
        
        const toastId = 'toast-' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : (type === 'error' ? 'bg-danger' : 'bg-warning');
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert" data-bs-autohide="true" data-bs-delay="3000">
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
        toast.show();
        
        toastElement.addEventListener('hidden.bs.toast', () => toastElement.remove());
    }
    
    // Cart Sidebar Toggle
    const cartSidebar = document.getElementById('cartSidebar');
    const cartOverlay = document.getElementById('cartOverlay');
    const toggleCartBtn = document.getElementById('toggleCart');
    const closeCartBtn = document.getElementById('closeCart');
    
    function openCart() {
        cartSidebar.classList.add('open');
        cartOverlay.classList.add('show');
        updateCartDisplay();
    }
    window.openCart = openCart;
    
    function closeCart() {
        cartSidebar.classList.remove('open');
        cartOverlay.classList.remove('show');
    }
    
    toggleCartBtn.addEventListener('click', openCart);
    closeCartBtn.addEventListener('click', closeCart);
    cartOverlay.addEventListener('click', closeCart);

    document.getElementById('keepBrowsingCatalog')?.addEventListener('click', function () {
        closeCart();
        const onCatalog = {{ request()->routeIs('advertiser.catalog') ? 'true' : 'false' }};
        if (!onCatalog) {
            window.location.href = catalogUrl;
        }
    });
    
    // Cart item actions (event delegation)
    document.getElementById('cartItemsContainer').addEventListener('click', function(e) {
        const target = e.target;
        const btn = target.closest('.decrease-qty, .increase-qty, .cart-item-remove');
        if (!btn) return;
        
        const id = parseInt(btn.dataset.id);
        const sensitiveType = btn.dataset.sensitiveType || null;
        
        // Find the exact item (including sensitive type)
        const itemIndex = cart.findIndex(item => 
            item.id === id && (item.sensitive_type || null) === sensitiveType
        );
        
        if (itemIndex === -1) return;
        
        if (btn.classList.contains('decrease-qty')) {
            if (cart[itemIndex].quantity > 1) {
                cart[itemIndex].quantity--;
            } else {
                cart.splice(itemIndex, 1);
            }
        } else if (btn.classList.contains('increase-qty')) {
            cart[itemIndex].quantity++;
        } else if (btn.classList.contains('cart-item-remove')) {
            cart.splice(itemIndex, 1);
        }
        
        saveCart();
        updateCartDisplay();
    });

    document.getElementById('cartItemsContainer').addEventListener('change', function(e) {
        const select = e.target.closest('.cart-article-select');
        if (!select) return;
        const id = parseInt(select.dataset.id, 10);
        const sensitiveType = select.dataset.sensitiveType || null;
        const submissionId = select.value ? parseInt(select.value, 10) : 0;
        const previous = select.dataset.prevValue || '';

        if (!submissionId) {
            select.dataset.prevValue = '';
            assignCartArticle(id, sensitiveType, 0);
            return;
        }

        const item = cart.find((row) =>
            row.id === id && (row.sensitive_type || null) === sensitiveType
        );
        const article = approvedArticles.find((row) => row.id === submissionId);
        const siteLang = String(item?.language || '').toLowerCase();
        const articleLang = String(article?.language || '').toLowerCase();
        const mismatch = siteLang && articleLang && siteLang !== articleLang
            ? ('Site is ' + siteLang.toUpperCase() + ', article is ' + articleLang.toUpperCase() + ' — continue?')
            : '';

        const proceed = function () {
            select.dataset.prevValue = select.value || '';
            assignCartArticle(id, sensitiveType, submissionId);
        };

        if (!mismatch) {
            proceed();
            return;
        }

        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                title: 'Language differs',
                text: mismatch,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Continue',
                cancelButtonText: 'Choose another',
                confirmButtonColor: '#0b6266',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then(function (result) {
                if (result.isConfirmed) {
                    proceed();
                } else {
                    select.value = previous;
                }
            });
            return;
        }

        if (window.confirm(mismatch)) {
            proceed();
        } else {
            select.value = previous;
        }
    });
    
    // Checkout from cart — blockers stay visible in the checklist (button disabled when incomplete)
    document.getElementById('checkoutFromCart').addEventListener('click', function() {
        if (cart.length === 0) {
            showToast('Your cart is empty!', 'error');
            return;
        }
        const missing = cartLinesMissingArticles();
        if (missing.length > 0) {
            openCart();
            return;
        }
        const wizardPay = @json(route('advertiser.wizard.pay'));
        const plainCheckout = @json(route('advertiser.checkout'));
        const inWizard = {{ request()->boolean('wizard') || !empty(\App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language'] ?? null) ? 'true' : 'false' }};
        window.location.href = inWizard ? wizardPay : plainCheckout;
    });
    
    // Load cart on page load
    loadCart();
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
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
            confirmButtonColor: '#0b6266',
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