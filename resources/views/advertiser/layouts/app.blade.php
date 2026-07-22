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

    <!-- Shell chrome lives in public/css/app-shell.css; cart drawer in cart.css -->
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
            Assign an approved article to at least one website to checkout. Sites without articles stay in your cart.
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
            const missingLines = cartLinesMissingArticles();
            const missing = missingLines.length;
            const readyCount = Math.max(0, cart.length - missing);
            const readyTotal = cart
                .filter((item) => !!parseInt(item.content_submission_id || 0, 10))
                .reduce((sum, item) => sum + ((parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 0)), 0);
            if (checklistEl) {
                let list = '<div class="small fw-semibold mb-1">Before Pay</div><ul class="mb-0 ps-0">';
                sortedCart.forEach((item) => {
                    const assigned = !!parseInt(item.content_submission_id || 0, 10);
                    const lang = String(item.language || '').toUpperCase();
                    const cls = assigned ? 'is-ok' : 'is-todo';
                    const mark = assigned ? '✓' : '!';
                    const detail = assigned
                        ? 'Ready — will be charged at checkout'
                        : ('Needs ' + (lang ? lang + ' ' : '') + 'article (stays in cart)');
                    list += `<li class="${cls}"><span class="mark" aria-hidden="true">${mark}</span><span><strong>${escapeHtml(item.name || 'Website')}</strong> — ${escapeHtml(detail)}</span></li>`;
                });
                list += '</ul>';
                checklistEl.innerHTML = list;
                checklistEl.classList.remove('d-none');
            }
            if (readyNote) {
                if (readyCount === 0) {
                    readyNote.classList.remove('d-none');
                    readyNote.innerHTML = missing === 1
                        ? 'Assign an approved article to this website before checkout. You can keep browsing and finish later.'
                        : ('Assign approved articles to at least one website before checkout. You can keep browsing and finish later.');
                } else if (missing > 0) {
                    readyNote.classList.remove('d-none');
                    readyNote.innerHTML = readyCount + ' ready to pay (€' + readyTotal.toFixed(2) + '). '
                        + missing + ' without articles stay in your cart.';
                } else {
                    readyNote.classList.remove('d-none');
                    readyNote.textContent = 'Checklist complete — proceed to pay, or keep browsing to add more sites.';
                }
            }
            if (proceedBtn) {
                // Checkout only for sites that are ready and need payment.
                proceedBtn.disabled = readyCount === 0;
                if (readyCount > 0 && missing > 0) {
                    proceedBtn.innerHTML = '<i class="fa fa-credit-card"></i> Checkout ' + readyCount + ' ready site' + (readyCount === 1 ? '' : 's');
                } else if (readyCount > 0) {
                    proceedBtn.innerHTML = '<i class="fa fa-credit-card"></i> Proceed to Checkout';
                } else {
                    proceedBtn.innerHTML = '<i class="fa fa-credit-card"></i> Proceed to Checkout';
                }
            }
            if (proceedHint) {
                proceedHint.classList.toggle('d-none', readyCount > 0);
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