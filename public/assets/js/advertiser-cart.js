/**
 * Early advertiser cart API. Catalog Buy buttons call window.addToCart; that
 * used to be assigned only at the end of the layout's large inline script, so
 * a parse/runtime error there (or a page without the drawer chrome) left
 * catalog.js toasting "Cart is not ready".
 *
 * This file loads from <head> with no defer so addToCart / openCart exist
 * before catalog.js. The layout may replace them with richer versions later.
 */
(function () {
    'use strict';

    function readConfig() {
        var el = document.getElementById('advertiser-cart-config');
        if (!el) return {};
        try {
            var parsed = JSON.parse(el.textContent || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function csrfToken() {
        var cfg = readConfig();
        if (cfg.csrf) return String(cfg.csrf);
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.getAttribute('content') || '') : '';
    }

    function addUrl() {
        var cfg = readConfig();
        if (cfg.addUrl) return String(cfg.addUrl);
        if (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.cartAdd) {
            return String(CatalogConfig.routes.cartAdd);
        }
        return '/advertiser/cart/add';
    }

    function toast(message, type, options) {
        if (typeof window.showAppToast === 'function') {
            return window.showAppToast(message, type, options);
        }
        if (typeof window.showToast === 'function') {
            return window.showToast(message, type, options);
        }
        if (typeof window.catalogToast === 'function') {
            return window.catalogToast(message, type, options);
        }
        if (type === 'error') {
            console.error(message);
        }
    }

    function applyPayload(data) {
        if (typeof window.__slbOnCartPayload === 'function') {
            try {
                window.__slbOnCartPayload(data);
                return;
            } catch (e) {
                console.error(e);
            }
        }
        var cart = Array.isArray(data) ? data : (Array.isArray(data && data.cart) ? data.cart : []);
        window.advertiserCart = cart;
        if (typeof window.catalogSyncInCartButtons === 'function') {
            window.catalogSyncInCartButtons(cart);
        }
        var badge = document.getElementById('cartBadge');
        if (!badge) return;
        var count = cart.reduce(function (sum, item) {
            return sum + (parseInt(item && item.quantity, 10) || 0);
        }, 0);
        badge.style.display = count > 0 ? 'flex' : 'none';
        badge.innerText = String(count);
    }

    function bootstrapOpenCart() {
        var sidebar = document.getElementById('cartSidebar');
        var overlay = document.getElementById('cartOverlay');
        if (!sidebar) return false;
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('show');
        document.body.classList.add('cart-open');
        sidebar.setAttribute('aria-hidden', 'false');
        try { sessionStorage.setItem('slb_cart_drawer_seen', '1'); } catch (e) {}
        return true;
    }

    function bootstrapAddToCart(id, name, price, sensitiveType, additionalPrice, basePrice, options) {
        if (typeof window.catalogIsOwnListing === 'function' && window.catalogIsOwnListing(id)) {
            var ownMsg = window.catalogOwnListingMessage || 'This is your listing — you can’t order it.';
            toast(ownMsg, 'error');
            return Promise.resolve({ ok: false, error: ownMsg });
        }
        var opts = options && typeof options === 'object' ? options : {};
        var token = csrfToken();
        if (!token) {
            toast('Cart is not ready. Refresh the page and try again.', 'error');
            return Promise.resolve({ ok: false, error: 'csrf' });
        }
        var body = new URLSearchParams();
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
        var qty = parseInt(opts.quantity, 10);
        if (Number.isFinite(qty) && qty > 0) {
            body.set('quantity', String(qty));
        }

        return fetch(addUrl(), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || !data.success) {
                    var msg = data.error || data.message || 'Could not add to cart.';
                    toast(msg, 'error');
                    return { ok: false, error: msg };
                }
                applyPayload(data);
                var label = sensitiveType ? (name + ' + ' + sensitiveType) : name;
                var flew = typeof window.catalogFlyToCart === 'function'
                    && window.catalogFlyToCart(opts.flyOrigin, { siteId: id, name: name });
                if (!opts.quiet) {
                    toast(data.message || (label + ' added to cart.'), 'success');
                } else if (!flew) {
                    toast(label + ' added to cart.', 'success', { delay: 1800, quiet: true });
                }
                if (typeof window.catalogAnnounceCart === 'function') {
                    window.catalogAnnounceCart(label);
                }
                var firstAdd = false;
                try { firstAdd = sessionStorage.getItem('slb_cart_drawer_seen') !== '1'; } catch (e) { firstAdd = true; }
                if (opts.openCart || opts.bulk || (Number.isFinite(qty) && qty > 1) || firstAdd) {
                    try {
                        if (typeof window.openCart === 'function') {
                            window.openCart();
                        } else {
                            bootstrapOpenCart();
                        }
                    } catch (e) { /* drawer chrome may be missing */ }
                    try { sessionStorage.setItem('slb_cart_drawer_seen', '1'); } catch (e) {}
                }
                return { ok: true, data: data };
            });
        }).catch(function () {
            toast('Could not add to cart.', 'error');
            return { ok: false, error: 'network' };
        });
    }

    if (typeof window.addToCart !== 'function') {
        window.addToCart = bootstrapAddToCart;
    }
    if (typeof window.openCart !== 'function') {
        window.openCart = bootstrapOpenCart;
    }
    window.__slbBootstrapAddToCart = bootstrapAddToCart;
    window.__slbBootstrapOpenCart = bootstrapOpenCart;
})();
