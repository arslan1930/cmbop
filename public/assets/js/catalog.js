/* Catalog page JS — expects window.CatalogConfig */
(function () {
if (!window.CatalogConfig) { window.CatalogConfig = { favorites: [], blacklist: [], routes: {}, csrfToken: '' }; }
})();

/**
 * Catalog homepage preview onerror: walk /media → /storage (and full → thumb → cover)
 * before showing the empty-state fallback. Hostinger often 404s /storage when the
 * public/storage symlink is missing; /media streams from the public disk.
 */
window.catalogSitePreviewOnError = function (img) {
    if (!img) return;
    // Ignore errors on the 1x1 deferred placeholder — hydrateExpandScreenshots
    // will assign the real URL when the expand/card opens.
    var cur = img.getAttribute('src') || '';
    if (img.hasAttribute('data-src') && cur.indexOf('data:') === 0) {
        return;
    }
    var chain = [];
    try {
        chain = JSON.parse(img.getAttribute('data-preview-chain') || '[]');
    } catch (e) {
        chain = [];
    }
    if (!Array.isArray(chain)) chain = [];
    var i = parseInt(img.getAttribute('data-preview-i') || '0', 10);
    if (isNaN(i) || i < 0) i = 0;
    var next = i + 1;
    if (next < chain.length && chain[next]) {
        img.setAttribute('data-preview-i', String(next));
        img.src = chain[next];
        return;
    }
    img.onerror = null;
    var z = img.closest('.site-preview-zoom');
    if (z) {
        z.classList.add('is-broken');
        var f = z.nextElementSibling;
        if (f && f.classList.contains('site-preview-fallback')) {
            f.classList.remove('d-none');
            f.classList.add('d-inline-flex');
            f.removeAttribute('aria-hidden');
        }
    }
};

/**
 * Floating desktop zoom popover for Site Details / card expand previews.
 * Previews stay out of catalog rows; hover enlarge only on expand.
 */
function initCatalogExpandPreviewZoom(root) {
    const scope = root || document;
    if (!window.matchMedia || window.matchMedia('(hover: none)').matches) return;

    let pop = document.getElementById('catalogExpandPreviewZoomPop');
    if (!pop) {
        pop = document.createElement('div');
        pop.id = 'catalogExpandPreviewZoomPop';
        pop.className = 'site-preview-zoom-pop';
        pop.setAttribute('aria-hidden', 'true');
        pop.innerHTML = '<img alt="" decoding="async">';
        document.body.appendChild(pop);
    }
    const img = pop.querySelector('img');
    let hideTimer = null;
    let zoomChain = [];
    let zoomIndex = 0;

    function place(trigger) {
        const rect = trigger.getBoundingClientRect();
        const pad = 12;
        const popW = pop.offsetWidth || 360;
        const popH = pop.offsetHeight || 220;
        let left = rect.right + 12;
        let top = rect.top + (rect.height / 2) - (popH / 2);
        if (left + popW > window.innerWidth - pad) {
            left = rect.left - popW - 12;
        }
        if (left < pad) left = pad;
        if (top < pad) top = pad;
        if (top + popH > window.innerHeight - pad) {
            top = Math.max(pad, window.innerHeight - popH - pad);
        }
        pop.style.left = Math.round(left) + 'px';
        pop.style.top = Math.round(top) + 'px';
    }

    function parseZoomChain(trigger) {
        let chain = [];
        try {
            chain = JSON.parse(trigger.getAttribute('data-zoom-chain') || '[]');
        } catch (e) {
            chain = [];
        }
        if (!Array.isArray(chain) || !chain.length) {
            const src = trigger.getAttribute('data-zoom-src');
            chain = src ? [src] : [];
        }
        return chain.filter(Boolean);
    }

    function show(trigger) {
        if (trigger.classList.contains('is-broken')) return;
        zoomChain = parseZoomChain(trigger);
        if (!zoomChain.length) return;
        clearTimeout(hideTimer);
        zoomIndex = 0;
        img.onerror = function () {
            zoomIndex += 1;
            if (zoomIndex < zoomChain.length) {
                img.src = zoomChain[zoomIndex];
                return;
            }
            img.onerror = null;
            pop.classList.remove('is-visible');
        };
        if (img.getAttribute('src') !== zoomChain[0]) {
            img.setAttribute('src', zoomChain[0]);
        }
        img.setAttribute('alt', trigger.getAttribute('aria-label') || 'Site preview');
        pop.classList.add('is-visible');
        pop.setAttribute('aria-hidden', 'false');
        place(trigger);
        requestAnimationFrame(function () { place(trigger); });
    }

    function hide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            pop.classList.remove('is-visible');
            pop.setAttribute('aria-hidden', 'true');
            img.onerror = null;
        }, 80);
    }

    scope.querySelectorAll('.site-preview-zoom[data-zoom-src]').forEach(function (el) {
        if (el.getAttribute('data-zoom-ready') === '1') return;
        el.setAttribute('data-zoom-ready', '1');
        el.addEventListener('mouseenter', function () { show(el); });
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', function () { show(el); });
        el.addEventListener('blur', hide);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initCatalogExpandPreviewZoom(document.getElementById('catalogResults') || document);
});

document.addEventListener('DOMContentLoaded', function () {
    // NEW-batch alert: badges keep a continuous red zoom/pulse (no border ring); on load we
    // also one-shot pop + play a clear triple beep once per tab session.
    // Do NOT mark the session before the beep succeeds (autoplay policies).
    (function alertNewListingsOnce() {
        const badges = document.querySelectorAll('.site-badge-new');
        if (!badges.length) return;

        const reduced = !!(window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

        function flashNewBadges() {
            badges.forEach(function (badge) {
                badge.classList.remove('is-alerting');
                void badge.offsetWidth;
                badge.classList.add('is-alerting');
                window.setTimeout(function () {
                    badge.classList.remove('is-alerting');
                }, 900);
            });
        }

        let catalogBeepCtx = null;
        function playCatalogNewBeep() {
            return new Promise(function (resolve) {
                if (reduced || document.hidden) {
                    resolve(false);
                    return;
                }
                try {
                    const AC = window.AudioContext || window.webkitAudioContext;
                    if (!AC) {
                        resolve(false);
                        return;
                    }
                    if (!catalogBeepCtx) {
                        catalogBeepCtx = new AC();
                    }
                    const ctx = catalogBeepCtx;

                    const run = function () {
                        try {
                            if (ctx.state !== 'running') {
                                resolve(false);
                                return;
                            }
                            function tone(freq, startAt, dur, peak) {
                                const osc = ctx.createOscillator();
                                const gain = ctx.createGain();
                                osc.type = 'sine';
                                osc.frequency.value = freq;
                                gain.gain.setValueAtTime(0.0001, startAt);
                                gain.gain.exponentialRampToValueAtTime(peak, startAt + 0.02);
                                gain.gain.exponentialRampToValueAtTime(0.0001, startAt + dur);
                                osc.connect(gain);
                                gain.connect(ctx.destination);
                                osc.start(startAt);
                                osc.stop(startAt + dur + 0.03);
                            }
                            const t0 = ctx.currentTime + 0.03;
                            // Louder triple chime so the NEW batch is clearly heard.
                            tone(784.0, t0, 0.12, 0.16);
                            tone(987.8, t0 + 0.13, 0.12, 0.14);
                            tone(1174.7, t0 + 0.26, 0.18, 0.13);
                            resolve(true);
                        } catch (err) {
                            resolve(false);
                        }
                    };

                    if (ctx.state === 'suspended') {
                        ctx.resume().then(run).catch(function () { resolve(false); });
                    } else {
                        run();
                    }
                } catch (err) {
                    resolve(false);
                }
            });
        }

        // V2 key: older builds set the flag before audio unlocked, which left
        // the tab permanently silent. Bump so stuck sessions can beep again.
        const beepStorageKey = 'catalogNewBadgeBeepedV2';

        function alreadyBeeped() {
            try {
                return sessionStorage.getItem(beepStorageKey) === '1';
            } catch (e) {
                return false;
            }
        }

        function markBeeped() {
            try {
                sessionStorage.setItem(beepStorageKey, '1');
            } catch (e) { /* private mode */ }
        }

        // Always animate — a prior blocked beep must not leave badges static.
        flashNewBadges();

        if (reduced || alreadyBeeped()) {
            return;
        }

        function armGestureBeep() {
            const unlock = function () {
                document.removeEventListener('pointerdown', unlock, true);
                document.removeEventListener('keydown', unlock, true);
                playCatalogNewBeep().then(function (ok) {
                    if (ok) {
                        markBeeped();
                        flashNewBadges();
                    }
                });
            };
            document.addEventListener('pointerdown', unlock, true);
            document.addEventListener('keydown', unlock, true);
            window.setTimeout(function () {
                document.removeEventListener('pointerdown', unlock, true);
                document.removeEventListener('keydown', unlock, true);
            }, 60000);
        }

        playCatalogNewBeep().then(function (ok) {
            if (ok) {
                markBeeped();
                return;
            }
            // Autoplay blocked — beep on the next click/key, then flash again.
            armGestureBeep();
        });
    })();

    const btn = document.getElementById('toggleMoreFiltersBtn');
    const drawer = document.getElementById('moreFiltersDrawer');
    if (btn && drawer) {
        btn.addEventListener('click', function () {
            const open = drawer.style.display !== 'none';
            drawer.style.display = open ? 'none' : 'block';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
    }

    // FR2 — preset chips set min/max inputs and apply via the live path.
    document.querySelectorAll('.filter-preset').forEach(function (chip) {
        chip.addEventListener('click', function () {
            const minEl = document.getElementById(chip.dataset.targetMin);
            const maxEl = document.getElementById(chip.dataset.targetMax);
            if (!minEl || !maxEl) return;
            minEl.value = chip.dataset.min || '';
            maxEl.value = chip.dataset.max || '';
            markActivePreset(chip.closest('.filter-presets'));
            if (typeof submitCatalogFilters === 'function') {
                submitCatalogFilters();
            }
        });
    });

    // Reflect the applied range back onto the chips. Without this the chip that
    // produced the current results looked no different from the other options.
    document.querySelectorAll('.filter-presets').forEach(function (group) {
        markActivePreset(group);
    });
});

/** Prevents double form.submit() while a navigation is already in flight. */
let catalogFilterSubmitInFlight = false;

/**
 * Cover the results card while the next live fragment is on its way.
 *
 * @param {{ label?: string }} [options]
 */
function markCatalogResultsBusy(options) {
    options = options || {};
    const card = document.getElementById('catalogResults');
    if (!card) return;

    // Hold the current height so a shorter fragment does not yank the page.
    if (!card.dataset.busyMinHeight) {
        const h = Math.round(card.getBoundingClientRect().height);
        if (h > 0) {
            card.dataset.busyMinHeight = String(h);
            card.style.minHeight = h + 'px';
        }
    }

    card.classList.add('is-busy');
    card.setAttribute('aria-busy', 'true');
    // Must declare busy here — a bare `busy` ReferenceError after is-busy
    // left the "Updating results…" veil stuck forever.
    const busy = card.querySelector('.catalog-results-busy');
    if (busy) {
        busy.hidden = false;
        busy.setAttribute('aria-hidden', 'false');
        const label = busy.querySelector('.catalog-results-busy__label');
        if (label) {
            label.textContent = options.label || 'Updating results…';
        }
    }

    const searchInput = document.getElementById('catalogSearchInput');
    if (searchInput) searchInput.setAttribute('aria-busy', 'true');
    const applyBtn = document.getElementById('applyFiltersBtn');
    if (applyBtn) applyBtn.disabled = true;
}

function clearCatalogResultsBusy() {
    const card = document.getElementById('catalogResults');
    if (!card) return;
    card.classList.remove('is-busy');
    card.removeAttribute('aria-busy');
    if (card.dataset.busyMinHeight) {
        card.style.minHeight = '';
        delete card.dataset.busyMinHeight;
    }
    const busy = card.querySelector('.catalog-results-busy');
    if (busy) {
        busy.hidden = true;
        busy.setAttribute('aria-hidden', 'true');
        const label = busy.querySelector('.catalog-results-busy__label');
        if (label) label.textContent = 'Updating results…';
    }
    const live = document.getElementById('catalogSearchStatus');
    if (live) live.textContent = '';
    const searchInput = document.getElementById('catalogSearchInput');
    if (searchInput) searchInput.removeAttribute('aria-busy');
    const applyBtn = document.getElementById('applyFiltersBtn');
    if (applyBtn) applyBtn.disabled = false;
    catalogFilterSubmitInFlight = false;
}

document.addEventListener('DOMContentLoaded', function () {
    // Fresh paint / back-forward cache can restore an in-flight "busy" state.
    clearCatalogResultsBusy();

    const form = document.getElementById('filterForm');
    if (form) {
        form.addEventListener('submit', markCatalogResultsBusy);
    }

    // Pagination and the sort/recovery links all navigate away.
    document.addEventListener('click', function (e) {
        const link = e.target.closest(
            '.pagination a.page-link, .catalog-clear-all, .filter-chip__remove, .catalog-clear-country, .catalog-try-language, .catalog-neighbor-market'
        );
        if (!link || link.getAttribute('href') === null) return;
        markCatalogResultsBusy();
    });
});

window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
        clearCatalogResultsBusy();
    }
});

/* ------------------------------------------------------------ bulk deal rail */

const BULK_RAIL_COLLAPSED_KEY = 'catalog.bulkDeals.collapsed';

/** Clears autoplay/search timers from the previous rail instance (live refresh). */
let bulkRailTeardown = null;

function destroyBulkDealRail() {
    if (typeof bulkRailTeardown === 'function') {
        try {
            bulkRailTeardown();
        } catch (err) {
            /* ignore */
        }
    }
    bulkRailTeardown = null;
}

function bulkRailReadCollapsed() {
    try {
        return window.localStorage.getItem(BULK_RAIL_COLLAPSED_KEY) === '1';
    } catch (err) {
        // Private mode / blocked storage: the section simply starts open.
        return false;
    }
}

