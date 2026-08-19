/* Publisher My Sites — expects window.PublisherWebsitesConfig */
(function () {
    if (!window.PublisherWebsitesConfig) {
        window.PublisherWebsitesConfig = {
            csrfToken: '',
            maxBulkRows: 200,
            routes: {},
            old: {},
            countryLanguageMap: {},
            languageCountryMap: {},
        };
    }
})();

/**
 * Row preview onerror: /media → /storage chain (Hostinger symlink recovery).
 * Safe to redefine; Blade inline may set this first when dual-loaded.
 */
window.publisherSitePreviewOnError = window.publisherSitePreviewOnError || function (img) {
    if (!img) return;
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
    img.removeAttribute('src');
    var wrap = img.closest('.site-row-preview');
    if (wrap) {
        wrap.classList.add('is-empty');
        wrap.removeAttribute('data-zoom-src');
        wrap.removeAttribute('data-zoom-chain');
        wrap.innerHTML = '<i class="fa fa-image" aria-hidden="true"></i>';
    }
};

/*
 * Legacy dual-load: websites.blade.php still ships a full inline script.
 * When that flag is set, skip this file's form/table boot to avoid
 * re-binding selects and double-fetching the sites table.
 * Accept / Decline / Get Verified / Feature / Discount / Bulk always bind below
 * (outside this gate).
 */
