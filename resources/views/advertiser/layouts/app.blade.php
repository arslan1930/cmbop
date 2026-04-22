<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Advertiser Dashboard</title>
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

        #toggleCart {
            width: 36px;
            height: 36px;
            border-radius: 40%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            position: relative;
        }

        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        #toggleDarkMode {
            width: 36px;
            height: 36px;
            border-radius: 40%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
        }

        #toggleNotifications {
            width: 36px;
            height: 36px;
            border-radius: 40%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
        }

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

        body.layout-dark .cart-sidebar {
            background-color: #1e1e2f;
            color: #ddd;
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

        .cart-item-price {
            font-size: 13px;
            color: #666;
        }

        body.layout-dark .cart-item-price {
            color: #aaa;
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

        body.layout-dark .cart-item-quantity button {
            background: #2d2d3f;
            border-color: #444;
            color: #ddd;
        }

        body.layout-dark .cart-item-quantity button:hover {
            background: #3d3d4f;
            border-color: #555;
        }

        .cart-item-remove {
            color: #dc3545;
            cursor: pointer;
            margin-left: 10px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .cart-item-remove:hover {
            background-color: #dc3545;
            color: white;
        }

        body.layout-dark .cart-item-remove:hover {
            background-color: #dc3545;
            color: white;
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

        <a href="{{ route('advertiser.dashboard') }}" class="{{ request()->routeIs('advertiser.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>

        <!-- Catalog -->
        <a href="{{ route('advertiser.catalog') }}" class="{{ request()->routeIs('advertiser.catalog') ? 'active' : '' }}">
            <i class="fa fa-list"></i> 
            <span>All Publishers</span>
        </a>

        <!-- Orders -->
        <a href="{{ route('advertiser.orders') }}" class="{{ request()->routeIs('advertiser.orders') ? 'active' : '' }}">
            <i class="fa fa-shopping-cart"></i> <span>Orders</span>
        </a>

        <!-- Add Funds -->
        <a href="{{ route('advertiser.add-funds') }}" class="{{ request()->routeIs('advertiser.add-funds') ? 'active' : '' }}">
            <i class="fa fa-coins"></i> <span>Add Funds</span>
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

    <div class="d-flex align-items-center gap-2">

        <!-- Notifications Icon -->
        <div class="position-relative">
            <button id="toggleNotifications" class="btn btn-outline-secondary btn-sm" title="Notifications">
                <i class="fa fa-message"></i>
            </button>
        </div>
        
        <!-- Cart Icon with Badge -->
        <div class="position-relative">
            <button id="toggleCart" class="btn btn-outline-secondary btn-sm" title="Cart">
                <i class="fa fa-shopping-cart"></i>
                <span id="cartBadge" class="cart-badge" style="display: none;">0</span>
            </button>
        </div>

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
                <li>
                    <a class="dropdown-item" href="{{ route('profile') }}">
                        <i class="fa fa-user"></i> Profile
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-item text-danger">Logout</button>
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
        <h5 class="mb-0">Your Cart</h5>
        <button id="closeCart" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-times"></i>
        </button>
    </div>
    <div class="cart-body" id="cartItemsContainer">
        <div class="text-center text-muted">Your cart is empty</div>
    </div>
    <div class="cart-footer">
        <div class="d-flex justify-content-between mb-3">
            <strong>Total:</strong>
            <strong id="cartTotalAmount">€0.00</strong>
        </div>
        <button id="checkoutFromCart" class="btn btn-success w-100">
            <i class="fa fa-credit-card"></i> Proceed to Checkout
        </button>
    </div>
</div>

<div id="content">
    @yield('content')
</div>

<footer>
    © 2026 SEOLinkBuildings
</footer>

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

    // Dark Mode
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

    // Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    tooltipTriggerList.map(function (el) {
        return new bootstrap.Tooltip(el)
    });

    // Cart Functionality
    let cart = [];
    
    // Load cart from session on page load
    function loadCart() {
        $.ajax({
            url: '{{ route("advertiser.cart.get") }}',
            method: 'GET',
            success: function(data) {
                cart = data;
                updateCartDisplay();
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
            data: JSON.stringify({ cart: cart })
        });
    }
    
    // Update cart display
    function updateCartDisplay() {
        const cartCount = cart.reduce((sum, item) => sum + item.quantity, 0);
        const cartTotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        
        const badge = document.getElementById('cartBadge');
        if (cartCount > 0) {
            badge.style.display = 'flex';
            badge.innerText = cartCount;
        } else {
            badge.style.display = 'none';
        }
        
        // Update cart sidebar
        const container = document.getElementById('cartItemsContainer');
        if (cart.length === 0) {
            container.innerHTML = '<div class="text-center text-muted">Your cart is empty</div>';
        } else {
            let html = '';
            cart.forEach((item, index) => {
                html += `
                    <div class="cart-item" data-index="${index}">
                        <div class="cart-item-info">
                            <div class="cart-item-name">${escapeHtml(item.name)}</div>
                            <div class="cart-item-price">€${item.price.toFixed(2)}</div>
                        </div>
                        <div class="cart-item-quantity">
                            <button class="decrease-qty" data-id="${item.id}">
                                <i class="fa fa-minus"></i>
                            </button>
                            <span class="quantity-number">${item.quantity}</span>
                            <button class="increase-qty" data-id="${item.id}">
                                <i class="fa fa-plus"></i>
                            </button>
                        </div>
                        <div class="cart-item-remove" data-id="${item.id}">
                            <i class="fa fa-times"></i>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }
        
        document.getElementById('cartTotalAmount').innerText = `€${cartTotal.toFixed(2)}`;
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
    
    // Add to cart (to be called from catalog)
    window.addToCart = function(id, name, price) {
        const existingItem = cart.find(item => item.id === id);
        if (existingItem) {
            existingItem.quantity++;
        } else {
            cart.push({ id: id, name: name, price: price, quantity: 1 });
        }
        saveCart();
        updateCartDisplay();
        
        // Show toast notification
        showToast(`${name} added to cart!`, 'success');
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
    
    function closeCart() {
        cartSidebar.classList.remove('open');
        cartOverlay.classList.remove('show');
    }
    
    toggleCartBtn.addEventListener('click', openCart);
    closeCartBtn.addEventListener('click', closeCart);
    cartOverlay.addEventListener('click', closeCart);
    
    // Cart item actions (event delegation)
    document.getElementById('cartItemsContainer').addEventListener('click', function(e) {
        const target = e.target;
        const btn = target.closest('.decrease-qty, .increase-qty, .cart-item-remove');
        if (!btn) return;
        
        const id = parseInt(btn.dataset.id);
        const item = cart.find(i => i.id === id);
        
        if (btn.classList.contains('decrease-qty')) {
            if (item.quantity > 1) {
                item.quantity--;
            } else {
                cart = cart.filter(i => i.id !== id);
            }
        } else if (btn.classList.contains('increase-qty')) {
            item.quantity++;
        } else if (btn.classList.contains('cart-item-remove')) {
            cart = cart.filter(i => i.id !== id);
        }
        
        saveCart();
        updateCartDisplay();
    });
    
    // Checkout from cart
    document.getElementById('checkoutFromCart').addEventListener('click', function() {
        if (cart.length === 0) {
            showToast('Your cart is empty!', 'error');
            return;
        }
        window.location.href = '{{ route("advertiser.checkout") }}';
    });
    
    // Load cart on page load
    loadCart();
</script>

</body>
</html>