function bulkRailWriteCollapsed(collapsed) {
    try {
        window.localStorage.setItem(BULK_RAIL_COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch (err) {
        /* not worth surfacing — the toggle still works for this page view */
    }
}

/**
 * Paged bulk offers (6 per batch) with a smooth R→L slide (translateX).
 *
 * ← / → and numbered buttons move between pages; a slow autoplay advances
 * when the section is idle. Hover/focus (and deal search) pause autoplay.
 * Trackpad / pointer horizontal swipe flips pages the same way.
 * Search matches the visible host / listing name only — never a hidden domain.
 */
function initBulkDealRail() {
    // Stop timers from a previous instance before binding a new section.
    destroyBulkDealRail();

    const section = document.querySelector('[data-bulk-rail]');
    if (!section) return;

    const track = section.querySelector('[data-bulk-track]');
    const pager = section.querySelector('[data-bulk-pager]');
    const pagesEl = section.querySelector('[data-bulk-pages]');
    const pageLabel = section.querySelector('[data-bulk-page-label]');
    const emptyEl = section.querySelector('[data-bulk-empty]');
    const prev = section.querySelector('[data-bulk-scroll="prev"]');
    const next = section.querySelector('[data-bulk-scroll="next"]');
    const toggle = section.querySelector('[data-bulk-toggle]');
    const toggleLabel = section.querySelector('[data-bulk-toggle-label]');
    const searchInput = section.querySelector('[data-bulk-search]');
    if (!track) return;

    // Ensure viewport wrapper exists (live fragment or older markup without it).
    let viewport = section.querySelector('[data-bulk-viewport]');
    if (!viewport) {
        viewport = document.createElement('div');
        viewport.className = 'catalog-bulk-viewport';
        viewport.setAttribute('data-bulk-viewport', '');
        if (track.parentNode) {
            track.parentNode.insertBefore(viewport, track);
            viewport.appendChild(track);
        }
    }

    const pageSize = Math.max(1, parseInt(section.getAttribute('data-bulk-page-size') || '6', 10) || 6);
    const allCards = Array.prototype.slice.call(section.querySelectorAll('[data-bulk-card]'));
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const AUTOPLAY_MS = 7000;
    const SLIDE_MS = 560;

    let currentPage = 1;
    let pageCount = 1;
    let visibleCards = allCards.slice();
    let autoplayTimer = null;
    let autoplayPaused = false;
    let pointerInside = false;
    let searchTimer = null;
    let resumeTimer = null;

    function clearHighlights() {
        allCards.forEach(function (card) {
            card.classList.remove('is-bulk-match');
        });
    }

    function searchQuery() {
        return searchInput ? String(searchInput.value || '').trim() : '';
    }

    function canAutoplay() {
        if (reduceMotion || pageCount <= 1 || autoplayPaused) return false;
        if (pointerInside) return false;
        if (section.classList.contains('is-collapsed')) return false;
        if (searchQuery()) return false;
        if (visibleCards.length === 0) return false;
        return true;
    }

    function renderPageButtons() {
        if (!pagesEl) return;
        pagesEl.textContent = '';
        for (let i = 1; i <= pageCount; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'catalog-bulk-page' + (i === currentPage ? ' is-active' : '');
            btn.textContent = String(i);
            btn.setAttribute('aria-label', 'Bulk deals page ' + i);
            if (i === currentPage) {
                btn.setAttribute('aria-current', 'page');
            }
            btn.addEventListener('click', function () {
                goToPage(i, { user: true });
            });
            pagesEl.appendChild(btn);
        }
    }

    function syncChrome() {
        const hasPages = pageCount > 1;
        const hasVisible = visibleCards.length > 0;

        if (pager) {
            pager.hidden = !hasVisible;
        }
        if (prev) prev.disabled = !hasPages || currentPage <= 1;
        if (next) next.disabled = !hasPages || currentPage >= pageCount;

        if (pageLabel) {
            pageLabel.textContent = hasVisible
                ? ('Page ' + currentPage + ' of ' + pageCount)
                : '';
        }

        renderPageButtons();

        if (emptyEl) {
            emptyEl.hidden = hasVisible;
        }
        if (viewport) {
            viewport.hidden = !hasVisible;
        }

        section.classList.toggle('is-empty-search', !hasVisible);
        section.classList.toggle('is-multipage', hasPages);
    }

    /**
     * Off-page panels stay in the track for translateX, but must not stay in
     * the tab order or AT tree (clipped content would otherwise remain reachable).
     */
    function syncPanelInert() {
        Array.prototype.forEach.call(
            track.querySelectorAll('[data-bulk-page-panel]'),
            function (panel, i) {
                const active = (i + 1) === currentPage;
                if (active) {
                    panel.removeAttribute('inert');
                    panel.removeAttribute('aria-hidden');
                } else {
                    panel.setAttribute('inert', '');
                    panel.setAttribute('aria-hidden', 'true');
                }
            }
        );
    }

    function setTrackOffset(pageIndex, instant) {
        const offset = Math.max(0, pageIndex - 1) * 100;
        if (instant || reduceMotion) {
            const prevTransition = track.style.transition;
            track.style.transition = 'none';
            track.style.transform = 'translateX(-' + offset + '%)';
            // Force reflow so the next animated slide starts from this offset.
            void track.offsetWidth;
            track.style.transition = prevTransition || '';
            return;
        }
        track.style.transform = 'translateX(-' + offset + '%)';
    }

    /** Pack visible cards into full-width page panels for the sliding track. */
    function rebuildPanels(cards) {
        const list = cards.slice();
        while (track.firstChild) {
            track.removeChild(track.firstChild);
        }

        pageCount = Math.max(1, Math.ceil(list.length / pageSize) || 1);
        if (!list.length) {
            setTrackOffset(1, true);
            return;
        }

        for (let p = 0; p < pageCount; p++) {
            const panel = document.createElement('div');
            panel.className = 'catalog-bulk-page-panel';
            panel.setAttribute('data-bulk-page-panel', '');
            panel.setAttribute('role', 'group');
            panel.setAttribute('aria-label', 'Bulk deals page ' + (p + 1));
            list.slice(p * pageSize, (p + 1) * pageSize).forEach(function (card) {
                card.classList.remove('is-bulk-hidden');
                card.removeAttribute('hidden');
                panel.appendChild(card);
            });
            track.appendChild(panel);
        }
    }

    function paint(opts) {
        const options = opts || {};
        setTrackOffset(currentPage, !!options.instant);
        syncPanelInert();
        syncChrome();
    }

    function goToPage(page, opts) {
        const options = opts || {};
        const target = Math.min(pageCount, Math.max(1, page));
        if (target === currentPage && !options.force) {
            syncChrome();
            return;
        }

        // Autoplay wrap last → first: snap (avoid sliding back through every page).
        const wrapForward = !options.user
            && pageCount > 1
            && currentPage === pageCount
            && target === 1;

        currentPage = target;
        paint({ instant: wrapForward || !!options.instant });

        if (options.user) {
            stopAutoplay();
            restartAutoplaySoon();
        }
    }

    function setVisibleCards(cards, opts) {
        const options = opts || {};
        visibleCards = cards.slice();
        rebuildPanels(visibleCards);
        if (options.page) {
            currentPage = Math.min(pageCount, Math.max(1, options.page));
        } else {
            currentPage = 1;
        }
        // Rebuild must not animate from a stale offset.
        paint({ instant: true });
    }

    function stopAutoplay() {
        if (autoplayTimer) {
            clearInterval(autoplayTimer);
            autoplayTimer = null;
        }
    }

    function tickAutoplay() {
        if (!canAutoplay()) return;
        const nextPage = currentPage >= pageCount ? 1 : currentPage + 1;
        goToPage(nextPage, { user: false });
    }

    function startAutoplay() {
        stopAutoplay();
        if (!canAutoplay()) return;
        autoplayTimer = setInterval(tickAutoplay, AUTOPLAY_MS);
    }

    function restartAutoplaySoon() {
        if (resumeTimer) clearTimeout(resumeTimer);
        resumeTimer = setTimeout(function () {
            autoplayPaused = false;
            startAutoplay();
        }, AUTOPLAY_MS);
    }

    function applySearch(raw) {
        const q = String(raw || '').trim().toLowerCase();
        clearHighlights();

        if (!q) {
            autoplayPaused = false;
            setVisibleCards(allCards);
            startAutoplay();
            return;
        }

        autoplayPaused = true;
        stopAutoplay();
        const matches = allCards.filter(function (card) {
            const hay = (card.getAttribute('data-bulk-search-text') || '').toLowerCase();
            return hay.indexOf(q) !== -1;
        });

        if (!matches.length) {
            setVisibleCards([]);
            return;
        }

        // Keep full catalog paging, jump to the batch that holds the first hit.
        const firstIndex = allCards.indexOf(matches[0]);
        const pageForFirst = Math.floor(firstIndex / pageSize) + 1;
        setVisibleCards(allCards, { page: pageForFirst });
        matches.forEach(function (card) {
            card.classList.add('is-bulk-match');
        });
    }

    if (prev) {
        prev.addEventListener('click', function () {
            goToPage(currentPage - 1, { user: true });
        });
    }
    if (next) {
        next.addEventListener('click', function () {
            goToPage(currentPage + 1, { user: true });
        });
    }

    // Trackpad / mouse horizontal swipe → flip pages (animated slide).
    let swipeCooldownUntil = 0;
    const SWIPE_COOLDOWN_MS = Math.max(320, SLIDE_MS);
    const SWIPE_DELTA_MIN = 18;

    function swipeToAdjacentPage(direction) {
        if (pageCount <= 1 || visibleCards.length === 0) return;
        if (section.classList.contains('is-collapsed')) return;
        const now = Date.now();
        if (now < swipeCooldownUntil) return;
        const target = currentPage + (direction < 0 ? -1 : 1);
        if (target < 1 || target > pageCount) return;
        swipeCooldownUntil = now + SWIPE_COOLDOWN_MS;
        goToPage(target, { user: true });
    }

    section.addEventListener('wheel', function (e) {
        const dx = e.deltaX || 0;
        const dy = e.deltaY || 0;
        // Prefer clear horizontal gestures (trackpad swipe left/right).
        if (Math.abs(dx) < SWIPE_DELTA_MIN) return;
        if (Math.abs(dx) <= Math.abs(dy)) return;
        e.preventDefault();
        swipeToAdjacentPage(dx);
    }, { passive: false });

    // Pointer drag (touch / click-drag) for the same page flip.
    let pointerSwipe = null;
    section.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        if (e.target.closest('button, a, input, label')) return;
        pointerSwipe = { id: e.pointerId, x: e.clientX, y: e.clientY, moved: false };
    });
    section.addEventListener('pointermove', function (e) {
        if (!pointerSwipe || pointerSwipe.id !== e.pointerId) return;
        const dx = e.clientX - pointerSwipe.x;
        const dy = e.clientY - pointerSwipe.y;
        if (!pointerSwipe.moved) {
            if (Math.abs(dx) < SWIPE_DELTA_MIN && Math.abs(dy) < SWIPE_DELTA_MIN) return;
            if (Math.abs(dx) <= Math.abs(dy)) {
                pointerSwipe = null;
                return;
            }
            pointerSwipe.moved = true;
        }
        if (Math.abs(dx) < 48) return;
        // Negative dx = swipe left → next page; positive → previous.
        swipeToAdjacentPage(-dx);
        pointerSwipe = null;
    });
    function clearPointerSwipe(e) {
        if (pointerSwipe && e.pointerId === pointerSwipe.id) {
            pointerSwipe = null;
        }
    }
    section.addEventListener('pointerup', clearPointerSwipe);
    section.addEventListener('pointercancel', clearPointerSwipe);

    section.addEventListener('mouseenter', function () {
        pointerInside = true;
        stopAutoplay();
    });
    section.addEventListener('mouseleave', function () {
        pointerInside = false;
        startAutoplay();
    });
    section.addEventListener('focusin', function () {
        pointerInside = true;
        stopAutoplay();
    });
    section.addEventListener('focusout', function (e) {
        if (section.contains(e.relatedTarget)) return;
        pointerInside = false;
        startAutoplay();
    });

    if (searchInput) {
        if (typeof window.SlbLiveSearch !== 'undefined') {
            window.SlbLiveSearch.init(searchInput, {
                mode: 'client',
                minChars: 1,
                onSearch: function (detail) {
                    applySearch(detail.query);
                },
            });
        } else {
            searchInput.addEventListener('input', function () {
                const value = searchInput.value;
                if (searchTimer) clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    applySearch(value);
                }, 350);
            });
        }
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchInput.value = '';
                applySearch('');
            }
        });
    }

    function applyCollapsed(collapsed) {
        section.classList.toggle('is-collapsed', collapsed);
        if (toggle) toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (toggleLabel) toggleLabel.textContent = collapsed ? 'Show' : 'Hide';
        if (collapsed) {
            stopAutoplay();
        } else {
            paint({ instant: true });
            startAutoplay();
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            const collapsed = !section.classList.contains('is-collapsed');
            applyCollapsed(collapsed);
            bulkRailWriteCollapsed(collapsed);
        });
    }

    setVisibleCards(allCards);
    applyCollapsed(bulkRailReadCollapsed());
    startAutoplay();

    bulkRailTeardown = function () {
        stopAutoplay();
        if (resumeTimer) {
            clearTimeout(resumeTimer);
            resumeTimer = null;
        }
        if (searchTimer) {
            clearTimeout(searchTimer);
            searchTimer = null;
        }
    };
}

document.addEventListener('DOMContentLoaded', initBulkDealRail);
window.initBulkDealRail = initBulkDealRail;
window.destroyBulkDealRail = destroyBulkDealRail;

/**
 * Highlight the preset whose range matches the inputs it targets.
 * Pass a chip's group to re-evaluate it after a click.
 */
function markActivePreset(group) {
    if (!group) return;
    group.querySelectorAll('.filter-preset').forEach(function (chip) {
        const minEl = document.getElementById(chip.dataset.targetMin);
        const maxEl = document.getElementById(chip.dataset.targetMax);
        if (!minEl || !maxEl) return;
        const matches = (minEl.value || '') === (chip.dataset.min || '')
            && (maxEl.value || '') === (chip.dataset.max || '')
            && ((chip.dataset.min || '') !== '' || (chip.dataset.max || '') !== '');
        chip.classList.toggle('is-active', matches);
        chip.setAttribute('aria-pressed', matches ? 'true' : 'false');
    });
}

/**
 * Catalog category= wire format: `|` between niches (publisher-aligned).
 * Legacy comma URLs: longest-first against CatalogConfig.categoryNames —
 * never blindly split on commas (Marketing, PR & Advertising is one niche).
 */
window.CatalogCategoryParam = (function () {
    // Mirror Category::NICHES_CONTAINING_COMMA — protect even if categoryNames is empty.
    var COMMA_NICHES = [
        'Events, Conferences & Trade Fairs',
        'Marketing, PR & Advertising',
        'NGOs, Charity & Social Impact'
    ];

    function knownNames() {
        var cfg = window.CatalogConfig || {};
        var names = Array.isArray(cfg.categoryNames) ? cfg.categoryNames.slice() : [];
        COMMA_NICHES.forEach(function (niche) {
            var hit = names.some(function (n) {
                return String(n).toLowerCase() === niche.toLowerCase();
            });
            if (!hit) names.push(niche);
        });
        return names;
    }

    function canonicalizeToken(token, names) {
        token = String(token || '').trim();
        if (!token) return '';
        var list = (names && names.length) ? names : knownNames();
        for (var i = 0; i < list.length; i++) {
            if (String(list[i]).toLowerCase() === token.toLowerCase()) {
                return list[i];
            }
        }
        return token;
    }

    function join(names) {
        return (names || []).map(function (n) {
            return String(n || '').trim();
        }).filter(Boolean).join('|');
    }

    /** Parse then re-join with `|` (legacy comma URLs → pipe wire format). */
    function canonicalize(raw, names) {
        return join(split(raw, names));
    }

    function split(raw, names) {
        raw = String(raw == null ? '' : raw).trim();
        if (!raw) return [];

        names = (names && names.length) ? names.slice() : knownNames();
        // Always protect comma niches (same as PHP parseCatalogCategoryParam).
        COMMA_NICHES.forEach(function (niche) {
            var hit = names.some(function (n) {
                return String(n).toLowerCase() === niche.toLowerCase();
            });
            if (!hit) names.push(niche);
        });

        if (raw.indexOf('|') !== -1) {
            return raw.split('|').map(function (s) {
                return canonicalizeToken(String(s || '').trim(), names);
            }).filter(Boolean);
        }

        var i;
        for (i = 0; i < names.length; i++) {
            if (String(names[i]).toLowerCase() === raw.toLowerCase()) {
                return [names[i]];
            }
        }

        names.sort(function (a, b) {
            return String(b).length - String(a).length;
        });

        var remaining = raw;
        var out = [];
        while (remaining) {
            remaining = remaining.replace(/^\s+/, '');
            if (!remaining) break;
            if (remaining.charAt(0) === ',') {
                remaining = remaining.slice(1).replace(/^\s+/, '');
                continue;
            }

            var matched = null;
            for (i = 0; i < names.length; i++) {
                var name = String(names[i]);
                if (!name) continue;
                if (remaining.toLowerCase().indexOf(name.toLowerCase()) !== 0) continue;
                var after = remaining.slice(name.length);
                var afterTrim = after.replace(/^\s+/, '');
                if (after === '' || afterTrim === '' || afterTrim.charAt(0) === ',' || afterTrim.charAt(0) === '|') {
                    matched = name;
                    remaining = afterTrim;
                    if (remaining.charAt(0) === ',') {
                        remaining = remaining.slice(1).replace(/^\s+/, '');
                    } else if (remaining.charAt(0) === '|') {
                        remaining = remaining.slice(1).replace(/^\s+/, '');
                    }
                    break;
                }
            }

            if (!matched) {
                var pos = remaining.indexOf(',');
                if (pos === -1) {
                    out.push(remaining.trim());
                    break;
                }
                out.push(remaining.slice(0, pos).trim());
                remaining = remaining.slice(pos + 1);
                continue;
            }
            out.push(matched);
        }

        return out.filter(Boolean);
    }

    return { join: join, split: split, canonicalize: canonicalize };
})();

// Initialize favorites and blacklist from database
const revealUrlEndpoint = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.revealUrl) || '';
const hideUrlEndpoint = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.hideUrl) || '';
const copyTrackEndpoint = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.copyTrack) || '';
let favorites = (window.CatalogConfig && CatalogConfig.favorites) ? CatalogConfig.favorites.slice() : [];
let blacklist = (window.CatalogConfig && CatalogConfig.blacklist) ? CatalogConfig.blacklist.slice() : [];

// Multi-select variables
let selectedMultiFilters = {
    category: [],
    country: [],
    language: []
};

// Initialize from URL parameters
if (CatalogConfig.categoryParam) {
    selectedMultiFilters.category = CatalogCategoryParam.split(
        CatalogConfig.categoryParam,
        CatalogConfig.categoryNames
    );
    // Step 1.1: keep hidden field + config on the `|` wire format.
    var canonicalCategory = CatalogCategoryParam.join(selectedMultiFilters.category);
    CatalogConfig.categoryParam = canonicalCategory;
    var selectedCategoryField = document.getElementById('selectedCategory');
    if (selectedCategoryField) {
        selectedCategoryField.value = canonicalCategory;
    }
}
if (CatalogConfig.countryParam) {
    selectedMultiFilters.country = String(CatalogConfig.countryParam).split(',').filter(function(v) { return v; });
}
if (CatalogConfig.languageParam) {
    selectedMultiFilters.language = String(CatalogConfig.languageParam).split(',').filter(function(v) { return v; });
}

function closeAllMultiDropdowns(exceptId) {
    var dropdowns = document.querySelectorAll('.multi-select-dropdown');
    for (var i = 0; i < dropdowns.length; i++) {
        if (exceptId && dropdowns[i].id === exceptId) continue;
        var wasOpen = dropdowns[i].classList.contains('show');
        dropdowns[i].classList.remove('show');
        var otherTrigger = dropdowns[i].previousElementSibling;
        if (otherTrigger) otherTrigger.setAttribute('aria-expanded', 'false');
        if (wasOpen && dropdowns[i].id === 'countryMultiDropdown'
            && window.CatalogCountryPicker
            && typeof CatalogCountryPicker.onCountryDropdownClosed === 'function') {
            CatalogCountryPicker.onCountryDropdownClosed();
        }
    }
}

function getVisibleMultiOptions(dropdown) {
    return Array.prototype.slice.call(dropdown.querySelectorAll('.option-item')).filter(function (el) {
        return el.style.display !== 'none';
    });
}

function focusMultiOption(dropdown, index) {
    var options = getVisibleMultiOptions(dropdown);
    if (!options.length) return;
    var i = ((index % options.length) + options.length) % options.length;
    options.forEach(function (el) { el.classList.remove('is-keyboard-focus'); });
    options[i].classList.add('is-keyboard-focus');
    // Phase 6 — focus the row (role=option), not the clipped checkbox.
    if (!options[i].hasAttribute('tabindex')) {
        options[i].setAttribute('tabindex', '-1');
    }
    options[i].focus({ preventScroll: false });
    options[i].scrollIntoView({ block: 'nearest' });
    dropdown.dataset.focusIndex = String(i);
}

function toggleMultiDropdown(dropdownId, triggerEl) {
    if (typeof event !== 'undefined' && event) event.stopPropagation();
    closeAllMultiDropdowns(dropdownId);
    var dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    var willOpen = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show', willOpen);
    if (triggerEl) triggerEl.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    if (willOpen) {
        var searchInput = dropdown.querySelector('.search-box input');
        if (searchInput) {
            searchInput.value = '';
            var list = dropdown.querySelector('.options-list');
            if (list) filterMultiOptions(list.id, '');
            setTimeout(function () { searchInput.focus(); }, 10);
        }
        dropdown.dataset.focusIndex = '-1';
        // Re-sync highlights so reopen always matches selectedMultiFilters.
        var wrapper = dropdown.closest('.multi-select-wrapper');
        var type = wrapper ? wrapper.getAttribute('data-multi-select') : '';
        if (type && typeof syncOptionSelectedState === 'function') {
            syncOptionSelectedState(type);
        }
    } else if (dropdownId === 'countryMultiDropdown'
        && window.CatalogCountryPicker
        && typeof CatalogCountryPicker.onCountryDropdownClosed === 'function') {
        CatalogCountryPicker.onCountryDropdownClosed();
    }
}

document.addEventListener('keydown', function (e) {
    var openDropdown = document.querySelector('.multi-select-dropdown.show');
    var trigger = e.target.closest && e.target.closest('.multi-select-input');

    // Backspace / Delete peel the last selected tag. Typeahead only when empty
    // so editing search text never wipes a selection. Main Search is untouched.
    if (e.key === 'Backspace' || e.key === 'Delete') {
        if (e.target && e.target.id === 'catalogSearchInput') {
            // Main catalog search — browser clears typed text only.
        } else if (trigger) {
            var backspaceWrapper = trigger.closest('.multi-select-wrapper');
            var backspaceType = backspaceWrapper ? backspaceWrapper.getAttribute('data-multi-select') : '';
            if (backspaceType && removeLastMultiFilterSelection(backspaceType)) {
                e.preventDefault();
            }
            return;
        } else if (openDropdown && e.target && e.target.closest && e.target.closest('.search-box')) {
            var typeahead = e.target.closest('.search-box').querySelector('input');
            var typeaheadEl = (e.target.tagName === 'INPUT') ? e.target : typeahead;
            if (typeaheadEl && String(typeaheadEl.value || '').length === 0) {
                var openType = multiSelectTypeFromDropdown(openDropdown);
                if (openType && removeLastMultiFilterSelection(openType)) {
                    e.preventDefault();
                }
            }
            return;
        }
    }

    if (trigger && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) {
        e.preventDefault();
        var wrapper = trigger.closest('.multi-select-wrapper');
        var dropdown = wrapper ? wrapper.querySelector('.multi-select-dropdown') : null;
        if (dropdown) toggleMultiDropdown(dropdown.id, trigger);
        return;
    }

    if (!openDropdown) return;

    if (e.key === 'Escape') {
        e.preventDefault();
        var closedId = openDropdown.id;
        openDropdown.classList.remove('show');
        var openTrigger = openDropdown.previousElementSibling;
        if (openTrigger) {
            openTrigger.setAttribute('aria-expanded', 'false');
            openTrigger.focus();
        }
        if (closedId === 'countryMultiDropdown'
            && window.CatalogCountryPicker
            && typeof CatalogCountryPicker.onCountryDropdownClosed === 'function') {
            CatalogCountryPicker.onCountryDropdownClosed();
        }
        return;
    }

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var current = parseInt(openDropdown.dataset.focusIndex || '-1', 10);
        focusMultiOption(openDropdown, e.key === 'ArrowDown' ? current + 1 : current - 1);
        return;
    }

    if (e.key === 'Enter' || e.key === ' ') {
        // Phase 6 — keyboard toggle for Category/Country/Language rows.
        // Space always stays in the typeahead. Enter in the search box selects
        // the sole visible option (e.g. type "auto" → Automotive → Enter).
        if (e.target && e.target.closest && e.target.closest('.search-box')) {
            if (e.key === 'Enter') {
                var soleVisible = null;
                var visibleCount = 0;
                var searchOptions = openDropdown.querySelectorAll('.option-item');
                for (var vi = 0; vi < searchOptions.length; vi++) {
                    if (searchOptions[vi].style.display === 'none') continue;
                    visibleCount++;
                    soleVisible = searchOptions[vi];
                    if (visibleCount > 1) break;
                }
                if (visibleCount === 1 && soleVisible) {
                    var soleInput = soleVisible.querySelector('input[type="checkbox"]');
                    if (soleInput) {
                        e.preventDefault();
                        soleInput.checked = true;
                        updateMultiFilter(soleInput);
                    }
                }
            }
            return;
        }
        var focusedOption = openDropdown.querySelector('.option-item.is-keyboard-focus');
        var optionItem = (e.target && e.target.closest)
            ? e.target.closest('.option-item')
            : null;
        var item = optionItem && openDropdown.contains(optionItem) ? optionItem : focusedOption;
        if (!item || !openDropdown.contains(item)) return;
        var optionInput = item.querySelector('input[type="checkbox"]');
        if (!optionInput) return;
        e.preventDefault();
        optionInput.checked = !optionInput.checked;
        updateMultiFilter(optionInput);
        return;
    }
});