if (window.__publisherWebsitesInlineLoaded) {
    // no-op — Blade inline owns form/table behaviour.
} else {
(function publisherWebsitesExternalBoot() {

const addBtn = $('#showFormBtn');
const bulkBtn = $('#showBulkRequestBtn');
const formCard = $('#formCard');
const submitBtn = $('#submitBtn');
const closeBtn = $('#closeBtn');
const formHeaderSpan = $('#formHeader');


document.addEventListener('DOMContentLoaded', function () {
    if (!(window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.openBulkRequestModal)) return;
    const el = document.getElementById('bulkRequestModal');
    if (el && window.bootstrap) {
        new bootstrap.Modal(el).show();
    }
});

// Quill editor (guarded so a CDN/CSP failure cannot break the sites table loader)
var quill = null;
const SITE_DESC_MIN_CHARS = Number((window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.descMinChars) || 50);
const SITE_DESC_MAX_WORDS = Number((window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.descMaxWords) || 500);
const SITE_DESC_PLACEHOLDER = (window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.descPlaceholder)
    || 'Describe your audience, niches, and why advertisers should buy a placement here…';

function siteDescPlainText(htmlOrText) {
    const tmp = document.createElement('div');
    tmp.innerHTML = htmlOrText || '';
    return String(tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
}

function siteDescWordCount(plain) {
    const t = String(plain || '').trim();
    if (!t) return 0;
    return t.split(/\s+/).filter(Boolean).length;
}

function siteDescValidationMessage(plain) {
    if (!plain) return 'Please enter a site description.';
    if (plain.length < SITE_DESC_MIN_CHARS) {
        return 'Description must be at least ' + SITE_DESC_MIN_CHARS + ' characters (visible text).';
    }
    if (siteDescWordCount(plain) > SITE_DESC_MAX_WORDS) {
        return 'Description must be at most ' + SITE_DESC_MAX_WORDS + ' words.';
    }
    return '';
}

function syncSiteDescriptionCounter() {
    const html = quill ? quill.root.innerHTML : ($('#siteDescription').val() || '');
    const plain = siteDescPlainText(html);
    const words = siteDescWordCount(plain);
    const el = document.getElementById('siteDescCounter');
    const err = document.getElementById('siteDescError');
    if (el) {
        el.textContent = plain.length + ' / ' + SITE_DESC_MIN_CHARS + ' chars · ' + words + ' / ' + SITE_DESC_MAX_WORDS + ' words';
        el.classList.remove('is-invalid', 'is-ok');
        const msg = siteDescValidationMessage(plain);
        if (msg) el.classList.add('is-invalid');
        else if (plain) el.classList.add('is-ok');
    }
    if (err && !err.dataset.serverError) {
        const msg = siteDescValidationMessage(plain);
        if (msg && plain) {
            err.textContent = msg;
            err.classList.remove('d-none');
            err.classList.add('d-block');
        }
    }
}

if (typeof Quill !== 'undefined' && document.getElementById('quillEditor')) {
    try {
        quill = new Quill('#quillEditor', {
            theme: 'snow',
            placeholder: SITE_DESC_PLACEHOLDER,
            modules: {
                toolbar: [
                    ['bold', 'italic'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    ['link']
                ]
            }
        });
        const initialDesc = document.getElementById('siteDescription')?.value || '';
        if (initialDesc) {
            quill.root.innerHTML = initialDesc;
        }
        const serverErr = document.getElementById('siteDescError');
        if (serverErr && serverErr.getAttribute('data-server-error') === '1') {
            serverErr.dataset.serverError = '1';
        }
        syncSiteDescriptionCounter();
    } catch (e) {
        console.warn('Quill init failed', e);
    }
}

// FR1 — progressive disclosure for sensitive topics
$('#sensitiveDisclosureBtn').on('click', function () {
    const panel = $('#sensitiveDisclosurePanel');
    const open = panel.prop('hidden');
    panel.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
    $(this).find('i').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
});

$('#placementDisclosureBtn').on('click', function () {
    const panel = $('#placementDisclosurePanel');
    const open = panel.prop('hidden');
    panel.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
    $(this).find('i').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
});

function setPlacementDisclosureOpen(open) {
    const panel = $('#placementDisclosurePanel');
    const btn = $('#placementDisclosureBtn');
    if (!panel.length || !btn.length) return;
    panel.prop('hidden', !open);
    btn.attr('aria-expanded', open ? 'true' : 'false');
    btn.find('i').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
}

function clearHomepageSocialFields() {
    $('.homepage-checkbox').prop('checked', false);
    $('.homepage-price').val('');
    $('.social-checkbox').prop('checked', false);
}

function fillHomepageSocialFromSite(site) {
    clearHomepageSocialFields();
    let hasPlacement = false;

    let homepage = site.homepage_placement_prices || null;
    if (typeof homepage === 'string') {
        try { homepage = JSON.parse(homepage); } catch (e) { homepage = null; }
    }
    if (homepage && typeof homepage === 'object') {
        Object.keys(homepage).forEach(function (days) {
            const $cb = $(`#homepage${days}`);
            if (!$cb.length) return;
            $cb.prop('checked', true);
            $(`input[name="price_homepage[${days}]"]`).val(homepage[days]);
            hasPlacement = true;
        });
    }

    let social = site.social_promotion || null;
    if (typeof social === 'string') {
        try { social = JSON.parse(social); } catch (e) { social = null; }
    }
    if (social && typeof social === 'object') {
        ['facebook', 'instagram', 'x'].forEach(function (channel) {
            if (!social[channel]) return;
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            $(id).prop('checked', true);
            hasPlacement = true;
        });
    }

    if (hasPlacement) {
        setPlacementDisclosureOpen(true);
    }
}

// FR3 — inline validation on blur
function markFieldValidity(el) {
    if (!el || !el.checkValidity) return;
    if (el.value === '' && !el.required) {
        el.classList.remove('is-invalid', 'is-valid');
        return;
    }
    if (el.checkValidity()) {
        el.classList.remove('is-invalid');
        el.classList.add('is-valid');
    } else {
        el.classList.remove('is-valid');
        el.classList.add('is-invalid');
    }
}

$('#addSiteForm').on('blur', 'input[required], select[required]', function () {
    markFieldValidity(this);
});

// ==================== Single Select Component for Country & Language ====================
function initSingleSelect(wrapperId, inputId, dropdownId, optionsId, hiddenInputId, searchId, valueDisplayId, placeholderText = 'Select option...') {
    let selectedValue = '';
    let selectedLabel = '';
    let allowedValues = null; // null = all options available
    const wrapper = $(`#${wrapperId}`);
    const input = $(`#${inputId}`);
    const dropdown = $(`#${dropdownId}`);
    const optionsContainer = $(`#${optionsId}`);
    const hiddenInput = $(`#${hiddenInputId}`);
    const searchInput = $(`#${searchId}`);
    const valueDisplay = $(`#${valueDisplayId}`);

    function updateDisplay() {
        if (selectedValue && selectedLabel) {
            valueDisplay.html(selectedLabel);
        } else {
            valueDisplay.html(`<span class="single-select-placeholder">${placeholderText}</span>`);
        }
        hiddenInput.val(selectedValue || '');
        hiddenInput.trigger('change');
        updateOptionsHighlight();
    }

    function selectOption(value, label) {
        selectedValue = value;
        selectedLabel = label;
        updateDisplay();
        dropdown.removeClass('show');
    }

    function updateOptionsHighlight() {
        optionsContainer.find('.single-select-option').each(function() {
            const $this = $(this);
            const value = String($this.data('value'));
            $this.toggleClass('selected', selectedValue === value);
        });
    }

    function isOptionAllowed(value) {
        if (allowedValues === null) return true;
        return allowedValues.includes(String(value).toLowerCase());
    }

    function filterOptions(searchTerm) {
        const term = (searchTerm || '').toLowerCase();
        optionsContainer.find('.single-select-option').each(function() {
            const $this = $(this);
            const value = String($this.data('value')).toLowerCase();
            const text = $this.text().toLowerCase();
            const matchesSearch = term === '' || text.includes(term);
            const matchesAllowed = isOptionAllowed(value);

            // Keep all countries visible (search can still hide); fade non-matching markets
            $this.toggleClass('hidden', !matchesSearch);
            $this.toggleClass('disabled', allowedValues !== null && !matchesAllowed);
            $this.toggleClass('suggested', allowedValues !== null && matchesAllowed);
        });

        // Suggested (allowed) countries first, then faded ones
        if (allowedValues !== null) {
            const opts = optionsContainer.find('.single-select-option').get();
            opts.sort((a, b) => {
                const aAllowed = $(a).hasClass('suggested') ? 0 : 1;
                const bAllowed = $(b).hasClass('suggested') ? 0 : 1;
                if (aAllowed !== bAllowed) return aAllowed - bAllowed;
                return $(a).text().localeCompare($(b).text());
            });
            optionsContainer.append(opts);
        }
    }

    function setAllowedValues(values) {
        allowedValues = values === null ? null : values.map(v => String(v).toLowerCase());
        // Clear selection if current value is no longer allowed
        if (selectedValue && allowedValues !== null && !allowedValues.includes(String(selectedValue).toLowerCase())) {
            selectedValue = '';
            selectedLabel = '';
            updateDisplay();
        } else {
            filterOptions(searchInput.val());
            updateOptionsHighlight();
        }
    }

    function setPlaceholder(text) {
        placeholderText = text;
        if (!selectedValue) {
            valueDisplay.html(`<span class="single-select-placeholder">${placeholderText}</span>`);
        }
    }

    input.on('click', function(e) {
        e.stopPropagation();
        $('.single-select-dropdown').not(dropdown).removeClass('show');
        $('.single-select-input').not(input).attr('aria-expanded', 'false');
        $('.multi-select-dropdown').removeClass('show');
        dropdown.toggleClass('show');
        const open = dropdown.hasClass('show');
        input.attr('aria-expanded', open ? 'true' : 'false');
        if (open) {
            searchInput.focus();
            filterOptions('');
        }
    });

    $(document).on('click', function() {
        $('.single-select-dropdown').removeClass('show');
        $('.single-select-input').attr('aria-expanded', 'false');
    });

    dropdown.on('click', function(e) {
        e.stopPropagation();
    });

    searchInput.on('keyup', function() {
        filterOptions($(this).val());
    });

    optionsContainer.on('click', '.single-select-option', function(e) {
        const $option = $(this);
        if ($option.hasClass('hidden') || $option.hasClass('disabled')) return;
        selectOption(String($option.data('value')), $option.data('label'));
    });

    function setSelectedValue(value, label) {
        selectedValue = value ? String(value).toLowerCase() : '';
        selectedLabel = label || '';
        updateDisplay();
    }

    function getSelectedValue() {
        return selectedValue;
    }

    function clearSelection() {
        selectedValue = '';
        selectedLabel = '';
        updateDisplay();
        searchInput.val('');
        filterOptions('');
    }

    // Initial placeholder
    updateDisplay();

    return {
        selectOption,
        setSelectedValue,
        getSelectedValue,
        clearSelection,
        setAllowedValues,
        setPlaceholder,
        filterOptions
    };
}

// Categories use shared Catalog-parity multi-select (public/js/multi-select.js):
// Enter adds sole/focused match, Backspace peels last chip, empty state, max 7.

window.countryLanguageMap = (window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.countryLanguageMap)
    || window.countryLanguageMap
    || {};
const countryLanguageMap = window.countryLanguageMap;

// Country first → language list filtered by allowed pairs
let countrySingleSelect = initSingleSelect(
    'countryWrapper', 'countryInput', 'countryDropdown', 'countryOptions',
    'selectedCountry', 'countrySearch', 'countryValue', 'Select country...'
);
let languageSingleSelect = initSingleSelect(
    'languageWrapper', 'languageInput', 'languageDropdown', 'languageOptions',
    'selectedLanguage', 'languageSearch', 'languageValue', 'Select country first...'
);

function relatedLanguageCodesForCountry(countryCode) {
    const related = [];
    (countryLanguageMap[countryCode] || []).forEach(item => {
        const code = typeof item === 'string' ? item : (item.code || '');
        if (code) related.push(String(code).toLowerCase());
    });
    return Array.from(new Set(related));
}

function applyCountryLanguageFilter(countryCode, { clearLanguage = true, preferLanguage = null } = {}) {
    const hint = $('#relatedLanguagesHint');
    if (!countryCode) {
        languageSingleSelect.setAllowedValues([]);
        languageSingleSelect.setPlaceholder('Select country first...');
        if (clearLanguage) languageSingleSelect.clearSelection();
        if (hint.length) hint.text('Select a country first.');
        return;
    }

    const relatedCodes = relatedLanguageCodesForCountry(countryCode);
    languageSingleSelect.setAllowedValues(relatedCodes.length ? relatedCodes : null);
    languageSingleSelect.setPlaceholder('Select language...');
    if (clearLanguage) languageSingleSelect.clearSelection();

    if (relatedCodes.length === 1) {
        const only = relatedCodes[0];
        const opt = $(`#languageOptions .single-select-option[data-value="${only}"]`);
        if (opt.length) {
            languageSingleSelect.setSelectedValue(only, opt.data('label'));
        }
        if (hint.length) hint.text('Language locked to ' + (opt.data('label') || only.toUpperCase()) + ' for this country.');
    } else if (relatedCodes.length) {
        if (preferLanguage && relatedCodes.indexOf(String(preferLanguage).toLowerCase()) !== -1) {
            const code = String(preferLanguage).toLowerCase();
            const opt = $(`#languageOptions .single-select-option[data-value="${code}"]`);
            if (opt.length) languageSingleSelect.setSelectedValue(code, opt.data('label'));
        }
        const labels = relatedCodes.map(code => {
            const opt = $(`#languageOptions .single-select-option[data-value="${code}"]`);
            return opt.length ? opt.data('label') : code.toUpperCase();
        });
        if (hint.length) hint.text('Allowed: ' + labels.join(', '));
    } else if (hint.length) {
        hint.text('No paired languages for this country.');
    }
}

let syncingCountryLanguage = false;
$('#selectedCountry').on('change', function() {
    if (syncingCountryLanguage) return;
    applyCountryLanguageFilter($(this).val() || '', { clearLanguage: true });
});

// Start with languages locked until country is chosen
applyCountryLanguageFilter('', { clearLanguage: false });


// Restore validation-old values from Blade config (if any)
(function hydratePublisherSiteFormOld() {
    const old = (window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.old) || {};
    if (old.country) {
        const code = String(old.country).toLowerCase();
        const opt = $(`#countryOptions .single-select-option[data-value="${code}"]`);
        if (opt.length) {
            syncingCountryLanguage = true;
            countrySingleSelect.setSelectedValue(code, opt.data('label'));
            applyCountryLanguageFilter(code, {
                clearLanguage: false,
                preferLanguage: old.language ? String(old.language).toLowerCase() : null
            });
            syncingCountryLanguage = false;
        }
    }
})();

// Initialize Category Multi Select (max 7) — guard so a missing multi-select.js
// never throws and breaks country/language selects further down this file.
let categoryMultiSelect = null;
try {
    if (typeof window.initMultiSelect === 'function') {
        categoryMultiSelect = window.initMultiSelect({
            wrapperId: 'categoryWrapper',
            inputId: 'categoryInput',
            dropdownId: 'categoryDropdown',
            optionsId: 'categoryOptions',
            hiddenInputId: 'selectedCategories',
            searchId: 'categorySearch',
            emptyId: 'categoryEmpty',
            maxSelections: 7,
            placeholderText: 'Select categories (max 7)...',
        });
    }
} catch (e) {
    console.error('Publisher category multi-select init threw', e);
}
if (!categoryMultiSelect) {
    console.error('Publisher category multi-select failed to init — is multi-select.js loaded?');
    categoryMultiSelect = {
        addItem: function () { return false; },
        removeItem: function () {},
        getSelectedItems: function () { return []; },
        clearSelections: function () {},
        setSelectedItems: function () {},
        updateDisplay: function () {},
    };
}
(function hydratePublisherSiteCategoriesOld() {
    const old = (window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.old) || {};
    let oldCategories = old.categories || [];
    if (typeof oldCategories === 'string') {
        oldCategories = String(oldCategories).split(/[|,]/).map(v => v.trim()).filter(Boolean);
    }
    if (oldCategories && oldCategories.length) {
        $('#categoryOptions .multi-select-option').each(function() {
            let val = $(this).data('value');
            if (oldCategories.includes(val)) {
                categoryMultiSelect.addItem(val, $(this).attr('data-label') || val);
            }
        });
    }
})();

const SITE_DRAFT_KEY = 'publisher_add_site_draft_v1';
let wizardStep = 1;
const wizardTotalSteps = 3;

function setWizardStep(step) {
    wizardStep = Math.max(1, Math.min(wizardTotalSteps, step));
    $('.wizard-pane').removeClass('active');
    $(`.wizard-pane[data-wizard-pane="${wizardStep}"]`).addClass('active');

    $('#siteWizardSteps .site-wizard-step').each(function() {
        const s = parseInt($(this).data('step'), 10);
        $(this).toggleClass('active', s === wizardStep);
        $(this).toggleClass('done', s < wizardStep);
    });

    $('#wizardBackBtn').toggleClass('d-none', wizardStep === 1);
    $('#wizardNextBtn').toggleClass('d-none', wizardStep === wizardTotalSteps);
    $('#submitBtn').toggleClass('d-none', wizardStep !== wizardTotalSteps);
}

function saveSiteDraft() {
    try {
        const draft = {
            siteName: $('#siteName').val(),
            siteUrl: $('#siteUrl').val(),
            exampleUrl: $('#exampleUrl').val(),
            da: $('#da').val(),
            dr: $('#dr').val(),
            traffic: $('#traffic').val(),
            turnaround_time: $('#turnaroundTime').val(),
            price: $('#price').val(),
            publicationTime: $('#publicationTime').val(),
            link_type: $('input[name="link_type"]:checked').val() || 'dofollow',
            language: $('#selectedLanguage').val(),
            country: $('#selectedCountry').val(),
            categories: $('#selectedCategories').val(),
            site_tag: $('input[name="site_tag"]:checked').val() || '',
            siteDescription: quill ? quill.root.innerHTML : ($('#siteDescription').val() || ''),
            sensitive: {},
            price_sensitive: {},
            homepage: {},
            price_homepage: {},
            social: {},
            step: wizardStep,
            savedAt: Date.now()
        };
        ['crypto','trading','CBD','forex'].forEach(topic => {
            draft.sensitive[topic] = $(`#sensitive${topic}`).is(':checked');
            draft.price_sensitive[topic] = $(`input[name="price_sensitive[${topic}]"]`).val();
        });
        [1, 7, 30].forEach(days => {
            draft.homepage[days] = $(`#homepage${days}`).is(':checked');
            draft.price_homepage[days] = $(`input[name="price_homepage[${days}]"]`).val();
        });
        ['facebook', 'instagram', 'x'].forEach(channel => {
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            draft.social[channel] = $(id).is(':checked');
        });
        localStorage.setItem(SITE_DRAFT_KEY, JSON.stringify(draft));
        $('#wizardDraftHint').text('Draft saved');
    } catch (e) {
        // ignore storage errors
    }
}

function clearSiteDraft() {
    try { localStorage.removeItem(SITE_DRAFT_KEY); } catch (e) {}
    $('#wizardDraftHint').text('');
}

function loadSiteDraft() {
    try {
        const raw = localStorage.getItem(SITE_DRAFT_KEY);
        if (!raw) return false;
        const draft = JSON.parse(raw);
        if (!draft || typeof draft !== 'object') return false;

        $('#siteName').val(draft.siteName || '');
        $('#siteUrl').val(draft.siteUrl || '');
        $('#exampleUrl').val(draft.exampleUrl || '');
        $('#da').val(draft.da || '');
        $('#dr').val(draft.dr || '');
        $('#traffic').val(draft.traffic || '');
        $('#turnaroundTime').val(draft.turnaround_time || '3days');
        $('#price').val(draft.price || '');
        $('#publicationTime').val(draft.publicationTime || '');
        if (draft.link_type === 'nofollow') {
            $('#linkTypeNofollow').prop('checked', true);
        } else {
            $('#linkTypeDofollow').prop('checked', true);
        }
        const draftTag = draft.site_tag
            || (draft.sponsored ? 'sponsored' : '')
            || (draft.partner_material ? 'partner_material' : '')
            || (draft.as_you_prefer ? 'as_you_prefer' : '');
        $(`input[name="site_tag"][value="${draftTag}"]`).prop('checked', true);
        if (!draftTag) $('#tagNone').prop('checked', true);
        if (draft.siteDescription) {
            if (quill) quill.root.innerHTML = draft.siteDescription;
            $('#siteDescription').val(draft.siteDescription);
            syncSiteDescriptionCounter();
        }
        ['crypto','trading','CBD','forex'].forEach(topic => {
            $(`#sensitive${topic}`).prop('checked', !!(draft.sensitive && draft.sensitive[topic]));
            $(`input[name="price_sensitive[${topic}]"]`).val((draft.price_sensitive && draft.price_sensitive[topic]) || '');
        });
        let hasPlacementDraft = false;
        [1, 7, 30].forEach(days => {
            const on = !!(draft.homepage && draft.homepage[days]);
            $(`#homepage${days}`).prop('checked', on);
            $(`input[name="price_homepage[${days}]"]`).val((draft.price_homepage && draft.price_homepage[days]) || '');
            if (on) hasPlacementDraft = true;
        });
        ['facebook', 'instagram', 'x'].forEach(channel => {
            const on = !!(draft.social && draft.social[channel]);
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            $(id).prop('checked', on);
            if (on) hasPlacementDraft = true;
        });
        if (hasPlacementDraft) {
            setPlacementDisclosureOpen(true);
        }

        if (draft.country) {
            const countryOpt = $(`#countryOptions .single-select-option[data-value="${draft.country}"]`);
            if (countryOpt.length) {
                syncingCountryLanguage = true;
                countrySingleSelect.setSelectedValue(draft.country, countryOpt.data('label'));
                applyCountryLanguageFilter(draft.country, {
                    clearLanguage: false,
                    preferLanguage: draft.language || null
                });
                syncingCountryLanguage = false;
            }
        } else {
            applyCountryLanguageFilter('', { clearLanguage: true });
        }
        if (draft.categories) {
            const raw = String(draft.categories);
            const cats = raw.split(raw.includes('|') ? '|' : ',').map(c => c.trim()).filter(Boolean);
            categoryMultiSelect.clearSelections();
            cats.forEach(val => {
                const opt = $(`#categoryOptions .multi-select-option[data-value="${val}"]`);
                if (opt.length) categoryMultiSelect.addItem(val, opt.attr('data-label') || val);
            });
        }

        setWizardStep(draft.step || 1);
        $('#wizardDraftHint').text('Draft restored');
        return true;
    } catch (e) {
        return false;
    }
}

function validateWizardStep(step) {
    const pane = $(`.wizard-pane[data-wizard-pane="${step}"]`);
    let ok = true;
    let message = '';

    pane.find('input[required], select[required]').each(function() {
        if (!this.checkValidity()) {
            ok = false;
            $(this).addClass('is-invalid');
            if (!message) message = this.validationMessage || 'Please fill in all required fields.';
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    if (step === 1) {
        const html = quill ? quill.root.innerHTML : ($('#siteDescription').val() || '');
        const desc = siteDescPlainText(html);
        const msg = siteDescValidationMessage(desc);
        if (msg) {
            ok = false;
            message = message || msg;
            const err = document.getElementById('siteDescError');
            if (err) {
                err.textContent = msg;
                err.classList.remove('d-none');
                err.classList.add('d-block');
                delete err.dataset.serverError;
            }
        } else if (quill) {
            $('#siteDescription').val(quill.root.innerHTML);
        }
        syncSiteDescriptionCounter();
    }

    if (step === 2) {
        if (!countrySingleSelect.getSelectedValue()) {
            ok = false;
            message = message || 'Please select a country / market.';
        }
        if (!languageSingleSelect.getSelectedValue()) {
            ok = false;
            message = message || 'Please select a language.';
        }
        if (categoryMultiSelect.getSelectedItems().length === 0) {
            ok = false;
            message = message || 'Please select at least one category.';
        }
    }

    if (!ok) {
        Swal.fire({ icon: 'error', title: 'Almost there', text: message || 'Please complete this step.' });
    }
    return ok;
}

$('#wizardNextBtn').on('click', function() {
    if (!validateWizardStep(wizardStep)) return;
    saveSiteDraft();
    setWizardStep(wizardStep + 1);
});

$('#wizardBackBtn').on('click', function() {
    saveSiteDraft();
    setWizardStep(wizardStep - 1);
});

$('#addSiteForm').on('change input', 'input, select, textarea', function() {
    if ($('#methodField').val() === 'POST') {
        saveSiteDraft();
    }
});
if (quill) {
    quill.on('text-change', function() {
        const err = document.getElementById('siteDescError');
        if (err && err.dataset.serverError) {
            delete err.dataset.serverError;
            err.classList.add('d-none');
            err.classList.remove('d-block');
            err.textContent = '';
        }
        syncSiteDescriptionCounter();
        if ($('#methodField').val() === 'POST') {
            saveSiteDraft();
        }
    });
}

// Toggle form for CREATE
addBtn.on('click', function() {
    formCard.toggleClass('d-none');
    let isOpen = !formCard.hasClass('d-none');

    addBtn.toggleClass('d-none', isOpen);
    bulkBtn.toggleClass('d-none', isOpen);
    formHeaderSpan.text('Add New Website');

    if(isOpen){
        // Reset form for new site
        sitePreviewConfirmed = false;
        $('#addSiteForm')[0].reset();
        $('#methodField').val('POST');
        $('#addSiteForm').attr('action', (window.PublisherWebsitesConfig.routes.store));
        if (quill) quill.root.innerHTML = '';
        submitBtn.prop('disabled', false).text('Review & submit');
        
        // Reset selects
        languageSingleSelect.clearSelection();
        countrySingleSelect.clearSelection();
        applyCountryLanguageFilter('', { clearLanguage: true });
        categoryMultiSelect.clearSelections();
        
        // Enable site name and URL for create
        $('#siteName').prop('disabled', false);
        $('#siteUrl').prop('disabled', false);
        $('.readonly-note').remove();

        const restored = loadSiteDraft();
        if (!restored) {
            setWizardStep(1);
            $('#wizardDraftHint').text('');
        }
    }
});

bulkBtn.on('click', function() {
    // Opens #bulkRequestModal via data-bs-toggle; keep single-site form closed.
    formCard.addClass('d-none');
    closeBtn.addClass('d-none');
    formHeaderSpan.text('Add New Website');
});

// Toggle form for CREATE — keep existing addBtn handler below
/* ============ REVIEW BEFORE SUBMIT ============ */
let sitePreviewConfirmed = false;

function previewEscape(str) {
    return String(str === null || str === undefined ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function previewValue(selector) {
    const $el = $(selector);
    if (!$el.length) return '';
    if ($el.is('select')) {
        const label = $el.find('option:selected').text();
        return $.trim(label || $el.val() || '');
    }
    return $.trim($el.val() || '');
}

/**
 * Country, language and niches are custom dropdowns backed by hidden inputs
 * holding codes, so reading the input gives "us" where the publisher chose
 * "United States". Look the label back up from the option they picked.
 */
function previewLabelFor(hiddenSelector, optionsSelector) {
    const value = $.trim($(hiddenSelector).val() || '');
    if (!value) return '';

    const $option = $(optionsSelector).find('[data-value="' + value.replace(/"/g, '\\"') + '"]').first();
    return $.trim($option.data('label') || $option.text() || value);
}

function previewNiches() {
    // The hidden field joins the chosen niche names with a pipe.
    return $.trim($('#selectedCategories').val() || '')
        .split('|')
        .map(function (name) { return $.trim(name); })
        .filter(Boolean)
        .join(', ');
}

function previewHomepagePromotions() {
    const parts = [];
    [1, 7, 30].forEach(function (days) {
        if (!$('#homepage' + days).is(':checked')) return;
        const raw = $.trim($('input[name="price_homepage[' + days + ']"]').val() || '');
        const fee = raw === '' ? 0 : parseFloat(raw);
        const label = days + ' day' + (days > 1 ? 's' : '');
        if (!Number.isFinite(fee) || fee <= 0) {
            parts.push(label + ' — Free');
        } else {
            parts.push(label + ' — +€' + fee.toFixed(2));
        }
    });
    return parts.join('; ');
}

function previewSocialChannels() {
    const labels = { facebook: 'Facebook', instagram: 'Instagram', x: 'X' };
    return ['facebook', 'instagram', 'x']
        .filter(function (channel) {
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            return $(id).is(':checked');
        })
        .map(function (channel) { return labels[channel] || channel; })
        .join(', ');
}

function previewSiteTagLabel() {
    const labels = {
        sponsored: 'Sponsored',
        partner_material: 'Partner article',
        as_you_prefer: 'As you prefer',
    };
    const value = previewValue('#addSiteForm [name="site_tag"]');
    return labels[value] || value;
}

function previewRow(label, value, opts) {
    opts = opts || {};
    const missing = !value;
    const shown = missing ? (opts.emptyLabel || 'Not set') : value;
    // A blank required field is called out rather than quietly omitted — that
    // is the whole reason for the screen. Optional ones stay muted, so red
    // always means something needs attention.
    const cls = missing ? (opts.optional ? 'text-muted' : 'text-danger fst-italic') : '';
    return '<div class="row g-2 py-2 border-bottom">' +
        '<div class="col-5 col-md-4 text-muted small">' + previewEscape(label) + '</div>' +
        '<div class="col-7 col-md-8 ' + cls + '">' + previewEscape(shown) + '</div>' +
        '</div>';
}

function previewDescriptionBlock(description) {
    if (!description) {
        return '<div class="border rounded-3 p-3 mb-3"><span class="text-danger fst-italic">Not set</span></div>';
    }

    // Clamp in place with Show more — keeps Submit visible without a side panel or horizontal scroll.
    return '<div class="border rounded-3 p-3 mb-3 site-preview-desc-wrap">' +
        '<div class="site-preview-desc is-clamped">' + previewEscape(description) + '</div>' +
        '<button type="button" class="btn btn-link btn-sm px-0 mt-1 site-preview-desc-toggle d-none" ' +
            'aria-expanded="false">Show more</button>' +
        '</div>';
}

function syncSitePreviewDescToggles(root) {
    if (!root) return;
    root.querySelectorAll('.site-preview-desc-wrap').forEach(function (wrap) {
        const desc = wrap.querySelector('.site-preview-desc');
        const btn = wrap.querySelector('.site-preview-desc-toggle');
        if (!desc || !btn) return;

        const wasExpanded = !desc.classList.contains('is-clamped');
        desc.classList.add('is-clamped');
        const needsToggle = desc.scrollHeight > desc.clientHeight + 1;
        if (!needsToggle) {
            desc.classList.remove('is-clamped');
            btn.classList.add('d-none');
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Show more';
            return;
        }

        btn.classList.remove('d-none');
        if (wasExpanded) {
            desc.classList.remove('is-clamped');
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = 'Show less';
        } else {
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Show more';
        }
    });
}

function buildSitePreview() {
    const price = previewValue('#addSiteForm [name="price"]');
    const description = quill
        ? $.trim($(quill.root).text())
        : $.trim($('<div>').html($('#siteDescription').val() || '').text());

    let html = '<div class="mb-3">' +
        '<div class="fw-semibold fs-5">' + previewEscape(previewValue('#addSiteForm [name="siteName"]') || 'Untitled site') + '</div>' +
        '<div class="text-muted small">' + previewEscape(previewValue('#addSiteForm [name="siteUrl"]')) + '</div>' +
        '</div>';

    html += '<div class="border rounded-3 p-3 mb-3">';
    html += previewRow('Price advertisers pay', price ? '€' + price : '');
    html += previewRow('Domain Authority (DA)', previewValue('#addSiteForm [name="da"]'));
    html += previewRow('Domain Rating (DR)', previewValue('#addSiteForm [name="dr"]'));
    html += previewRow('Monthly traffic', previewValue('#addSiteForm [name="traffic"]'));
    html += previewRow('Country', previewLabelFor('#selectedCountry', '#countryOptions'));
    html += previewRow('Language', previewLabelFor('#selectedLanguage', '#languageOptions'));
    html += previewRow('Niches', previewNiches());
    html += previewRow('Link type', previewValue('#addSiteForm [name="link_type"]'));
    html += previewRow('Turnaround time', previewValue('#addSiteForm [name="turnaround_time"]'));
    html += previewRow('Publication time', previewValue('#addSiteForm [name="publicationTime"]'));
    html += previewRow('Site tag', previewSiteTagLabel(), { emptyLabel: 'No tags', optional: true });
    html += previewRow('Example post', previewValue('#addSiteForm [name="exampleUrl"]'), { emptyLabel: 'None', optional: true });
    html += previewRow('Homepage promotions', previewHomepagePromotions(), { emptyLabel: 'None', optional: true });
    html += previewRow('Social', previewSocialChannels(), { emptyLabel: 'None', optional: true });
    html += '</div>';

    html += '<div class="text-muted small mb-1">Description advertisers will read</div>';
    html += previewDescriptionBlock(description);

    // The turnaround time is a promise we hold publishers to in reminder
    // emails, so it is worth naming here rather than burying in the table.
    const turnaround = previewValue('#addSiteForm [name="turnaround_time"]');
    if (turnaround) {
        html += '<div class="alert alert-light border small mb-0">' +
            'Once you accept an order we will expect the article live within <strong>' +
            previewEscape(turnaround) + '</strong>, and will remind you as that deadline approaches.' +
            '</div>';
    }

    return html;
}

$('#sitePreviewConfirmBtn').on('click', function () {
    sitePreviewConfirmed = true;
    if (quill) {
        $('#siteDescription').val(quill.root.innerHTML);
    }
    if ($('#methodField').val() !== 'PUT') {
        clearSiteDraft();
    }
    const modalEl = document.getElementById('sitePreviewModal');
    const instance = bootstrap.Modal.getInstance(modalEl);
    if (instance) instance.hide();

    // Native submit: bypasses jQuery's submit handlers and avoids Chromium
    // cancelling the POST when the submit control is disabled mid-submit.
    const formEl = document.getElementById('addSiteForm');
    submitBtn.prop('disabled', true).html('<span class="loading-spinner"></span> Saving...');
    if (formEl) {
        HTMLFormElement.prototype.submit.call(formEl);
    }
});

$('#sitePreviewBody').on('click', '.site-preview-desc-toggle', function () {
    const wrap = this.closest('.site-preview-desc-wrap');
    const desc = wrap ? wrap.querySelector('.site-preview-desc') : null;
    if (!desc) return;
    const expanded = desc.classList.toggle('is-clamped') === false;
    this.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    this.textContent = expanded ? 'Show less' : 'Show more';
});

document.getElementById('sitePreviewModal')?.addEventListener('shown.bs.modal', function () {
    syncSitePreviewDescToggles(document.getElementById('sitePreviewBody'));
});

function resetPublisherSubmitButton() {
    const isEdit = $('#methodField').val() === 'PUT';
    submitBtn.prop('disabled', false).text(isEdit ? 'Review & update' : 'Review & submit');
}

// Back-forward cache / failed navigation: never leave the CTA stuck on Saving…
window.addEventListener('pageshow', function () {
    sitePreviewConfirmed = false;
    resetPublisherSubmitButton();
});

$('#addSiteForm').submit(function(e){
    if (quill) $('#siteDescription').val(quill.root.innerHTML);

    for (let s = 1; s <= wizardTotalSteps; s++) {
        if (!validateWizardStep(s)) {
            e.preventDefault();
            setWizardStep(s);
            return;
        }
    }

    let form = this;
    // Temporarily show all panes so native validity covers every required field
    $('.wizard-pane').addClass('active');
    if(!form.checkValidity()){
        e.preventDefault();
        e.stopPropagation();
        $(form).addClass('was-validated');
        for (let s = 1; s <= wizardTotalSteps; s++) {
            const pane = $(`.wizard-pane[data-wizard-pane="${s}"]`);
            if (pane.find('input:invalid, select:invalid').length > 0) {
                setWizardStep(s);
                return;
            }
        }
        setWizardStep(wizardStep);
    } else if (!sitePreviewConfirmed) {
        // Everything is valid but nobody has seen the listing whole yet.
        e.preventDefault();
        $('#sitePreviewBody').html(buildSitePreview());
        const previewModal = new bootstrap.Modal(document.getElementById('sitePreviewModal'));
        previewModal.show();
        // Measure clamp after paint in case shown.bs.modal already fired.
        requestAnimationFrame(function () {
            syncSitePreviewDescToggles(document.getElementById('sitePreviewBody'));
        });
    } else {
        if ($('#methodField').val() !== 'PUT') {
            clearSiteDraft();
        }
        // Defer disable: Chromium aborts form submit when the submitting
        // button is disabled synchronously inside the submit handler.
        setTimeout(function () {
            submitBtn.prop('disabled', true).html('<span class="loading-spinner"></span> Saving...');
        }, 0);
    }
});

// Fetch sites
let sitesStatusFilter = (function () {
    try {
        const raw = (new URLSearchParams(window.location.search).get('status') || 'active').toLowerCase();
        return (raw === 'pending' || raw === 'active' || raw === 'invites' || raw === 'archived') ? raw : 'active';
    } catch (e) {
        return 'active';
    }
})();
const ACTIVE_SITES_SEEN_KEY = 'slb_publisher_active_sites_seen_v1';

function parseActiveIds(raw) {
    if (!raw) return [];
    return String(raw)
        .split(',')
        .map(function (part) { return parseInt(part, 10); })
        .filter(function (id) { return Number.isFinite(id) && id > 0; });
}

function getSeenActiveSiteIds() {
    try {
        const raw = localStorage.getItem(ACTIVE_SITES_SEEN_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(parsed)) return new Set();
        return new Set(parsed.map(function (id) { return parseInt(id, 10); }).filter(Boolean));
    } catch (e) {
        return new Set();
    }
}

function saveSeenActiveSiteIds(ids) {
    try {
        localStorage.setItem(ACTIVE_SITES_SEEN_KEY, JSON.stringify(Array.from(ids)));
    } catch (e) { /* ignore quota / private mode */ }
}

function markActiveSitesSeen(activeIds) {
    const seen = getSeenActiveSiteIds();
    (activeIds || []).forEach(function (id) { seen.add(id); });
    saveSeenActiveSiteIds(seen);
}

function syncNewActiveBadges(activeIds, markSeen) {
    const ids = Array.isArray(activeIds) ? activeIds : [];
    const seen = getSeenActiveSiteIds();

    if (seen.size === 0) {
        // First visit: seed current actives so historical listings don't flash as "new".
        saveSeenActiveSiteIds(new Set(ids));
        markSeen = false;
    } else if (markSeen) {
        markActiveSitesSeen(ids);
    }

    const latestSeen = markSeen ? getSeenActiveSiteIds() : (seen.size === 0 ? new Set(ids) : seen);
    const newIdSet = new Set(ids.filter(function (id) { return !latestSeen.has(id); }));

    document.querySelectorAll('[data-site-new-badge]').forEach(function (badge) {
        const row = badge.closest('tr.main-row');
        const id = row ? parseInt(row.getAttribute('data-id') || '', 10) : 0;
        const isNew = id > 0 && newIdSet.has(id);
        if (window.PulseBadge && typeof window.PulseBadge.sync === 'function') {
            window.PulseBadge.sync(badge, isNew ? 1 : 0);
            if (isNew) {
                badge.textContent = 'New';
                badge.classList.add('is-visible');
            } else {
                badge.textContent = '';
                badge.classList.remove('is-visible');
            }
        } else if (isNew) {
            badge.hidden = false;
            badge.textContent = 'New';
            badge.classList.add('is-visible', 'is-pulsing', 'pulse-badge');
        } else {
            badge.hidden = true;
            badge.textContent = '';
            badge.classList.remove('is-visible', 'is-pulsing');
        }
        badge.setAttribute('aria-label', isNew ? 'Newly approved site' : 'Not new');
    });

    return newIdSet.size;
}

function syncSitesFilterUi(pendingCount, activeCount, status, activeIds, inviteCount) {
    const pendingCountEl = document.getElementById('sitesPendingCount');
    const activeCountEl = document.getElementById('sitesActiveCount');
    const inviteCountEl = document.getElementById('sitesInviteCount');
    const hint = document.getElementById('sitesFilterHint');
    const meta = document.getElementById('sitesStatusMeta');
    const bulkWaiting = parseInt(meta?.getAttribute('data-bulk-waiting') || '0', 10);
    const openBulk = meta?.getAttribute('data-open-bulk') === '1';
    const invites = inviteCount ?? parseInt(meta?.getAttribute('data-invites') || '0', 10);

    if (pendingCountEl) {
        pendingCountEl.textContent = String(pendingCount ?? 0);
        pendingCountEl.classList.toggle('text-bg-secondary', !(pendingCount > 0));
        pendingCountEl.classList.toggle('text-bg-warning', pendingCount > 0);
    }
    if (activeCountEl) activeCountEl.textContent = String(activeCount ?? 0);
    if (inviteCountEl) {
        inviteCountEl.textContent = String(invites || 0);
        inviteCountEl.classList.toggle('text-bg-secondary', !(invites > 0));
        inviteCountEl.classList.toggle('text-bg-info', invites > 0);
    }

    document.querySelectorAll('.site-status-filter').forEach(function (btn) {
        const on = btn.getAttribute('data-status') === status;
        btn.classList.toggle('is-active', on);
        btn.classList.remove('btn-primary', 'btn-outline-secondary');
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    const ids = Array.isArray(activeIds) ? activeIds : parseActiveIds(
        meta?.getAttribute('data-active-ids') || ''
    );
    syncNewActiveBadges(ids, false);

    if (hint) {
        if (status === 'active') {
            hint.textContent = 'Approved and live sites on your panel.';
        } else if (status === 'invites') {
            hint.textContent = 'Sites our team added for you — accept to move them into My Sites, or decline to remove them.';
        } else if (bulkWaiting > 0) {
            hint.textContent = bulkWaiting === 1
                ? '1 site is with our marketer; others below may need your details or admin review.'
                : bulkWaiting + ' sites are with our marketer; others below may need your details or admin review.';
        } else if (openBulk) {
            hint.textContent = 'Your bulk request is open — drafts appear here as the marketer adds them, then you finish details.';
        } else {
            hint.textContent = 'Drafts that need your details, plus sites waiting for admin approval.';
        }
    }
}

function initSitesTableTips(root) {
    const scope = root || document.getElementById('sitesTableWrapper') || document;
    if (window.GlassTip && typeof window.GlassTip.enhance === 'function') {
        window.GlassTip.enhance(scope);
    }
    initSitePreviewZoom(scope);
}

function initSitePreviewZoom(root) {
    const scope = root || document;
    if (!window.matchMedia || window.matchMedia('(hover: none)').matches) return;

    let pop = document.getElementById('sitePreviewZoomPop');
    if (!pop) {
        pop = document.createElement('div');
        pop.id = 'sitePreviewZoomPop';
        pop.className = 'site-preview-zoom-pop';
        pop.setAttribute('aria-hidden', 'true');
        pop.innerHTML = '<img alt="" decoding="async">';
        document.body.appendChild(pop);
    }
    const img = pop.querySelector('img');
    let hideTimer = null;

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

    function show(trigger) {
        const src = trigger.getAttribute('data-zoom-src');
        if (!src || trigger.classList.contains('is-empty')) return;
        clearTimeout(hideTimer);
        if (img.getAttribute('src') !== src) {
            img.setAttribute('src', src);
        }
        img.setAttribute('alt', trigger.getAttribute('aria-label') || 'Site preview');
        pop.classList.add('is-visible');
        place(trigger);
        requestAnimationFrame(function () { place(trigger); });
    }

    function hide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            pop.classList.remove('is-visible');
        }, 80);
    }

    scope.querySelectorAll('.site-row-preview[data-zoom-src]').forEach(function (el) {
        if (el.getAttribute('data-zoom-ready') === '1') return;
        el.setAttribute('data-zoom-ready', '1');
        el.addEventListener('mouseenter', function () { show(el); });
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', function () { show(el); });
        el.addEventListener('blur', hide);
    });
}

function fetchSites(page = 1, query = '', opts = {}) {
    window.__publisherSitesList = {
        page: parseInt(page, 10) || 1,
        query: query == null ? '' : String(query),
    };
    $('#sitesTableWrapper').html('<div class="text-muted">Loading...</div>');

    $.ajax({
        url: (window.PublisherWebsitesConfig.routes.ajax),
        method: 'GET',
        dataType: 'html',
        data: { page: page, query: query, status: sitesStatusFilter },
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        success: function(res) {
            const html = (res || '').trim();
            // Session expiry / middleware redirect often returns the login page HTML.
            if (html.includes('name="password"') && html.includes('/login')) {
                $('#sitesTableWrapper').html(
                    '<div class="text-center py-4">' +
                    '<div class="text-danger mb-2">Your session expired. Please refresh and sign in again.</div>' +
                    '<a class="btn btn-sm btn-primary" href="' + (window.PublisherWebsitesConfig.routes.login) + '">Sign in</a>' +
                    '</div>'
                );
                return;
            }
            if (html === '') {
                if (sitesStatusFilter === 'invites') {
                    $('#sitesTableWrapper').html(
                        '<div class="alert alert-light border text-center mb-0">' +
                        '<i class="fa fa-inbox me-2 text-muted"></i>' +
                        'No site invites waiting. When our team adds a website for you, Accept / Decline appear here.' +
                        '</div>'
                    );
                } else {
                    $('#sitesTableWrapper').html(
                        '<div class="ui-empty-state text-center mx-auto py-4" style="max-width:420px">' +
                        '<div class="mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,var(--brand-primary, #1a585e))" aria-hidden="true"><i class="fa-solid fa-globe"></i></div>' +
                        '<h5 class="mb-2">No websites listed yet</h5>' +
                        '<p class="text-muted mb-3">Add your first site so advertisers can find and order from you.</p>' +
                        '<button type="button" class="btn btn-primary btn-sm" id="emptyAddSiteCta"><i class="fa fa-plus"></i> Add New Website</button>' +
                        '</div>'
                    );
                    $('#emptyAddSiteCta').on('click', function(){ $('#showFormBtn').trigger('click'); });
                }
                syncNewActiveBadges([], !!opts.acknowledgeNewActive);
            } else {
                $('#sitesTableWrapper').html(html);
                const meta = document.getElementById('sitesStatusMeta');
                const activeIds = parseActiveIds(meta?.getAttribute('data-active-ids') || '');
                if (meta) {
                    syncSitesFilterUi(
                        parseInt(meta.getAttribute('data-pending') || '0', 10),
                        parseInt(meta.getAttribute('data-active') || '0', 10),
                        meta.getAttribute('data-status') || sitesStatusFilter,
                        activeIds,
                        parseInt(meta.getAttribute('data-invites') || '0', 10)
                    );
                }
                if (opts.acknowledgeNewActive) {
                    syncNewActiveBadges(activeIds, true);
                }
                initSitesTableTips(document.getElementById('sitesTableWrapper'));
            }
        },
        error: function(xhr) {
            const message = xhr.status === 403
                ? 'You do not have access to load sites. Refresh the page (or switch to Publisher) and try again.'
                : (xhr.status === 401 || xhr.status === 419)
                    ? 'Your session expired. Please refresh and sign in again.'
                    : 'Failed to load sites.';
            $('#sitesTableWrapper').html(
                '<div class="text-center py-4">' +
                '<div class="text-danger mb-2">' + message + '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-primary me-2" id="retrySitesBtn">Retry</button>' +
                '<button type="button" class="btn btn-sm btn-primary" id="reloadSitesPageBtn">Refresh page</button>' +
                '</div>'
            );
            $('#reloadSitesPageBtn').on('click', function () {
                window.location.reload();
            });
            $('#retrySitesBtn').on('click', function () {
                fetchSites(page, query);
            });
        }
    });
}
window.loadSites = fetchSites;

// Debounced search
let delayTimer;
$(document).ready(function(){
    syncSitesFilterUi(0, 0, sitesStatusFilter);
    fetchSites();
    if (sitesStatusFilter === 'pending' || sitesStatusFilter === 'invites') {
        const section = document.getElementById('sitesTableWrapper');
        if (section && typeof section.scrollIntoView === 'function') {
            setTimeout(function () {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 120);
        }
    }

    $(document).on('click', '.site-status-filter', function () {
        const next = this.getAttribute('data-status') || 'active';
        const acknowledgeNewActive = next === 'active';
        if (next === sitesStatusFilter) {
            if (acknowledgeNewActive) {
                const meta = document.getElementById('sitesStatusMeta');
                syncNewActiveBadges(parseActiveIds(meta?.getAttribute('data-active-ids') || ''), true);
            }
            return;
        }
        sitesStatusFilter = next;
        syncSitesFilterUi(
            parseInt(document.getElementById('sitesPendingCount')?.textContent || '0', 10),
            parseInt(document.getElementById('sitesActiveCount')?.textContent || '0', 10),
            sitesStatusFilter,
            null,
            parseInt(document.getElementById('sitesInviteCount')?.textContent || '0', 10)
        );
        fetchSites(1, $('#siteSearch').val(), { acknowledgeNewActive: acknowledgeNewActive });
    });

    // Catalog-parity live search (350ms debounce, min 2 chars, Enter flush).
    (function initSiteSearchLive() {
        const input = document.getElementById('siteSearch');
        if (!input || typeof window.SlbLiveSearch === 'undefined') {
            $('#siteSearch').on('keyup', function(){
                clearTimeout(delayTimer);
                delayTimer = setTimeout(() => {
                    fetchSites(1, $(this).val());
                }, 400);
            });
            return;
        }
        window.SlbLiveSearch.init(input, {
            mode: 'event',
            statusEl: document.getElementById('siteSearchStatus'),
            clearBtn: document.getElementById('siteSearchClear'),
            onSearch: function (detail) {
                clearTimeout(delayTimer);
                fetchSites(1, detail.query);
            },
        });
    })();

    $(document).on('click', '.pagination a', function(e){
        const href = $(this).attr('href');
        if (!href || href === '#') return;
        e.preventDefault();
        let page = $(this).data('page');
        if (!page) {
            try {
                page = new URL(href, window.location.origin).searchParams.get('page') || 1;
            } catch (err) {
                page = 1;
            }
        }
        fetchSites(page, $('#siteSearch').val());
    });

    $(document).on('click', '.pagination li[data-page]', function(){
        const page = $(this).data('page');
        if (page) fetchSites(page, $('#siteSearch').val());
    });
});

// Close form
closeBtn.on('click', function(){
    if ($('#methodField').val() !== 'PUT') {
        saveSiteDraft();
    }
    formCard.addClass('d-none');
    addBtn.removeClass('d-none');
    bulkBtn.removeClass('d-none');
    formHeaderSpan.text('Add New Website');
    sitePreviewConfirmed = false;
    $('#addSiteForm')[0].reset();
    if (quill) quill.root.innerHTML = '';
    $('.tag-checkbox').prop('checked', false);
    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    clearHomepageSocialFields();
    setPlacementDisclosureOpen(false);
    
    // Reset selects
    languageSingleSelect.clearSelection();
    countrySingleSelect.clearSelection();
    applyCountryLanguageFilter('', { clearLanguage: true });
    categoryMultiSelect.clearSelections();
    
    $('#siteName').prop('disabled', false);
    $('#siteUrl').prop('disabled', false);
    $('.readonly-note').remove();
    setWizardStep(1);
    $('#wizardDraftHint').text('');
});

// Expand / hide site details (handlers live here so AJAX table HTML needs no scripts)
$(document).on('click', '.action-view', function(e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const expandRow = $('#expand-' + id);
    expandRow.toggleClass('expanded');

    const icon = $(this).find('i');
    const text = $(this).find('.btn-text');
    if (expandRow.hasClass('expanded')) {
        icon.removeClass('fa-eye').addClass('fa-eye-slash');
        text.text('Hide');
    } else {
        icon.removeClass('fa-eye-slash').addClass('fa-eye');
        text.text('View');
    }
});

$(document).on('click', '.btn-delete', function() {
    const form = $(this).closest('form');
    Swal.fire({
        title: 'Are you sure?',
        text: 'This site will be deleted permanently!',
        icon: 'warning',
        showCancelButton: true,
        customClass: { confirmButton: 'slb-swal-danger' },
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
});

// Edit functionality - Prefill all values
$(document).on('click', '.btn-edit', function() {
    const site = $(this).data('site');
    if (!site || !site.id) {
        Swal.fire({ icon: 'error', title: 'Could not edit', text: 'Site data failed to load. Refresh and try again.' });
        return;
    }

    // Show form
    $('#formCard').removeClass('d-none');
    $('#showFormBtn').addClass('d-none');
    $('#showBulkRequestBtn').addClass('d-none');
    $('#formHeader').text('Edit Site: ' + site.site_name);
    setWizardStep(1);
    $('#wizardDraftHint').text('');
    
    // Set form action for update
    $('#methodField').remove();
    $('#addSiteForm')
        .attr('action', '/publisher/sites/' + site.id)
        .append('<input type="hidden" name="_method" value="PUT" id="methodField">');
    
    // Prefill all fields
    $('#siteName').val(site.site_name).prop('disabled', true);
    $('#siteUrl').val(site.site_url).prop('disabled', true);
    
    // Add readonly message
    if (!$('#siteName').next('.readonly-note').length) {
        $('#siteName').after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
    }
    if (!$('#siteUrl').next('.readonly-note').length) {
        $('#siteUrl').after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
    }
    
    $('#exampleUrl').val(site.example_url);
    $('#da').val(site.da);
    $('#dr').val(site.dr);
    $('#traffic').val(site.traffic);
    $('#price').val(site.price);
    $('#turnaroundTime').val(site.turnaround_time || '3days');
    $('#publicationTime').val(site.publication_time);
    
    // Link type radio
    if (site.link_type === 'dofollow') {
        $('#linkTypeDofollow').prop('checked', true);
    } else {
        $('#linkTypeNofollow').prop('checked', true);
    }
    
    // Tags (single radio)
    let siteTag = '';
    if (site.sponsored == 1) siteTag = 'sponsored';
    else if (site.partner_material == 1) siteTag = 'partner_material';
    else if (site.as_you_prefer == 1) siteTag = 'as_you_prefer';
    $(`input[name="site_tag"][value="${siteTag}"]`).prop('checked', true);
    if (!siteTag) $('#tagNone').prop('checked', true);
    
    // Sensitive topics
    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    clearHomepageSocialFields();
    setPlacementDisclosureOpen(false);
    
    if (site.sensitive_prices) {
        let prices = typeof site.sensitive_prices === 'string' ? JSON.parse(site.sensitive_prices) : site.sensitive_prices;
        for (const key in prices) {
            $(`#sensitive${key.charAt(0).toUpperCase() + key.slice(1)}`).prop('checked', true);
            $(`input[name="price_sensitive[${key}]"]`).val(prices[key]);
        }
    }

    fillHomepageSocialFromSite(site);
    
    // Country first, then paired language
    const langCode = (site.language || site.language_code || (Array.isArray(site.languages) ? site.languages[0] : null) || '').toString().toLowerCase();
    const countryCode = (site.country || site.country_code || (Array.isArray(site.countries) ? site.countries[0] : null) || '').toString().toLowerCase();

    syncingCountryLanguage = true;
    languageSingleSelect.clearSelection();
    countrySingleSelect.clearSelection();
    if (countryCode) {
        const countryOpt = $(`#countryOptions .single-select-option[data-value="${countryCode}"]`);
        if (countryOpt.length) {
            countrySingleSelect.setSelectedValue(countryCode, countryOpt.data('label'));
            applyCountryLanguageFilter(countryCode, {
                clearLanguage: false,
                preferLanguage: langCode || null
            });
        }
    } else {
        applyCountryLanguageFilter('', { clearLanguage: true });
    }
    syncingCountryLanguage = false;
    
    // Set Categories
    categoryMultiSelect.clearSelections();
    if (site.categories) {
        let categoriesArray = typeof site.categories === 'string' ? JSON.parse(site.categories) : site.categories;
        categoriesArray.forEach(categoryName => {
            let option = $(`#categoryOptions .multi-select-option[data-value="${categoryName}"]`);
            if (option.length) {
                categoryMultiSelect.addItem(categoryName, option.attr('data-label') || categoryName);
            }
        });
    } else if (site.category) {
        // Fallback for pipe/comma-separated legacy category column
        const raw = String(site.category);
        raw.split(raw.includes('|') ? '|' : ',').map(v => v.trim()).filter(Boolean).forEach(categoryName => {
            let option = $(`#categoryOptions .multi-select-option[data-value="${categoryName}"]`);
            if (option.length) {
                categoryMultiSelect.addItem(categoryName, option.attr('data-label') || categoryName);
            }
        });
    }
    
    // Description
    if (quill) {
        quill.root.innerHTML = site.description || '';
    }
    
    $('#submitBtn').prop('disabled', false).text('Review & update');
    
    // Scroll to form
    $('html, body').animate({
        scrollTop: $("#formCard").offset().top - 100
    }, 500);
});
})(); // publisherWebsitesExternalBoot
}

/*
 * Always-on row actions: Blade sets __publisherWebsitesInlineLoaded and skips the
 * form/table boot above, but Accept / Decline / Get Verified / promotions still need handlers.
 */
(function publisherWebsitesAlwaysOnActions() {
'use strict';

function reloadPublisherSitesTable(page) {
    const last = window.__publisherSitesList || { page: 1, query: '' };
    const q = (window.jQuery && $('#siteSearch').length)
        ? $('#siteSearch').val()
        : last.query;
    const p = page || last.page || 1;
    if (typeof window.loadSites === 'function') {
        window.loadSites(p, q);
    }
}

function setPublisherSitesFilter(status) {
    if (typeof window.setSitesStatusFilter === 'function') {
        window.setSitesStatusFilter(status);
    }
}

/* —— File-based site verification —— */
function verificationCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || (window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.csrfToken)
        || '';
}

async function postSiteVerification(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-CSRF-TOKEN': verificationCsrfToken(),
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({}));
    if (res.status === 419) {
        return {
            success: false,
            message: 'Your session expired. Refresh the page and try Get Verified again.',
            error_code: 'csrf',
        };
    }
    if (res.status === 401 || res.status === 403) {
        return {
            success: false,
            message: data.message || 'Please sign in again to verify this website.',
            error_code: 'auth',
        };
    }
    return data;
}

async function startSiteVerification(siteId, regenerate = false) {
    return postSiteVerification(`/publisher/sites/${siteId}/verification/start`, {
        regenerate: !!regenerate,
    });
}

async function checkSiteVerification(siteId) {
    return postSiteVerification(`/publisher/sites/${siteId}/verification/check`, {});
}

function verificationErrorTitle(errorCode) {
    switch (errorCode) {
        case 'not_found':
            return 'File not found';
        case 'mismatch':
            return 'Wrong content';
        case 'unreachable':
            return 'Site not reachable';
        case 'not_public':
            return 'File not publicly accessible';
        case 'rate_limited':
            return 'Too many checks';
        case 'incomplete':
            return 'Finish site details';
        case 'pending_acceptance':
            return 'Accept this site first';
        case 'csrf':
            return 'Session expired';
        case 'auth':
            return 'Sign in required';
        default:
            return 'Not verified yet';
    }
}

function verificationInstructionsHtml(data, siteName) {
    const esc = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const token = esc(data.token || '');
    const fileName = esc(data.file_name || 'seolinkbuildings-verify.txt');
    const fileUrl = esc(data.file_url || '');
    return `
        <div class="text-start">
            <p class="mb-2">Upload a small file to prove you control this domain. After we find it, your site gets the Verified badge automatically.</p>
            <ol class="mb-3 ps-3">
                <li class="mb-2">Create a file named <code>${fileName}</code></li>
                <li class="mb-2">Paste this code into the file:<br>
                    <code id="verifyTokenCode" style="display:inline-block;margin-top:6px;padding:6px 8px;background:#f1f5f5;border-radius:6px;word-break:break-all;">${token}</code>
                </li>
                <li class="mb-2">Upload it to your site root so it opens at:<br>
                    <a href="${fileUrl}" target="_blank" rel="noopener noreferrer"><code>${fileUrl}</code></a>
                </li>
                <li>Click <strong>Check verification</strong></li>
            </ol>
            <p class="small text-muted mb-0">Keep the file live until verification succeeds. You can regenerate a new code if needed.</p>
        </div>
    `;
}

async function openSiteVerificationDialog(siteId, siteName) {
    Swal.fire({
        title: 'Preparing verification…',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
    });

    let data;
    try {
        data = await startSiteVerification(siteId, false);
    } catch (e) {
        await Swal.fire({
            icon: 'error',
            title: 'Network error',
            text: 'Could not start verification. Check your connection and try again.',
        });
        return;
    }

    if (data.verified) {
        await Swal.fire({ icon: 'success', title: 'Already verified', text: data.message || 'This website is already verified.' });
        reloadPublisherSitesTable();
        return;
    }
    if (!data.success || !data.token) {
        await Swal.fire({
            icon: 'error',
            title: verificationErrorTitle(data.error_code),
            text: data.message || 'Unable to start verification.',
        });
        return;
    }

    while (true) {
        const choice = await Swal.fire({
            title: 'Verify this website',
            html: verificationInstructionsHtml(data, siteName),
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'Check verification',
            denyButtonText: 'Regenerate code',
            cancelButtonText: 'Close',
            width: 560,
        });

        if (choice.isDismissed) {
            break;
        }

        if (choice.isDenied) {
            Swal.fire({
                title: 'Generating new code…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });
            let regen;
            try {
                regen = await startSiteVerification(siteId, true);
            } catch (e) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Network error',
                    text: 'Could not regenerate the code. Try again.',
                });
                break;
            }
            if (!regen.success || !regen.token) {
                await Swal.fire({
                    icon: 'error',
                    title: verificationErrorTitle(regen.error_code),
                    text: regen.message || 'Try again.',
                });
                break;
            }
            Object.assign(data, regen);
            continue;
        }

        Swal.fire({
            title: 'Checking file…',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });
        let result;
        try {
            result = await checkSiteVerification(siteId);
        } catch (e) {
            await Swal.fire({
                icon: 'error',
                title: 'Network error',
                text: 'Could not check the verification file. Try again.',
                confirmButtonText: 'Back to instructions',
            });
            continue;
        }
        if (result.success && result.verified) {
            await Swal.fire({
                icon: 'success',
                title: 'Verified!',
                text: result.message || 'Your Verified badge is now live.',
            });
            reloadPublisherSitesTable();
            break;
        }

        await Swal.fire({
            icon: 'error',
            title: verificationErrorTitle(result.error_code),
            text: result.message || 'Upload the file, then try again.',
            confirmButtonText: 'Back to instructions',
        });
    }
}

window.openSiteVerificationDialog = openSiteVerificationDialog;

$(document).on('click', '.btn-verify-site', function () {
    const id = $(this).data('id');
    const name = $(this).data('name') || 'this website';
    openSiteVerificationDialog(id, name);
});

async function postSiteAssignment(url) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
        },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) {
        throw new Error(data.message || 'Request failed');
    }
    return data;
}

$(document).on('click', '.btn-accept-assignment', async function () {
    const id = $(this).data('id');
    const name = $(this).data('name') || 'this website';
    const confirm = await Swal.fire({
        icon: 'question',
        title: 'Accept this site?',
        text: name + ' will appear in My Sites (Pending) until staff activate it.',
        showCancelButton: true,
        confirmButtonText: 'Accept',
    });
    if (!confirm.isConfirmed) return;
    try {
        const data = await postSiteAssignment(`/publisher/sites/${id}/accept-assignment`);
        Swal.fire({ icon: 'success', title: data.message || 'Accepted', timer: 2200, showConfirmButton: false });
        setPublisherSitesFilter('pending');
        reloadPublisherSitesTable(1);
    } catch (e) {
        Swal.fire({ icon: 'error', title: e.message || 'Could not accept' });
    }
});

$(document).on('click', '.btn-reject-assignment', async function () {
    const id = $(this).data('id');
    const name = $(this).data('name') || 'this website';
    const confirm = await Swal.fire({
        icon: 'warning',
        title: 'Decline this invite?',
        text: name + ' will be removed from your account.',
        showCancelButton: true,
        confirmButtonText: 'Decline',
        customClass: { confirmButton: 'slb-swal-danger' },
    });
    if (!confirm.isConfirmed) return;
    try {
        const data = await postSiteAssignment(`/publisher/sites/${id}/reject-assignment`);
        Swal.fire({ icon: 'success', title: data.message || 'Declined', timer: 2200, showConfirmButton: false });
        reloadPublisherSitesTable(1);
    } catch (e) {
        Swal.fire({ icon: 'error', title: e.message || 'Could not decline' });
    }
});

/* —— Site promotions: Feature / Discount / Bulk (single path) —— */
if (!window.__publisherPromoHandlersBound) {
window.__publisherPromoHandlersBound = true;

function promoEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function promoCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || (window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.csrfToken)
        || '';
}

function reloadSitesAfterPromo() {
    reloadPublisherSitesTable();
}

function promoDaysLeft(iso) {
    if (!iso) return 0;
    const end = Date.parse(iso);
    if (Number.isNaN(end)) return 0;
    const ms = end - Date.now();
    if (ms <= 0) return 0;
    return Math.max(1, Math.ceil(ms / 86400000));
}

function promoFormatDate(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function promoBetterOfNote() {
    return 'Advertisers get the better of a timed sale or bulk — they are not added together.';
}

async function startFeatureStripeCheckout(siteId) {
    const res = await fetch(`/publisher/sites/${siteId}/feature/checkout`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    });
    const data = await res.json().catch(() => ({}));
    if (data.success && data.checkout_url) {
        window.location.href = data.checkout_url;
        return;
    }
    Swal.fire({ icon: 'error', title: 'Checkout unavailable', text: data.message || 'Could not start card payment.' });
}

$(document).on('click', '.btn-feature-site', async function () {
    const $btn = $(this);
    const id = $btn.data('id');
    const name = promoEscapeHtml($btn.data('name'));
    const featuredUntil = $btn.attr('data-featured-until') || '';
    const daysLeft = promoDaysLeft(featuredUntil);
    const isLive = daysLeft > 0;
    const isVerified = String($btn.attr('data-verified') ?? '1') === '1';
    const cfg = window.PublisherWebsitesConfig || { routes: {} };
    let wallet = {
        feature_price: 10,
        feature_days: 7,
        balance: 0,
        top_up_url: cfg.routes.balance,
        stripe_available: true,
    };
    try {
        const w = await fetch(cfg.routes.promotionsWallet, { headers: { 'Accept': 'application/json' } });
        wallet = await w.json();
    } catch (e) {}

    const spendable = Number(wallet.withdrawable ?? wallet.balance ?? 0);
    const canWallet = spendable >= Number(wallet.feature_price || 10);
    const featureDays = Number(wallet.feature_days || 7);
    const featurePrice = Number(wallet.feature_price || 10).toFixed(2);
    const unverifiedNote = isVerified
        ? ''
        : '<p class="small text-muted">This site is active but not verified. Featuring still works; advertisers may trust it less.</p>';
    const body = isLive
        ? `<p><strong>${name}</strong> is already featured until <strong>${promoEscapeHtml(promoFormatDate(featuredUntil))}</strong> (${daysLeft} day${daysLeft === 1 ? '' : 's'} left).</p>
           <p>Paying <strong>€${featurePrice}</strong> adds another <strong>${featureDays} days</strong>.</p>`
        : `<p>Feature <strong>${name}</strong> for <strong>${featureDays} days</strong> to boost catalog visibility.</p>
           <p class="mb-1">Cost: <strong>€${featurePrice}</strong></p>`;
    const result = await Swal.fire({
        title: isLive ? 'Extend featuring?' : 'Feature this website?',
        html: `${body}
               ${unverifiedNote}
               <p class="small text-muted">Publisher earnings: €${spendable.toFixed(2)} (bonus cannot be used for featuring)</p>
               <p class="small text-muted">Pay from earnings, or pay securely by card with Stripe.</p>`,
        showDenyButton: !!wallet.stripe_available,
        showCancelButton: true,
        confirmButtonText: canWallet ? 'Pay from wallet' : 'Use card / top up',
        denyButtonText: wallet.stripe_available ? 'Pay by card' : undefined,
    });

    if (result.isDenied) {
        return startFeatureStripeCheckout(id);
    }
    if (!result.isConfirmed) return;
    if (!canWallet) {
        return Swal.fire({
            icon: 'info',
            title: 'Insufficient balance',
            html: `Top up your wallet or pay by card.<br><br>
                   <button type="button" class="btn btn-sm btn-primary me-1" id="swalPayCard">Pay by card</button>
                   <a class="btn btn-sm btn-outline-secondary" href="${wallet.top_up_url || wallet.balance_url || '#'}">Add Funds</a>`,
            didOpen: () => {
                document.getElementById('swalPayCard')?.addEventListener('click', () => startFeatureStripeCheckout(id));
            },
            showConfirmButton: false,
            showCancelButton: true,
        });
    }

    const res = await fetch(`/publisher/sites/${id}/feature`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    });
    const data = await res.json().catch(() => ({}));
    if (data.success) {
        Swal.fire({ icon: 'success', title: 'Featured!', text: data.message });
        reloadSitesAfterPromo();
    } else if (data.needs_top_up) {
        Swal.fire({
            icon: 'info',
            title: 'Top up or pay by card',
            html: `${promoEscapeHtml(data.message || '')}<br><br>
                   <button type="button" class="btn btn-sm btn-primary me-1" id="swalPayCard2">Pay by card (€${Number(wallet.feature_price || 10).toFixed(2)})</button>
                   <a class="btn btn-sm btn-outline-secondary" href="${wallet.top_up_url || wallet.balance_url || '#'}">Add Funds</a>`,
            didOpen: () => {
                document.getElementById('swalPayCard2')?.addEventListener('click', () => startFeatureStripeCheckout(id));
            },
            showConfirmButton: false,
            showCancelButton: true,
        });
    } else {
        Swal.fire({ icon: 'error', title: 'Could not feature', text: data.message || 'Failed' });
    }
});

$(document).on('click', '.btn-discount-site', async function () {
    const $btn = $(this);
    const id = $btn.data('id');
    const name = promoEscapeHtml($btn.data('name'));
    const current = $btn.attr('data-percent') || $btn.data('percent') || 15;
    const endsAt = $btn.attr('data-ends') || '';
    const remaining = promoDaysLeft(endsAt);
    const isLive = $btn.hasClass('is-on') || remaining > 0;
    const daysPrefill = isLive ? Math.min(90, Math.max(1, remaining || 7)) : 7;
    const result = await Swal.fire({
        title: isLive ? 'Update timed sale' : 'Set timed discount',
        html: `<p class="small text-muted">${isLive
            ? `Sale on <strong>${name}</strong> has about <strong>${daysPrefill}</strong> day${daysPrefill === 1 ? '' : 's'} left. Change the percent or remaining days, or end it now.`
            : `Discount for <strong>${name}</strong>. Ends automatically; you’ll get an email when it ends.`}</p>
               <p class="small text-muted">${promoEscapeHtml(promoBetterOfNote())}</p>
               <label for="swal-pct" class="small fw-semibold d-block text-start ms-3 mb-0">Discount percent (1–70)</label>
               <input id="swal-pct" type="number" min="1" max="70" class="swal2-input" placeholder="Percent (1–70)" value="${promoEscapeHtml(current)}">
               <label for="swal-days" class="small fw-semibold d-block text-start ms-3 mb-0 mt-2">Days active (1–90)</label>
               <input id="swal-days" type="number" min="1" max="90" class="swal2-input" placeholder="Days active" value="${daysPrefill}">`,
        showDenyButton: isLive,
        showCancelButton: true,
        confirmButtonText: isLive ? 'Update sale' : 'Publish sale',
        denyButtonText: isLive ? 'End sale now' : undefined,
        customClass: isLive ? { denyButton: 'slb-swal-danger' } : undefined,
        preConfirm: () => ({
            percent: document.getElementById('swal-pct').value,
            days: document.getElementById('swal-days').value,
        }),
    });
    if (result.isDenied) {
        const res = await fetch(`/publisher/sites/${id}/discount`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': promoCsrfToken(), 'Accept': 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
        if (data.success) { reloadSitesAfterPromo(); }
        return;
    }
    if (!result.isConfirmed || !result.value) return;
    const res = await fetch(`/publisher/sites/${id}/discount`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(result.value),
    });
    const data = await res.json().catch(() => ({}));
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) { reloadSitesAfterPromo(); }
});

$(document).on('click', '.btn-bulk-site', async function () {
    const $btn = $(this);
    const id = $btn.data('id');
    const joined = String($btn.attr('data-joined')) === '1';
    const cfg = window.PublisherWebsitesConfig || {};
    const bulkMin = Number(cfg.bulkMinPercent ?? cfg.routes?.bulkMinPercent ?? 10);
    const bulkMax = Number(cfg.bulkMaxPercent ?? cfg.routes?.bulkMaxPercent ?? 80);
    const currentPct = Number($btn.attr('data-percent') || bulkMin);
    const result = await Swal.fire({
        title: joined ? `Bulk −${currentPct}% is on` : 'Join bulk discount program',
        html: `<p class="small text-muted">${promoEscapeHtml(promoBetterOfNote())}</p>
               <label for="swal-bulk-pct" class="small fw-semibold d-block text-start ms-3 mb-0">Discount % for 3–5 articles (${bulkMin}–${bulkMax})</label>
               <input id="swal-bulk-pct" type="number" min="${bulkMin}" max="${bulkMax}" step="1" class="swal2-input" value="${joined ? currentPct : bulkMin}">`,
        showDenyButton: joined,
        showCancelButton: true,
        confirmButtonText: joined ? 'Update percent' : 'Join',
        denyButtonText: joined ? 'Leave programme' : undefined,
        customClass: joined ? { denyButton: 'slb-swal-danger' } : undefined,
        preConfirm: () => {
            const n = Number(document.getElementById('swal-bulk-pct')?.value);
            if (!Number.isFinite(n) || n < bulkMin || n > bulkMax) {
                Swal.showValidationMessage(`Enter a percent from ${bulkMin} to ${bulkMax}.`);
                return false;
            }
            return n;
        },
    });
    if (result.isDenied) {
        const res = await fetch(`/publisher/sites/${id}/bulk-discount`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': promoCsrfToken(), 'Accept': 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
        if (data.success) { reloadSitesAfterPromo(); }
        return;
    }
    if (!result.isConfirmed || result.value === undefined || result.value === null || result.value === '') return;
    const res = await fetch(`/publisher/sites/${id}/bulk-discount`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ percent: result.value }),
    });
    const data = await res.json().catch(() => ({}));
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) { reloadSitesAfterPromo(); }
});
}

})(); // publisherWebsitesAlwaysOnActions

