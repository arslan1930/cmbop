/* Catalog page JS — expects window.CatalogConfig */
(function () {
if (!window.CatalogConfig) { window.CatalogConfig = { favorites: [], blacklist: [], routes: {}, csrfToken: '' }; }
})();

document.addEventListener('DOMContentLoaded', function () {
    const filtersPanel = document.getElementById('catalogFiltersPanel');
    const filtersToggle = document.getElementById('toggleCatalogFilters');
    const filtersToggleLabel = document.getElementById('toggleCatalogFiltersLabel');
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
    // The form carries the panel state so the next page load respects it —
    // otherwise "Hide filters" was undone by every sort change and reload.
    const filtersOpenField = document.getElementById('filtersOpenField');
    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            const currentlyOpen = !filtersPanel.classList.contains('d-none');
            filtersPanel.classList.toggle('d-none', currentlyOpen);
            filtersToggle.setAttribute('aria-expanded', currentlyOpen ? 'false' : 'true');
            if (filtersToggleLabel) {
                filtersToggleLabel.textContent = currentlyOpen ? 'Show filters' : 'Hide filters';
            }
            if (filtersOpenField) {
                filtersOpenField.value = currentlyOpen ? '0' : '1';
            }
        });
    }

    const btn = document.getElementById('toggleMoreFiltersBtn');
    const drawer = document.getElementById('moreFiltersDrawer');
    if (btn && drawer) {
        btn.addEventListener('click', function () {
            const open = drawer.style.display !== 'none';
            drawer.style.display = open ? 'none' : 'block';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
    }

    // FR2 — preset chips set min/max inputs
    document.querySelectorAll('.filter-preset').forEach(function (chip) {
        chip.addEventListener('click', function () {
            const minEl = document.getElementById(chip.dataset.targetMin);
            const maxEl = document.getElementById(chip.dataset.targetMax);
            if (!minEl || !maxEl) return;
            minEl.value = chip.dataset.min || '';
            maxEl.value = chip.dataset.max || '';
            markActivePreset(chip.closest('.filter-presets'));
        });
    });

    // Reflect the applied range back onto the chips. Without this the chip that
    // produced the current results looked no different from the other options.
    document.querySelectorAll('.filter-presets').forEach(function (group) {
        markActivePreset(group);
    });
});

/**
 * Cover the results card while the next page is on its way.
 *
 * Sorting, filtering and paging are full reloads, so without this the click had
 * no answer at all until the new document painted.
 */
function markCatalogResultsBusy() {
    const card = document.getElementById('catalogResults');
    if (!card || card.classList.contains('is-busy')) return;

    card.classList.add('is-busy');
    card.setAttribute('aria-busy', 'true');
    const busy = card.querySelector('.catalog-results-busy');
    if (busy) {
        busy.hidden = false;
        busy.setAttribute('aria-hidden', 'false');
    }
}

function clearCatalogResultsBusy() {
    const card = document.getElementById('catalogResults');
    if (!card) return;
    card.classList.remove('is-busy');
    card.removeAttribute('aria-busy');
    const busy = card.querySelector('.catalog-results-busy');
    if (busy) {
        busy.hidden = true;
        busy.setAttribute('aria-hidden', 'true');
    }
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
        const link = e.target.closest('.pagination a.page-link, .catalog-clear-all, .filter-chip__remove');
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
 * Side-scrolling rail of bulk offers.
 *
 * Arrows page by roughly a viewport of cards, and they disappear when every
 * card already fits so the header does not carry dead controls. The whole
 * section can be collapsed, and that choice is remembered.
 */
function initBulkDealRail() {
    const section = document.querySelector('[data-bulk-rail]');
    if (!section) return;

    const track = section.querySelector('[data-bulk-track]');
    const prev = section.querySelector('[data-bulk-scroll="prev"]');
    const next = section.querySelector('[data-bulk-scroll="next"]');
    const toggle = section.querySelector('[data-bulk-toggle]');
    const toggleLabel = section.querySelector('[data-bulk-toggle-label]');
    if (!track) return;

    function syncNav() {
        // Sub-pixel widths mean scrollWidth can sit a hair above clientWidth
        // with nothing actually clipped.
        const overflow = track.scrollWidth - track.clientWidth;
        const scrollable = overflow > 2;
        section.classList.toggle('is-scrollable', scrollable);
        if (!scrollable) return;

        if (prev) prev.disabled = track.scrollLeft <= 2;
        if (next) next.disabled = track.scrollLeft >= overflow - 2;
    }

    function page(direction) {
        const card = track.querySelector('.bulk-deal-card');
        const step = card
            ? (card.getBoundingClientRect().width + 12) * Math.max(1, Math.floor(track.clientWidth / (card.getBoundingClientRect().width + 12)))
            : track.clientWidth;
        track.scrollBy({ left: direction * step, behavior: 'smooth' });
    }

    if (prev) prev.addEventListener('click', () => page(-1));
    if (next) next.addEventListener('click', () => page(1));
    track.addEventListener('scroll', syncNav, { passive: true });
    window.addEventListener('resize', syncNav);

    function applyCollapsed(collapsed) {
        section.classList.toggle('is-collapsed', collapsed);
        if (toggle) toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (toggleLabel) toggleLabel.textContent = collapsed ? 'Show' : 'Hide';
        if (!collapsed) syncNav();
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            const collapsed = !section.classList.contains('is-collapsed');
            applyCollapsed(collapsed);
            bulkRailWriteCollapsed(collapsed);
        });
    }

    applyCollapsed(bulkRailReadCollapsed());
    syncNav();
}