function filterMultiOptions(optionsId, searchTerm) {
    var options = document.getElementById(optionsId);
    if (!options) return;
    var optionItems = options.querySelectorAll('.option-item');
    var term = (searchTerm || '').toLowerCase().trim();
    var visible = 0;

    // Country group browse (DACH+ / Nordics): only show member markets.
    var groupCodeSet = null;
    if (optionsId === 'countryMultiOptions'
        && window.CatalogCountryPicker
        && typeof CatalogCountryPicker.getActiveGroup === 'function'
        && CatalogCountryPicker.getActiveGroup()) {
        var groupCodes = CatalogCountryPicker.groupCodes(CatalogCountryPicker.getActiveGroup());
        groupCodeSet = {};
        for (var g = 0; g < groupCodes.length; g++) {
            groupCodeSet[groupCodes[g]] = true;
        }
    }

    for (var i = 0; i < optionItems.length; i++) {
        var option = optionItems[i];
        if (option.classList.contains('is-pair-hidden')) {
            option.style.display = 'none';
            continue;
        }
        var text = (option.querySelector('span') ? option.querySelector('span').textContent : '').toLowerCase();
        var code = (option.querySelector('input') ? option.querySelector('input').value : '').toLowerCase();
        var inGroup = !groupCodeSet || !!groupCodeSet[code];
        var match = inGroup && (term === '' || text.indexOf(term) !== -1 || code.indexOf(term) !== -1);
        option.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    }

    // Hide section headers when none of their options match the typeahead.
    var sections = options.querySelectorAll('.multi-select-section');
    for (var s = 0; s < sections.length; s++) {
        var section = sections[s];
        if (section.getAttribute('data-section') === 'recent' && section.classList.contains('is-empty')) {
            section.hidden = true;
            continue;
        }
        var sectionOptions = section.querySelectorAll('.option-item');
        var sectionVisible = 0;
        for (var j = 0; j < sectionOptions.length; j++) {
            if (sectionOptions[j].style.display !== 'none') sectionVisible++;
        }
        var hideSection = sectionOptions.length > 0 ? sectionVisible === 0 : true;
        if (section.getAttribute('data-section') === 'recent') {
            hideSection = sectionVisible === 0;
        }
        section.hidden = hideSection;
        section.classList.toggle('is-empty', hideSection && section.getAttribute('data-section') === 'recent');
    }

    var empty = options.parentElement ? options.parentElement.querySelector('.multi-select-empty') : null;
    if (empty) empty.classList.toggle('d-none', visible > 0);
}

/**
 * Country picker helpers: Recent (localStorage) + DACH+ / Nordics browse groups.
 *
 * Group buttons expand member countries for picking — they do NOT select the
 * whole group as a filter. Query stays country=de,at (never dach_plus).
 */
var CatalogCountryPicker = (function () {
    var STORAGE_KEY = 'catalog.recentCountries';
    var MAX_RECENT = 3;
    var activeCountryGroup = null;

    function readRecent() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            var parsed = raw ? JSON.parse(raw) : [];
            if (!Array.isArray(parsed)) return [];
            return parsed.map(function (c) { return String(c || '').toLowerCase().trim(); })
                .filter(function (c) { return c; })
                .slice(0, MAX_RECENT);
        } catch (err) {
            return [];
        }
    }

    function writeRecent(codes) {
        var unique = [];
        for (var i = 0; i < codes.length; i++) {
            var code = String(codes[i] || '').toLowerCase().trim();
            if (!code || unique.indexOf(code) !== -1) continue;
            unique.push(code);
            if (unique.length >= MAX_RECENT) break;
        }
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(unique));
        } catch (err) { /* ignore quota / private mode */ }
    }

    function rememberFromSelection(codes) {
        var next = [];
        var incoming = Array.isArray(codes) ? codes : [];
        for (var i = 0; i < incoming.length; i++) {
            var code = String(incoming[i] || '').toLowerCase().trim();
            if (code) next.push(code);
        }
        var previous = readRecent();
        for (var j = 0; j < previous.length; j++) {
            next.push(previous[j]);
        }
        writeRecent(next);
        // Phase 5 — keep trigger + row highlights in sync after Recent pin changes.
        refreshCountryPickerUi();
    }

    function findOption(code) {
        return document.querySelector('#countryMultiOptions .option-item input[value="' + code + '"]');
    }

    function normalizeCodes(list) {
        var out = [];
        var seen = {};
        var incoming = Array.isArray(list) ? list : [];
        for (var i = 0; i < incoming.length; i++) {
            var code = String(incoming[i] || '').toLowerCase().trim();
            if (!code || seen[code]) continue;
            seen[code] = true;
            out.push(code);
        }
        return out;
    }

    function groupCodes(groupKey) {
        var codes = (window.CatalogConfig && CatalogConfig.countryGroups && CatalogConfig.countryGroups[groupKey])
            ? CatalogConfig.countryGroups[groupKey]
            : [];
        if (!codes.length) {
            var btn = document.querySelector('[data-country-group="' + groupKey + '"]');
            if (btn && btn.getAttribute('data-country-codes')) {
                codes = btn.getAttribute('data-country-codes').split(',');
            }
        }
        return normalizeCodes(codes);
    }

    function groupLabel(groupKey) {
        if (window.CatalogConfig && CatalogConfig.countryGroupLabels && CatalogConfig.countryGroupLabels[groupKey]) {
            return String(CatalogConfig.countryGroupLabels[groupKey]);
        }
        var btn = document.querySelector('[data-country-group="' + groupKey + '"]');
        if (btn) {
            return btn.getAttribute('data-country-group-label')
                || (btn.textContent || '').trim()
                || groupKey;
        }
        return groupKey;
    }

    function getActiveGroup() {
        return activeCountryGroup;
    }

    function setActiveGroup(groupKey) {
        activeCountryGroup = groupKey || null;
        syncGroupActionButtons();
    }

    function clearActiveGroup() {
        activeCountryGroup = null;
        syncGroupActionButtons();
    }

    function syncGroupActionButtons() {
        var actions = document.querySelectorAll('[data-country-group]');
        for (var i = 0; i < actions.length; i++) {
            var key = actions[i].getAttribute('data-country-group');
            var on = !!(activeCountryGroup && key === activeCountryGroup);
            actions[i].classList.toggle('is-active', on);
            actions[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    }

    /**
     * Closed-trigger group label.
     * - While browsing a group: show that label when every pick is a member.
     * - Otherwise infer only for 2+ picks that all sit inside one configured group
     *   (so a lone Germany from Popular does not become “DACH+”).
     */
    function groupContextForValues(values) {
        var selected = normalizeCodes(values);
        if (!selected.length) return null;

        if (activeCountryGroup) {
            var activeCodes = groupCodes(activeCountryGroup);
            var allInActive = selected.every(function (code) {
                return activeCodes.indexOf(code) !== -1;
            });
            if (allInActive) {
                return { key: activeCountryGroup, label: groupLabel(activeCountryGroup) };
            }
            return null;
        }

        if (selected.length < 2) return null;

        var groups = (window.CatalogConfig && CatalogConfig.countryGroups) ? CatalogConfig.countryGroups : {};
        var bestKey = null;
        var bestSize = Infinity;
        Object.keys(groups).forEach(function (key) {
            var codes = groupCodes(key);
            if (!codes.length) return;
            var covers = selected.every(function (code) {
                return codes.indexOf(code) !== -1;
            });
            if (covers && codes.length < bestSize) {
                bestKey = key;
                bestSize = codes.length;
            }
        });
        if (!bestKey) return null;
        return { key: bestKey, label: groupLabel(bestKey) };
    }

    function syncActiveGroupWithSelection() {
        var selected = (typeof selectedMultiFilters !== 'undefined' && selectedMultiFilters.country)
            ? selectedMultiFilters.country
            : [];
        if (!selected.length) {
            // Keep browse focus while the dropdown is open so the user can still pick.
            var dropdown = document.getElementById('countryMultiDropdown');
            if (dropdown && dropdown.classList.contains('show') && activeCountryGroup) {
                return;
            }
            clearActiveGroup();
            return;
        }
        var ctx = groupContextForValues(selected);
        if (activeCountryGroup && (!ctx || ctx.key !== activeCountryGroup)) {
            // Selection left the browse group — drop the browse context (and prefix).
            clearActiveGroup();
        }
    }

    /**
     * Phase 3 — after the country list closes:
     * no picks → clear group focus; otherwise keep focus only while picks ⊆ group.
     */
    function onCountryDropdownClosed() {
        var selected = (typeof selectedMultiFilters !== 'undefined' && selectedMultiFilters.country)
            ? selectedMultiFilters.country
            : [];
        if (!selected.length) {
            clearActiveGroup();
        } else {
            syncActiveGroupWithSelection();
        }
        var searchInput = document.getElementById('countrySearch');
        if (typeof filterMultiOptions === 'function') {
            // Re-run filter with (possibly cleared) active group so the next open
            // is honest if something left the list filtered.
            filterMultiOptions('countryMultiOptions', searchInput ? searchInput.value : '');
        }
        refreshCountryPickerUi();
    }

    /*
     * Phase 5 — after group browse / recent DOM moves / remember, re-align
     * checkbox/.is-selected highlights and the closed-field tags.
     */
    function refreshCountryPickerUi() {
        if (typeof syncOptionSelectedState === 'function') {
            syncOptionSelectedState('country');
        }
        if (typeof updateMultiDisplay === 'function') {
            updateMultiDisplay('country');
        }
    }

    function renderRecent() {
        var list = document.getElementById('countryMultiOptions');
        if (!list) return;
        var section = list.querySelector('.multi-select-section[data-section="recent"]');
        if (!section) return;

        var label = section.querySelector('.multi-select-section__label');
        // Move previously-recent options back to their home sections first.
        var parked = section.querySelectorAll('.option-item[data-home-section]');
        for (var p = 0; p < parked.length; p++) {
            var homeKey = parked[p].getAttribute('data-home-section');
            var home = list.querySelector('.multi-select-section[data-section="' + homeKey + '"]');
            if (home) home.appendChild(parked[p]);
            parked[p].removeAttribute('data-home-section');
        }

        var recent = readRecent();
        var moved = 0;
        for (var i = 0; i < recent.length; i++) {
            var input = findOption(recent[i]);
            if (!input) continue;
            var item = input.closest('.option-item');
            if (!item) continue;
            var currentSection = item.closest('.multi-select-section');
            // Keep Popular pins where they are — Recent only lifts non-popular rows.
            if (currentSection && currentSection.getAttribute('data-section') === 'popular') {
                continue;
            }
            if (currentSection && currentSection.getAttribute('data-section') !== 'recent') {
                item.setAttribute('data-home-section', currentSection.getAttribute('data-section') || '');
            }
            section.appendChild(item);
            item.style.display = 'flex';
            moved++;
        }

        section.hidden = moved === 0;
        section.classList.toggle('is-empty', moved === 0);
        if (label && section.contains(label) && section.firstChild !== label) {
            section.insertBefore(label, section.firstChild);
        }
        refreshCountryPickerUi();
    }

    /**
     * Browse a market group: open the list and show only member countries.
     * Does not check any boxes / does not write country=…
     */
    function selectGroup(groupKey) {
        var codes = groupCodes(groupKey);
        if (!groupKey || !codes.length) return;

        setActiveGroup(groupKey);

        var dropdown = document.getElementById('countryMultiDropdown');
        var wrapper = dropdown ? dropdown.closest('.multi-select-wrapper') : null;
        var trigger = wrapper ? wrapper.querySelector('.multi-select-input') : null;
        if (dropdown && !dropdown.classList.contains('show') && typeof toggleMultiDropdown === 'function') {
            toggleMultiDropdown('countryMultiDropdown', trigger);
        }

        var searchInput = document.getElementById('countrySearch');
        if (searchInput) searchInput.value = '';

        if (typeof filterMultiOptions === 'function') {
            filterMultiOptions('countryMultiOptions', '');
        }

        // Focus the first visible member for keyboard users.
        if (dropdown) {
            var first = dropdown.querySelector('.option-item:not([style*="display: none"])');
            if (first && typeof focusMultiOption === 'function') {
                dropdown.dataset.focusIndex = '0';
            }
            var focusables = dropdown.querySelectorAll('.option-item');
            var focusIndex = -1;
            for (var f = 0; f < focusables.length; f++) {
                if (focusables[f].style.display === 'none') continue;
                focusIndex = f;
                break;
            }
            if (focusIndex >= 0 && typeof focusMultiOption === 'function') {
                focusMultiOption(dropdown, focusIndex);
            }
        }

        refreshCountryPickerUi();
    }

    function bindGroupActions() {
        var actions = document.querySelectorAll('[data-country-group]');
        for (var i = 0; i < actions.length; i++) {
            actions[i].addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var key = this.getAttribute('data-country-group');
                // Toggle off if the same group is already active.
                if (activeCountryGroup && key === activeCountryGroup) {
                    clearActiveGroup();
                    var searchInput = document.getElementById('countrySearch');
                    if (typeof filterMultiOptions === 'function') {
                        filterMultiOptions('countryMultiOptions', searchInput ? searchInput.value : '');
                    }
                    refreshCountryPickerUi();
                    return;
                }
                selectGroup(key);
            });
        }
    }

    function init() {
        bindGroupActions();
        renderRecent();
        // Do not auto-activate browse filtering from the URL. Closed-trigger
        // still shows [DACH+] via groupContextForValues when 2+ picks ⊆ a group.
        refreshCountryPickerUi();
    }

    return {
        init: init,
        rememberFromSelection: rememberFromSelection,
        renderRecent: renderRecent,
        selectGroup: selectGroup,
        readRecent: readRecent,
        refreshCountryPickerUi: refreshCountryPickerUi,
        getActiveGroup: getActiveGroup,
        setActiveGroup: setActiveGroup,
        clearActiveGroup: clearActiveGroup,
        groupCodes: groupCodes,
        groupLabel: groupLabel,
        groupContextForValues: groupContextForValues,
        syncActiveGroupWithSelection: syncActiveGroupWithSelection,
        onCountryDropdownClosed: onCountryDropdownClosed
    };
})();
window.CatalogCountryPicker = CatalogCountryPicker;

function updateMultiFilter(checkbox) {
    var type = checkbox.getAttribute('data-type');
    var value = checkbox.value;
    
    if (checkbox.checked) {
        if (selectedMultiFilters[type].indexOf(value) === -1) {
            selectedMultiFilters[type].push(value);
        }
        if (type === 'country' && window.CatalogCountryPicker) {
            // Recent click / any country select: pin Recent, then re-sync highlights.
            CatalogCountryPicker.rememberFromSelection([value]);
            CatalogCountryPicker.renderRecent();
        }
    } else {
        var newArray = [];
        for (var i = 0; i < selectedMultiFilters[type].length; i++) {
            if (selectedMultiFilters[type][i] !== value) {
                newArray.push(selectedMultiFilters[type][i]);
            }
        }
        selectedMultiFilters[type] = newArray;
    }

    syncOptionSelectedState(type);
    if (type === 'country' && window.CatalogCountryPicker
        && typeof CatalogCountryPicker.syncActiveGroupWithSelection === 'function') {
        CatalogCountryPicker.syncActiveGroupWithSelection();
    }
    if (type === 'country') {
        applyCatalogLanguagePairFilter({ pruneInvalid: true });
    }
    updateMultiDisplay(type);
    // Live-apply after a short pause so ticking several niches batches into one fetch.
    if (typeof scheduleCatalogFilterLive === 'function') {
        scheduleCatalogFilterLive({ replace: true });
    }
}

/*
 * Country-first language pairs: when one or more countries are selected,
 * only show languages allowed for those markets (union). Empty country
 * keeps Option A — all languages available for language-only browse.
 */
function catalogAllowedLanguageCodesForCountries(countries) {
    var map = (window.CatalogConfig && CatalogConfig.countryLanguageMap) || {};
    var list = Array.isArray(countries) ? countries : [];
    if (!list.length) return null;
    var allowed = {};
    var anyMapped = false;
    for (var i = 0; i < list.length; i++) {
        var code = String(list[i] || '').toLowerCase();
        var rows = map[code] || [];
        if (rows.length) anyMapped = true;
        for (var r = 0; r < rows.length; r++) {
            var lang = typeof rows[r] === 'string' ? rows[r] : (rows[r] && rows[r].code);
            if (lang) allowed[String(lang).toLowerCase()] = true;
        }
    }
    if (!anyMapped) return null;
    return allowed;
}

function applyCatalogLanguagePairFilter(opts) {
    opts = opts || {};
    var list = document.getElementById('languageMultiOptions');
    if (!list) return;
    var allowed = catalogAllowedLanguageCodesForCountries(selectedMultiFilters.country || []);
    var items = list.querySelectorAll('.option-item');
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var input = item.querySelector('input[data-type="language"]');
        var code = input ? String(input.value || '').toLowerCase() : '';
        var pairOk = !allowed || !!(allowed[code]);
        item.classList.toggle('is-pair-hidden', !pairOk);
        if (!pairOk) {
            item.style.display = 'none';
            if (input) input.checked = false;
        }
    }
    if (opts.pruneInvalid && allowed) {
        var kept = [];
        var langs = selectedMultiFilters.language || [];
        for (var j = 0; j < langs.length; j++) {
            if (allowed[String(langs[j]).toLowerCase()]) kept.push(langs[j]);
        }
        if (kept.length !== langs.length) {
            selectedMultiFilters.language = kept;
        }
    }
    // Re-apply typeahead so search + pair constraints stay in sync.
    var search = document.getElementById('languageSearch');
    if (typeof filterMultiOptions === 'function') {
        filterMultiOptions('languageMultiOptions', search ? search.value : '');
    }
    syncOptionSelectedState('language');
    updateMultiDisplay('language');
}

/*
 * Phase 0 product rules (catalog multi-select):
 * - No visible checkboxes (CSS); whole row click toggles
 * - Selected look = brand tint + bold (no checkmark icon)
 * - Trigger: 0 → placeholder; fits → tags with ×; overflow → "N countries"
 * - Multi-select OR within country / within language; country AND language when both set
 * - Selecting a language never auto-sets country (Option A — language-only = all sites in that language)
 * - Do not auto-close on click
 * - Recent is country-only; click toggles + pins on remember
 */
/*
 * Container ids are listed rather than derived. Adding "s" to the type produced
 * "selectedCategorysDisplay" and "selectedCountrysDisplay", which match nothing
 * in the markup, so ticking a category or country never showed a tag.
 *
 * The placeholder wording is read from the markup's data-placeholder, because a
 * copy here silently overwrote whatever the Blade template said.
 */
var MULTI_FILTER_UI = {
    category: {
        container: 'selectedCategoriesDisplay',
        placeholder: 'All categories',
        singular: 'category',
        plural: 'categories'
    },
    country: {
        container: 'selectedCountriesDisplay',
        placeholder: 'All countries',
        singular: 'country',
        plural: 'countries'
    },
    language: {
        container: 'selectedLanguagesDisplay',
        placeholder: 'All languages',
        singular: 'language',
        plural: 'languages'
    }
};

/*
 * Phase 2 — selected-row sync.
 * Keep checkbox checked, .is-selected, and aria-selected aligned with
 * selectedMultiFilters[type] so reopen always shows the same highlights.
 * Call sites: updateMultiFilter, remove/clear (tag ×), initializeMultiSelects,
 * country group actions, and dropdown open.
 */
function syncOptionSelectedState(type) {
    var list = document.getElementById(type + 'MultiOptions');
    if (!list) return;
    var selected = selectedMultiFilters[type] || [];
    var selectedSet = {};
    for (var s = 0; s < selected.length; s++) {
        selectedSet[String(selected[s])] = true;
        // Country codes are lowercase in the picker; tolerate mixed-case URL params.
        selectedSet[String(selected[s]).toLowerCase()] = true;
    }
    var inputs = list.querySelectorAll('.option-item input[data-type="' + type + '"]');
    for (var i = 0; i < inputs.length; i++) {
        var input = inputs[i];
        var value = String(input.value || '');
        var on = !!(selectedSet[value] || selectedSet[value.toLowerCase()]);
        input.checked = on;
        var item = input.closest('.option-item');
        if (!item) continue;
        item.classList.toggle('is-selected', on);
        item.setAttribute('aria-selected', on ? 'true' : 'false');
    }
}

function multiFilterOptionLabel(type, value) {
    var option = document.querySelector('#' + type + 'MultiOptions input[value="' + value + '"]');
    if (option) {
        return option.getAttribute('data-name') || value;
    }
    return value;
}

/**
 * Phase 7 — pure compact-trigger formatter (unit-testable contract).
 * formatMultiSelectTrigger(3, 'country', 'countries') → "3 countries"
 */
function formatMultiSelectTrigger(count, singular, plural) {
    var n = parseInt(count, 10);
    if (!n || n < 1) return '';
    return n + ' ' + (n === 1 ? singular : plural);
}

function multiFilterCountLabel(type, count, container, ui) {
    var singular = (container && container.dataset.singular) || ui.singular || type;
    var plural = (container && container.dataset.plural) || ui.plural || (type + 's');
    return formatMultiSelectTrigger(count, singular, plural);
}

