<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Advertiser Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.favicon')

    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">

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
    <link href="{{ asset('assets/css/cart.css') }}?v={{ @filemtime(public_path('assets/css/cart.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/chat.css') }}?v={{ @filemtime(public_path('assets/css/chat.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/glass-tip.css') }}?v={{ @filemtime(public_path('assets/css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/pulse-badge.css') }}?v={{ @filemtime(public_path('assets/css/pulse-badge.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/notification-center.css') }}?v={{ @filemtime(public_path('assets/css/notification-center.css')) ?: '5' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/dialog-system.css') }}?v={{ @filemtime(public_path('assets/css/dialog-system.css')) ?: '1' }}" rel="stylesheet">
    {{-- Page stylesheets belong here, not in @section('content'): loading them
         with the body made the page paint unstyled first, and put them after the
         hover system in the cascade. --}}
    @stack('page-styles')
    <link href="{{ asset('assets/css/slb-live-search.css') }}?v={{ @filemtime(public_path('assets/css/slb-live-search.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/slb-pagination.css') }}?v={{ @filemtime(public_path('assets/css/slb-pagination.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/hover-system.css') }}?v={{ @filemtime(public_path('assets/css/hover-system.css')) ?: '1' }}" rel="stylesheet">
    <script src="{{ asset('assets/js/pulse-badge.js') }}?v={{ @filemtime(public_path('assets/js/pulse-badge.js')) ?: '1' }}" defer></script>
    <script src="{{ asset('assets/js/glass-tip.js') }}?v={{ @filemtime(public_path('assets/js/glass-tip.js')) ?: '1' }}" defer></script>
    <script src="{{ asset('assets/js/image-rights.js') }}?v={{ @filemtime(public_path('assets/js/image-rights.js')) ?: '1' }}" defer></script>

    <!-- Shell chrome lives in public/assets/css/app-shell.css; cart drawer in cart.css -->
</head>

<body class="role-shell-advertiser">

<a href="#main-content" class="skip-to-content">Skip to main content</a>

<!-- Sidebar -->
<nav id="sidebar" aria-label="Advertiser">
    <!-- Mobile Sidebar Logo (visible only on mobile) -->
    <div class="mobile-sidebar-logo">
        <img id="mobileSidebarLogo" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="48" width="172" style="width:auto;max-width:min(280px,90%);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
    </div>
    
    <div class="menu">

        <!-- Mobile Role Switch -->
        <div class="text-center my-2 d-md-none">
            @include('partials.role-switcher')
        </div>
        
        <div class="shell-sidebar-brand text-center my-3 d-none d-md-block">
            <img id="logoSidebar" class="shell-logo-wordmark" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="48" width="172" style="width:auto;max-width:100%;object-fit:contain;background:transparent" alt="SEOLinkBuildings">
            <img class="shell-logo-mark" src="{{ asset('assets/brand/web/favicon.svg') }}" height="36" width="36" alt="" aria-hidden="true">
        </div>

        <a href="{{ route('advertiser.dashboard') }}" class="{{ request()->routeIs('advertiser.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt" aria-hidden="true"></i> <span class="nav-label">Dashboard</span>
        </a>

        <!-- Catalog -->
        <a href="{{ route('advertiser.catalog') }}" class="{{ request()->routeIs('advertiser.catalog') ? 'active' : '' }}">
            <i class="fa fa-list" aria-hidden="true"></i>
            <span class="nav-label">Catalog</span>
        </a>

        <a href="{{ route('advertiser.content-library') }}" class="{{ request()->routeIs('advertiser.content-library*') ? 'active' : '' }}">
            <i class="fa fa-file-word" aria-hidden="true"></i>
            <span class="nav-label">Content Library</span>
        </a>

        <!-- Orders -->
        <a href="{{ route('advertiser.orders') }}" class="{{ request()->routeIs('advertiser.orders') ? 'active' : '' }}">
            <i class="fa fa-shopping-cart" aria-hidden="true"></i>
            <span class="nav-label d-flex align-items-center w-100">
                <span>Orders</span>
                <span id="navNeedsActionBadge" class="badge nav-alert-badge rounded-pill ms-auto" style="display:none;">0</span>
            </span>
        </a>

        @php
            $navUpcomingScheduled = 0;
            try {
                $navUpcomingScheduled = app(\App\Services\ContentUpload\ScheduledOrderService::class)
                    ->upcomingCount((int) auth()->id());
            } catch (\Throwable) {
                $navUpcomingScheduled = 0;
            }
        @endphp
        <a href="{{ route('advertiser.scheduled-orders', ['tab' => 'upcoming']) }}" class="{{ request()->routeIs('advertiser.scheduled-orders*') ? 'active' : '' }}">
            <i class="fa fa-calendar-alt" aria-hidden="true"></i>
            <span class="nav-label d-flex align-items-center w-100">
                <span>Scheduled</span>
                @if($navUpcomingScheduled > 0)
                    <span class="badge nav-alert-badge rounded-pill ms-auto" title="Upcoming scheduled orders">{{ $navUpcomingScheduled > 99 ? '99+' : $navUpcomingScheduled }}</span>
                @endif
            </span>
        </a>

        <a href="{{ route('advertiser.saved-sites') }}" class="{{ request()->routeIs('advertiser.saved-sites*') ? 'active' : '' }}">
            <i class="fa-solid fa-heart nav-icon-heart" aria-hidden="true"></i>
            <span class="nav-label">Saved Sites</span>
        </a>

        <a href="{{ route('advertiser.projects.index') }}" class="{{ request()->routeIs('advertiser.projects*') ? 'active' : '' }}">
            <i class="fa fa-folder-open" aria-hidden="true"></i>
            <span class="nav-label">Projects</span>
        </a>

        <a href="{{ route('site-claims.index') }}" class="{{ request()->routeIs('site-claims.*') ? 'active' : '' }}">
            <i class="fa fa-user-check" aria-hidden="true"></i>
            <span class="nav-label">My Claims</span>
        </a>

        <!-- Add Funds -->
        <a href="{{ route('advertiser.add-funds') }}" class="{{ request()->routeIs('advertiser.add-funds*') || request()->routeIs('advertiser.balance*') ? 'active' : '' }}">
            <i class="fa fa-coins" aria-hidden="true"></i> <span class="nav-label">Add Funds</span>
        </a>

        <a href="{{ route('advertiser.billing.index') }}" class="{{ request()->routeIs('advertiser.billing*') ? 'active' : '' }}">
            <i class="fa fa-file-invoice" aria-hidden="true"></i>
            <span class="nav-label">Billing &amp; Invoices</span>
        </a>
        
        <!-- Spending History -->
        <a href="{{ route('advertiser.analytics') }}" class="{{ request()->routeIs('advertiser.analytics*') ? 'active' : '' }}">
            <i class="fa fa-chart-area" aria-hidden="true"></i> <span class="nav-label">Spending</span>
        </a>

        <!-- Reports -->
        <a href="{{ route('advertiser.reports') }}" class="{{ request()->routeIs('advertiser.reports') ? 'active' : '' }}">
            <i class="fa fa-chart-line" aria-hidden="true"></i> <span class="nav-label">Reports</span>
        </a>
        @include('components.ad-banners', ['placement' => 'sidebar', 'audience' => 'advertiser'])
    </div>
