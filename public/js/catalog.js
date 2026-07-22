/* Catalog page JS — expects window.CatalogConfig */
(function () {
if (!window.CatalogConfig) { window.CatalogConfig = { favorites: [], blacklist: [], routes: {}, csrfToken: '' }; }
})();

document.addEventListener('DOMContentLoaded', function () {
    const filtersPanel = document.getElementById('catalogFiltersPanel');
    const filtersToggle = document.getElementById('toggleCatalogFilters');
    const filtersToggleLabel = document.getElementById('toggleCatalogFiltersLabel');
    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            const currentlyOpen = !filtersPanel.classList.contains('d-none');
            filtersPanel.classList.toggle('d-none', currentlyOpen);
            filtersToggle.setAttribute('aria-expanded', currentlyOpen ? 'false' : 'true');
            if (filtersToggleLabel) {
                filtersToggleLabel.textContent = currentlyOpen ? 'Show filters' : 'Hide filters';
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
            const group = chip.closest('.filter-presets');
            if (group) {
                group.querySelectorAll('.filter-preset').forEach(c => c.classList.remove('is-active'));
            }
            chip.classList.add('is-active');
        });
    });
});

// Initialize favorites and blacklist from database
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

function updateMultiDisplay(type) {
    var container = document.getElementById('selected' + type.charAt(0).toUpperCase() + type.slice(1) + 'sDisplay');
    var values = selectedMultiFilters[type];
    
    if (!container) return;
    
    container.innerHTML = '';
    
    if (values.length === 0) {
        container.innerHTML = '<span class="placeholder-text">Select ' + type + 's...</span>';
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
        
        var tag = document.createElement('span');
        tag.className = 'selected-tag';
        tag.innerHTML = displayName + ' <span class="remove-tag" onclick="event.stopPropagation(); removeMultiFilter(\'' + type + '\', \'' + value + '\')">&times;</span>';
        container.appendChild(tag);
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

function submitCatalogFilters() {
    document.getElementById('selectedCategory').value = selectedMultiFilters.category.join(',');
    document.getElementById('selectedCountry').value = selectedMultiFilters.country.join(',');
    document.getElementById('selectedLanguage').value = selectedMultiFilters.language.join(',');
    document.getElementById('filterForm').submit();
}

// Apply Filters button - submit the form with all selected values
document.getElementById('applyFiltersBtn').addEventListener('click', function() {
    submitCatalogFilters();
});

// Favorites / Blacklist selects apply immediately so heart & block workflows are obvious
['favorites_filter', 'blacklist_filter'].forEach(function (name) {
    const select = document.querySelector('select[name="' + name + '"]');
    if (!select) return;
    select.addEventListener('change', function () {
        submitCatalogFilters();
    });
});

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.multi-select-wrapper')) {
        var dropdowns = document.querySelectorAll('.multi-select-dropdown');
        for (var i = 0; i < dropdowns.length; i++) {
            if (dropdowns[i]) {
                dropdowns[i].classList.remove('show');
            }
        }
    }
});

// Initialize multi-selects on page load
initializeMultiSelects();

// Store selected sensitive price additional amount for each site
let selectedSensitiveAdditionalPrice = {};

// Toast function
function showToast(message, type = 'success') {
    const toastEl = document.getElementById('toastMessage');
    if (toastEl) {
        const toastBody = document.getElementById('toastMessageBody');
        toastBody.innerText = message;
        
        if (type === 'success') {
            toastEl.classList.remove('bg-danger', 'bg-warning');
            toastEl.classList.add('bg-success');
        } else if (type === 'error') {
            toastEl.classList.remove('bg-success', 'bg-warning');
            toastEl.classList.add('bg-danger');
        } else if (type === 'warning') {
            toastEl.classList.remove('bg-success', 'bg-danger');
            toastEl.classList.add('bg-warning');
        }
        
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
    } else {
        alert(message);
    }
}

// Update cart badge
function updateCartBadge() {
    if (typeof window.updateCartBadge === 'function') {
        window.updateCartBadge();
    }
}