/*
 * Phase 3 — compact overflow count (v1 rule).
 * 0 → placeholder; 1 → single removable tag; 2+ → one chip ("3 countries").
 * Catalog filter columns are narrow (col-lg-2), so length>1 is the sticky rule
 * rather than relying only on layout measurement (which can miss before paint).
 * multiDisplayOverflows remains available if a later phase wants measure-only.
 */
function multiDisplayOverflows(container) {
    if (!container || !container.children.length) return false;
    if (container.clientWidth <= 0) return false;
    var first = container.querySelector('.selected-tag');
    if (!first) return false;
    var lineBudget = Math.ceil(first.getBoundingClientRect().height) + 8;
    if (lineBudget < 8) return false;
    return container.scrollHeight > lineBudget + 2 || container.scrollWidth > container.clientWidth + 1;
}

/**
 * Phase 0 — locked product rules (catalog search + multi-select):
 * - Search debounce: reuse CATALOG_FILTER_LIVE_MS (~350ms).
 * - Min chars before live search: 2 (empty query still clears → full catalog).
 * - Enter / Apply: submit with history entry (same as today).
 * - Suggest endpoint: kept registered but unused by typing UX.
 * - Hide mode: live /results HTML still applies eye/mask rules server-side.
 * - Multi-select: always named tags (no “2 countries” count chip); wrap OK for v1.
 * - Tag ×: keep per-value remove (no compact clear-all chip).
 * - Backspace/Delete: peel last tag when trigger focused, or typeahead is empty.
 */
const CATALOG_SEARCH_MIN_CHARS = 2;

/*
 * Phase 3 compact overflow was retired for product: always show exact names.
 * Helper kept for contract tests / possible later overflow-only compact.
 */
function shouldCompactMultiDisplay(values) {
    return false;
}

/**
 * Country selections that sit inside DACH+/Nordics stay as named × tags
 * (plus a non-removable group label) instead of collapsing to "N countries".
 */
function shouldCompactCountryDisplay(values) {
    if (!Array.isArray(values) || values.length <= 1) return false;
    if (window.CatalogCountryPicker && typeof CatalogCountryPicker.groupContextForValues === 'function') {
        if (CatalogCountryPicker.groupContextForValues(values)) return false;
    }
    return true;
}

// Expose pure helpers for contract tests / debugging (Phase 7).
window.CatalogMultiSelectFormat = {
    formatMultiSelectTrigger: formatMultiSelectTrigger,
    shouldCompactMultiDisplay: shouldCompactMultiDisplay,
    shouldCompactCountryDisplay: shouldCompactCountryDisplay
};

function renderCompactMultiDisplay(container, type, count, ui) {
    container.innerHTML = '';
    container.classList.add('is-compact');

    var label = multiFilterCountLabel(type, count, container, ui);
    var tag = document.createElement('span');
    tag.className = 'selected-tag selected-tag--count';
    // Phase 6 — accessible name for the compact chip (e.g. "2 countries selected").
    tag.setAttribute('aria-label', label + ' selected');
    tag.appendChild(document.createTextNode(label + ' '));

    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'remove-tag';
    clear.dataset.filterType = type;
    clear.dataset.filterClearAll = '1';
    clear.setAttribute('aria-label', 'Clear ' + label);
    clear.innerHTML = '&times;';
    tag.appendChild(clear);
    container.appendChild(tag);
}

function renderNamedMultiTags(container, type, values) {
    for (var i = 0; i < values.length; i++) {
        var value = values[i];
        var displayName = multiFilterOptionLabel(type, value);

        /* Built with DOM nodes rather than an HTML string: these labels come from
           the database, and the old inline onclick put them inside a quoted JS
           argument, so a single apostrophe broke the handler. */
        var tag = document.createElement('span');
        tag.className = 'selected-tag';
        tag.appendChild(document.createTextNode(displayName + ' '));

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'remove-tag';
        remove.dataset.filterType = type;
        remove.dataset.filterValue = value;
        remove.setAttribute('aria-label', 'Remove filter ' + displayName);
        remove.innerHTML = '&times;';
        tag.appendChild(remove);

        container.appendChild(tag);
    }
}

function updateMultiDisplay(type) {
    var ui = MULTI_FILTER_UI[type];
    if (!ui) return;

    var container = document.getElementById(ui.container);
    var values = selectedMultiFilters[type];

    if (!container) return;

    container.innerHTML = '';
    container.classList.remove('is-compact');

    if (values.length === 0) {
        var placeholder = document.createElement('span');
        placeholder.className = 'placeholder-text';
        placeholder.textContent = container.dataset.placeholder || ui.placeholder;
        container.appendChild(placeholder);
        return;
    }

    // Country + DACH+/Nordics context: [ DACH+ ] [ Germany × ] [ Austria × ]
    if (type === 'country'
        && window.CatalogCountryPicker
        && typeof CatalogCountryPicker.groupContextForValues === 'function') {
        var groupCtx = CatalogCountryPicker.groupContextForValues(values);
        if (groupCtx) {
            var groupTag = document.createElement('span');
            groupTag.className = 'selected-tag selected-tag--group';
            groupTag.setAttribute('aria-label', groupCtx.label + ' market group');
            groupTag.appendChild(document.createTextNode(groupCtx.label));
            container.appendChild(groupTag);
            renderNamedMultiTags(container, type, values);
            return;
        }
    }

    // Phase 3 v1: 2+ selections → compact count chip (clear-all × included).
    // One selection always stays a named tag. Trigger click still opens the list.
    // Country group context skips compact (see shouldCompactCountryDisplay).
    var compact = type === 'country'
        ? shouldCompactCountryDisplay(values)
        : shouldCompactMultiDisplay(values);
    if (compact) {
        renderCompactMultiDisplay(container, type, values.length, ui);
        return;
    }

    renderNamedMultiTags(container, type, values);
}

/*
 * One delegated listener for every filter tag, however often they re-render.
 *
 * Capture phase on purpose: the tags sit inside .multi-select-input, whose own
 * click handler opens the dropdown. Listening on the way down lets us cancel
 * that before it runs, so removing a tag no longer also opens the list.
 * Phase 6 — stopImmediatePropagation so × / clear-all never reopen awkwardly.
 */
document.addEventListener('click', function (e) {
    var remove = e.target.closest ? e.target.closest('.remove-tag[data-filter-type]') : null;
    if (!remove) return;

    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') {
        e.stopImmediatePropagation();
    }
    if (remove.dataset.filterClearAll === '1') {
        clearMultiFilter(remove.dataset.filterType);
        return;
    }
    removeMultiFilter(remove.dataset.filterType, remove.dataset.filterValue);
}, true);

function clearMultiFilter(type) {
    if (!MULTI_FILTER_UI[type]) return;
    selectedMultiFilters[type] = [];
    if (type === 'country' && window.CatalogCountryPicker) {
        CatalogCountryPicker.clearActiveGroup();
        var searchInput = document.getElementById('countrySearch');
        if (typeof filterMultiOptions === 'function') {
            filterMultiOptions('countryMultiOptions', searchInput ? searchInput.value : '');
        }
    }
    syncOptionSelectedState(type);
    if (type === 'country') {
        applyCatalogLanguagePairFilter({ pruneInvalid: true });
    }
    updateMultiDisplay(type);
    if (typeof scheduleCatalogFilterLive === 'function') {
        scheduleCatalogFilterLive({ replace: true });
    }
}

function removeMultiFilter(type, value) {
    var newArray = [];
    for (var i = 0; i < selectedMultiFilters[type].length; i++) {
        if (selectedMultiFilters[type][i] !== value) {
            newArray.push(selectedMultiFilters[type][i]);
        }
    }
    selectedMultiFilters[type] = newArray;
    
    var checkbox = document.querySelector('#' + type + 'MultiOptions input[value="' + value + '"]');
    if (checkbox) {
        checkbox.checked = false;
    }

    if (type === 'country' && window.CatalogCountryPicker
        && typeof CatalogCountryPicker.syncActiveGroupWithSelection === 'function') {
        CatalogCountryPicker.syncActiveGroupWithSelection();
    }

    syncOptionSelectedState(type);
    if (type === 'country') {
        applyCatalogLanguagePairFilter({ pruneInvalid: true });
    }
    updateMultiDisplay(type);
    if (typeof scheduleCatalogFilterLive === 'function') {
        scheduleCatalogFilterLive({ replace: true });
    }
}

/**
 * Map open dropdown id → filter type (categoryMultiDropdown → category).
 */
function multiSelectTypeFromDropdown(dropdown) {
    if (!dropdown || !dropdown.id) return '';
    var id = String(dropdown.id);
    if (id.indexOf('MultiDropdown') === -1) return '';
    return id.replace(/MultiDropdown$/, '');
}

/**
 * Backspace / Delete target: remove last selected value (or clear compact chip).
 * @return {boolean} true when a selection changed
 */
function removeLastMultiFilterSelection(type) {
    if (!MULTI_FILTER_UI[type]) return false;
    var values = selectedMultiFilters[type] || [];
    if (!values.length) return false;

    var compact = type === 'country'
        ? shouldCompactCountryDisplay(values)
        : shouldCompactMultiDisplay(values);
    if (compact || values.length === 1) {
        clearMultiFilter(type);
        return true;
    }

    removeMultiFilter(type, values[values.length - 1]);
    return true;
}