document.addEventListener('DOMContentLoaded', initBulkDealRail);

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

// Initialize favorites and blacklist from database
const revealUrlEndpoint = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.revealUrl) || '';
const hideUrlEndpoint = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.hideUrl) || '';
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
    selectedMultiFilters.category = String(CatalogConfig.categoryParam).split(',').filter(function(v) { return v; });
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
        dropdowns[i].classList.remove('show');
        var otherTrigger = dropdowns[i].previousElementSibling;
        if (otherTrigger) otherTrigger.setAttribute('aria-expanded', 'false');
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
    var input = options[i].querySelector('input');
    if (input) input.focus({ preventScroll: false });
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
    }
}

document.addEventListener('keydown', function (e) {
    var openDropdown = document.querySelector('.multi-select-dropdown.show');
    var trigger = e.target.closest && e.target.closest('.multi-select-input');

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
        openDropdown.classList.remove('show');
        var openTrigger = openDropdown.previousElementSibling;
        if (openTrigger) {
            openTrigger.setAttribute('aria-expanded', 'false');
            openTrigger.focus();
        }
        return;
    }

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var current = parseInt(openDropdown.dataset.focusIndex || '-1', 10);
        focusMultiOption(openDropdown, e.key === 'ArrowDown' ? current + 1 : current - 1);
        return;
    }

    if (e.key === 'Enter' && e.target && e.target.matches && e.target.matches('.option-item input, .option-item')) {
        // native checkbox toggle via Enter on focused input
        return;
    }
});

function filterMultiOptions(optionsId, searchTerm) {
    var options = document.getElementById(optionsId);
    if (!options) return;
    var optionItems = options.querySelectorAll('.option-item');
    var term = (searchTerm || '').toLowerCase().trim();
    var visible = 0;

    for (var i = 0; i < optionItems.length; i++) {
        var option = optionItems[i];
        var text = (option.querySelector('span') ? option.querySelector('span').textContent : '').toLowerCase();
        var code = (option.querySelector('input') ? option.querySelector('input').value : '').toLowerCase();
        var match = term === '' || text.indexOf(term) !== -1 || code.indexOf(term) !== -1;
        option.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    }

    var empty = options.parentElement ? options.parentElement.querySelector('.multi-select-empty') : null;
    if (empty) empty.classList.toggle('d-none', visible > 0);
}