// Add to cart
function addToCart(id, name, basePrice, additionalPrice = 0) {
    let finalPrice = parseFloat(basePrice) + parseFloat(additionalPrice);
    
    if (typeof window.addToCart === 'function') {
        window.addToCart(id, name, finalPrice);
    } else {
        window.location.reload();
    }
    
    return finalPrice;
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

// Update buy button price display
function updateBuyButtonPrice(siteId, basePrice, additionalPrice = 0) {
    document.querySelectorAll(`.buy-now[data-id="${siteId}"]`).forEach(function (buyButton) {
        let priceSpan = buyButton.querySelector('.base-price-display, .fw-semibold');
        let totalPrice = parseFloat(basePrice) + parseFloat(additionalPrice);
        if (priceSpan) {
            priceSpan.textContent = `€${totalPrice.toFixed(2)}`;
        }
        buyButton.dataset.currentAdditionalPrice = additionalPrice;
    });
}

// Save favorites to database
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
        return data;
    }).catch(err => {
        console.error('Error saving favorites:', err);
        showToast(err.message || 'Could not save favorites', 'error');
    });
}

// Save blacklist to database
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
        return data;
    }).catch(err => {
        console.error('Error saving blacklist:', err);
        showToast(err.message || 'Could not save blacklist', 'error');
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

    // Store selected sensitive price for each site
    let selectedSensitivePrices = {};

    // Handle sensitive price checkbox selection
    document.querySelectorAll('.sensitive-prices-group').forEach(group => {
        let siteId = group.dataset.siteId;
        let basePrice = parseFloat(group.dataset.basePrice);
        let checkboxes = group.querySelectorAll('.sensitive-price-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function(e) {
                e.stopPropagation();
                
                if (!this.checked) return;

                let additionalPrice = parseFloat(this.dataset.additionalPrice);
                let totalPrice = parseFloat(this.dataset.totalPrice);
                let priceType = this.dataset.type;

                if (priceType === 'none' || additionalPrice === 0) {
                    delete selectedSensitivePrices[siteId];
                    updateBuyButtonPrice(siteId, basePrice, 0);
                    let priceInfoDiv = document.getElementById(`price-info-${siteId}`);
                    if (priceInfoDiv) {
                        priceInfoDiv.innerHTML = `
                            <small class="text-muted">Current price: <strong>€${basePrice.toFixed(2)}</strong> (Base price)</small>
                        `;
                    }
                    return;
                }

                selectedSensitivePrices[siteId] = {
                    type: priceType,
                    additionalPrice: additionalPrice,
                    totalPrice: totalPrice
                };

                updateBuyButtonPrice(siteId, basePrice, additionalPrice);

                let priceInfoDiv = document.getElementById(`price-info-${siteId}`);
                if (priceInfoDiv) {
                    priceInfoDiv.innerHTML = `
                        <small class="text-muted">Base price: <strong>€${basePrice.toFixed(2)}</strong></small><br>
                        <small class="text-success">Selected: <strong>${priceType}</strong> - Total: <strong>€${totalPrice.toFixed(2)}</strong> (+€${additionalPrice.toFixed(2)})</small>
                    `;
                }

                showToast(`${priceType} selected: +€${additionalPrice.toFixed(2)} - Total: €${totalPrice.toFixed(2)}`, 'success');
            });
        });
    });

    // Toggle URL visibility (desktop table + mobile cards)
    document.querySelectorAll('.toggle-url').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = this.dataset.id;
            let prefix = this.dataset.urlPrefix ? this.dataset.urlPrefix + '-' : '';
            let maskedSpan = document.getElementById('url-masked-' + prefix + id);
            let fullSpan = document.getElementById('url-full-' + prefix + id);
            if (!maskedSpan || !fullSpan) return;

            if (maskedSpan.classList.contains('d-none')) {
                maskedSpan.classList.remove('d-none');
                fullSpan.classList.add('d-none');
                this.querySelector('i').classList.remove('fa-eye-slash');
                this.querySelector('i').classList.add('fa-eye');
                this.setAttribute('aria-label', 'Reveal full URL');
            } else {
                maskedSpan.classList.add('d-none');
                fullSpan.classList.remove('d-none');
                this.querySelector('i').classList.remove('fa-eye');
                this.querySelector('i').classList.add('fa-eye-slash');
                this.setAttribute('aria-label', 'Hide full URL');
            }
        });
    });

    // Toggle expanded row
    function toggleExpandRow(id, arrowElement) {
        let expandedRow = document.querySelector('.expanded-row-' + id);
        
        if (expandedRow.style.display === 'none' || expandedRow.style.display === '') {
            document.querySelectorAll('[class^="expanded-row-"]').forEach(row => {
                if (row.style.display === 'table-row') {
                    row.style.display = 'none';
                    let rowId = row.className.match(/expanded-row-(\d+)/);
                    if (rowId && rowId[1]) {
                        let otherArrow = document.getElementById('arrow-' + rowId[1]);
                        if (otherArrow) {
                            otherArrow.classList.remove('rotate-arrow');
                        }
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
            if (arrowElement) {
                arrowElement.classList.add('rotate-arrow');
                arrowElement.setAttribute('aria-expanded', 'true');
            }
        } else {
            expandedRow.style.display = 'none';
            if (arrowElement) {
                arrowElement.classList.remove('rotate-arrow');
                arrowElement.setAttribute('aria-expanded', 'false');
            }
        }
    }

    document.querySelectorAll('.site-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if(e.target.closest('.toggle-url') || e.target.closest('.buy-now') || 
               e.target.closest('.favorite-btn') || e.target.closest('.blacklist-btn') ||
               e.target.closest('.copy-example-url') || e.target.closest('.expand-arrow') ||
               e.target.closest('.sensitive-price-checkbox') || e.target.closest('a') ||
               e.target.closest('.form-check-label')) {
                return;
            }
            
            let id = this.dataset.id;
            let arrowElement = document.getElementById('arrow-' + id);
            toggleExpandRow(id, arrowElement);
        });
    });

    document.querySelectorAll('.expand-arrow').forEach(arrow => {
        arrow.addEventListener('click', function(e) {
            e.stopPropagation();
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
                showToast('URL copied to clipboard!', 'success');
                let originalText = this.innerHTML;
                this.innerHTML = '<i class="fa-regular fa-check"></i> Copied!';
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 1500);
            } catch (err) {
                console.error('Failed to copy:', err);
                showToast('Failed to copy URL', 'error');
            }
        });
    });

    // Add to Cart
    document.querySelectorAll('.buy-now').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let basePrice = parseFloat(this.dataset.basePrice);
            let name = this.dataset.name;
            
            let sensitiveType = selectedSensitivePrices[id] ? selectedSensitivePrices[id].type : null;
            let additionalPrice = selectedSensitivePrices[id] ? selectedSensitivePrices[id].additionalPrice : 0;
            let finalPrice = basePrice + additionalPrice;
            
            if (typeof window.addToCart === 'function') {
                window.addToCart(id, name, finalPrice, sensitiveType, additionalPrice, basePrice);
            }
            
            let originalText = this.innerHTML;
            this.innerHTML = '<i class="fa-solid fa-check"></i> Added!';
            setTimeout(() => {
                this.innerHTML = originalText;
            }, 1000);
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

            if (index === -1) {
                favorites.push(id);
                showToast(`${name} added to favorites!`, 'success');
            } else {
                favorites.splice(index, 1);
                showToast(`${name} removed from favorites!`, 'warning');
                // On Favorites Only view, remove the site from the list immediately
                if (CatalogConfig.favoritesFilter) {
                hideCatalogSite(id);
                }
            }

            updateButtonStates();
            saveFavorites();
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

            if (index === -1) {
                blacklist.push(id);
                showToast(`${name} has been blacklisted!`, 'warning');
                // Main catalog: remove immediately (desktop row + mobile card)
                if (!CatalogConfig.blacklistFilter) {
                hideCatalogSite(id);
                }
            } else {
                blacklist.splice(index, 1);
                showToast(`${name} removed from blacklist!`, 'success');
                if (CatalogConfig.blacklistFilter) {
                // Blacklisted Only view: site no longer belongs here
                hideCatalogSite(id);
                } else {
                showCatalogSite(id);
                }
            }

            updateButtonStates();
            saveBlacklist();
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
    const btn = e.target.closest('.btn-suggest-website');
    if (!btn) return;
    const prefill = btn.dataset.search || document.querySelector('input[name="search"]')?.value || '';
    const { value: form } = await Swal.fire({
        title: 'Suggest a website',
        html: `<p class="small text-muted mb-2">Can’t find a publisher site? Suggest it and we’ll try to include it.</p>
               <input id="swal-site-name" class="swal2-input" placeholder="Website name" value="${prefill.replace(/"/g, '&quot;')}">
               <input id="swal-site-url" class="swal2-input" placeholder="https://example.com">
               <textarea id="swal-site-notes" class="swal2-textarea" placeholder="Why should we add it? (optional)"></textarea>`,
        showCancelButton: true,
        confirmButtonText: 'Submit suggestion',
        confirmButtonColor: '#185054',
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