function initializeMultiSelects() {
    // Initialize checkboxes from URL-restored selection
    for (var i = 0; i < selectedMultiFilters.category.length; i++) {
        var value = selectedMultiFilters.category[i];
        var checkbox = document.querySelector('#categoryMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    for (var i = 0; i < selectedMultiFilters.country.length; i++) {
        var value = selectedMultiFilters.country[i];
        var checkbox = document.querySelector('#countryMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    for (var i = 0; i < selectedMultiFilters.language.length; i++) {
        var value = selectedMultiFilters.language[i];
        var checkbox = document.querySelector('#languageMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }

    syncOptionSelectedState('category');
    syncOptionSelectedState('country');
    syncOptionSelectedState('language');
    
    // Update displays
    updateMultiDisplay('category');
    updateMultiDisplay('country');
    updateMultiDisplay('language');
    applyCatalogLanguagePairFilter({ pruneInvalid: true });
}

/**
 * Copy the ticked multi-select values into the hidden fields the form posts.
 *
 * Anything that submits #filterForm has to go through this. Sorting and the
 * Enter key used to call form.submit() directly, which posted the hidden fields
 * as the server rendered them and silently dropped tags ticked since page load.
 */
function syncCatalogFilterFields() {
    const map = {
        selectedCategory: selectedMultiFilters.category,
        selectedCountry: selectedMultiFilters.country,
        selectedLanguage: selectedMultiFilters.language,
    };
    Object.keys(map).forEach(function (id) {
        const field = document.getElementById(id);
        if (!field) return;
        // Category uses `|` so niches like "Marketing, PR & Advertising" stay intact.
        field.value = id === 'selectedCategory'
            ? CatalogCategoryParam.join(map[id])
            : map[id].join(',');
    });
}

/**
 * Catalog listing URL helpers — query string is the source of truth for
 * refresh, back/forward, share links, and (Phase 3) live results fetches.
 */
const CatalogUrl = (function () {
    const cfg = window.CatalogConfig || {};
    const KEYS = Array.isArray(cfg.queryKeys) && cfg.queryKeys.length
        ? cfg.queryKeys.slice()
        : [
            'search', 'category', 'country', 'language',
            'price_min', 'price_max', 'da_min', 'da_max', 'dr_min', 'dr_max',
            'traffic_min', 'traffic_max', 'sponsored', 'favorites_filter',
            'blacklist_filter', 'bulk_deals', 'new_badge', 'on_sale', 'verified', 'quality',
            'rating_min', 'has_completions', 'site', 'sort', 'per_page', 'page',
            'wizard',
        ];
    const DEFAULT_SORT = cfg.defaultSort || 'dr_desc';
    const DEFAULT_PER_PAGE = '20';
    const ALLOWED_PER_PAGE = { '10': 1, '20': 1, '25': 1, '50': 1 };
    const PATH = cfg.catalogPath || '/advertiser/catalog';

    function keySet() {
        const set = {};
        for (let i = 0; i < KEYS.length; i++) set[KEYS[i]] = true;
        return set;
    }

    function canonicalize(params) {
        const allowed = keySet();
        const out = new URLSearchParams();
        KEYS.forEach(function (key) {
            if (!allowed[key]) return;
            let value = params.get ? params.get(key) : params[key];
            if (value == null) return;
            value = String(value).trim();
            if (value === '') return;
            if (key === 'sort' && value === DEFAULT_SORT) return;
            if (key === 'per_page') {
                if (!ALLOWED_PER_PAGE[value] || value === DEFAULT_PER_PAGE) return;
            }
            if (key === 'page' && value === '1') return;
            out.set(key, value);
        });
        return out;
    }

    function fromLocation() {
        return canonicalize(new URLSearchParams(window.location.search));
    }

    /**
     * Read allowlisted listing state from #filterForm (+ sort select).
     * Contextual keys (wizard) are preserved from the current URL.
     */
    function fromForm(options) {
        options = options || {};
        syncCatalogFilterFields();

        const form = document.getElementById('filterForm');
        const raw = new URLSearchParams();
        if (form) {
            const fd = new FormData(form);
            fd.forEach(function (value, key) {
                if (typeof value !== 'string') return;
                // Later checkbox/radio wins; catalog form uses singles.
                raw.set(key, value);
            });
        }

        // Sort lives outside the form (form="filterForm") — still collected by FormData
        // in modern browsers; fall back to the select if missing.
        const sortEl = document.getElementById('catalogSort');
        if (sortEl && !raw.has('sort')) {
            raw.set('sort', sortEl.value || DEFAULT_SORT);
        }

        // Keep wizard (and any other contextual allowlisted keys not on the form).
        // Form checkboxes are omitted from FormData when unchecked — do not revive
        // them from the URL or live clear (Bulk / On sale / New / Quality) no-ops.
        const current = new URLSearchParams(window.location.search);
        KEYS.forEach(function (key) {
            if (raw.has(key)) return;
            if (key === 'page') return;
            if (form) {
                const el = form.querySelector('[name="' + key + '"]');
                if (el && el.type === 'checkbox') return;
            }
            const existing = current.get(key);
            if (existing != null && String(existing).trim() !== '') {
                raw.set(key, existing);
            }
        });

        if (!options.keepPage) {
            raw.delete('page');
        }

        return canonicalize(raw);
    }

    function href(params) {
        const qs = params && params.toString ? params.toString() : '';
        return qs ? (PATH + '?' + qs) : PATH;
    }

    /** Write the listing query without navigating (Phase 3 live fetch hook). */
    function replaceState(params) {
        const next = href(params || fromForm({ keepPage: true }));
        if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState({ catalogLive: 1 }, '', next);
        }
        return next;
    }

    function pushState(params) {
        const next = href(params || fromForm({ keepPage: true }));
        if (window.history && typeof window.history.pushState === 'function') {
            window.history.pushState({ catalogLive: 1 }, '', next);
        }
        return next;
    }

    /** Drop keys (and page by default) — chip-remove / clear-filter. */
    function except(params, drop, dropPage) {
        const raw = new URLSearchParams();
        (params || new URLSearchParams()).forEach(function (value, key) {
            raw.set(key, value);
        });
        (drop || []).forEach(function (key) { raw.delete(key); });
        if (dropPage !== false) raw.delete('page');
        return canonicalize(raw);
    }

    function setInputValue(el, value) {
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = value === '1' || value === 'true' || value === true;
            return;
        }
        el.value = value == null ? '' : String(value);
    }

    /**
     * Mirror allowlisted query params back into the filter form (popstate / deep links).
     */
    function applyToForm(params) {
        params = params || fromLocation();
        const form = document.getElementById('filterForm');
        if (!form) return;

        const get = function (key) {
            return params.get ? (params.get(key) || '') : (params[key] || '');
        };

        setInputValue(form.querySelector('[name="search"]'), get('search'));
        // Category: split with shared rule, then write `|` into the hidden field.
        if (typeof selectedMultiFilters !== 'undefined' && typeof CatalogCategoryParam !== 'undefined') {
            selectedMultiFilters.category = CatalogCategoryParam.split(
                get('category'),
                (window.CatalogConfig && CatalogConfig.categoryNames) || []
            );
            var canonicalCategory = CatalogCategoryParam.join(selectedMultiFilters.category);
            setInputValue(document.getElementById('selectedCategory'), canonicalCategory);
            if (window.CatalogConfig) {
                CatalogConfig.categoryParam = canonicalCategory;
            }
        } else {
            setInputValue(document.getElementById('selectedCategory'), get('category'));
        }
        setInputValue(document.getElementById('selectedCountry'), get('country'));
        setInputValue(document.getElementById('selectedLanguage'), get('language'));
        setInputValue(form.querySelector('[name="price_min"]'), get('price_min'));
        setInputValue(form.querySelector('[name="price_max"]'), get('price_max'));
        setInputValue(form.querySelector('[name="da_min"]'), get('da_min'));
        setInputValue(form.querySelector('[name="da_max"]'), get('da_max'));
        setInputValue(form.querySelector('[name="dr_min"]'), get('dr_min'));
        setInputValue(form.querySelector('[name="dr_max"]'), get('dr_max'));
        setInputValue(form.querySelector('[name="traffic_min"]'), get('traffic_min'));
        setInputValue(form.querySelector('[name="traffic_max"]'), get('traffic_max'));
        setInputValue(form.querySelector('[name="sponsored"]'), get('sponsored'));
        setInputValue(form.querySelector('[name="favorites_filter"]'), get('favorites_filter'));
        setInputValue(form.querySelector('[name="blacklist_filter"]'), get('blacklist_filter'));
        setInputValue(form.querySelector('[name="bulk_deals"]'), get('bulk_deals'));
        setInputValue(form.querySelector('[name="new_badge"]'), get('new_badge'));
        setInputValue(form.querySelector('[name="on_sale"]'), get('on_sale'));
        setInputValue(form.querySelector('[name="quality"]'), get('quality'));
        setInputValue(form.querySelector('[name="rating_min"]'), get('rating_min'));
        setInputValue(form.querySelector('[name="has_completions"]'), get('has_completions'));

        const sortEl = document.getElementById('catalogSort');
        if (sortEl) sortEl.value = get('sort') || DEFAULT_SORT;

        const perPageEl = document.getElementById('catalogPerPage');
        if (perPageEl) {
            const pp = get('per_page');
            perPageEl.value = ALLOWED_PER_PAGE[pp] ? pp : DEFAULT_PER_PAGE;
        }

        if (typeof selectedMultiFilters !== 'undefined') {
            selectedMultiFilters.country = get('country').split(',').filter(Boolean);
            selectedMultiFilters.language = get('language').split(',').filter(Boolean);

            ['category', 'country', 'language'].forEach(function (type) {
                // Case-tolerant checkbox + .is-selected sync (same as tag remove).
                if (typeof syncOptionSelectedState === 'function') {
                    syncOptionSelectedState(type);
                }
                if (typeof updateMultiDisplay === 'function') updateMultiDisplay(type);
            });
            if (typeof applyCatalogLanguagePairFilter === 'function') {
                applyCatalogLanguagePairFilter({ pruneInvalid: true });
            }
        }

        if (typeof markActivePreset === 'function') {
            document.querySelectorAll('.filter-presets').forEach(function (group) {
                markActivePreset(group);
            });
        }
    }

    /**
     * Full-page fallback when live fetch cannot run.
     */
    function navigate(options) {
        options = options || {};
        const params = options.params || fromForm({ keepPage: !!options.keepPage });
        const next = href(params);
        markCatalogResultsBusy();
        if (options.replace) {
            window.location.replace(next);
        } else {
            window.location.assign(next);
        }
        return next;
    }

    return {
        keys: KEYS,
        defaultSort: DEFAULT_SORT,
        path: PATH,
        canonicalize: canonicalize,
        fromLocation: fromLocation,
        fromForm: fromForm,
        href: href,
        replaceState: replaceState,
        pushState: pushState,
        except: except,
        applyToForm: applyToForm,
        navigate: navigate,
    };
})();

window.CatalogUrl = CatalogUrl;

/**
 * Live catalog results — fetch the HTML fragment and swap #catalogResults
 * without a full page reload. URL stays the source of truth (Phase 2).
 * Phase 5 polishes busy copy, scroll, chrome sync, and redundant fetches.
 */
const CatalogLive = (function () {
    let abortController = null;
    let requestSeq = 0;
    let lastAppliedQuery = null;

    function resultsEndpoint(params) {
        const base = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.results)
            || '/advertiser/catalog/results';
        const qs = params && params.toString ? params.toString() : '';
        return qs ? (base + '?' + qs) : base;
    }

    function bulkDealsEndpoint(params) {
        const base = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.bulkDeals)
            || '/advertiser/catalog/bulk-deals';
        // Bulk follows Catalog country= (and blacklist_filter) — drop page noise.
        const next = new URLSearchParams();
        if (params) {
            const country = params.get('country');
            if (country) next.set('country', country);
            const blacklist = params.get('blacklist_filter');
            if (blacklist) next.set('blacklist_filter', blacklist);
        }
        const qs = next.toString();
        return qs ? (base + '?' + qs) : base;
    }

    function bulkFilterKey(params) {
        if (!params) return '||';
        return String(params.get('country') || '')
            + '|' + String(params.get('blacklist_filter') || '')
            + '|' + String(params.get('bulk_deals') || '');
    }

    let bulkAbortController = null;
    let lastBulkFilterKey = null;

    /**
     * Refresh #catalogBulkHost so the rail tracks country= with live results.
     * Option 2: when More → Bulk deals only is on, clear the rail (table is
     * already bulk-only). Uses its own AbortController so the results 15s
     * timeout cannot leave a half-updated host after results already swapped.
     */
    function refreshBulkDeals(params, seq) {
        const host = document.getElementById('catalogBulkHost');
        if (!host) return Promise.resolve();

        const filterKey = bulkFilterKey(params);

        // Option 2 — listing is already bulk-only; hide the Spendable slideshow.
        if (params && params.get('bulk_deals') === '1') {
            if (bulkAbortController) {
                try { bulkAbortController.abort(); } catch (err) { /* ignore */ }
            }
            if (typeof window.destroyBulkDealRail === 'function') {
                window.destroyBulkDealRail();
            }
            host.innerHTML = '';
            lastBulkFilterKey = filterKey;
            return Promise.resolve();
        }

        if (!window.fetch || !(CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.bulkDeals)) {
            return Promise.resolve();
        }

        if (bulkAbortController) {
            try { bulkAbortController.abort(); } catch (err) { /* ignore */ }
        }
        bulkAbortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const localController = bulkAbortController;

        const fetchOpts = {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };
        if (localController) fetchOpts.signal = localController.signal;

        return fetch(bulkDealsEndpoint(params), fetchOpts)
            .then(function (res) {
                if (!res.ok) throw new Error('Catalog bulk deals HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                if (seq !== requestSeq) return;
                if (typeof window.destroyBulkDealRail === 'function') {
                    window.destroyBulkDealRail();
                }
                host.innerHTML = String(html || '').trim();
                lastBulkFilterKey = filterKey;
                if (typeof window.initBulkDealRail === 'function') {
                    window.initBulkDealRail();
                }
            })
            .catch(function (err) {
                // Newer bulk refresh aborted us — leave the in-flight winner alone.
                if (err && err.name === 'AbortError') return;
                if (seq !== requestSeq) return;
                // Transient error with the same country/blacklist: keep the painted rail.
                if (lastBulkFilterKey !== null && lastBulkFilterKey === filterKey) return;
                // Filter changed (or never painted) — prefer empty over the previous country.
                if (typeof window.destroyBulkDealRail === 'function') {
                    window.destroyBulkDealRail();
                }
                host.innerHTML = '';
            });
    }

    function syncConfigFlags(params) {
        if (!window.CatalogConfig) return;
        CatalogConfig.favoritesFilter = params.get('favorites_filter') === '1';
        CatalogConfig.blacklistFilter = params.get('blacklist_filter') === '1';
        // Live restore: keep categoryParam on the `|` wire format.
        var rawCategory = params.get('category') || '';
        CatalogConfig.categoryParam = (typeof CatalogCategoryParam !== 'undefined')
            ? CatalogCategoryParam.canonicalize(rawCategory, CatalogConfig.categoryNames)
            : rawCategory;
        CatalogConfig.countryParam = params.get('country') || '';
        CatalogConfig.languageParam = params.get('language') || '';
    }

    function announceResults(total, first, last, card) {
        const status = document.getElementById('catalogLiveStatus');
        if (!status) return;
        if (total > 0 && first > 0) {
            status.textContent = 'Showing ' + first + ' to ' + last + ' of ' + total
                + (total === 1 ? ' site' : ' sites');
        } else {
            // Prefer fragment copy (Phase 6 niche/country empty headlines).
            status.textContent = (card && card.getAttribute('data-status-announce'))
                || (card && card.getAttribute('data-status-text'))
                || 'No sites match your filters';
        }
    }

    function syncResultsCount(card) {
        const el = document.getElementById('catalogResultsCount');
        if (!el || !card) return;
        const total = parseInt(card.getAttribute('data-result-total') || '0', 10) || 0;
        const first = parseInt(card.getAttribute('data-first-item') || '0', 10) || 0;
        const last = parseInt(card.getAttribute('data-last-item') || '0', 10) || 0;
        if (total > 0 && first > 0) {
            el.innerHTML = 'Showing <strong class="text-dark">' + first + '–' + last
                + '</strong> of <strong class="text-dark">' + total.toLocaleString()
                + '</strong> ' + (total === 1 ? 'site' : 'sites');
        } else {
            // Keep Phase 6 empty-status wording after live fragment swap.
            el.textContent = card.getAttribute('data-status-text') || 'No sites match your filters';
        }

        const countEl = document.querySelector('.catalog-inventory-teaser strong.text-dark');
        if (countEl) {
            countEl.textContent = total.toLocaleString();
        }

        announceResults(total, first, last, card);
    }

    function syncSuggestButtons(params) {
        const search = params.get('search') || '';
        document.querySelectorAll('.btn-suggest-website').forEach(function (btn) {
            btn.setAttribute('data-search', search);
        });
    }

    function busyLabelFor(options) {
        if (options && options.busyLabel) return options.busyLabel;
        const intent = (options && options.intent) || 'filter';
        if (intent === 'search') return 'Searching…';
        if (intent === 'page') return 'Loading page…';
        return 'Updating results…';
    }

    function prefersReducedMotion() {
        return !!(window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    /**
     * Soft-scroll the results chrome into view after intentional changes
     * (filter apply / pagination), not while the user is typing.
     */
    function maybeScrollResults(options) {
        options = options || {};
        if (options.history === 'none') return;
        if (options.history === 'replace' && options.intent !== 'page') return;

        const target = document.querySelector('.catalog-results-bar')
            || document.getElementById('catalogResults');
        if (!target || typeof target.scrollIntoView !== 'function') return;

        const top = target.getBoundingClientRect().top;
        // Already near the top of the viewport — leave the page alone.
        if (top >= 0 && top < 140) return;

        target.scrollIntoView({
            block: 'start',
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
    }

    function chipDefs(params) {
        const chips = [];
        if (params.get('site')) chips.push({ label: 'Recommended site', params: ['site'] });
        if (params.get('search')) chips.push({ label: 'Search: ' + params.get('search'), params: ['search'] });
        // One named chip per niche — × rebuilds category= without that niche.
        if (params.get('category')) {
            var niches = (typeof CatalogCategoryParam !== 'undefined')
                ? CatalogCategoryParam.split(params.get('category'), (window.CatalogConfig && CatalogConfig.categoryNames) || [])
                : String(params.get('category') || '').split('|').filter(Boolean);
            niches.forEach(function (niche) {
                chips.push({ label: niche, categoryRemove: niche, params: [] });
            });
        }
        if (params.get('country')) chips.push({ label: 'Country', params: ['country'] });
        if (params.get('price_min') || params.get('price_max')) chips.push({ label: 'Price', params: ['price_min', 'price_max'] });
        if (params.get('language')) chips.push({ label: 'Language', params: ['language'] });
        if (params.get('sponsored') === '1') chips.push({ label: 'Sponsored', params: ['sponsored'] });
        if (params.get('favorites_filter') === '1') chips.push({ label: 'Favorites', params: ['favorites_filter'] });
        if (params.get('blacklist_filter') === '1') chips.push({ label: 'Blacklist', params: ['blacklist_filter'] });
        if (params.get('bulk_deals') === '1') chips.push({ label: 'Bulk deals', params: ['bulk_deals'] });
        if (params.get('da_min') || params.get('da_max')) chips.push({ label: 'DA (Domain Authority)', params: ['da_min', 'da_max'] });
        if (params.get('dr_min') || params.get('dr_max')) chips.push({ label: 'DR (Domain Rating)', params: ['dr_min', 'dr_max'] });
        if (params.get('traffic_min') || params.get('traffic_max')) chips.push({ label: 'Traffic', params: ['traffic_min', 'traffic_max'] });
        if (params.get('new_badge') === '1') chips.push({ label: 'New sites', params: ['new_badge'] });
        if (params.get('on_sale') === '1') chips.push({ label: 'On sale', params: ['on_sale'] });
        if (params.get('quality') === '1') chips.push({ label: 'Quality bar (DA/DR/traffic)', params: ['quality'] });
        if (params.get('rating_min')) chips.push({ label: 'Min rating ' + params.get('rating_min') + '+', params: ['rating_min'] });
        if (params.get('has_completions') === '1') chips.push({ label: 'Has completions', params: ['has_completions'] });
        if (params.get('per_page') && params.get('per_page') !== '20') {
            chips.push({ label: params.get('per_page') + ' per page', params: ['per_page'] });
        }
        return chips;
    }

    function paramsWithoutCategoryNiche(params, niche) {
        const next = new URLSearchParams(params.toString ? params.toString() : String(params || ''));
        const raw = next.get('category') || '';
        const names = (window.CatalogConfig && CatalogConfig.categoryNames) || [];
        const remaining = (typeof CatalogCategoryParam !== 'undefined'
            ? CatalogCategoryParam.split(raw, names)
            : raw.split('|').filter(Boolean)
        ).filter(function (token) {
            return String(token).toLowerCase() !== String(niche || '').toLowerCase();
        });
        if (remaining.length) {
            next.set(
                'category',
                typeof CatalogCategoryParam !== 'undefined'
                    ? CatalogCategoryParam.join(remaining)
                    : remaining.join('|')
            );
        } else {
            next.delete('category');
        }
        next.delete('page');
        return CatalogUrl.canonicalize(next);
    }

    function syncFilterChips(params) {
        const host = document.getElementById('catalogActiveFiltersHost');
        if (!host) return;
        const chips = chipDefs(params);
        if (!chips.length) {
            host.innerHTML = '';
            return;
        }
        let html = '<div class="d-flex flex-wrap align-items-center gap-2 mt-3" id="activeFilterChips">'
            + '<span class="small text-muted me-1">Active:</span>';
        chips.forEach(function (chip) {
            const hrefParams = chip.categoryRemove
                ? paramsWithoutCategoryNiche(params, chip.categoryRemove)
                : CatalogUrl.except(params, chip.params || [], true);
            const href = CatalogUrl.href(hrefParams);
            html += '<span class="badge rounded-pill filter-chip">'
                + catalogEscapeHtml(chip.label)
                + '<a href="' + catalogEscapeHtml(href) + '" class="filter-chip__remove" aria-label="Remove filter: '
                + catalogEscapeHtml(chip.label) + '" title="Remove this filter">&times;</a></span>';
        });
        html += '<a href="' + catalogEscapeHtml(CatalogUrl.path) + '" class="small ms-1 catalog-clear-all">Clear all</a></div>';
        host.innerHTML = html;
    }

    function syncMoreFiltersBadge(params) {
        const btn = document.getElementById('toggleMoreFiltersBtn');
        if (!btn) return;
        const moreKeys = [
            'sponsored', 'favorites_filter', 'blacklist_filter', 'bulk_deals',
            'da_min', 'da_max', 'dr_min', 'dr_max',
            'traffic_min', 'traffic_max', 'new_badge', 'on_sale', 'quality',
            'rating_min', 'has_completions',
        ];
        let count = 0;
        moreKeys.forEach(function (key) {
            const value = params.get(key);
            if (value != null && String(value).trim() !== '') count++;
        });
        let badge = btn.querySelector('[data-more-filters-count]');
        if (count === 0) {
            if (badge) badge.remove();
            return;
        }
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'badge rounded-pill ms-1';
            badge.setAttribute('data-more-filters-count', '1');
            badge.style.background = 'var(--brand-primary-bg,#e6f5f5)';
            badge.style.color = 'var(--brand-primary,#1a585e)';
            badge.style.border = '1px solid var(--brand-primary-border,#b8e4e4)';
            btn.appendChild(badge);
        }
        badge.textContent = String(count);
    }

    function applyResultsHtml(html) {
        const wrap = document.createElement('div');
        wrap.innerHTML = String(html || '').trim();
        const next = wrap.querySelector('#catalogResults');
        const current = document.getElementById('catalogResults');
        if (!current || !next) return null;
        // Carry the height lock onto the new card until clearCatalogResultsBusy.
        if (current.dataset.busyMinHeight) {
            next.dataset.busyMinHeight = current.dataset.busyMinHeight;
            next.style.minHeight = current.dataset.busyMinHeight + 'px';
        }
        current.replaceWith(next);
        return next;
    }

    function afterSwap(card, params, options) {
        syncConfigFlags(params);
        syncResultsCount(card);
        syncFilterChips(params);
        syncMoreFiltersBadge(params);
        syncSuggestButtons(params);
        if (typeof updateButtonStates === 'function') updateButtonStates();
        if (typeof syncDefaultHomepagePrices === 'function') syncDefaultHomepagePrices();
        if (typeof initCatalogExpandPreviewZoom === 'function') {
            initCatalogExpandPreviewZoom(card || document.getElementById('catalogResults'));
        }
        if (window.GlassTip && typeof window.GlassTip.enhance === 'function') {
            window.GlassTip.enhance(card || document.getElementById('catalogResults'));
        }
        // Re-hide blacklisted rows on the main catalog after a fresh paint.
        if (!CatalogConfig.blacklistFilter && typeof hideCatalogSite === 'function') {
            document.querySelectorAll('.site-row[data-id], .catalog-mobile-card[data-id]').forEach(function (el) {
                const id = parseInt(el.dataset.id, 10);
                if (blacklist.includes(id)) hideCatalogSite(id);
            });
        }
        // Keep preset chip active states honest after a live apply / popstate.
        if (typeof markActivePreset === 'function') {
            document.querySelectorAll('.filter-presets').forEach(function (group) {
                markActivePreset(group);
            });
        }
        clearCatalogResultsBusy();
        maybeScrollResults(options);
        // After an intentional page change, park focus on the results card so
        // keyboard / screen-reader users are not left on a detached control.
        if (options && options.intent === 'page' && card && typeof card.focus === 'function') {
            try {
                card.focus({ preventScroll: true });
            } catch (err) {
                try { card.focus(); } catch (err2) { /* ignore */ }
            }
        }
    }

    /**
     * @param {{
     *   params?: URLSearchParams,
     *   keepPage?: boolean,
     *   history?: 'replace'|'push'|'none',
     *   fromLocation?: boolean,
     *   intent?: 'search'|'filter'|'page',
     *   busyLabel?: string,
     *   force?: boolean
     * }} options
     */
    function apply(options) {
        options = options || {};
        if (options.fromLocation) {
            CatalogUrl.applyToForm(CatalogUrl.fromLocation());
        }

        const params = options.params
            || CatalogUrl.fromForm({ keepPage: !!options.keepPage });

        const historyMode = options.history || 'replace';
        const queryKey = params.toString();
        // Skip a no-op Filter click when the listing already matches the URL.
        // Must run before pushState/replaceState so redundant Apply/Enter does
        // not leave an extra history entry with no fetch.
        if (!options.force && lastAppliedQuery !== null && queryKey === lastAppliedQuery
            && document.getElementById('catalogResults')) {
            syncFilterChips(params);
            syncMoreFiltersBadge(params);
            syncSuggestButtons(params);
            return Promise.resolve();
        }

        if (historyMode === 'push') CatalogUrl.pushState(params);
        else if (historyMode === 'replace') CatalogUrl.replaceState(params);

        if (! window.fetch
            || ! (CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.results)
            || CatalogConfig.liveSearch === false) {
            // Kill switch / unsupported browser — classic full GET navigation.
            CatalogUrl.navigate({ params: params, replace: historyMode === 'replace' });
            return Promise.resolve();
        }

        if (abortController) {
            try { abortController.abort(); } catch (err) { /* ignore */ }
        }
        // Request-local controller so a late timeout cannot abort a newer apply().
        const thisController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        abortController = thisController;
        const seq = ++requestSeq;
        // Slow / hung fragment fetches must not leave "Updating results…" forever.
        const LIVE_FETCH_TIMEOUT_MS = 15000;
        let timedOut = false;
        let fallbackNavigated = false;
        let timeoutId = setTimeout(function () {
            timedOut = true;
            if (thisController) {
                try { thisController.abort(); } catch (err) { /* ignore */ }
            }
        }, LIVE_FETCH_TIMEOUT_MS);

        markCatalogResultsBusy({ label: busyLabelFor(options) });

        const fetchOpts = {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };
        if (thisController) fetchOpts.signal = thisController.signal;

        return fetch(resultsEndpoint(params), fetchOpts)
            .then(function (res) {
                if (!res.ok) throw new Error('Catalog results HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                if (seq !== requestSeq) return;
                const card = applyResultsHtml(html);
                if (!card) throw new Error('Catalog results markup missing');
                lastAppliedQuery = queryKey;
                afterSwap(card, params, options);
                // Option 1: bulk rail follows Catalog country= — refresh after results.
                // Own AbortController inside refreshBulkDeals (not the results timeout).
                return refreshBulkDeals(params, seq);
            })
            .catch(function (err) {
                // Newer request aborted us — leave its busy state alone.
                if (err && err.name === 'AbortError' && !timedOut) return;
                if (seq !== requestSeq) return;
                // Full-page fallback: navigate() marks busy for the reload — do not
                // clear here or a later finally would fight that veil.
                fallbackNavigated = true;
                CatalogUrl.navigate({ params: params, replace: historyMode === 'replace' });
            })
            .finally(function () {
                clearTimeout(timeoutId);
                if (seq !== requestSeq) return;
                if (fallbackNavigated) return;
                // Safety net only when we stay on this page (success already clears
                // in afterSwap; this covers a stuck veil without a navigation handoff).
                const card = document.getElementById('catalogResults');
                if (card && card.classList.contains('is-busy')) {
                    clearCatalogResultsBusy();
                }
            });
    }

    function applyFromLocation(historyMode) {
        return apply({
            fromLocation: true,
            params: CatalogUrl.fromLocation(),
            history: historyMode || 'none',
            keepPage: true,
            force: true,
        });
    }

    // Seed so the first identical Filter click is a no-op.
    try {
        lastAppliedQuery = CatalogUrl.fromLocation().toString();
    } catch (err) {
        lastAppliedQuery = null;
    }

    return {
        apply: apply,
        applyFromLocation: applyFromLocation,
        syncFilterChips: syncFilterChips,
        syncResultsCount: syncResultsCount,
    };
})();

window.CatalogLive = CatalogLive;

function submitCatalogFilters(options) {
    options = options || {};
    // Callers may pass reason: 'search' (Apply / Enter) or intent: 'search'
    // (live fragment path). Map both onto CatalogLive's intent labels.
    var intent = options.intent || null;
    if (!intent && options.reason === 'search') {
        intent = 'search';
    }
    if (!intent) {
        intent = 'filter';
    }
    // Live fragment fetch — URL is built from the allowlisted form state.
    CatalogLive.apply({
        history: options.replace ? 'replace' : 'push',
        keepPage: !!options.keepPage,
        intent: intent,
        force: !!options.force,
        busyLabel: options.busyLabel,
    });
}

/**
 * Debounced live apply for filter fields that fire often (multi-select ticks,
 * range number typing). Intentional one-shots (presets, selects) call
 * submitCatalogFilters() directly instead.
 */
let catalogFilterLiveTimer = null;
const CATALOG_FILTER_LIVE_MS = 350;

function scheduleCatalogFilterLive(options) {
    options = options || {};
    if (catalogFilterLiveTimer) {
        clearTimeout(catalogFilterLiveTimer);
        catalogFilterLiveTimer = null;
    }
    var payload = {
        replace: options.replace !== false,
        intent: options.intent || null,
        reason: options.reason || null,
        busyLabel: options.busyLabel || null,
    };
    if (options.immediate) {
        payload.replace = !!options.replace;
        submitCatalogFilters(payload);
        return;
    }
    catalogFilterLiveTimer = setTimeout(function () {
        catalogFilterLiveTimer = null;
        submitCatalogFilters(payload);
    }, CATALOG_FILTER_LIVE_MS);
}

window.scheduleCatalogFilterLive = scheduleCatalogFilterLive;

// Apply Filters / Enter / debounced search — always sync multi-selects first.
// Search typing updates live result rows (see initCatalogSearchLiveRows).
(function () {
    const applyBtn = document.getElementById('applyFiltersBtn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function (e) {
            // type="submit" also fires native submit; one path only.
            e.preventDefault();
            submitCatalogFilters({ reason: 'search' });
        });
    }

    const form = document.getElementById('filterForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            // Native Enter (and submit buttons) must sync multi-selects first.
            e.preventDefault();
            submitCatalogFilters({ reason: 'search' });
        });
    }

    const sort = document.getElementById('catalogSort');
    if (sort) {
        sort.addEventListener('change', function () {
            submitCatalogFilters({ reason: 'sort' });
        });
    }

    const perPage = document.getElementById('catalogPerPage');
    if (perPage) {
        perPage.addEventListener('change', function () {
            // Page size change always restarts at page 1 (fromForm drops page).
            submitCatalogFilters({ replace: true, intent: 'filter' });
        });
    }

    // More-filters selects + checkbox filters share the live path.
    ['sponsored', 'favorites_filter', 'blacklist_filter'].forEach(function (name) {
        const select = document.querySelector('#filterForm select[name="' + name + '"]');
        if (!select) return;
        select.addEventListener('change', function () {
            submitCatalogFilters();
        });
    });

    const bulkDeals = document.getElementById('bulk_deals');
    if (bulkDeals) {
        bulkDeals.addEventListener('change', function () {
            submitCatalogFilters();
        });
    }

    const newBadge = document.getElementById('new_badge');
    if (newBadge) {
        newBadge.addEventListener('change', function () {
            submitCatalogFilters();
        });
    }

    const onSale = document.getElementById('on_sale');
    if (onSale) {
        onSale.addEventListener('change', function () {
            submitCatalogFilters();
        });
    }

    const qualityGate = document.getElementById('catalogQualityGate');
    if (qualityGate) {
        qualityGate.addEventListener('change', function () {
            submitCatalogFilters();
        });
    }

    const ratingMin = document.getElementById('catalogRatingMin');
    if (ratingMin) {
        ratingMin.addEventListener('change', function () {
            submitCatalogFilters();
        });
    }

    const hasCompletions = document.getElementById('catalogHasCompletions');
    if (hasCompletions) {
        hasCompletions.addEventListener('change', function () {
            submitCatalogFilters();
        });
    }

    // Range / metric number fields — debounce while typing; apply on blur.
    const rangeNames = [
        'price_min', 'price_max',
        'da_min', 'da_max',
        'dr_min', 'dr_max',
        'traffic_min', 'traffic_max',
    ];
    rangeNames.forEach(function (name) {
        const input = document.querySelector('#filterForm [name="' + name + '"]');
        if (!input) return;
        input.addEventListener('input', function () {
            scheduleCatalogFilterLive({ replace: true });
        });
        input.addEventListener('change', function () {
            scheduleCatalogFilterLive({ replace: true, immediate: true });
        });
    });

    // Search: typing updates real catalog rows (live /results), not a suggest dropdown.
    // Enter / Apply still push a history entry. Suggest endpoint stays unused here.
    const searchInput = document.getElementById('catalogSearchInput');
    if (searchInput) {
        initCatalogSearchLiveRows(searchInput);
    }
})();

/**
 * Debounced live catalog search for #catalogSearchInput.
 * Uses shared SlbLiveSearch (Catalog main-search contract sitewide).
 * Empty query → full catalog; < CATALOG_SEARCH_MIN_CHARS (and non-empty) → no fetch.
 * Enter → immediate submit with history push.
 */
function initCatalogSearchLiveRows(searchInput) {
    if (!searchInput || typeof window.SlbLiveSearch === 'undefined') {
        return;
    }

    const status = document.getElementById('catalogSearchStatus');

    function clearStatus() {
        if (status) status.textContent = '';
    }

    function scheduleLiveSearch() {
        // SlbLiveSearch already debounced; flush the catalog live apply now.
        scheduleCatalogFilterLive({ replace: true, intent: 'search', immediate: true });
    }

    window.SlbLiveSearch.init(searchInput, {
        mode: 'event',
        statusEl: status,
        clearBtn: document.getElementById('catalogSearchClear'),
        minChars: CATALOG_SEARCH_MIN_CHARS,
        debounceMs: CATALOG_FILTER_LIVE_MS,
        onSearch: function (detail) {
            clearStatus();
            if (detail.reason === 'enter' || detail.reason === 'clear') {
                if (catalogFilterLiveTimer) {
                    clearTimeout(catalogFilterLiveTimer);
                    catalogFilterLiveTimer = null;
                }
                submitCatalogFilters({ replace: false, intent: 'search', reason: 'search' });
                return;
            }
            scheduleLiveSearch();
        },
    });
}

// Pagination, chip remove, clear-all, reset → live fetch (same query allowlist).
document.addEventListener('click', function (e) {
    const pageLink = e.target.closest('#catalogResults .pagination a.page-link');
    if (pageLink && pageLink.getAttribute('href')) {
        e.preventDefault();
        const url = new URL(pageLink.href, window.location.origin);
        const params = CatalogUrl.canonicalize(url.searchParams);
        CatalogLive.apply({ params: params, history: 'push', keepPage: true, intent: 'page' });
        return;
    }

    const chipRemove = e.target.closest('.filter-chip__remove');
    if (chipRemove && chipRemove.getAttribute('href')) {
        e.preventDefault();
        const url = new URL(chipRemove.href, window.location.origin);
        const params = CatalogUrl.canonicalize(url.searchParams);
        CatalogUrl.applyToForm(params);
        CatalogLive.apply({ params: params, history: 'push', keepPage: true });
        return;
    }

    const clearAll = e.target.closest('a.catalog-clear-all, a.catalog-reset-filters');
    if (clearAll && clearAll.getAttribute('href')) {
        e.preventDefault();
        const empty = CatalogUrl.canonicalize(new URLSearchParams());
        // Preserve wizard chrome when clearing filters inside the guided flow.
        const wizard = CatalogUrl.fromLocation().get('wizard');
        if (wizard) empty.set('wizard', wizard);
        CatalogUrl.applyToForm(empty);
        CatalogLive.apply({ params: empty, history: 'push', keepPage: true });
    }
});

/**
 * Alt+← / Alt+→ page the catalog when focus is inside the catalog surface.
 * Plain arrows stay free for multi-select / text carets.
 */
document.addEventListener('keydown', function (e) {
    if (!e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;

    const catalogRoot = document.querySelector('.catalog-page');
    if (!catalogRoot) return;

    const active = document.activeElement;
    if (active) {
        const tag = (active.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || active.isContentEditable) {
            return;
        }
        if (active.closest && active.closest('.multi-select-dropdown:not(.d-none), .multi-select-wrapper.is-open')) {
            return;
        }
        if (!catalogRoot.contains(active) && active !== document.body && active !== document.documentElement) {
            // Allow when focus is on the results card (may be outside .catalog-page in edge layouts).
            if (!active.closest || !active.closest('#catalogResults')) return;
        }
    }

    const results = document.getElementById('catalogResults');
    if (!results) return;

    const rel = e.key === 'ArrowRight' ? 'next' : 'prev';
    const candidates = results.querySelectorAll('a.page-link[rel="' + rel + '"]');
    let link = null;
    for (let i = 0; i < candidates.length; i++) {
        const el = candidates[i];
        // Skip the Bootstrap-hidden mobile/desktop twin.
        if (el.offsetParent === null && el.getClientRects().length === 0) continue;
        link = el;
        break;
    }
    if (!link || !link.getAttribute('href')) return;

    e.preventDefault();
    link.click();
});

window.addEventListener('popstate', function () {
    if (!document.getElementById('catalogResults')) return;
    CatalogLive.applyFromLocation('none');
});

// Close dropdown when clicking outside. Routed through closeAllMultiDropdowns so
// the trigger's aria-expanded is reset too — it used to stay "true" for the rest
// of the session, so screen readers kept announcing a list that was closed.
document.addEventListener('click', function(event) {
    if (!event.target.closest('.multi-select-wrapper')) {
        closeAllMultiDropdowns();
    }
});

// Initialize multi-selects on page load
initializeMultiSelects();
if (window.CatalogCountryPicker) {
    CatalogCountryPicker.init();
}

/**
 * Escape a value before it goes into markup.
 *
 * Category names, country labels and publisher-defined sensitive-topic keys all
 * reach this file as plain strings and several places build HTML from them.
 */
function catalogEscapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Prefer shared layout toast (partials/app-toast); keep a local fallback for catalog-only pages.
function catalogToast(message, type = 'success', options) {
    if (typeof window.showAppToast === 'function') {
        return window.showAppToast(message, type, options);
    }
    if (typeof window.showToast === 'function' && window.showToast !== catalogToast) {
        return window.showToast(message, type, options);
    }
    if (typeof window.slbAlert === 'function') {
        window.slbAlert({ icon: type === 'error' ? 'error' : 'success', title: message });
        return;
    }
    console.warn(message);
}

// Cart mutations live on window.addToCart from the advertiser layout.
// Do not declare a top-level function addToCart / updateCartBadge — classic
// scripts hoist those onto window and recurse until the Buy button crashes.

/**
 * Round money the same way PHP round(..., 2) does for catalog prices.
 */
function catalogRoundMoney(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Math.round((n + Number.EPSILON) * 100) / 100;
}

/**
 * Pick the element the shopper can actually see.
 *
 * The table and the card list both render every site, and only one of them is
 * displayed at a given width, so reading "the first match" could return the
 * layout that is hidden.
 */
function catalogVisibleFirst(nodes) {
    const list = Array.prototype.slice.call(nodes);
    return list.find(function (el) { return el.offsetParent !== null; }) || list[0] || null;
}

/**
 * Active custom-discount % for a catalog site (0 when none).
 * Prefer the sensitive-price group, then the Buy button.
 */
function catalogDiscountPercentForSite(siteId) {
    const id = String(siteId);
    const group = catalogVisibleFirst(document.querySelectorAll(
        '.sensitive-prices-group[data-site-id="' + id + '"]'
    ));
    if (group && group.dataset.discountPercent != null && group.dataset.discountPercent !== '') {
        const fromGroup = parseFloat(group.dataset.discountPercent);
        if (Number.isFinite(fromGroup) && fromGroup > 0) return fromGroup;
    }

    const buy = document.querySelector('.buy-now[data-id="' + id + '"]');
    if (buy && buy.dataset.discountPercent != null && buy.dataset.discountPercent !== '') {
        const fromBuy = parseFloat(buy.dataset.discountPercent);
        if (Number.isFinite(fromBuy) && fromBuy > 0) return fromBuy;
    }

    return 0;
}

/**
 * Publisher payout floor for a catalog site (entered base + selected add-on).
 * data-publisher-price is the raw listing price before the portal fee markup.
 */
function catalogPublisherPayoutFloor(siteId, additionalPrice) {
    const id = String(siteId);
    const buy = document.querySelector('.buy-now[data-id="' + id + '"]');
    const group = catalogVisibleFirst(document.querySelectorAll(
        '.sensitive-prices-group[data-site-id="' + id + '"]'
    ));
    const raw = (group && group.dataset.publisherPrice != null && group.dataset.publisherPrice !== '')
        ? group.dataset.publisherPrice
        : (buy && buy.dataset.publisherPrice != null ? buy.dataset.publisherPrice : null);
    const publisherBase = parseFloat(raw);
    if (!Number.isFinite(publisherBase)) return null;
    const addOn = Number(additionalPrice);
    const extra = Number.isFinite(addOn) && addOn > 0 ? addOn : 0;
    return catalogRoundMoney(publisherBase + extra);
}

/**
 * Apply a percent discount to a list total.
 * Mirrors CartPricingService: discount_amount = round(list * pct/100, 2),
 * then floor at publisherPayout so discounts never push advertiser pay below
 * what the publisher will be credited (fee-absorbing discounts only).
 */
function catalogApplyDiscount(listTotal, discountPercent, publisherPayoutFloor) {
    const list = catalogRoundMoney(listTotal);
    const pct = Number(discountPercent);
    let total = list;
    if (pct > 0) {
        const discountAmount = catalogRoundMoney(list * (pct / 100));
        total = Math.max(0, catalogRoundMoney(list - discountAmount));
    }
    const floor = Number(publisherPayoutFloor);
    if (Number.isFinite(floor) && floor > 0 && total < floor) {
        total = catalogRoundMoney(floor);
    }
    return total;
}

/**
 * Read the checked sensitive-topic radio for a catalog site.
 * DOM is the source of truth (avoids stale in-memory maps after re-renders).
 *
 * Prices: data-base-price / data-additional-price are list (pre-discount).
 * totalPrice is what the advertiser pays after any active custom discount —
 * the same (base + add-on) × (1 − %) formula used in CartPricingService.
 */
function getSelectedSensitiveForSite(siteId) {
    const id = String(siteId);
    const discountPercent = catalogDiscountPercentForSite(id);
    // Matched on data-site-id, not on the radio name: the table row and the card
    // are separate groups so each keeps its own visible default, and the one on
    // screen is the one that decides the price.
    const checked = catalogVisibleFirst(document.querySelectorAll(
        'input.sensitive-price-checkbox[data-site-id="' + id + '"]:checked'
    ));
    if (!checked) {
        return {
            type: null,
            additionalPrice: 0,
            totalPrice: null,
            basePrice: null,
            listTotal: null,
            discountPercent: discountPercent,
        };
    }

    const group = checked.closest('.sensitive-prices-group');
    const basePrice = parseFloat(group && group.dataset.basePrice != null
        ? group.dataset.basePrice
        : (checked.dataset.basePrice || '0')) || 0;
    const type = (checked.dataset.type || '').trim();
    const additionalPrice = parseFloat(checked.dataset.additionalPrice);
    const addOn = Number.isFinite(additionalPrice) ? additionalPrice : 0;
    const listTotal = catalogRoundMoney(basePrice + (addOn > 0 ? addOn : 0));
    const floor = catalogPublisherPayoutFloor(id, addOn > 0 ? addOn : 0);
    const totalPrice = catalogApplyDiscount(listTotal, discountPercent, floor);

    if (!type || type === 'none' || !(addOn > 0)) {
        return {
            type: null,
            additionalPrice: 0,
            totalPrice: totalPrice,
            basePrice: basePrice,
            listTotal: catalogRoundMoney(basePrice),
            discountPercent: discountPercent,
        };
    }

    return {
        type: type,
        additionalPrice: addOn,
        totalPrice: totalPrice,
        basePrice: basePrice,
        listTotal: listTotal,
        discountPercent: discountPercent,
    };
}

/**
 * Read the checked homepage-placement radio for a catalog site.
 * When no radios exist the site does not offer homepage — return explicit:false
 * so Buy omits homepage_days and the server applies its default.
 */
function getSelectedHomepageForSite(siteId) {
    const id = String(siteId);
    const checked = catalogVisibleFirst(document.querySelectorAll(
        'input.homepage-placement-radio[data-site-id="' + id + '"]:checked'
    ));
    if (!checked) {
        return { days: null, price: 0, explicit: false };
    }
    const daysRaw = (checked.dataset.days || checked.value || '').trim();
    if (!daysRaw || daysRaw === 'none') {
        return { days: null, price: 0, explicit: true };
    }
    const fee = parseFloat(checked.dataset.price);
    return {
        days: parseInt(daysRaw, 10),
        price: Number.isFinite(fee) ? catalogRoundMoney(fee) : 0,
        explicit: true,
    };
}

// Update UI for favorites and blacklist (quiet icon actions)
function updateButtonStates() {
    document.querySelectorAll('.favorite-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        const icon = btn.querySelector('i');
        if (favorites.includes(id)) {
            btn.classList.add('is-active');
            if (icon) { icon.classList.remove('fa-regular'); icon.classList.add('fa-solid'); }
            btn.title = 'Remove from Favorites';
            btn.setAttribute('aria-label', 'Remove from favorites');
        } else {
            btn.classList.remove('is-active');
            if (icon) { icon.classList.remove('fa-solid'); icon.classList.add('fa-regular'); }
            btn.title = 'Add to Favorites';
            btn.setAttribute('aria-label', 'Add to favorites');
        }
    });

    document.querySelectorAll('.blacklist-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        if (blacklist.includes(id)) {
            btn.classList.add('is-active');
            btn.title = 'Remove from Blacklist';
            btn.setAttribute('aria-label', 'Remove from blacklist');
        } else {
            btn.classList.remove('is-active');
            btn.title = 'Blacklist Site';
            btn.setAttribute('aria-label', 'Blacklist site');
        }
        btn.style.backgroundColor = '';
        btn.style.color = '';
    });
}