/*
 * Listing preview before submit — always-on so it works when Blade owns the form.
 * Blade calls window.showSiteListingPreview() from the inline submit gate.
 */
(function publisherWebsitesListingPreview() {
'use strict';

window.sitePreviewConfirmed = false;

function previewEscape(str) {
    return String(str === null || str === undefined ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function previewValue(selector) {
    const $el = $(selector);
    if (!$el.length) return '';
    if ($el.is('select')) {
        const label = $el.find('option:selected').text();
        return $.trim(label || $el.val() || '');
    }
    return $.trim($el.val() || '');
}

function previewLabelFor(hiddenSelector, optionsSelector) {
    const value = $.trim($(hiddenSelector).val() || '');
    if (!value) return '';

    const $option = $(optionsSelector).find('[data-value="' + value.replace(/"/g, '\\"') + '"]').first();
    return $.trim($option.data('label') || $option.text() || value);
}

function previewNiches() {
    return $.trim($('#selectedCategories').val() || '')
        .split('|')
        .map(function (name) { return $.trim(name); })
        .filter(Boolean)
        .join(', ');
}

function previewHomepagePromotions() {
    const parts = [];
    [1, 7, 30].forEach(function (days) {
        if (!$('#homepage' + days).is(':checked')) return;
        const raw = $.trim($('input[name="price_homepage[' + days + ']"]').val() || '');
        const fee = raw === '' ? 0 : parseFloat(raw);
        const label = days + ' day' + (days > 1 ? 's' : '');
        if (!Number.isFinite(fee) || fee <= 0) {
            parts.push(label + ' — Free');
        } else {
            parts.push(label + ' — +€' + fee.toFixed(2));
        }
    });
    return parts.join('; ');
}

function previewSocialChannels() {
    const labels = { facebook: 'Facebook', instagram: 'Instagram', x: 'X' };
    return ['facebook', 'instagram', 'x']
        .filter(function (channel) {
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            return $(id).is(':checked');
        })
        .map(function (channel) { return labels[channel] || channel; })
        .join(', ');
}

function previewSiteTagLabel() {
    const labels = {
        sponsored: 'Sponsored',
        partner_material: 'Partner article',
        as_you_prefer: 'As you prefer',
    };
    const value = previewValue('#addSiteForm [name="site_tag"]');
    return labels[value] || value;
}

function previewRow(label, value, opts) {
    opts = opts || {};
    const missing = !value;
    const shown = missing ? (opts.emptyLabel || 'Not set') : value;
    const cls = missing ? (opts.optional ? 'text-muted' : 'text-danger fst-italic') : '';
    return '<div class="row g-2 py-2 border-bottom">' +
        '<div class="col-5 col-md-4 text-muted small">' + previewEscape(label) + '</div>' +
        '<div class="col-7 col-md-8 ' + cls + '">' + previewEscape(shown) + '</div>' +
        '</div>';
}

function previewDescriptionBlock(description) {
    if (!description) {
        return '<div class="border rounded-3 p-3 mb-3"><span class="text-danger fst-italic">Not set</span></div>';
    }

    return '<div class="border rounded-3 p-3 mb-3 site-preview-desc-wrap">' +
        '<div class="site-preview-desc is-clamped">' + previewEscape(description) + '</div>' +
        '<button type="button" class="btn btn-link btn-sm px-0 mt-1 site-preview-desc-toggle d-none" ' +
            'aria-expanded="false">Show more</button>' +
        '</div>';
}

function syncSitePreviewDescToggles(root) {
    if (!root) return;
    root.querySelectorAll('.site-preview-desc-wrap').forEach(function (wrap) {
        const desc = wrap.querySelector('.site-preview-desc');
        const btn = wrap.querySelector('.site-preview-desc-toggle');
        if (!desc || !btn) return;

        const wasExpanded = !desc.classList.contains('is-clamped');
        desc.classList.add('is-clamped');
        const needsToggle = desc.scrollHeight > desc.clientHeight + 1;
        if (!needsToggle) {
            desc.classList.remove('is-clamped');
            btn.classList.add('d-none');
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Show more';
            return;
        }

        btn.classList.remove('d-none');
        if (wasExpanded) {
            desc.classList.remove('is-clamped');
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = 'Show less';
        } else {
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Show more';
        }
    });
}

function publisherQuill() {
    if (typeof window.getPublisherQuill === 'function') {
        return window.getPublisherQuill();
    }
    return null;
}

function buildSitePreview() {
    const price = previewValue('#addSiteForm [name="price"]');
    const q = publisherQuill();
    const description = q
        ? $.trim($(q.root).text())
        : $.trim($('<div>').html($('#siteDescription').val() || '').text());

    let html = '<div class="mb-3">' +
        '<div class="fw-semibold fs-5">' + previewEscape(previewValue('#addSiteForm [name="siteName"]') || 'Untitled site') + '</div>' +
        '<div class="text-muted small">' + previewEscape(previewValue('#addSiteForm [name="siteUrl"]')) + '</div>' +
        '</div>';

    html += '<div class="border rounded-3 p-3 mb-3">';
    html += previewRow('Price advertisers pay', price ? '€' + price : '');
    html += previewRow('Domain Authority (DA)', previewValue('#addSiteForm [name="da"]'));
    html += previewRow('Domain Rating (DR)', previewValue('#addSiteForm [name="dr"]'));
    html += previewRow('Monthly traffic', previewValue('#addSiteForm [name="traffic"]'));
    html += previewRow('Country', previewLabelFor('#selectedCountry', '#countryOptions'));
    html += previewRow('Language', previewLabelFor('#selectedLanguage', '#languageOptions'));
    html += previewRow('Niches', previewNiches());
    html += previewRow('Link type', previewValue('#addSiteForm [name="link_type"]'));
    html += previewRow('Turnaround time', previewValue('#addSiteForm [name="turnaround_time"]'));
    html += previewRow('Publication time', previewValue('#addSiteForm [name="publicationTime"]'));
    html += previewRow('Site tag', previewSiteTagLabel(), { emptyLabel: 'No tags', optional: true });
    html += previewRow('Example post', previewValue('#addSiteForm [name="exampleUrl"]'), { emptyLabel: 'None', optional: true });
    html += previewRow('Homepage promotions', previewHomepagePromotions(), { emptyLabel: 'None', optional: true });
    html += previewRow('Social', previewSocialChannels(), { emptyLabel: 'None', optional: true });
    html += '</div>';

    html += '<div class="text-muted small mb-1">Description advertisers will read</div>';
    html += previewDescriptionBlock(description);

    const turnaround = previewValue('#addSiteForm [name="turnaround_time"]');
    if (turnaround) {
        html += '<div class="alert alert-light border small mb-0">' +
            'Once you accept an order we will expect the article live within <strong>' +
            previewEscape(turnaround) + '</strong>, and will remind you as that deadline approaches.' +
            '</div>';
    }

    return html;
}

window.showSiteListingPreview = function showSiteListingPreview() {
    const body = document.getElementById('sitePreviewBody');
    const modalEl = document.getElementById('sitePreviewModal');
    if (!body || !modalEl || !window.bootstrap) {
        console.warn('Site listing preview modal is missing');
        return;
    }
    body.innerHTML = buildSitePreview();
    const previewModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    previewModal.show();
    requestAnimationFrame(function () {
        syncSitePreviewDescToggles(body);
    });
};

function resetPublisherSubmitButton() {
    const isEdit = $('#methodField').val() === 'PUT';
    $('#submitBtn').prop('disabled', false).text(isEdit ? 'Review & update' : 'Review & submit');
}

$('#sitePreviewConfirmBtn').on('click', function () {
    window.sitePreviewConfirmed = true;
    const q = publisherQuill();
    if (q) {
        $('#siteDescription').val(q.root.innerHTML);
    }
    if ($('#methodField').val() !== 'PUT' && typeof window.clearSiteDraft === 'function') {
        window.clearSiteDraft();
    }
    const modalEl = document.getElementById('sitePreviewModal');
    const instance = modalEl && bootstrap.Modal.getInstance(modalEl);
    if (instance) instance.hide();

    const formEl = document.getElementById('addSiteForm');
    $('#submitBtn').prop('disabled', true).html('<span class="loading-spinner"></span> Saving...');
    if (formEl) {
        HTMLFormElement.prototype.submit.call(formEl);
    }
});

$('#sitePreviewBody').on('click', '.site-preview-desc-toggle', function () {
    const wrap = this.closest('.site-preview-desc-wrap');
    const desc = wrap ? wrap.querySelector('.site-preview-desc') : null;
    if (!desc) return;
    const expanded = desc.classList.toggle('is-clamped') === false;
    this.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    this.textContent = expanded ? 'Show less' : 'Show more';
});

document.getElementById('sitePreviewModal')?.addEventListener('shown.bs.modal', function () {
    syncSitePreviewDescToggles(document.getElementById('sitePreviewBody'));
});

window.addEventListener('pageshow', function () {
    window.sitePreviewConfirmed = false;
    resetPublisherSubmitButton();
});

})(); // publisherWebsitesListingPreview