</nav>

<!-- Navbar -->
<div class="top-navbar">

    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary" type="button" aria-label="Toggle sidebar navigation" title="Toggle sidebar" aria-controls="sidebar" aria-expanded="true">
            <span class="arrow" aria-hidden="true"><i class="fa fa-chevron-left"></i></span>
        </button>

        <!-- Navbar logo - will be hidden on mobile via CSS -->
        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="44" width="158" style="width:auto;max-width:min(220px,42vw);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
        </a>

        <div class="d-none d-md-block">
            @include('partials.role-switcher')
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">

        <!-- Cart — count + estimated total while browsing -->
        @php
            $headerCart = is_array($headerCart ?? null) ? $headerCart : session('cart', []);
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
        <a href="{{ route('advertiser.add-funds') }}" class="balance-block text-decoration-none" data-glass-tip data-glass-tip-body="{{ $headerBalanceTitle }}" data-glass-tip-placement="bottom" aria-label="Spendable balance {{ number_format($spendableBalance, 2) }} euros">
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
                @include('partials.user-avatar', ['user' => $user, 'size' => 36])
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
                <li>
                    <a class="dropdown-item" href="{{ route('profile.notifications') }}">
                        <i class="fa fa-bell" aria-hidden="true"></i> Email preferences
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('advertiser.billing.index') }}">
                        <i class="fa fa-file-invoice" aria-hidden="true"></i> Billing &amp; invoices
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('advertiser.add-funds') }}">
                        <i class="fa fa-coins" aria-hidden="true"></i> Add funds
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
<div id="cartSidebar" class="cart-sidebar" role="dialog" aria-modal="true" aria-labelledby="cartTitle" aria-hidden="true">
    <div class="cart-header">
        <div>
            <h5 id="cartTitle" class="mb-0">Your Cart</h5>
            <div id="cartHeaderMeta" class="small text-muted mt-1">Pay with wallet, card, or PayPal at checkout. Sites without an article stay in the cart.</div>
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
        <div id="cartTotals" class="cart-totals d-none">
            <div class="cart-totals__pay">
                <span id="cartTotalLabel">Pay now</span>
                <strong id="cartTotalAmount">€0.00</strong>
            </div>
            <div id="cartHeldNote" class="cart-totals__held d-none"></div>
        </div>
        <div id="cartScheduleHint" class="cart-schedule-hint d-none" hidden></div>
        <button id="checkoutFromCart" class="btn btn-primary w-100 d-none" type="button" disabled>
            <i class="fa fa-credit-card"></i> Proceed to Checkout
        </button>
        <div id="cartProceedHint" class="small text-muted mt-2 d-none">
            Assign an article to at least one website to checkout. Sites without articles stay in your cart.
        </div>
        <details class="cart-after-pay">
            <summary>What happens after you pay</summary>
            @include('partials.buy-confidence')
        </details>
        <button id="keepBrowsingCatalog" class="cart-keep-browsing" type="button">
            Keep browsing publishers
        </button>
    </div>
</div>

<main id="main-content" tabindex="-1">
    @include('components.site-announcements', ['audience' => 'advertiser'])
    @include('components.ad-banners', ['placement' => 'dashboard', 'audience' => 'advertiser'])
    @include('components.ad-banners', ['placement' => 'content_top', 'audience' => 'advertiser'])
    @include('partials.session-flash')
    @yield('content')
    @include('components.ad-banners', ['placement' => 'content_bottom', 'audience' => 'advertiser'])