/**
 * Find the price readouts that belong to a Buy button.
 *
 * The price used to sit inside the button, so the CTA read "Buy €113.00 €90.40".
 * It now has its own block beside the button; look there first and fall back to
 * the button so a listing rendered the old way still updates.
 */
function catalogPriceDisplaysFor(buyButton) {
    const scope = buyButton.closest('.catalog-card-buy, .catalog-row-actions')
        || buyButton.parentElement
        || buyButton;
    const block = scope.querySelector('.catalog-price') || buyButton;

    return {
        pay: block.querySelector('.base-price-display'),
        list: block.querySelector('.list-price-display'),
    };
}

// Update buy button price display (desktop table + mobile cards share data-id).
/**
 * Effective % saved after the publisher-payout floor (matches CartPricingService).
 * Nominal configured % is only for re-applying offer math — never for labels.
 */
function catalogEffectiveDiscountPercent(listTotal, payTotal) {
    const list = catalogRoundMoney(listTotal);
    const pay = catalogRoundMoney(payTotal);
    if (!(list > 0) || !(pay < list)) {
        return 0;
    }
    return catalogRoundMoney(((list - pay) / list) * 100);
}

function catalogFormatPercentLabel(percent) {
    const n = Number(percent);
    if (!Number.isFinite(n) || n <= 0) {
        return '';
    }
    return String(catalogRoundMoney(n)).replace(/\.0+$/, '');
}

function updateBuyButtonPrice(siteId, basePrice, additionalPrice = 0, sensitiveType = null, discountPercent = 0, homepageFee = 0) {
    const id = String(siteId);
    const base = parseFloat(basePrice);
    const addOn = parseFloat(additionalPrice);
    const safeBase = Number.isFinite(base) ? base : 0;
    const safeAdd = Number.isFinite(addOn) && addOn > 0 ? addOn : 0;
    const pct = Number.isFinite(parseFloat(discountPercent)) ? parseFloat(discountPercent) : 0;
    const homeRaw = parseFloat(homepageFee);
    const homeFee = Number.isFinite(homeRaw) && homeRaw > 0 ? catalogRoundMoney(homeRaw) : 0;
    const listTotal = catalogRoundMoney(safeBase + safeAdd);
    const floor = catalogPublisherPayoutFloor(id, safeAdd);
    const articlePay = catalogApplyDiscount(listTotal, pct, floor);
    // Homepage fee is never discounted — added at full price after article math.
    const totalPrice = catalogRoundMoney(articlePay + homeFee);
    const displayList = catalogRoundMoney(listTotal + homeFee);
    const effectivePct = catalogEffectiveDiscountPercent(listTotal, articlePay);
    const offerLabel = effectivePct > 0 ? (catalogFormatPercentLabel(effectivePct) + '% off') : '';

    document.querySelectorAll('.buy-now[data-id="' + id + '"]').forEach(function (buyButton) {
        const price = catalogPriceDisplaysFor(buyButton);

        if (price.pay) {
            price.pay.textContent = '€' + totalPrice.toFixed(2);
        }

        // Strike-through shows the pre-discount list total when a sale is active.
        if (price.list) {
            price.list.textContent = '€' + displayList.toFixed(2);
            price.list.hidden = !(pct > 0);
        }

        const offerText = buyButton.closest('.catalog-card-buy, .catalog-row-actions')
            ?.querySelector('.catalog-price__offer-text');
        const offerWrap = buyButton.closest('.catalog-card-buy, .catalog-row-actions')
            ?.querySelector('[data-catalog-offer-pct]');
        if (offerText && offerWrap) {
            if (offerLabel) {
                offerText.textContent = offerLabel;
                offerWrap.hidden = false;
            } else {
                offerWrap.hidden = true;
            }
        }

        buyButton.dataset.currentAdditionalPrice = String(safeAdd);
        buyButton.dataset.homepageFee = String(homeFee);
        if (sensitiveType) {
            buyButton.dataset.sensitiveType = sensitiveType;
            buyButton.setAttribute('aria-label',
                'Buy placement' + (buyButton.dataset.name ? ' for ' + buyButton.dataset.name : '')
                + ' with ' + sensitiveType + ' add-on, €' + totalPrice.toFixed(2));
        } else {
            delete buyButton.dataset.sensitiveType;
            if (buyButton.dataset.name) {
                buyButton.setAttribute('aria-label', 'Buy placement for ' + buyButton.dataset.name);
            }
        }
    });
}