function updateMultiFilter(checkbox) {
    var type = checkbox.getAttribute('data-type');
    var value = checkbox.value;
    
    if (checkbox.checked) {
        if (selectedMultiFilters[type].indexOf(value) === -1) {
            selectedMultiFilters[type].push(value);
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
    
    // Update display
    updateMultiDisplay(type);
}

/*
 * Container ids are listed rather than derived. Adding "s" to the type produced
 * "selectedCategorysDisplay" and "selectedCountrysDisplay", which match nothing
 * in the markup, so ticking a category or country never showed a tag.
 *
 * The placeholder wording is read from the markup's data-placeholder, because a
 * copy here silently overwrote whatever the Blade template said.
 */
var MULTI_FILTER_UI = {
    category: { container: 'selectedCategoriesDisplay', placeholder: 'All categories' },
    country: { container: 'selectedCountriesDisplay', placeholder: 'All countries' },
    language: { container: 'selectedLanguagesDisplay', placeholder: 'All languages' }
};

function updateMultiDisplay(type) {
    var ui = MULTI_FILTER_UI[type];
    if (!ui) return;

    var container = document.getElementById(ui.container);
    var values = selectedMultiFilters[type];

    if (!container) return;

    container.innerHTML = '';

    if (values.length === 0) {
        var placeholder = document.createElement('span');
        placeholder.className = 'placeholder-text';
        placeholder.textContent = container.dataset.placeholder || ui.placeholder;
        container.appendChild(placeholder);
        return;
    }
    
    for (var i = 0; i < values.length; i++) {
        var value = values[i];
        var displayName = value;
        
        if (type === 'country') {
            var option = document.querySelector('#countryMultiOptions input[value="' + value + '"]');
            if (option) {
                displayName = option.getAttribute('data-name') || value;
            }
        }
        
        if (type === 'language') {
            var option = document.querySelector('#languageMultiOptions input[value="' + value + '"]');
            if (option) {
                displayName = option.getAttribute('data-name') || value;
            }
        }
        
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

/*
 * One delegated listener for every filter tag, however often they re-render.
 *
 * Capture phase on purpose: the tags sit inside .multi-select-input, whose own
 * click handler opens the dropdown. Listening on the way down lets us cancel
 * that before it runs, so removing a tag no longer also opens the list.
 */
document.addEventListener('click', function (e) {
    var remove = e.target.closest ? e.target.closest('.remove-tag[data-filter-type]') : null;
    if (!remove) return;

    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') {
        e.stopImmediatePropagation();
    }
    removeMultiFilter(remove.dataset.filterType, remove.dataset.filterValue);
}, true);

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
    
    updateMultiDisplay(type);
}

function initializeMultiSelects() {
    // Initialize checkboxes
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
    
    // Update displays
    updateMultiDisplay('category');
    updateMultiDisplay('country');
    updateMultiDisplay('language');
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
        if (field) field.value = map[id].join(',');
    });
}

function submitCatalogFilters() {
    syncCatalogFilterFields();
    // form.submit() does not fire a submit event, so the busy state has to be
    // raised here as well as from the listener that catches native submits.
    markCatalogResultsBusy();
    const form = document.getElementById('filterForm');
    if (form) form.submit();
}

// Apply Filters button - submit the form with all selected values
(function () {
    const applyBtn = document.getElementById('applyFiltersBtn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function() {
            submitCatalogFilters();
        });
    }

    // Enter inside any field, and the Sort select, both submit natively.
    const form = document.getElementById('filterForm');
    if (form) {
        form.addEventListener('submit', function () {
            syncCatalogFilterFields();
        });
    }

    const sort = document.getElementById('catalogSort');
    if (sort) {
        sort.addEventListener('change', function () {
            submitCatalogFilters();
        });
    }
})();