</main>

<footer>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 w-100 px-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span>© {{ date('Y') }} SEOLinkBuildings</span>
            <span class="mx-1">·</span>
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="document.getElementById('helpFeedbackToggle')?.click()">Report a problem</button>
            <span class="mx-1">·</span>
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="document.getElementById('helpFeedbackToggle')?.click()">Suggestion box</button>
            @include('partials.trustpilot-trust', ['compact' => true])
        </div>
        @include('partials.payment-trust', ['compact' => true, 'showMethods' => true])
    </div>
</footer>
@include('components.help-feedback-widget')

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/js/modal-stack.js') }}?v={{ @filemtime(public_path('assets/js/modal-stack.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
@include('partials.app-toast')

<script>
    // Sidebar Toggle
    const toggleBtn = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('main-content');
    const topNavbar = document.querySelector('.top-navbar');
    const footerEl = document.querySelector('footer');

    function syncSidebarExpanded() {
        if (!toggleBtn || !sidebar) return;
        const desktopCollapsed = window.innerWidth > 768 && sidebar.classList.contains('collapsed');
        const mobileClosed = window.innerWidth <= 768 && !sidebar.classList.contains('show');
        toggleBtn.setAttribute('aria-expanded', (desktopCollapsed || mobileClosed) ? 'false' : 'true');
    }

    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
        content.classList.add('collapsed');
        topNavbar.classList.add('collapsed');
        footerEl.classList.add('collapsed');
        toggleBtn.classList.add('collapsed');
    }
    syncSidebarExpanded();

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
        syncSidebarExpanded();
    });
    window.addEventListener('resize', syncSidebarExpanded);

    // Dark mode removed — ensure light theme
    document.body.classList.remove('layout-dark');
    try { localStorage.removeItem('layoutDarkMode'); } catch (e) {}

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
            // Static count only — no red pulse / beep on the Orders number.
            if (navBadge) {
                if (total > 0) {
                    navBadge.style.display = 'inline-block';
                    navBadge.innerText = total > 99 ? '99+' : String(total);
                    navBadge.classList.remove('pulse-badge', 'is-pulsing', 'is-alerting');
                } else {
                    navBadge.style.display = 'none';
                    navBadge.classList.remove('pulse-badge', 'is-pulsing', 'is-alerting');
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
    
    // Generate unique key for cart item (site + sensitive + homepage days)
    function getCartItemKey(item) {
        const homepage = (item.homepage_days == null || item.homepage_days === '')
            ? 'none'
            : String(item.homepage_days);
        return `${item.id}_${item.sensitive_type || 'standard'}_${homepage}`;
    }

    function cartHomepageParam(item) {
        if (item.homepage_days == null || item.homepage_days === '') {
            return 'none';
        }
        return String(item.homepage_days);
    }
    
    let approvedArticles = [];
    let requireSameLanguage = false;
    let cartSchedule = null;
    let contentLibraryUploadUrl = @json(route('advertiser.content-library', ['upload' => 1]));
    let catalogUrl = @json(route('advertiser.catalog'));

    function applyCartPayload(data) {
        if (Array.isArray(data)) {
            cart = data;
            return;
        }
        cart = Array.isArray(data?.cart) ? data.cart : [];
        approvedArticles = Array.isArray(data?.approved_articles) ? data.approved_articles : [];
        requireSameLanguage = !!data?.require_same_language;
        cartSchedule = data?.schedule && data.schedule.mode === 'scheduled' ? data.schedule : null;
        if (data?.content_library_url) {
            contentLibraryUploadUrl = data.content_library_url;
        }
        toastRemovedCartNames(
            Array.isArray(data?.removed_inactive) ? data.removed_inactive : [],
            Array.isArray(data?.removed_owned) ? data.removed_owned : []
        );
    }

    function toastRemovedCartNames(removed, removedOwned) {
        if (removed.length === 1) {
            showToast(removed[0] + ' was deactivated and removed from your cart.', 'warning');
        } else if (removed.length > 1) {
            const preview = removed.slice(0, 2).join(', ');
            const more = removed.length > 2 ? ' (+' + (removed.length - 2) + ' more)' : '';
            showToast(removed.length + ' sites were deactivated and removed from your cart: ' + preview + more + '.', 'warning');
        }
        if (removedOwned.length === 1) {
            showToast(removedOwned[0] + ' is your listing and was removed from your cart.', 'warning');
        } else if (removedOwned.length > 1) {
            const preview = removedOwned.slice(0, 2).join(', ');
            const more = removedOwned.length > 2 ? ' (+' + (removedOwned.length - 2) + ' more)' : '';
            showToast(removedOwned.length + ' of your listings were removed from your cart: ' + preview + more + '.', 'warning');
        }
    }

    function siteLanguageCodes(item) {
        const codes = [];
        const primary = String(item?.language || '').toLowerCase().trim();
        if (primary) codes.push(primary);
        if (Array.isArray(item?.languages)) {
            item.languages.forEach((c) => {
                const v = String(c || '').toLowerCase().trim();
                if (v && !codes.includes(v)) codes.push(v);
            });
        }
        return codes;
    }

    function articleFitsSiteLanguages(article, siteLangs) {
        const articleLang = String(article?.language || '').toLowerCase().trim();
        if (!articleLang || !siteLangs.length) return true;
        return siteLangs.includes(articleLang);
    }

    function lineContentIds(item) {
        const qty = Math.max(1, parseInt(item.quantity, 10) || 1);
        const raw = Array.isArray(item.content_submission_ids) ? item.content_submission_ids : [];
        const ids = [];
        for (let i = 0; i < qty; i++) {
            ids[i] = parseInt(raw[i] || 0, 10) || 0;
        }
        if (!ids[0] && item.content_submission_id) {
            ids[0] = parseInt(item.content_submission_id, 10) || 0;
        }
        return ids;
    }

    function lineFullyAssigned(item) {
        return lineContentIds(item).every((id) => id > 0);
    }

    function usedSubmissionIds(exceptKey, exceptCopyIndex) {
        const used = new Set();
        cart.forEach((row) => {
            const key = getCartItemKey(row);
            lineContentIds(row).forEach((id, copyIndex) => {
                if (!id) return;
                if (key === exceptKey && copyIndex === exceptCopyIndex) return;
                used.add(id);
            });
        });
        return used;
    }

    function articlesForCartPlacement(item, copyIndex) {
        const selectedId = lineContentIds(item)[copyIndex] || 0;
        const usedElsewhere = usedSubmissionIds(getCartItemKey(item), copyIndex);
        const siteLangs = siteLanguageCodes(item);
        const options = approvedArticles.filter((article) => {
            if (usedElsewhere.has(article.id) && article.id !== selectedId) return false;
            if (requireSameLanguage && !articleFitsSiteLanguages(article, siteLangs)) return false;
            return true;
        });
        // Soft prefer: same-language articles first, then others.
        options.sort((a, b) => {
            const aFit = articleFitsSiteLanguages(a, siteLangs) ? 0 : 1;
            const bFit = articleFitsSiteLanguages(b, siteLangs) ? 0 : 1;
            if (aFit !== bFit) return aFit - bFit;
            return String(a.title || '').localeCompare(String(b.title || ''));
        });
        return options;
    }

    function cartLinesMissingArticles() {
        return cart.filter((item) => !lineFullyAssigned(item));
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
    
    // Save cart to session (server clamps bulk packs to 3–5 and reprices).
    function saveCart() {
        $.ajax({
            url: '{{ route("advertiser.cart.save") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            contentType: 'application/json',
            data: JSON.stringify({ cart: cart }),
            success: function(data) {
                if (data && (Array.isArray(data.cart) || Array.isArray(data))) {
                    applyCartPayload(data);
                    updateCartDisplay();
                    return;
                }
                loadCart();
            },
            error: function() {
                console.error('Failed to save cart');
                loadCart();
            }
        });
    }

    function assignCartArticle(siteId, sensitiveType, submissionId, copyIndex, homepageDays) {
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
                homepage_days: (homepageDays === undefined || homepageDays === null || homepageDays === '')
                    ? 'none'
                    : homepageDays,
                content_submission_id: submissionId || '',
                copy_index: copyIndex || 0
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
        if (badge) {
            if (cartCount > 0) {
                badge.style.display = 'flex';
                badge.innerText = cartCount;
            } else {
                badge.style.display = 'none';
            }
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
        const headerMeta = document.getElementById('cartHeaderMeta');
        const totalLabel = document.getElementById('cartTotalLabel');
        const heldNote = document.getElementById('cartHeldNote');
        const totalsEl = document.getElementById('cartTotals');
        const scheduleHint = document.getElementById('cartScheduleHint');
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
                proceedBtn.classList.add('d-none');
            }
            if (proceedHint) {
                proceedHint.classList.add('d-none');
            }
            if (headerMeta) {
                headerMeta.textContent = 'Pay sites that have an article. Others stay in the cart.';
            }
            if (totalLabel) {
                totalLabel.textContent = 'Pay now';
            }
            if (heldNote) {
                heldNote.classList.add('d-none');
                heldNote.textContent = '';
            }
            if (totalsEl) {
                totalsEl.classList.add('d-none');
            }
            if (scheduleHint) {
                scheduleHint.classList.add('d-none');
                scheduleHint.hidden = true;
                scheduleHint.textContent = '';
            }
        } else {
            let html = '';
            const sortedCart = [...cart].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
            const missingLines = cartLinesMissingArticles();
            const missing = missingLines.length;
            const readyCount = Math.max(0, cart.length - missing);
            const readyTotal = cart
                .filter((item) => lineFullyAssigned(item))
                .reduce((sum, item) => sum + ((parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 0)), 0);
            const missingSlots = cart.reduce((n, item) => (
                n + lineContentIds(item).filter((id) => !id).length
            ), 0);
            const heldTotal = Math.max(0, cartTotal - readyTotal);
            if (totalsEl) {
                totalsEl.classList.remove('d-none');
            }
            if (headerMeta) {
                headerMeta.textContent = cart.length + ' site' + (cart.length === 1 ? '' : 's')
                    + ' · ' + readyCount + ' ready to pay';
            }
            if (checklistEl) {
                if (missing === 0) {
                    checklistEl.classList.add('d-none');
                    checklistEl.innerHTML = '';
                } else {
                    const status = readyCount === 0
                        ? (missingSlots === 1 ? '1 article still needed' : missingSlots + ' articles still needed')
                        : (readyCount + ' ready · ' + missingSlots + ' article' + (missingSlots === 1 ? '' : 's') + ' still needed');
                    checklistEl.innerHTML = '<div class="cart-checklist__status">' + escapeHtml(status) + '</div>';
                    checklistEl.classList.remove('d-none');
                }
            }
            if (readyNote) {
                if (readyCount > 0 && missing === 0) {
                    readyNote.classList.remove('d-none');
                    readyNote.textContent = 'Articles attached — proceed to pay, or keep browsing.';
                } else {
                    readyNote.classList.add('d-none');
                    readyNote.textContent = '';
                }
            }
            if (proceedBtn) {
                // Checkout only for sites that are ready and need payment.
                proceedBtn.classList.remove('d-none');
                proceedBtn.disabled = readyCount === 0;
                if (readyCount > 0 && missing > 0) {
                    proceedBtn.innerHTML = '<i class="fa fa-credit-card"></i> Checkout ' + readyCount + ' ready site' + (readyCount === 1 ? '' : 's');
                } else {
                    proceedBtn.innerHTML = '<i class="fa fa-credit-card"></i> Proceed to Checkout';
                }
            }
            if (proceedHint) {
                proceedHint.classList.toggle('d-none', readyCount > 0);
            }
            if (totalLabel) {
                totalLabel.textContent = readyCount > 0 && missing === 0 ? 'Total' : 'Pay now';
            }
            if (scheduleHint) {
                if (cartSchedule && cartSchedule.label) {
                    const href = cartSchedule.checkout_url || @json(route('advertiser.checkout'));
                    scheduleHint.innerHTML = escapeHtml(cartSchedule.label).replace(
                        'change at checkout',
                        '<a href="' + href + '">change at checkout</a>'
                    );
                    scheduleHint.classList.remove('d-none');
                    scheduleHint.hidden = false;
                } else {
                    scheduleHint.classList.add('d-none');
                    scheduleHint.hidden = true;
                    scheduleHint.textContent = '';
                }
            }
            if (heldNote) {
                if (readyCount === 0) {
                    heldNote.classList.remove('d-none');
                    heldNote.textContent = 'In cart €' + cartTotal.toFixed(2);
                } else if (missing > 0) {
                    heldNote.classList.remove('d-none');
                    heldNote.textContent = missing + ' site' + (missing === 1 ? '' : 's')
                        + ' stay' + (missing === 1 ? 's' : '') + ' in cart (€' + heldTotal.toFixed(2) + ')';
                } else {
                    heldNote.classList.add('d-none');
                    heldNote.textContent = '';
                }
            }
            
            sortedCart.forEach((item) => {
                const itemKey = getCartItemKey(item);
                const itemKeyAttr = escapeHtml(itemKey);
                const sensitiveAttr = escapeHtml(item.sensitive_type || '');
                const siteName = item.name || 'Website';
                const sensitiveDisplay = item.sensitive_type ? 
                    `<div class="cart-item-sensitive"><small>+ ${escapeHtml(item.sensitive_type)} (€${(parseFloat(item.additional_price) || 0).toFixed(2)})</small></div>` : '';
                const homepageDays = item.homepage_days != null && item.homepage_days !== '' ? parseInt(item.homepage_days, 10) : null;
                const homepageFee = parseFloat(item.homepage_price) || 0;
                const homepageDisplay = homepageDays
                    ? `<div class="cart-item-homepage"><small>Homepage ${homepageDays} day${homepageDays === 1 ? '' : 's'}${homepageFee > 0 ? ' (+€' + homepageFee.toFixed(2) + ')' : ' (Free)'}</small></div>`
                    : '';
                const socialList = Array.isArray(item.social_channels) ? item.social_channels : [];
                const socialDisplay = socialList.length
                    ? `<div class="cart-item-social"><small>Social: ${escapeHtml(socialList.map((c) => c === 'x' ? 'X' : (c.charAt(0).toUpperCase() + c.slice(1))).join(', '))}</small></div>`
                    : '';
                const placementIds = lineContentIds(item);
                const qty = Math.max(1, parseInt(item.quantity, 10) || 1);
                let articleBlock = '';
                if (approvedArticles.length === 0 && placementIds.every((id) => !id)) {
                    articleBlock = `
                        <div class="cart-item-article needs-document">
                            <div class="cart-item-article-empty">
                                No approved article.
                                <a class="cart-item-upload-link cart-item-upload-link--primary" href="${contentLibraryUploadUrl}">Upload article</a>
                            </div>
                        </div>`;
                } else {
                    articleBlock = placementIds.map((selectedId, copyIndex) => {
                        const options = articlesForCartPlacement(item, copyIndex);
                        const slotLabel = placementIds.length > 1
                            ? `Article ${copyIndex + 1} of ${placementIds.length}`
                            : (selectedId ? 'Attached' : 'Add article');
                        const selectId = 'cart-doc-' + itemKey.replace(/[^a-zA-Z0-9_-]/g, '-') + '-' + copyIndex;
                        let opts = `<option value="">— Choose ${placementIds.length > 1 ? 'article ' + (copyIndex + 1) + ' of ' + placementIds.length : 'article'} —</option>`;
                        options.forEach((article) => {
                            const fits = articleFitsSiteLanguages(article, siteLanguageCodes(item));
                            const label = (article.title || 'Document')
                                + ' (' + String(article.language || '').toUpperCase()
                                + (article.country ? '/' + String(article.country).toUpperCase() : '')
                                + ')'
                                + (fits ? '' : ' · different language');
                            opts += `<option value="${article.id}" ${article.id === selectedId ? 'selected' : ''}>${escapeHtml(label)}</option>`;
                        });
                        if (selectedId && !options.some((a) => a.id === selectedId)) {
                            opts += `<option value="${selectedId}" selected>Assigned document #${selectedId}</option>`;
                        }
                        const emptyHint = options.length === 0 && !selectedId
                            ? `<div class="cart-item-article-empty mt-1">Need another article? <a class="cart-item-upload-link cart-item-upload-link--primary" href="${contentLibraryUploadUrl}">Upload article</a></div>`
                            : '';
                        const langNote = item.language_note
                            ? `<div class="cart-item-language-note" title="Preferred match is the same language as the site">${escapeHtml(item.language_note)}</div>`
                            : '';
                        const uploadLink = `<a class="cart-item-upload-link" href="${contentLibraryUploadUrl}">Upload new</a>`;
                        return `
                        <div class="cart-item-article ${selectedId ? 'is-assigned' : 'needs-document'}">
                            <div class="cart-item-order-label">
                                <span class="cart-item-order-kicker">${escapeHtml(slotLabel)}</span>
                            </div>
                            <label class="visually-hidden" for="${selectId}">Article for ${escapeHtml(siteName)}</label>
                            <select id="${selectId}"
                                    class="cart-article-select"
                                    data-id="${item.id}"
                                    data-sensitive-type="${sensitiveAttr}"
                                    data-homepage-days="${cartHomepageParam(item)}"
                                    data-copy-index="${copyIndex}"
                                    data-prev-value="${selectedId || ''}">
                                ${opts}
                            </select>
                            ${langNote}
                            <div class="cart-item-article-actions">
                                ${uploadLink}
                            </div>
                            ${emptyHint}
                        </div>`;
                    }).join('');
                }
                const qtyNote = qty > 1
                    ? `<div class="cart-item-qty-note">${qty} placements · ${qty} articles</div>`
                    : '';
                
                html += `
                    <div class="cart-item" data-key="${itemKeyAttr}">
                        <div class="cart-item-top">
                            <div class="cart-item-info">
                                <div class="cart-item-name">${escapeHtml(siteName)}</div>
                                ${sensitiveDisplay}
                                ${homepageDisplay}
                                ${socialDisplay}
                                <div class="cart-item-price">€${(parseFloat(item.price) || 0).toFixed(2)} each</div>
                                ${qtyNote}
                            </div>
                            <div class="cart-item-quantity">
                                <button type="button" class="decrease-qty" data-id="${item.id}" data-sensitive-type="${sensitiveAttr}" data-homepage-days="${cartHomepageParam(item)}" aria-label="Decrease placements" title="Placements — each needs its own article">
                                    <i class="fa fa-minus" aria-hidden="true"></i>
                                </button>
                                <span class="quantity-number" aria-label="Placements ${item.quantity}">${item.quantity}</span>
                                <button type="button" class="increase-qty" data-id="${item.id}" data-sensitive-type="${sensitiveAttr}" data-homepage-days="${cartHomepageParam(item)}" aria-label="Increase placements — each needs its own article" title="Placements — each needs its own article">
                                    <i class="fa fa-plus" aria-hidden="true"></i>
                                </button>
                            </div>
                            <button type="button" class="cart-item-remove" data-id="${item.id}" data-sensitive-type="${sensitiveAttr}" data-homepage-days="${cartHomepageParam(item)}" aria-label="Remove ${escapeHtml(siteName)} from cart">
                                <i class="fa fa-times" aria-hidden="true"></i>
                            </button>
                        </div>
                        ${articleBlock}
                    </div>
                `;
            });
            container.innerHTML = html;
        }
        
        const payEl = document.getElementById('cartTotalAmount');
        if (payEl) {
            const payNow = cart.length === 0 ? 0 : cart
                .filter((item) => lineFullyAssigned(item))
                .reduce((sum, item) => sum + ((parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 0)), 0);
            payEl.innerHTML = `€${payNow.toFixed(2)}`;
        }
    }
    
    // Escape HTML
    function escapeHtml(str) {
        if (str == null || str === '') return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    // Add to cart via server so Content Library article rules apply.
    // Use fetch (not jQuery) so Buy still works if $ fails to load.
    // options: { quantity, bulk, openCart } — deal cards start a 3-article pack;
    // cart qty can then move within 3–5 with one document slot per placement.
    window.catalogOwnListingMessage = @json(\App\Models\Site::cannotOrderOwnListingMessage());
    window.catalogViewerUserId = {{ (int) auth()->id() }};
    window.catalogOwnSiteIds = @json(\App\Models\Site::ownedIdsFor(auth()->user()));
    window.catalogIsOwnListing = function (siteId) {
        const id = parseInt(siteId, 10);
        if (!Number.isFinite(id) || id <= 0) return false;
        const ownedIds = Array.isArray(window.catalogOwnSiteIds) ? window.catalogOwnSiteIds : [];
        if (ownedIds.some(function (ownId) { return parseInt(ownId, 10) === id; })) {
            return true;
        }
        const nodes = document.querySelectorAll('[data-id="' + id + '"]');
        for (let i = 0; i < nodes.length; i++) {
            const el = nodes[i];
            if (el.getAttribute('data-own-listing') === '1') {
                return true;
            }
            const me = parseInt(window.catalogViewerUserId, 10);
            if (!me) continue;
            const pub = parseInt(el.getAttribute('data-publisher-id') || '', 10);
            const owner = parseInt(el.getAttribute('data-owner-id') || '', 10);
            if (pub === me || owner === me) {
                return true;
            }
        }
        return false;
    };
    window.addToCart = function(id, name, price, sensitiveType = null, additionalPrice = 0, basePrice = null, options = null) {
        if (typeof window.catalogIsOwnListing === 'function' && window.catalogIsOwnListing(id)) {
            const msg = window.catalogOwnListingMessage || 'This is your listing — you can’t order it.';
            showToast(msg, 'error');
            return Promise.resolve({ ok: false, error: msg });
        }
        const opts = options && typeof options === 'object' ? options : {};
        const body = new URLSearchParams();
        body.set('id', String(id));
        body.set('sensitive_type', sensitiveType || '');
        if (Object.prototype.hasOwnProperty.call(opts, 'homepage_days')) {
            body.set('homepage_days', opts.homepage_days == null || opts.homepage_days === ''
                ? 'none'
                : String(opts.homepage_days));
        }
        if (opts.bulk) {
            body.set('bulk', '1');
        }
        const qty = parseInt(opts.quantity, 10);
        if (Number.isFinite(qty) && qty > 0) {
            body.set('quantity', String(qty));
        }

        return fetch(@json(route('advertiser.cart.add')), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': @json(csrf_token()),
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: body.toString()
        }).then(async function (res) {
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok || !data.success) {
                const msg = data.error || data.message || 'Could not add to cart.';
                showToast(msg, 'error');
                return { ok: false, error: msg };
            }
            applyCartPayload(data);
            updateCartDisplay();
            const label = sensitiveType ? (name + ' + ' + sensitiveType) : name;
            showToast(data.message || (label + ' added to cart.'), 'success');
            updateCartDisplay();
            if (opts.openCart || opts.bulk || (Number.isFinite(qty) && qty > 1)) {
                try { openCart(); } catch (_) { /* cart chrome may not be ready */ }
            }
            return { ok: true, data: data };
        }).catch(function () {
            showToast('Could not add to cart.', 'error');
            return { ok: false, error: 'network' };
        });
    };
    
    // Cart Sidebar Toggle
    const cartSidebar = document.getElementById('cartSidebar');
    const cartOverlay = document.getElementById('cartOverlay');
    const toggleCartBtn = document.getElementById('toggleCart');
    const closeCartBtn = document.getElementById('closeCart');
    let cartLastFocus = null;

    function getCartFocusable() {
        if (!cartSidebar) return [];
        return Array.from(cartSidebar.querySelectorAll(
            'a[href], button:not([disabled]), textarea, input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(el => el.offsetParent !== null || el === document.activeElement);
    }

    function openCart() {
        cartLastFocus = document.activeElement;
        cartSidebar.classList.add('open');
        cartOverlay.classList.add('show');
        document.body.classList.add('cart-open');
        cartSidebar.setAttribute('aria-hidden', 'false');
        updateCartDisplay();
        (closeCartBtn || getCartFocusable()[0])?.focus();
    }
    window.openCart = openCart;

    function closeCart() {
        cartSidebar.classList.remove('open');
        cartOverlay.classList.remove('show');
        document.body.classList.remove('cart-open');
        cartSidebar.setAttribute('aria-hidden', 'true');
        const restore = cartLastFocus && document.contains(cartLastFocus)
            ? cartLastFocus
            : toggleCartBtn;
        restore?.focus();
        cartLastFocus = null;
    }

    toggleCartBtn.addEventListener('click', openCart);
    closeCartBtn.addEventListener('click', closeCart);
    cartOverlay.addEventListener('click', closeCart);

    document.addEventListener('keydown', function (e) {
        if (!cartSidebar.classList.contains('open')) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            closeCart();
            return;
        }
        if (e.key !== 'Tab') return;
        const focusable = getCartFocusable();
        if (focusable.length === 0) {
            e.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

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
        const homepageDays = Object.prototype.hasOwnProperty.call(btn.dataset, 'homepageDays')
            ? btn.dataset.homepageDays
            : null;
        
        // Find the exact item (including sensitive type + homepage)
        const itemIndex = cart.findIndex(item => {
            if (item.id !== id || (item.sensitive_type || null) !== sensitiveType) {
                return false;
            }
            if (homepageDays === null) {
                return true;
            }
            return cartHomepageParam(item) === String(homepageDays);
        });
        
        if (itemIndex === -1) return;
        
        const item = cart[itemIndex];
        const minBulk = parseInt(item.bulk_min_qty, 10) || 3;
        const maxBulk = parseInt(item.bulk_max_qty, 10) || 5;
        const isBulkPack = !!item.bulk_pack || (!!item.bulk_eligible && (parseInt(item.quantity, 10) || 0) >= minBulk);

        if (btn.classList.contains('decrease-qty')) {
            const qty = parseInt(item.quantity, 10) || 1;
            if (isBulkPack && qty <= minBulk) {
                // Bulk packs stay in the 3–5 discount band; remove via × instead.
                showToast('Bulk packs stay at ' + minBulk + '–' + maxBulk + ' articles. Remove the site to clear the pack.', 'warning');
                return;
            }
            if (qty > 1) {
                cart[itemIndex].quantity = qty - 1;
            } else {
                cart.splice(itemIndex, 1);
            }
        } else if (btn.classList.contains('increase-qty')) {
            const qty = parseInt(item.quantity, 10) || 1;
            if (qty >= maxBulk) {
                showToast('Maximum ' + maxBulk + ' article placements per site.', 'warning');
                return;
            }
            cart[itemIndex].quantity = qty + 1;
            if (item.bulk_eligible && cart[itemIndex].quantity >= minBulk) {
                cart[itemIndex].bulk_pack = true;
            }
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
        const homepageDays = Object.prototype.hasOwnProperty.call(select.dataset, 'homepageDays')
            ? select.dataset.homepageDays
            : 'none';
        const copyIndex = parseInt(select.dataset.copyIndex || '0', 10) || 0;
        const submissionId = select.value ? parseInt(select.value, 10) : 0;
        const previous = select.dataset.prevValue || '';

        if (!submissionId) {
            select.dataset.prevValue = '';
            assignCartArticle(id, sensitiveType, 0, copyIndex, homepageDays);
            return;
        }

        const item = cart.find((row) =>
            row.id === id
            && (row.sensitive_type || null) === sensitiveType
            && cartHomepageParam(row) === String(homepageDays)
        );
        const article = approvedArticles.find((row) => row.id === submissionId);
        const siteLangs = siteLanguageCodes(item);
        const articleLang = String(article?.language || '').toLowerCase();
        const fits = articleFitsSiteLanguages(article, siteLangs);
        const mismatch = (!fits && articleLang)
            ? ('Site is ' + siteLangs.map((c) => c.toUpperCase()).join('/') + ', article is ' + articleLang.toUpperCase()
                + (requireSameLanguage ? ' — same language is required.' : ' — continue?'))
            : '';

        const proceed = function () {
            select.dataset.prevValue = select.value || '';
            assignCartArticle(id, sensitiveType, submissionId, copyIndex, homepageDays);
        };

        if (!mismatch) {
            proceed();
            return;
        }

        if (requireSameLanguage) {
            showToast(mismatch, 'error');
            select.value = previous;
            return;
        }

        // slbConfirm is loaded in every layout and owns the SweetAlert / native
        // fallback, so there is no branch to duplicate here.
        window.slbConfirm({
            title: 'Language differs',
            text: mismatch,
            confirmText: 'Continue',
            cancelText: 'Choose another',
            icon: 'warning',
        }).then(function (ok) {
            if (ok) {
                proceed();
            } else {
                select.value = previous;
            }
        });
    });
    
    // Checkout from cart — pay only ready sites; incomplete lines stay in the cart
    document.getElementById('checkoutFromCart').addEventListener('click', function() {
        if (cart.length === 0) {
            showToast('Your cart is empty!', 'error');
            return;
        }
        const missing = cartLinesMissingArticles();
        const readyCount = Math.max(0, cart.length - missing.length);
        if (readyCount === 0) {
            openCart();
            showToast('Assign an approved article to at least one website before checkout.', 'error');
            return;
        }
        const wizardPay = @json(route('advertiser.wizard.pay'));
        const plainCheckout = @json(route('advertiser.checkout'));
        const inWizard = {{ request()->boolean('wizard') || !empty(\App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language'] ?? null) ? 'true' : 'false' }};
        window.location.href = inWizard ? wizardPay : plainCheckout;
    });
    
    // Load cart on page load
    loadCart();
    // Catalog shows its own banner; other pages toast names already dropped during render.
    const onCatalogPage = {{ request()->routeIs('advertiser.catalog') ? 'true' : 'false' }};
    if (!onCatalogPage) {
        toastRemovedCartNames(
            @json($ssrCartRemovedInactive ?? []),
            @json($ssrCartRemovedOwned ?? [])
        );
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('js/slb-confirm.js') }}?v={{ @filemtime(public_path('js/slb-confirm.js')) ?: '1' }}"></script>
<script src="{{ asset('js/slb-live-search.js') }}?v={{ @filemtime(public_path('js/slb-live-search.js')) ?: '1' }}"></script>
<script src="{{ asset('js/slb-http.js') }}?v={{ @filemtime(public_path('js/slb-http.js')) ?: '1' }}"></script>
<script>
</script>
<script src="{{ asset('js/role-switch.js') }}?v={{ @filemtime(public_path('js/role-switch.js')) ?: '1' }}"></script>
<script src="{{ asset('js/order-chat.js') }}?v={{ @filemtime(public_path('js/order-chat.js')) ?: '1' }}" defer></script>
<script src="{{ asset('js/notification-center.js') }}?v={{ @filemtime(public_path('js/notification-center.js')) ?: '8' }}" defer></script>
@stack('scripts')

</body>
</html>