function syncSensitiveSelectionUi(siteId) {
    const selected = getSelectedSensitiveForSite(siteId);
    const homepage = getSelectedHomepageForSite(siteId);
    const basePrice = selected.basePrice != null
        ? selected.basePrice
        : (parseFloat((document.querySelector(
            '.sensitive-prices-group[data-site-id="' + String(siteId) + '"]'
        ) || {}).dataset?.basePrice) || 0);
    const discountPercent = selected.discountPercent != null
        ? selected.discountPercent
        : catalogDiscountPercentForSite(siteId);
    const articlePay = selected.totalPrice != null
        ? selected.totalPrice
        : catalogApplyDiscount(
            catalogRoundMoney(basePrice + (selected.additionalPrice || 0)),
            discountPercent,
            catalogPublisherPayoutFloor(siteId, selected.additionalPrice || 0)
        );
    const homeFee = homepage.price || 0;
    const payTotal = catalogRoundMoney(Number(articlePay) + homeFee);

    updateBuyButtonPrice(
        siteId,
        basePrice,
        selected.additionalPrice,
        selected.type,
        discountPercent,
        homeFee
    );

    let infoHtml;
    const listForLabel = Number(selected.listTotal != null ? selected.listTotal : (basePrice + (selected.additionalPrice || 0)));
    const effectiveOfferPct = catalogEffectiveDiscountPercent(listForLabel, articlePay);
    const homeNote = homepage.days
        ? (' · Homepage ' + homepage.days + 'd'
            + (homeFee > 0 ? (' +€' + homeFee.toFixed(2)) : ' Free'))
        : '';

    if (selected.type && selected.additionalPrice > 0) {
        infoHtml =
            '<small class="text-muted">List price: <strong>€'
            + listForLabel.toFixed(2)
            + '</strong></small><br>'
            + '<small class="text-success">Selected: <strong>' + catalogEscapeHtml(selected.type)
            + '</strong> — You pay: <strong>€' + Number(payTotal).toFixed(2)
            + '</strong> (+€' + selected.additionalPrice.toFixed(2);
        if (effectiveOfferPct > 0) {
            infoHtml += ', includes −'
                + catalogFormatPercentLabel(effectiveOfferPct)
                + '% offer';
        }
        infoHtml += homeNote + ')</small>';
            } else if (discountPercent > 0 || homeFee > 0 || homepage.days) {
        infoHtml =
            '<small class="text-muted">You pay: <strong>€' + Number(payTotal).toFixed(2)
            + '</strong>';
        if (discountPercent > 0) {
            infoHtml += ' <span class="text-decoration-line-through">€'
                + catalogRoundMoney(listForLabel + homeFee).toFixed(2) + '</span> (offer price)';
        } else if (homepage.days) {
            infoHtml += homeNote;
        } else {
            infoHtml += ' (Base price)';
        }
        infoHtml += '</small>';
    } else {
        infoHtml =
            '<small class="text-muted">You pay: <strong>€' + Number(basePrice).toFixed(2)
            + '</strong> (Base price)</small>';
    }

    ['price-info-' + siteId, 'price-info-mobile-' + siteId].forEach(function (infoId) {
        const priceInfoDiv = document.getElementById(infoId);
        if (priceInfoDiv) {
            priceInfoDiv.innerHTML = infoHtml;
        }
    });
}

/**
 * Promote deferred expand screenshots on first open.
 * Expand panels start display:none / hidden; Safari often never loads
 * loading=lazy images in that state. Blade already uses eager+src when a
 * capture exists; this covers data-src deferred imgs and card panels.
 * Missing assets stay on the Blade placeholder — nothing to hydrate.
 */
function hydrateExpandScreenshots(root) {
    if (!root) return;

    root.querySelectorAll('img.catalog-deferred-preview[data-src]').forEach(function (img) {
        const deferred = img.getAttribute('data-src');
        if (!deferred) return;
        // Always promote data-src (Blade may use a 1x1 placeholder src).
        img.setAttribute('data-preview-i', '0');
        img.setAttribute('src', deferred);
        img.setAttribute('loading', 'eager');
        img.setAttribute('decoding', 'async');
        img.removeAttribute('data-src');
        // Re-bind after any premature placeholder onerror nulled the handler.
        img.onerror = function () {
            if (window.catalogSitePreviewOnError) {
                window.catalogSitePreviewOnError(img);
            }
        };
        const zoom = img.closest('.site-preview-zoom');
        if (zoom) {
            zoom.classList.remove('is-broken');
            const fallback = zoom.nextElementSibling;
            if (fallback && fallback.classList.contains('site-preview-fallback')) {
                fallback.classList.add('d-none');
                fallback.classList.remove('d-inline-flex');
                fallback.setAttribute('aria-hidden', 'true');
            }
        }
    });
}

/**
 * Free homepage options can be pre-checked in Blade. Sync Buy totals for those
 * sites so the header price matches before the first radio change — including
 * after live results HTML swaps (DOMContentLoaded only runs once).
 */
function syncDefaultHomepagePrices() {
    const seen = {};
    document.querySelectorAll('.homepage-placement-radio:checked').forEach(function (radio) {
        const siteId = radio.dataset.siteId
            || (radio.closest('.homepage-placement-group') || {}).dataset?.siteId;
        if (!siteId || seen[siteId]) return;
        if (String(radio.value) === 'none' || String(radio.dataset.days) === 'none') return;
        seen[siteId] = true;
        syncSensitiveSelectionUi(siteId);
    });
}

/**
 * Keep table expand and card Details in the same open/closed voice:
 * label text + chevron rotation on the icon (not the whole button).
 */
function setCatalogDetailsToggleState(toggle, open) {
    if (!toggle) return;

    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.classList.remove('rotate-arrow');

    const label = toggle.querySelector('.catalog-details-toggle__label');
    if (label) label.textContent = open ? 'Hide details' : 'Details';

    const icon = toggle.querySelector('i');
    if (icon) icon.classList.toggle('rotate-arrow', !!open);
}

function toggleCardDetails(toggle) {
    if (!toggle) return;

    const panel = document.getElementById(toggle.dataset.cardDetails || '');
    if (!panel) return;

    const willOpen = panel.hidden;
    panel.hidden = !willOpen;
    setCatalogDetailsToggleState(toggle, willOpen);
    if (willOpen) {
        hydrateExpandScreenshots(panel);
        if (typeof initCatalogExpandPreviewZoom === 'function') {
            initCatalogExpandPreviewZoom(panel);
        }
        const siteId = (toggle.dataset.cardDetails || '').replace('card-details-', '');
        if (siteId) {
            syncSensitiveSelectionUi(siteId);
        }
    }
}

// Card "Details" disclosure — the content the table keeps in its expand row.
document.addEventListener('click', function (e) {
    const toggle = e.target.closest('.catalog-card-details-toggle');
    if (!toggle) return;

    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') {
        e.stopImmediatePropagation();
    }

    toggleCardDetails(toggle);
});

/**
 * Save favourites and report whether it stuck.
 *
 * The heart flips before the request finishes, so a failed save used to leave
 * the site looking saved when it was not. Resolves false on failure so the
 * caller can put the previous state back.
 */
function saveFavorites() {
    return fetch(CatalogConfig.routes.favoritesSave, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CatalogConfig.csrfToken
        },
        body: JSON.stringify({ favorites: favorites })
    }).then(async (res) => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || data.error || 'Could not save favorites');
        }
        return true;
    }).catch(err => {
        console.error('Error saving favorites:', err);
        catalogToast(err.message || 'Could not save favorites', 'error');
        return false;
    });
}

// Save blacklist to database
/** Same contract as saveFavorites: false means the change did not persist. */
function saveBlacklist() {
    return fetch(CatalogConfig.routes.blacklistSave, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CatalogConfig.csrfToken
        },
        body: JSON.stringify({ blacklist: blacklist })
    }).then(async (res) => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || data.error || 'Could not save blacklist');
        }
        return true;
    }).catch(err => {
        console.error('Error saving blacklist:', err);
        catalogToast(err.message || 'Could not save blacklist', 'error');
        return false;
    });
}

function hideCatalogSite(siteId) {
    document.querySelectorAll(`.site-row[data-id="${siteId}"], .catalog-mobile-card[data-id="${siteId}"]`).forEach((el) => {
        el.style.transition = 'opacity 0.3s ease';
        el.style.opacity = '0';
        setTimeout(() => { el.style.display = 'none'; }, 300);
    });
    const expandedRow = document.querySelector('.expanded-row-' + siteId);
    if (expandedRow) {
        expandedRow.style.transition = 'opacity 0.3s ease';
        expandedRow.style.opacity = '0';
        setTimeout(() => { expandedRow.style.display = 'none'; }, 300);
    }
}