// Favorites / Blacklist selects apply immediately so heart & block workflows are obvious
['favorites_filter', 'blacklist_filter'].forEach(function (name) {
    const select = document.querySelector('select[name="' + name + '"]');
    if (!select) return;
    select.addEventListener('change', function () {
        submitCatalogFilters();
    });
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
function updateBuyButtonPrice(siteId, basePrice, additionalPrice = 0, sensitiveType = null, discountPercent = 0) {
    const id = String(siteId);
    const base = parseFloat(basePrice);
    const addOn = parseFloat(additionalPrice);
    const safeBase = Number.isFinite(base) ? base : 0;
    const safeAdd = Number.isFinite(addOn) && addOn > 0 ? addOn : 0;
    const pct = Number.isFinite(parseFloat(discountPercent)) ? parseFloat(discountPercent) : 0;
    const listTotal = catalogRoundMoney(safeBase + safeAdd);
    const floor = catalogPublisherPayoutFloor(id, safeAdd);
    const totalPrice = catalogApplyDiscount(listTotal, pct, floor);

    document.querySelectorAll('.buy-now[data-id="' + id + '"]').forEach(function (buyButton) {
        const price = catalogPriceDisplaysFor(buyButton);

        if (price.pay) {
            price.pay.textContent = '€' + totalPrice.toFixed(2);
        }

        // Strike-through shows the pre-discount list total when a sale is active.
        if (price.list) {
            price.list.textContent = '€' + listTotal.toFixed(2);
            price.list.hidden = !(pct > 0);
        }

        buyButton.dataset.currentAdditionalPrice = String(safeAdd);
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
    const basePrice = selected.basePrice != null
        ? selected.basePrice
        : (parseFloat((document.querySelector(
            '.sensitive-prices-group[data-site-id="' + String(siteId) + '"]'
        ) || {}).dataset?.basePrice) || 0);
    const discountPercent = selected.discountPercent != null
        ? selected.discountPercent
        : catalogDiscountPercentForSite(siteId);
    const payTotal = selected.totalPrice != null
        ? selected.totalPrice
        : catalogApplyDiscount(
            catalogRoundMoney(basePrice + (selected.additionalPrice || 0)),
            discountPercent,
            catalogPublisherPayoutFloor(siteId, selected.additionalPrice || 0)
        );

    updateBuyButtonPrice(
        siteId,
        basePrice,
        selected.additionalPrice,
        selected.type,
        discountPercent
    );

    let infoHtml;
    if (selected.type && selected.additionalPrice > 0) {
        infoHtml =
            '<small class="text-muted">List price: <strong>€'
            + Number(selected.listTotal != null ? selected.listTotal : (basePrice + selected.additionalPrice)).toFixed(2)
            + '</strong></small><br>'
            + '<small class="text-success">Selected: <strong>' + catalogEscapeHtml(selected.type)
            + '</strong> — You pay: <strong>€' + Number(payTotal).toFixed(2)
            + '</strong> (+€' + selected.additionalPrice.toFixed(2);
        if (discountPercent > 0) {
            infoHtml += ', includes −'
                + catalogRoundMoney(discountPercent).toString().replace(/\.0+$/, '')
                + '% offer';
        }
        infoHtml += ')</small>';
    } else if (discountPercent > 0) {
        infoHtml =
            '<small class="text-muted">Current price: <strong>€' + Number(payTotal).toFixed(2)
            + '</strong> <span class="text-decoration-line-through">€'
            + Number(basePrice).toFixed(2) + '</span> (offer price)</small>';
    } else {
        infoHtml =
            '<small class="text-muted">Current price: <strong>€' + Number(basePrice).toFixed(2)
            + '</strong> (Base price)</small>';
    }

    ['price-info-' + siteId, 'price-info-mobile-' + siteId].forEach(function (infoId) {
        const priceInfoDiv = document.getElementById(infoId);
        if (priceInfoDiv) {
            priceInfoDiv.innerHTML = infoHtml;
        }
    });
}

// Card "Details" disclosure — the content the table keeps in its expand row.
document.addEventListener('click', function (e) {
    const toggle = e.target.closest('.catalog-card-details-toggle');
    if (!toggle) return;

    e.preventDefault();
    e.stopPropagation();

    const panel = document.getElementById(toggle.dataset.cardDetails || '');
    if (!panel) return;

    const willOpen = panel.hidden;
    panel.hidden = !willOpen;
    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

    setCatalogDetailsToggleState(toggle, willOpen);
});

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

    // Sensitive topic radios: delegate so late/expanded markup still works.
    document.addEventListener('change', function (e) {
        const radio = e.target && e.target.closest
            ? e.target.closest('.sensitive-price-checkbox')
            : null;
        if (!radio || !radio.checked) return;

        e.stopPropagation();

        const siteId = radio.dataset.siteId
            || (radio.closest('.sensitive-prices-group') || {}).dataset?.siteId;
        if (!siteId) return;

        syncSensitiveSelectionUi(siteId);

        const selected = getSelectedSensitiveForSite(siteId);
        if (selected.type && selected.additionalPrice > 0) {
            const total = selected.totalPrice != null
                ? selected.totalPrice
                : (selected.basePrice + selected.additionalPrice);
            catalogToast(
                selected.type + ' selected: +€' + selected.additionalPrice.toFixed(2)
                + ' — Total: €' + Number(total).toFixed(2),
                'success'
            );
        }
    });

    /**
     * Ask the server for one publisher domain.
     *
     * The masked host is all the page was sent, so this is a real request rather
     * than a CSS toggle — which is what makes the disclosure loggable. There is
     * no quota: browse as long as you like. If the server asks us to wait it is
     * because the pace looks automated, so we simply wait and try again, which a
     * person barely notices.
     */
    async function requestReveal(button, attempt) {
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

            hostEl.textContent = json.url;
            hostEl.dataset.host = json.url;
            hostEl.removeAttribute('data-glass-tip');
            hostEl.removeAttribute('data-glass-tip-title');
            hostEl.removeAttribute('data-glass-tip-body');

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
     * again. The disclosure row stays on the server for audit/pace.
     */
    async function requestConceal(button, hostEl) {
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

            if (!json.success) {
                throw new Error(json.message || 'Could not hide that address');
            }

            const masked = json.masked || URL_MASK;
            if (hostEl) {
                hostEl.textContent = masked;
                delete hostEl.dataset.host;
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
        // Kept for callers that still guard against accidental expand. The
        // table no longer expands on whole-row click — only .expand-arrow does —
        // so this mainly protects any leftover delegated handlers.
        return !!e.target.closest(
            'button, a, input, label, select, textarea, .reveal-url, .hide-url, .toggle-url, .catalog-url-eye, .expand-arrow, .btn-icon-quiet, .site-open-link, .buy-now, .favorite-btn, .blacklist-btn, .btn-claim-site, .copy-example-url, .sensitive-price-checkbox, .form-check-label, .site-chip, .site-badge-new, .catalog-site-actions, .catalog-site-controls'
        );
    }

    const URL_MASK = '•••••••';

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
        const label = revealed
            ? 'Hide this address'
            : 'Show the full website address';
        button.setAttribute('aria-label', label);
        button.title = label;
    }

    function hostLooksRevealed(hostEl) {
        if (!hostEl) return false;
        if (hostEl.dataset.host) {
            return hostEl.textContent.trim() === hostEl.dataset.host;
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

    // Capture phase so reveal wins over the bubbling row-expand handler.
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

    // Toggle expanded row
    function toggleExpandRow(id, arrowElement) {
        let expandedRow = document.querySelector('.expanded-row-' + id);
        if (!expandedRow) return;
        
        if (expandedRow.style.display === 'none' || expandedRow.style.display === '') {
            document.querySelectorAll('[class^="expanded-row-"]').forEach(row => {
                if (row.style.display === 'table-row') {
                    row.style.display = 'none';
                    let rowId = row.className.match(/expanded-row-(\d+)/);
                    if (rowId && rowId[1]) {
                        setCatalogDetailsToggleState(
                            document.getElementById('arrow-' + rowId[1]),
                            false
                        );
                    }
                }
            });
            
            expandedRow.style.display = 'table-row';

            // Load deferred expand screenshots on first open
            expandedRow.querySelectorAll('img.catalog-deferred-preview[data-src]').forEach(function (img) {
                if (!img.getAttribute('src')) {
                    img.setAttribute('src', img.getAttribute('data-src'));
                    img.removeAttribute('data-src');
                }
            });
            setCatalogDetailsToggleState(arrowElement, true);
        } else {
            expandedRow.style.display = 'none';
            setCatalogDetailsToggleState(arrowElement, false);
        }
    }

    // Details only — whole-row click used to expand the panel, which meant a
    // near-miss on the eye / open icons opened "Details" instead of revealing.
    document.querySelectorAll('.expand-arrow').forEach(arrow => {
        arrow.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
            let id = this.id.replace('arrow-', '');
            toggleExpandRow(id, this);
        });
    });

    // Copy example URL
    document.querySelectorAll('.copy-example-url').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();
            let url = this.dataset.url;
            
            try {
                await navigator.clipboard.writeText(url);
                catalogToast('URL copied to clipboard!', 'success');
                let originalText = this.innerHTML;
                this.innerHTML = '<i class="fa-regular fa-check"></i> Copied!';
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 1500);
            } catch (err) {
                console.error('Failed to copy:', err);
                catalogToast('Failed to copy URL', 'error');
            }
        });
    });

    // Add to Cart — sensitive type always read from the checked radio in the DOM.
    document.querySelectorAll('.buy-now').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (this.disabled || this.dataset.busy === '1') return;

            let id = parseInt(this.dataset.id, 10);
            let basePrice = parseFloat(this.dataset.basePrice);
            let name = this.dataset.name;
            if (!id || Number.isNaN(id)) {
                catalogToast('Could not add to cart.', 'error');
                return;
            }

            const selected = getSelectedSensitiveForSite(id);
            const sensitiveType = selected.type;
            const additionalPrice = selected.additionalPrice || 0;
            if (Number.isFinite(selected.basePrice)) {
                basePrice = selected.basePrice;
            }
            // Server re-prices from the live listing; keep the optimistic total
            // discounted so the badge matches what checkout will charge.
            const finalPrice = selected.totalPrice != null
                ? selected.totalPrice
                : catalogApplyDiscount(
                    catalogRoundMoney((Number.isFinite(basePrice) ? basePrice : 0) + additionalPrice),
                    selected.discountPercent || catalogDiscountPercentForSite(id),
                    catalogPublisherPayoutFloor(id, additionalPrice)
                );

            if (typeof window.addToCart !== 'function') {
                catalogToast('Cart is not ready. Refresh the page and try again.', 'error');
                return;
            }

            // Bulk deal cards always start a 3-article pack in the cart (no
            // on-card quantity picker). Separate document slots open there.
            const cartOptions = {};
            const bulkHint = this.dataset.bulkHint === '1' || this.hasAttribute('data-bulk-hint');
            if (bulkHint) {
                cartOptions.bulk = true;
                cartOptions.quantity = 3;
                cartOptions.openCart = true;
            }

            const btn = this;
            const originalText = btn.innerHTML;
            btn.dataset.busy = '1';
            btn.disabled = true;

            Promise.resolve(window.addToCart(id, name, finalPrice, sensitiveType, additionalPrice, basePrice, cartOptions))
                .then(function (result) {
                    if (result && result.ok === false) return;
                    btn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Added!';
                    setTimeout(function () {
                        btn.innerHTML = originalText;
                        // Re-apply selected add-on price after the temporary "Added!" label.
                        syncSensitiveSelectionUi(id);
                    }, 1000);
                })
                .finally(function () {
                    btn.dataset.busy = '0';
                    btn.disabled = false;
                });
        });
    });

    // Favorite functionality (desktop table + mobile cards stay in sync)
    document.querySelectorAll('.favorite-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = favorites.indexOf(id);
            const wasAdded = index === -1;

            if (wasAdded) {
                favorites.push(id);
            } else {
                favorites.splice(index, 1);
                // On Favorites Only view, remove the site from the list immediately
                if (CatalogConfig.favoritesFilter) {
                    hideCatalogSite(id);
                }
            }

            updateButtonStates();

            /* Optimistic: put the previous list back if the save is refused, so the
               heart never claims a favourite the server did not keep. */
            const previousFavorites = wasAdded
                ? favorites.filter((f) => f !== id)
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
                wasAdded ? `${name} added to favorites!` : `${name} removed from favorites!`,
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
    });

    // Blacklist functionality — hide from catalog; show again under Blacklisted Only / after unblock
    document.querySelectorAll('.blacklist-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = blacklist.indexOf(id);
            const wasBlacklisted = index === -1;

            if (wasBlacklisted) {
                blacklist.push(id);
                // Main catalog: remove immediately (desktop row + mobile card)
                if (!CatalogConfig.blacklistFilter) {
                    hideCatalogSite(id);
                }
            } else {
                blacklist.splice(index, 1);
                if (CatalogConfig.blacklistFilter) {
                    // Blacklisted Only view: site no longer belongs here
                    hideCatalogSite(id);
                } else {
                    showCatalogSite(id);
                }
            }

            updateButtonStates();

            /* Blacklisting hides the row, so a failed save would hide a site the
               server still lists. Restore both list and row if it is refused. */
            const previousBlacklist = wasBlacklisted
                ? blacklist.filter((b) => b !== id)
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
                wasBlacklisted ? `${name} has been blacklisted!` : `${name} removed from blacklist!`,
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
            confirmButtonColor: '#75787B',
            cancelButtonColor: '#9ca3af',
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
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done', confirmButtonColor: '#75787B' });
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
        confirmButtonColor: '#1a585e',
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