function showCatalogSite(siteId) {
    document.querySelectorAll(`.site-row[data-id="${siteId}"], .catalog-mobile-card[data-id="${siteId}"]`).forEach((el) => {
        el.style.display = '';
        el.style.opacity = '';
        el.style.transition = '';
        el.classList.remove('blacklisted-row', 'is-blacklisted');
    });
    const expandedRow = document.querySelector('.expanded-row-' + siteId);
    if (expandedRow) {
        expandedRow.style.display = '';
        expandedRow.style.opacity = '';
        expandedRow.style.transition = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateButtonStates();

    // Default free homepage can be pre-checked in Blade; sync Buy totals before
    // the first radio change so expand/header prices match the selection.
    syncDefaultHomepagePrices();

    // Sensitive topic + homepage radios: delegate so late/expanded markup still works.
    document.addEventListener('change', function (e) {
        const radio = e.target && e.target.closest
            ? e.target.closest('.sensitive-price-checkbox, .homepage-placement-radio')
            : null;
        if (!radio || !radio.checked) return;

        e.stopPropagation();

        const siteId = radio.dataset.siteId
            || (radio.closest('.sensitive-prices-group, .homepage-placement-group') || {}).dataset?.siteId;
        if (!siteId) return;

        syncSensitiveSelectionUi(siteId);

        if (radio.classList.contains('sensitive-price-checkbox')) {
            const selected = getSelectedSensitiveForSite(siteId);
            const homepage = getSelectedHomepageForSite(siteId);
            if (selected.type && selected.additionalPrice > 0) {
                const article = selected.totalPrice != null
                    ? selected.totalPrice
                    : (selected.basePrice + selected.additionalPrice);
                const total = catalogRoundMoney(Number(article) + (homepage.price || 0));
                catalogToast(
                    selected.type + ' selected: +€' + selected.additionalPrice.toFixed(2)
                    + ' — Total: €' + Number(total).toFixed(2),
                    'success'
                );
            }
        }
    });

    /**
     * Ask the server for one publisher domain (copy-strike hide mode only).
     *
     * The masked host is all the page was sent in hide mode, so this is a real
     * request rather than a CSS toggle — which is what makes the disclosure
     * loggable. Pace waits are absorbed silently when short.
     */
    async function requestReveal(button, attempt) {
        if (!(CatalogConfig && CatalogConfig.inCatalogHideMode)) return;
        attempt = attempt || 1;

        const siteId = button.dataset.siteId || button.dataset.id;
        const suffix = button.dataset.targetSuffix ? button.dataset.targetSuffix + '-' : '';
        const hostEl = hostElementFor(siteId, button.dataset.targetSuffix || '')
            || document.getElementById('url-host-' + suffix + siteId);
        if (!hostEl) return;

        const icon = button.querySelector('i');
        button.dataset.busy = '1';
        if (icon) icon.className = 'fa-solid fa-spinner fa-spin';

        const restore = () => {
            if (icon) icon.className = 'fa-regular fa-eye';
            button.dataset.busy = '';
        };

        try {
            const res = await fetch(revealUrlEndpoint.replace('__SITE__', siteId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
            });
            const json = await res.json();

            if (json.code === 'hide_mode_only') {
                restore();
                return;
            }

            if (json.code === 'slow_down') {
                const wait = Math.max(1, Number(json.retry_after) || 3);

                // The server quotes the real time until there is room, so a short
                // wait is worth absorbing silently — the reader sees a spinner and
                // then their address. Only retry once: a second refusal means the
                // pace is sustained, and spinning for minutes is worse than saying so.
                if (wait <= 10 && attempt === 1) {
                    await new Promise(r => setTimeout(r, wait * 1000));
                    return requestReveal(button, attempt + 1);
                }

                restore();
                if (window.showAppToast) {
                    window.showAppToast(json.message, 'warning');
                } else if (window.Swal) {
                    Swal.fire({ icon: 'info', title: 'Going a little fast', text: json.message });
                }
                return;
            }

            if (json.code === 'paused') {
                restore();
                if (window.Swal) {
                    // Same Swal chrome as before; pre-line keeps the three-part
                    // pause copy readable without inventing a new dialog.
                    const body = String(json.message || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                    Swal.fire({
                        icon: 'info',
                        title: 'Paused for a moment',
                        html: `<div style="white-space:pre-line;text-align:left">${body}</div>`,
                    });
                } else if (window.showAppToast) {
                    window.showAppToast(json.message, 'warning');
                }
                return;
            }

            if (!json.success) {
                throw new Error(json.message || 'Could not open that address');
            }

            const rooted = json.rooted_url || formatRootedDisplay(json.url);
            paintHostElements(siteId, rooted, json.url);
            if (json.name) {
                paintNameElements(siteId, json.name, true);
            }

            button.dataset.busy = '';

            // A card's control stays put and becomes the hide affordance; the
            // table's reveal button steps aside for its .hide-url twin.
            if (isTwoWayUrlToggle(button)) {
                setUrlToggleState(button, true);
                return;
            }

            button.classList.add('d-none');
            if (icon) icon.className = 'fa-regular fa-eye';

            const hideBtn = document.getElementById('url-hide-' + siteId);
            if (hideBtn) hideBtn.classList.remove('d-none');
        } catch (err) {
            restore();
            if (window.showAppToast) {
                window.showAppToast(err.message || 'Could not open that address', 'error');
            }
        }
    }

    /**
     * Persist a hide so a refresh keeps the address masked until they open it
     * again (copy-strike hide mode only). The disclosure row stays for audit/pace.
     */
    async function requestConceal(button, hostEl) {
        if (!(CatalogConfig && CatalogConfig.inCatalogHideMode)) return;
        const siteId = button.dataset.siteId || button.dataset.id;
        if (!siteId || !hideUrlEndpoint) return;

        const icon = button.querySelector('i');
        button.dataset.busy = '1';
        if (icon) icon.className = 'fa-solid fa-spinner fa-spin';

        const restore = (revealed) => {
            if (isTwoWayUrlToggle(button)) {
                setUrlToggleState(button, !!revealed);
            } else if (icon) {
                icon.className = 'fa-regular fa-eye-slash';
            }
            button.dataset.busy = '';
        };

        try {
            const res = await fetch(hideUrlEndpoint.replace('__SITE__', siteId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
            });
            const json = await res.json();

            if (json.code === 'hide_mode_only') {
                restore(true);
                return;
            }

            if (!json.success) {
                throw new Error(json.message || 'Could not hide that address');
            }

            const masked = json.masked || URL_MASK;
            paintHostElements(siteId, json.masked_rooted || formatRootedDisplay(masked), null);
            if (typeof json.masked_name === 'string' && json.masked_name !== '') {
                const revealName = !!(CatalogConfig && CatalogConfig.inCatalogHideMode);
                paintNameElements(siteId, json.masked_name, !revealName);
            }

            button.dataset.busy = '';

            if (isTwoWayUrlToggle(button)) {
                setUrlToggleState(button, false);
                return;
            }

            button.classList.add('d-none');
            if (icon) icon.className = 'fa-regular fa-eye-slash';

            const revealBtn = revealButtonFor(siteId, '');
            if (revealBtn) revealBtn.classList.remove('d-none');
        } catch (err) {
            restore(true);
            if (window.showAppToast) {
                window.showAppToast(err.message || 'Could not hide that address', 'error');
            }
        }
    }

    function catalogActionClick(e) {
        // Interactive chrome must not toggle Details — ↗, eye, Buy, favorite,
        // blacklist, claim, tip chips, Details itself (has its own handler), etc.
        return !!e.target.closest(
            'button, a, input, label, select, textarea, .reveal-url, .hide-url, .toggle-url, .catalog-url-eye, .expand-arrow, .catalog-card-details-toggle, .btn-icon-quiet, .site-open-link, .buy-now, .favorite-btn, .blacklist-btn, .btn-claim-site, .copy-example-url, .sensitive-price-checkbox, .homepage-placement-radio, .form-check-label, .site-chip, .site-badge-new, .catalog-site-actions, .catalog-site-controls, .catalog-card-details'
        );
    }

    const URL_MASK = '•••••••';

    /**
     * Catalog shows scheme + host only under the listing name.
     * Reveal/hide APIs still speak bare hosts / masks — prefix https when needed.
     */
    function formatRootedDisplay(hostOrUrl) {
        const value = String(hostOrUrl || '').trim();
        if (!value) return '';
        if (/^https?:\/\//i.test(value)) {
            try {
                const parsed = new URL(value);
                return parsed.protocol + '//' + parsed.hostname;
            } catch (err) {
                return value.replace(/[/?#].*$/, '');
            }
        }
        return 'https://' + value.replace(/^\/+/, '');
    }

    function paintHostElements(siteId, displayText, bareHost) {
        const nodes = [
            document.getElementById('url-host-' + siteId),
            document.getElementById('url-host-mobile-' + siteId),
        ].filter(Boolean);
        nodes.forEach(function (el) {
            el.textContent = displayText;
            if (bareHost) {
                el.dataset.host = bareHost;
                el.removeAttribute('data-glass-tip');
                el.removeAttribute('data-glass-tip-title');
                el.removeAttribute('data-glass-tip-body');
            } else {
                delete el.dataset.host;
            }
            if (el.getAttribute('title') !== null) {
                el.setAttribute('title', displayText);
            }
        });
        return nodes[0] || null;
    }

    /**
     * Hide-mode: one eye paints/remasks the listing name with the URL.
     * Outside hide mode, conceal still sends the real name so the label stays.
     */
    function paintNameElements(siteId, displayName, setTitle) {
        const row = document.querySelector('.site-row[data-id="' + siteId + '"]');
        const card = document.querySelector('.catalog-mobile-card[data-id="' + siteId + '"]');
        const roots = [row, card].filter(Boolean);

        roots.forEach(function (root) {
            root.querySelectorAll('.catalog-site-name, [data-site-name-label]').forEach(function (el) {
                el.textContent = displayName;
                if (setTitle) {
                    el.setAttribute('title', displayName);
                } else {
                    el.removeAttribute('title');
                }
            });
            root.setAttribute('data-name', displayName);
            root.querySelectorAll('[data-name]').forEach(function (el) {
                el.setAttribute('data-name', displayName);
            });
            root.querySelectorAll('[data-site-name]').forEach(function (el) {
                el.setAttribute('data-site-name', displayName);
            });
        });
    }

    /**
     * True for the card's single address control, which reveals and then hides.
     * The table splits those into a .reveal-url / .hide-url pair instead.
     */
    function isTwoWayUrlToggle(button) {
        return button.classList.contains('toggle-url');
    }

    function setUrlToggleState(button, revealed) {
        const icon = button.querySelector('i');
        if (icon) {
            icon.className = revealed ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        }
        const hideMode = !!(CatalogConfig && CatalogConfig.inCatalogHideMode);
        const label = revealed
            ? (hideMode ? 'Hide site name and URL' : 'Hide this address')
            : (hideMode ? 'Show site name and URL' : 'Show the full website address');
        button.setAttribute('aria-label', label);
        button.title = label;
    }

    function hostLooksRevealed(hostEl) {
        if (!hostEl) return false;
        if (hostEl.dataset.host) {
            const shown = hostEl.textContent.trim();
            const bare = String(hostEl.dataset.host).trim();
            return shown === bare || shown === formatRootedDisplay(bare);
        }
        // Server-rendered full host has no mask markers.
        const text = hostEl.textContent.trim();
        return text !== '' && text !== URL_MASK && !text.includes('***') && !text.includes('••');
    }

    function revealButtonFor(siteId, preferSuffix) {
        if (preferSuffix) {
            return document.getElementById('url-reveal-' + preferSuffix + '-' + siteId)
                || document.getElementById('url-reveal-' + siteId);
        }
        return document.getElementById('url-reveal-' + siteId)
            || document.getElementById('url-reveal-mobile-' + siteId);
    }

    function hostElementFor(siteId, suffix) {
        const prefix = suffix ? suffix + '-' : '';
        return document.getElementById('url-host-' + prefix + siteId)
            || document.getElementById('url-host-' + siteId)
            || document.getElementById('url-host-mobile-' + siteId);
    }

    // Capture-phase eye listeners only while copy-strike hide mode is active.
    // Outside hide mode the Blade omits eye controls; after expiry/admin clear
    // the next load has inCatalogHideMode=false and these never bind.
    if (CatalogConfig && CatalogConfig.inCatalogHideMode) {
        document.addEventListener('click', function (e) {
            const button = e.target.closest('.reveal-url, .toggle-url');
            if (!button) return;

            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }

            if (button.dataset.busy === '1') return;

            const siteId = button.dataset.siteId || button.dataset.id;
            if (!siteId) return;

            const suffix = button.dataset.targetSuffix
                || (button.dataset.urlPrefix === 'mobile' ? 'mobile' : '');
            const hostEl = hostElementFor(siteId, suffix);

            // Cards carry one control for the address rather than the table's
            // reveal + hide pair, so it has to work in both directions — both
            // directions hit the server so a refresh keeps the chosen state.
            if (isTwoWayUrlToggle(button)) {
                button.dataset.targetSuffix = suffix || '';
                if (hostLooksRevealed(hostEl)) {
                    requestConceal(button, hostEl);
                    return;
                }
                requestReveal(button, 1);
                return;
            }

            const revealBtn = button.classList.contains('reveal-url')
                ? button
                : (revealButtonFor(siteId, suffix) || button);

            if (revealBtn) {
                if (suffix && !revealBtn.dataset.targetSuffix) {
                    revealBtn.dataset.targetSuffix = suffix;
                }
                requestReveal(revealBtn, 1);
            }
        }, true);

        // Sticky hide: same disclosure stays on file; only the display preference flips.
        document.addEventListener('click', function (e) {
            const button = e.target.closest('.hide-url');
            if (!button) return;

            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }

            if (button.dataset.busy === '1') return;

            const siteId = button.dataset.siteId;
            const hostEl = hostElementFor(siteId, '');
            requestConceal(button, hostEl);
        }, true);
    }

    // Toggle expanded row — multi-open: siblings stay expanded.
    function toggleExpandRow(id, arrowElement) {
        const expandedRow = document.querySelector('.expanded-row-' + id);
        if (!expandedRow) return;

        const arrow = arrowElement || document.getElementById('arrow-' + id);
        const isClosed = expandedRow.style.display === 'none' || expandedRow.style.display === '';

        if (isClosed) {
            expandedRow.style.display = 'table-row';
            hydrateExpandScreenshots(expandedRow);
            if (typeof initCatalogExpandPreviewZoom === 'function') {
                initCatalogExpandPreviewZoom(expandedRow);
            }
            setCatalogDetailsToggleState(arrow, true);
            syncSensitiveSelectionUi(id);
        } else {
            expandedRow.style.display = 'none';
            setCatalogDetailsToggleState(arrow, false);
        }
    }

    // Details button — dedicated control (also excluded from whole-row handler).
    document.addEventListener('click', function (e) {
        const arrow = e.target.closest('.expand-arrow');
        if (!arrow) return;
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }
        const id = arrow.id.replace('arrow-', '');
        toggleExpandRow(id, arrow);
    });

    // Whole-row click toggles Details (name, URL text, tile, metrics, empty space).
    // ↗ stays external-only; interactive chrome is filtered via catalogActionClick.
    // Delegated so live-fetched rows stay interactive. Multi-open: opening one
    // does not close others; second click on the same row collapses it.
    document.addEventListener('click', function (e) {
        const row = e.target.closest('tr.site-row');
        if (!row) return;
        if (catalogActionClick(e)) return;

        const id = row.getAttribute('data-id');
        if (!id) return;

        toggleExpandRow(id, document.getElementById('arrow-' + id));
    });

    // Mobile cards: same body-click toggle parity with the table.
    document.addEventListener('click', function (e) {
        const card = e.target.closest('.catalog-mobile-card');
        if (!card) return;
        if (catalogActionClick(e)) return;

        const toggle = card.querySelector('.catalog-card-details-toggle');
        if (!toggle) return;

        toggleCardDetails(toggle);
    });

    // Copy example URL
    document.addEventListener('click', async function (e) {
        const button = e.target.closest('.copy-example-url');
        if (!button) return;
        e.preventDefault();
        e.stopPropagation();
        const url = button.dataset.url;
        try {
            await navigator.clipboard.writeText(url);
            catalogToast('URL copied to clipboard!', 'success');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fa-regular fa-check"></i> Copied!';
            setTimeout(function () {
                button.innerHTML = originalText;
            }, 1500);
        } catch (err) {
            console.error('Failed to copy:', err);
            catalogToast('Failed to copy URL', 'error');
        }
    });

    // Add to Cart — sensitive type always read from the checked radio in the DOM.
    document.addEventListener('click', function (e) {
        const button = e.target.closest('.buy-now');
        if (!button) return;
        e.preventDefault();
        e.stopPropagation();
        if (button.disabled || button.dataset.busy === '1') return;

        let id = parseInt(button.dataset.id, 10);
        let basePrice = parseFloat(button.dataset.basePrice);
        let name = button.dataset.name;
        if (!id || Number.isNaN(id)) {
            catalogToast('Could not add to cart.', 'error');
            return;
        }

        if (button.closest('[data-own-listing="1"]')
            || (typeof window.catalogIsOwnListing === 'function' && window.catalogIsOwnListing(id))) {
            catalogToast(window.catalogOwnListingMessage || 'This is your listing — you can’t order it.', 'error');
            return;
        }

        const selected = getSelectedSensitiveForSite(id);
        const homepage = getSelectedHomepageForSite(id);
        const sensitiveType = selected.type;
        const additionalPrice = selected.additionalPrice || 0;
        if (Number.isFinite(selected.basePrice)) {
            basePrice = selected.basePrice;
        }
        const articlePrice = selected.totalPrice != null
            ? selected.totalPrice
            : catalogApplyDiscount(
                catalogRoundMoney((Number.isFinite(basePrice) ? basePrice : 0) + additionalPrice),
                selected.discountPercent || catalogDiscountPercentForSite(id),
                catalogPublisherPayoutFloor(id, additionalPrice)
            );
        const finalPrice = catalogRoundMoney(Number(articlePrice) + (homepage.price || 0));

        if (typeof window.addToCart !== 'function') {
            catalogToast('Cart is not ready. Refresh the page and try again.', 'error');
            return;
        }

        const cartOptions = {};
        const bulkHint = button.dataset.bulkHint === '1' || button.hasAttribute('data-bulk-hint');
        if (bulkHint) {
            const packQty = parseInt(button.dataset.bulkQty, 10);
            cartOptions.bulk = true;
            cartOptions.quantity = Number.isFinite(packQty) && packQty > 0 ? packQty : 3;
            cartOptions.openCart = true;
        }
        // When homepage radios exist, always send the selection (incl. none).
        // When absent, omit so the server auto-picks the longest free duration.
        if (homepage.explicit) {
            cartOptions.homepage_days = homepage.days == null ? 'none' : homepage.days;
        }

        const btn = button;
        const originalText = btn.innerHTML;
        btn.dataset.busy = '1';
        btn.disabled = true;

        Promise.resolve(window.addToCart(id, name, finalPrice, sensitiveType, additionalPrice, basePrice, cartOptions))
            .then(function (result) {
                if (result && result.ok === false) return;
                btn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Added!';
                setTimeout(function () {
                    btn.innerHTML = originalText;
                    syncSensitiveSelectionUi(id);
                }, 1000);
            })
            .finally(function () {
                btn.dataset.busy = '0';
                btn.disabled = false;
            });
    });

    // Favorite functionality (desktop table + mobile cards stay in sync)
    document.addEventListener('click', function (e) {
        const button = e.target.closest('.favorite-btn');
        if (!button) return;
        e.preventDefault();
        e.stopPropagation();
        let id = parseInt(button.dataset.id, 10);
        let name = button.dataset.name;
        let index = favorites.indexOf(id);
        const wasAdded = index === -1;

        if (wasAdded) {
            favorites.push(id);
        } else {
            favorites.splice(index, 1);
            if (CatalogConfig.favoritesFilter) {
                hideCatalogSite(id);
            }
        }

        updateButtonStates();

        const previousFavorites = wasAdded
            ? favorites.filter(function (f) { return f !== id; })
            : favorites.concat([id]);
        saveFavorites().then(function (ok) {
            if (ok) return;
            favorites = previousFavorites;
            updateButtonStates();
            if (!wasAdded && CatalogConfig.favoritesFilter) {
                showCatalogSite(id);
            }
        });

        catalogToast(
            wasAdded ? (name + ' added to favorites!') : (name + ' removed from favorites!'),
            wasAdded ? 'success' : 'warning',
            {
                actionLabel: 'Undo',
                onAction: function () {
                    const i = favorites.indexOf(id);
                    if (wasAdded) {
                        if (i !== -1) favorites.splice(i, 1);
                    } else {
                        if (i === -1) favorites.push(id);
                        if (CatalogConfig.favoritesFilter) {
                            showCatalogSite(id);
                        }
                    }
                    updateButtonStates();
                    saveFavorites();
                }
            }
        );
    });

    // Blacklist functionality — hide from catalog; show again under Blacklisted Only / after unblock
    document.addEventListener('click', function (e) {
        const button = e.target.closest('.blacklist-btn');
        if (!button) return;
        e.preventDefault();
        e.stopPropagation();
        let id = parseInt(button.dataset.id, 10);
        let name = button.dataset.name;
        let index = blacklist.indexOf(id);
        const wasBlacklisted = index === -1;

        if (wasBlacklisted) {
            blacklist.push(id);
            if (!CatalogConfig.blacklistFilter) {
                hideCatalogSite(id);
            }
        } else {
            blacklist.splice(index, 1);
            if (CatalogConfig.blacklistFilter) {
                hideCatalogSite(id);
            } else {
                showCatalogSite(id);
            }
        }

        updateButtonStates();

        const previousBlacklist = wasBlacklisted
            ? blacklist.filter(function (b) { return b !== id; })
            : blacklist.concat([id]);
        saveBlacklist().then(function (ok) {
            if (ok) return;
            blacklist = previousBlacklist;
            updateButtonStates();
            if (wasBlacklisted && !CatalogConfig.blacklistFilter) {
                showCatalogSite(id);
            } else if (!wasBlacklisted && CatalogConfig.blacklistFilter) {
                showCatalogSite(id);
            }
        });

        catalogToast(
            wasBlacklisted ? (name + ' has been blacklisted!') : (name + ' removed from blacklist!'),
            wasBlacklisted ? 'warning' : 'success',
            {
                actionLabel: 'Undo',
                onAction: function () {
                    const i = blacklist.indexOf(id);
                    if (wasBlacklisted) {
                        if (i !== -1) blacklist.splice(i, 1);
                        if (!CatalogConfig.blacklistFilter) {
                            showCatalogSite(id);
                        }
                    } else {
                        if (i === -1) blacklist.push(id);
                        if (CatalogConfig.blacklistFilter) {
                            showCatalogSite(id);
                        } else {
                            hideCatalogSite(id);
                        }
                    }
                    updateButtonStates();
                    saveBlacklist();
                }
            }
        );
    });
});

// Safety net: hide any blacklisted sites still rendered on the main catalog
if (!CatalogConfig.blacklistFilter) {
document.querySelectorAll('.site-row[data-id], .catalog-mobile-card[data-id]').forEach(el => {
    let id = parseInt(el.dataset.id);
    if (blacklist.includes(id)) {
        hideCatalogSite(id);
    }
});
}

document.addEventListener('click', async function (e) {
    const claimBtn = e.target.closest('.btn-claim-site');
    if (claimBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (!window.Swal || typeof Swal.fire !== 'function') return;
        if (!CatalogConfig.routes || !CatalogConfig.routes.siteClaim) return;

        const siteId = claimBtn.dataset.siteId;
        const siteName = claimBtn.dataset.siteName || 'this website';
        const siteUrl = claimBtn.dataset.siteUrl || '';
        const contactEmail = CatalogConfig.contactEmail || '';
        const esc = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

        const { value: form } = await Swal.fire({
            title: 'Claim this website',
            html: `<p class="small text-muted mb-2 text-start">If you own <strong>${esc(siteName)}</strong>, submit a claim for review. Include proof of ownership.</p>
                   <input id="swal-claim-name" class="swal2-input" placeholder="Website name (as listed)" value="${esc(siteName)}">
                   <input id="swal-claim-email" class="swal2-input" placeholder="Contact email" value="${esc(contactEmail)}">
                   <textarea id="swal-claim-proof" class="swal2-textarea" placeholder="Proof of ownership (domain registrar, CMS access, etc.)"></textarea>`,
            showCancelButton: true,
            confirmButtonText: 'Submit claim',
            focusConfirm: false,
            preConfirm: () => {
                const website_name = document.getElementById('swal-claim-name').value.trim();
                const contact_email = document.getElementById('swal-claim-email').value.trim();
                const proof_message = document.getElementById('swal-claim-proof').value.trim();
                if (proof_message.length < 20) {
                    Swal.showValidationMessage('Please add at least 20 characters of ownership proof.');
                    return false;
                }
                return {
                    site_id: parseInt(siteId, 10),
                    website_name: website_name || siteName,
                    website_url: siteUrl || undefined,
                    contact_email: contact_email || undefined,
                    proof_message,
                };
            },
        });
        if (!form) return;

        const res = await fetch(CatalogConfig.routes.siteClaim, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CatalogConfig.csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify(form),
        });
        const data = await res.json().catch(() => ({}));
        const claimsUrl = (CatalogConfig.routes && CatalogConfig.routes.siteClaimsIndex) || '/site-claims';
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: data.message || 'Claim submitted',
                html: `<p class="small text-muted mb-0">Track status anytime from <a href="${claimsUrl}">My Claims</a>.</p>`,
            });
        } else {
            Swal.fire({ icon: 'error', title: data.message || 'Done' });
        }
        return;
    }

    const btn = e.target.closest('.btn-suggest-website');
    if (!btn) return;
    const prefill = btn.dataset.search || document.querySelector('input[name="search"]')?.value || '';
    const { value: form } = await Swal.fire({
        title: 'Suggest a website',
        html: `<p class="small text-muted mb-2">Can’t find a publisher site? Suggest it and we’ll try to include it.</p>
               <input id="swal-site-name" class="swal2-input" placeholder="Website name" value="${catalogEscapeHtml(prefill)}">
               <input id="swal-site-url" class="swal2-input" placeholder="https://example.com">
               <textarea id="swal-site-notes" class="swal2-textarea" placeholder="Why should we add it? (optional)"></textarea>`,
        showCancelButton: true,
        confirmButtonText: 'Submit suggestion',
        preConfirm: () => {
            const website_name = document.getElementById('swal-site-name').value.trim();
            const website_url = document.getElementById('swal-site-url').value.trim();
            const notes = document.getElementById('swal-site-notes').value.trim();
            if (!website_name || !website_url) {
                Swal.showValidationMessage('Website name and URL are required');
                return false;
            }
            return { website_name, website_url, notes, search_query: prefill };
        },
    });
    if (!form) return;
    const res = await fetch(CatalogConfig.routes.websiteSuggestionsStore, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CatalogConfig.csrfToken,
        },
        body: JSON.stringify(form),
    });
    const data = await res.json().catch(() => ({}));
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
});

/**
 * Phase 2 — track clipboard copies of URL/domain identity on the catalog.
 * Distinct domains toward ~5 pages / short window → warn, then 24h hide mode.
 * Disabled while hide mode is already on (eye + mask; no need to track).
 * Entering hide_mode mid-session reloads so Blade paints masks + eyes.
 */
(function trackCatalogDomainCopies() {
    if (!copyTrackEndpoint) return;
    // Hide mode already masks identity (eye only) — no copy strikes needed.
    if (CatalogConfig && CatalogConfig.inCatalogHideMode) return;

    const DOMAINISH = /^(?:https?:\/\/)?(?:www\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+(?:[/:?#].*)?$/i;
    const recentKeys = new Set();
    let warningShown = false;
    let hideToastShown = false;
    let trackingStopped = false;

    function looksDomainish(text) {
        const t = String(text || '').trim();
        if (!t || t.length > 500 || /\r|\n/.test(t)) return false;
        return DOMAINISH.test(t);
    }

    function rowSiteId(node) {
        if (!node || !node.closest) return null;
        // Bulk deals are exempt from copy-strike tracking (always show real URLs).
        if (node.closest('.bulk-deal-card, [data-bulk-deal-card], [data-bulk-rail]')) {
            return null;
        }
        const row = node.closest('.site-row, .catalog-mobile-card, [data-id]');
        if (!row) return null;
        if (row.closest('.bulk-deal-card, [data-bulk-rail]')) return null;
        const id = parseInt(row.getAttribute('data-id') || '', 10);
        return Number.isFinite(id) && id > 0 ? id : null;
    }

    function selectionInsideCatalog() {
        const sel = window.getSelection && window.getSelection();
        if (!sel || sel.isCollapsed || !sel.rangeCount) return null;
        const text = String(sel.toString() || '').trim();
        if (!looksDomainish(text)) return null;
        const anchor = sel.anchorNode && (sel.anchorNode.nodeType === 3 ? sel.anchorNode.parentElement : sel.anchorNode);
        const focus = sel.focusNode && (sel.focusNode.nodeType === 3 ? sel.focusNode.parentElement : sel.focusNode);
        const node = anchor || focus;
        if (!node || !node.closest) return null;

        // Bulk discount rail: real URLs on purpose — do not count toward strikes.
        if (node.closest('.bulk-deal-card, [data-bulk-deal-card], [data-bulk-rail]')) {
            return null;
        }

        // Prefer explicit URL cells; also accept any selection inside a site row.
        const urlCell = node.closest('.catalog-site-url');
        const row = node.closest('.site-row, .catalog-mobile-card');
        if (!urlCell && !row) return null;

        return { text, siteId: rowSiteId(urlCell || row) };
    }

    async function reportCopy(text, siteId) {
        if (trackingStopped || (CatalogConfig && CatalogConfig.inCatalogHideMode)) return;

        const key = String(siteId || '') + '|' + String(text).toLowerCase();
        if (recentKeys.has(key)) return;
        recentKeys.add(key);
        window.setTimeout(function () { recentKeys.delete(key); }, 1500);

        try {
            const res = await fetch(copyTrackEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (CatalogConfig && CatalogConfig.csrfToken)
                        || document.querySelector('meta[name="csrf-token"]')?.content
                        || '',
                },
                body: JSON.stringify({
                    text: text,
                    site_id: siteId || null,
                }),
            });
            const data = await res.json().catch(function () { return {}; });
            if (!data || !data.success) return;

            if (data.status === 'warning' && !warningShown) {
                warningShown = true;
                catalogToast(data.message || 'Stop mass-copying website addresses from the catalog.', 'warning', {
                    delay: 9000,
                });
            } else if (data.status === 'hide_mode' && !hideToastShown) {
                hideToastShown = true;
                trackingStopped = true;
                if (CatalogConfig) {
                    CatalogConfig.inCatalogHideMode = true;
                    CatalogConfig.catalogHideUntil = data.hide_until || CatalogConfig.catalogHideUntil;
                }
                catalogToast(data.message || 'Site names and URLs are hidden for 24 hours.', 'error', {
                    delay: 2500,
                });
                // Server-side dual-mask only applies on the next render — reload
                // so names/URLs already painted in this session do not stay visible.
                window.setTimeout(function () {
                    window.location.reload();
                }, 1200);
            }
        } catch (err) {
            // Non-blocking — never break copy UX.
        }
    }

    function onCatalogCopy() {
        if (trackingStopped || (CatalogConfig && CatalogConfig.inCatalogHideMode)) return;
        const hit = selectionInsideCatalog();
        if (!hit) return;
        reportCopy(hit.text, hit.siteId);
    }

    document.addEventListener('copy', onCatalogCopy);
})();
