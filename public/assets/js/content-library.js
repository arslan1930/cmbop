/* Content Library page logic — boot config from window.ContentLibraryBoot */
(function () {
'use strict';
const boot = window.ContentLibraryBoot || {};
const libraryUpdateUrl = boot.libraryUpdateUrl;
const libraryContentUrl = boot.libraryContentUrl;
const libraryImageUploadUrl = boot.libraryImageUploadUrl;
const libraryOrderUrlBase = boot.libraryOrderUrlBase;
const libraryPreviewUrlBase = boot.libraryPreviewUrlBase;
const libraryCsrf = boot.libraryCsrf;
const libraryLanguageCountryMap = boot.libraryLanguageCountryMap || {};
const libraryCountryLanguageMap = boot.libraryCountryLanguageMap || {};
const libraryPreferredCountry = boot.libraryPreferredCountry || '';
const libraryPreferredLanguage = boot.libraryPreferredLanguage || '';
const libraryUploadsEnabled = !!boot.uploadsEnabled;
const libraryOpenUpload = !!boot.openUpload;
const libraryEditSubmission = boot.editSubmission || null;
const libraryIndexUrl = boot.libraryIndexUrl || '';

let articleQuill = null;
let articleEditorSubmissionId = null;
let articleEditorDetectedLinks = [];
let previewModalState = { title: '', submissionId: null, editable: false, html: '' };
let pendingLibraryLanding = null;
let skipEditorListLanding = false;
let skipPreviewListLanding = false;

function refreshLibraryLanguages(preferredLanguage) {
    const countrySelect = document.getElementById('libraryCountry');
    const langSelect = document.getElementById('libraryLanguage');
    if (!countrySelect || !langSelect) return;
    const country = (countrySelect.value || '').toLowerCase();
    const options = libraryCountryLanguageMap[country] || [];
    const keep = (preferredLanguage || langSelect.value || '').toLowerCase();
    langSelect.innerHTML = '';
    if (!country) {
        langSelect.disabled = true;
        langSelect.innerHTML = '<option value="">Select country first</option>';
        updateMarketChip();
        updateUploadSteps();
        return;
    }
    langSelect.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select language';
    langSelect.appendChild(placeholder);
    options.forEach(function (item) {
        const opt = document.createElement('option');
        opt.value = item.code;
        opt.textContent = item.name;
        if (keep && keep === item.code) opt.selected = true;
        langSelect.appendChild(opt);
    });
    if (options.length === 1) {
        langSelect.value = options[0].code;
    } else if (keep && !Array.from(langSelect.options).some(function (o) { return o.value === keep; })) {
        langSelect.value = '';
    }
    const hint = document.getElementById('libraryLanguageHint');
    if (hint) {
        hint.textContent = options.length
            ? 'Languages paired with this country.'
            : 'No languages are paired with this country.';
    }
    updateMarketChip();
    updateUploadSteps();
}
document.getElementById('libraryCountry')?.addEventListener('change', function () {
    refreshLibraryLanguages('');
});
document.getElementById('libraryLanguage')?.addEventListener('change', function () {
    updateMarketChip();
    updateUploadSteps();
});
document.addEventListener('DOMContentLoaded', function () {
    if (libraryPreferredCountry) {
        const countrySelect = document.getElementById('libraryCountry');
        if (countrySelect) countrySelect.value = libraryPreferredCountry;
    }
    refreshLibraryLanguages(libraryPreferredLanguage);
    bindLibraryDropzone();
    bindLibraryModalA11y();
    bindLibraryResultLanding();
    applyLibraryResultFocus();
});
document.getElementById('uploadContentModal')?.addEventListener('shown.bs.modal', function () {
    refreshLibraryLanguages(libraryPreferredLanguage || document.getElementById('libraryLanguage')?.value || '');
});

function selectedOptionLabel(select) {
    if (!select || !select.selectedIndex || select.selectedIndex < 0) return '';
    const opt = select.options[select.selectedIndex];
    return opt && opt.value ? String(opt.textContent || '').trim() : '';
}

function updateMarketChip() {
    const chip = document.getElementById('libraryMarketChip');
    const country = selectedOptionLabel(document.getElementById('libraryCountry'));
    const language = selectedOptionLabel(document.getElementById('libraryLanguage'));
    if (!chip) return;
    if (!country || !language) {
        chip.classList.add('d-none');
        chip.textContent = '';
        return;
    }
    chip.textContent = country + ' · ' + language;
    chip.classList.remove('d-none');
}

function updateUploadSteps() {
    const file = document.getElementById('libraryFileInput');
    const hasFile = !!(file && file.files && file.files[0]);
    const hasMarket = !!(document.getElementById('libraryCountry')?.value
        && document.getElementById('libraryLanguage')?.value);
    const fileStep = document.querySelector('[data-upload-step="file"]');
    const marketStep = document.querySelector('[data-upload-step="market"]');
    const rightsStep = document.querySelector('[data-upload-step="rights"]');
    if (fileStep) {
        fileStep.classList.toggle('is-done', hasFile);
        fileStep.classList.toggle('is-current', !hasFile);
    }
    if (marketStep) {
        marketStep.classList.toggle('is-done', hasMarket);
        marketStep.classList.toggle('is-current', hasFile && !hasMarket);
    }
    if (rightsStep) {
        rightsStep.classList.add('is-pending');
        rightsStep.classList.toggle('is-current', hasFile && hasMarket);
    }
}

function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    if (bytes < 1024) return bytes + ' B';
    const kb = bytes / 1024;
    if (kb < 1024) return Math.round(kb) + ' KB';
    return (kb / 1024).toFixed(1) + ' MB';
}

function titleFromFilename(name) {
    return String(name || '')
        .replace(/\.docx$/i, '')
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function showDropzoneFile(file) {
    const idle = document.getElementById('libraryDropzoneIdle');
    const shown = document.getElementById('libraryDropzoneFile');
    const zone = document.getElementById('libraryDropzone');
    if (idle) idle.classList.toggle('d-none', !!file);
    if (shown) {
        shown.classList.toggle('d-none', !file);
        shown.innerHTML = file
            ? '<strong>' + escapeHtml(file.name) + '</strong><span>' + escapeHtml(formatFileSize(file.size)) + '</span>'
            : '';
    }
    zone?.classList.remove('is-error', 'is-dragover');
}

function assignLibraryFile(file, feedback) {
    const input = document.getElementById('libraryFileInput');
    const maxKb = Number(boot.maxKilobytes || 5120);
    if (!file || !input) return false;
    if (!/\.docx$/i.test(file.name)) {
        setFeedbackHtml(feedback, false, 'Word .docx only — not PDF, Google Doc, or pasted text.');
        document.getElementById('libraryDropzone')?.classList.add('is-error');
        return false;
    }
    if (file.size > maxKb * 1024) {
        setFeedbackHtml(feedback, false, 'That file is over the ' + Math.max(1, Math.round(maxKb / 1024)) + ' MB limit.');
        document.getElementById('libraryDropzone')?.classList.add('is-error');
        return false;
    }
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    showDropzoneFile(file);
    const titleInput = document.getElementById('libraryTitleInput');
    if (titleInput && (!titleInput.value.trim() || titleInput.dataset.autofilled === '1')) {
        titleInput.value = titleFromFilename(file.name);
        titleInput.dataset.autofilled = '1';
    }
    if (feedback) feedback.textContent = '';
    updateUploadSteps();
    return true;
}

function bindLibraryDropzone() {
    const zone = document.getElementById('libraryDropzone');
    const input = document.getElementById('libraryFileInput');
    const feedback = document.getElementById('libraryUploadFeedback');
    const titleInput = document.getElementById('libraryTitleInput');
    if (!zone || !input) return;

    titleInput?.addEventListener('input', function () {
        titleInput.dataset.autofilled = '0';
    });
    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (file) assignLibraryFile(file, feedback);
        else {
            showDropzoneFile(null);
            updateUploadSteps();
        }
    });

    ['dragenter', 'dragover'].forEach(function (type) {
        zone.addEventListener(type, function (e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        zone.addEventListener(type, function (e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('is-dragover');
        });
    });
    zone.addEventListener('drop', function (e) {
        const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        assignLibraryFile(file, feedback);
    });
}

function escapeHtml(str) {
    if (str == null || str === '') return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function setFeedbackHtml(el, ok, message) {
    if (!el) return;
    el.innerHTML = '<span class="text-' + (ok ? 'success' : 'danger') + '">' + escapeHtml(message) + '</span>';
}

function firstErrorMessage(data, fallback) {
    if (data && typeof data.message === 'string' && data.message.trim()) {
        return data.message.trim();
    }
    const errors = data && data.errors;
    if (errors && typeof errors === 'object') {
        const keys = Object.keys(errors);
        for (let i = 0; i < keys.length; i++) {
            const val = errors[keys[i]];
            if (Array.isArray(val) && val[0]) return String(val[0]);
            if (typeof val === 'string' && val.trim()) return val.trim();
        }
    }
    return fallback;
}

function showLibraryFlash(message, ok) {
    const el = document.getElementById('libraryFlash');
    if (!el) return;
    el.className = 'alert alert-' + (ok ? 'success' : 'danger');
    el.textContent = message;
    el.classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function libraryChipParams(submission) {
    const availability = String((submission && submission.availability) || '');
    const status = String((submission && submission.moderation_status) || '');
    if (
        availability === 'needs_fix'
        || status === 'needs_improvement'
        || status === 'rejected'
        || status === 'error'
        || (submission && submission.needs_correction)
    ) {
        return { status: 'all', availability: 'needs_fix' };
    }
    return { status: 'approved', availability: 'available' };
}

function libraryResultMessage(submission, fallback, ok) {
    if (fallback) return fallback;
    if (!ok) {
        return 'Article needs corrections — it is listed here so you can fix and resubmit.';
    }
    const availability = String((submission && submission.availability) || '');
    const status = String((submission && submission.moderation_status) || '');
    if (availability === 'evaluating' || status === 'pending' || status === 'processing') {
        return 'Article is still evaluating — it stays on Approved until the check finishes.';
    }
    return 'Article approved — you can order it from here.';
}

function libraryDestinationUrl(submission) {
    const url = new URL(libraryIndexUrl || window.location.pathname, window.location.origin);
    const chip = libraryChipParams(submission);
    url.search = '';
    url.searchParams.set('status', chip.status);
    url.searchParams.set('availability', chip.availability);
    if (submission && submission.id) {
        url.hash = 'library-row-' + submission.id;
    }
    return url.pathname + url.search + url.hash;
}

function goToLibraryResult(submission, message, ok) {
    pendingLibraryLanding = null;
    skipEditorListLanding = true;
    skipPreviewListLanding = true;
    const text = libraryResultMessage(submission, message, ok);
    try {
        sessionStorage.setItem('libraryResultFlash', JSON.stringify({
            message: text || '',
            ok: !!ok,
            id: submission && submission.id ? String(submission.id) : '',
        }));
    } catch (e) { /* ignore */ }
    window.location.href = libraryDestinationUrl(submission);
}

function rememberLibraryLanding(submission, message, ok) {
    pendingLibraryLanding = {
        submission: submission || {},
        message: libraryResultMessage(submission, message, ok),
        ok: !!ok,
    };
}

function applyLibraryResultFocus() {
    let flash = null;
    try {
        flash = JSON.parse(sessionStorage.getItem('libraryResultFlash') || 'null');
        sessionStorage.removeItem('libraryResultFlash');
    } catch (e) {
        flash = null;
    }
    if (flash && flash.message) {
        showLibraryFlash(flash.message, flash.ok !== false);
    }
    const fromHash = (window.location.hash.match(/^#library-row-(\d+)/) || [])[1];
    const id = (flash && flash.id) || fromHash;
    const row = id ? document.getElementById('library-row-' + id) : null;
    if (!row) return;
    row.classList.add('library-row--focus');
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

function bindLibraryResultLanding() {
    const editorEl = document.getElementById('articleEditorModal');
    if (editorEl && editorEl.dataset.listLandingBound !== '1') {
        editorEl.dataset.listLandingBound = '1';
        editorEl.addEventListener('hidden.bs.modal', function () {
            if (skipEditorListLanding) {
                skipEditorListLanding = false;
                return;
            }
            if (!pendingLibraryLanding) return;
            const next = pendingLibraryLanding;
            goToLibraryResult(next.submission, next.message, next.ok);
        });
    }
    const previewEl = document.getElementById('articlePreviewModal');
    if (previewEl && previewEl.dataset.listLandingBound !== '1') {
        previewEl.dataset.listLandingBound = '1';
        previewEl.addEventListener('hidden.bs.modal', function () {
            if (skipPreviewListLanding) {
                skipPreviewListLanding = false;
                return;
            }
            if (!pendingLibraryLanding) return;
            const next = pendingLibraryLanding;
            goToLibraryResult(next.submission, next.message, next.ok);
        });
    }
}

function openPreviewModal(title, html, links, submissionId, editable) {
    const tools = window.ArticlePreviewTools;
    previewModalState = {
        title: title || 'Article preview',
        submissionId: submissionId || null,
        editable: !!editable && !!submissionId,
        html: html || '',
    };
    document.getElementById('articlePreviewTitle').textContent = previewModalState.title;
    const body = document.getElementById('articlePreviewBody');
    body.innerHTML = html || '';
    fixPreviewImages(body);
    if (tools) tools.enhanceImages(body);

    const heading = tools ? tools.extractHeading(body, previewModalState.title) : previewModalState.title;
    const hint = document.getElementById('articlePreviewHeadingHint');
    if (hint) hint.textContent = heading ? ('Heading: ' + heading) : '';

    const list = document.getElementById('articlePreviewLinksList');
    const saveBtn = document.getElementById('articleLinksSaveBtn');
    const help = document.getElementById('articleLinksHelp');
    let linkRows = Array.isArray(links) ? links : [];
    if ((!linkRows.length) && tools && html) {
        linkRows = tools.extractLinksFromHtml(html);
    }
    if (tools) tools.renderLinkRows(list, linkRows, previewModalState.editable);
    if (saveBtn) saveBtn.classList.toggle('d-none', !previewModalState.editable);
    const editBtn = document.getElementById('articlePreviewEditBtn');
    if (editBtn) {
        const canEdit = previewModalState.editable
            || (!!articleEditorSubmissionId && Number(articleEditorSubmissionId) === Number(previewModalState.submissionId));
        editBtn.classList.toggle('d-none', !canEdit);
    }
    if (help) {
        help.textContent = previewModalState.editable
            ? 'Edit any anchor or URL, then save. The first link is used for checkout.'
            : 'Shown outside the article so you can review every anchor and URL.';
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('articlePreviewModal')).show();
}

async function fetchSubmissionPayload(submissionId) {
    const res = await fetch(libraryPreviewUrlBase + '/' + submissionId + '/preview', {
        headers: { 'Accept': 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error((data && data.message) || 'Could not load article');
    }
    return data;
}

document.querySelectorAll('.js-open-preview').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const id = btn.getAttribute('data-submission-id');
        if (!id) {
            showLibraryFlash('Could not open preview', false);
            return;
        }
        btn.disabled = true;
        try {
            const payload = await fetchSubmissionPayload(id);
            openPreviewModal(
                payload.title || 'Article preview',
                payload.html || payload.preview_html || '',
                payload.links || payload.detected_links || [],
                payload.id || parseInt(id, 10),
                !!payload.editable
            );
        } catch (e) {
            console.error('Failed to open preview', e);
            showLibraryFlash(e.message || 'Could not open preview', false);
        } finally {
            btn.disabled = false;
        }
    });
});

document.querySelectorAll('.js-open-editor').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const id = btn.getAttribute('data-submission-id');
        if (!id) {
            showLibraryFlash('Could not open editor', false);
            return;
        }
        btn.disabled = true;
        try {
            const payload = await fetchSubmissionPayload(id);
            openArticleEditor(Object.assign({}, payload, {
                id: payload.id || parseInt(id, 10),
                preview_html: payload.preview_html || payload.html || '',
                detected_links: payload.detected_links || payload.links || [],
            }));
        } catch (e) {
            console.error('Failed to open editor', e);
            showLibraryFlash(e.message || 'Could not open editor', false);
        } finally {
            btn.disabled = false;
        }
    });
});

document.getElementById('articleCopyHeadingBtn')?.addEventListener('click', async function () {
    const tools = window.ArticlePreviewTools;
    const body = document.getElementById('articlePreviewBody');
    const heading = tools ? tools.extractHeading(body, previewModalState.title) : previewModalState.title;
    if (!tools) {
        showLibraryFlash('Copy tools failed to load', false);
        return;
    }
    try {
        await tools.copyText(heading);
        tools.toast('Heading copied');
    } catch (e) {
        tools.toast('Could not copy heading', false);
    }
});

document.getElementById('articleCopyContentBtn')?.addEventListener('click', async function () {
    const tools = window.ArticlePreviewTools;
    const body = document.getElementById('articlePreviewBody');
    if (!tools) {
        showLibraryFlash('Copy tools failed to load', false);
        return;
    }
    try {
        await tools.copyHtml(body.innerHTML, body.innerText);
        tools.toast('Article copied — paste into your CMS');
    } catch (e) {
        tools.toast('Could not copy article', false);
    }
});

document.getElementById('articleLinksSaveBtn')?.addEventListener('click', async function () {
    const tools = window.ArticlePreviewTools;
    if (!previewModalState.editable || !previewModalState.submissionId) return;
    if (!tools) {
        showLibraryFlash('Preview tools failed to load', false);
        return;
    }
    const links = tools.readLinkRows(document.getElementById('articlePreviewLinksList'));
    const btn = this;
    btn.disabled = true;
    try {
        const res = await fetch(libraryUpdateUrl + '/' + previewModalState.submissionId, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                links: links,
                preview_html: document.getElementById('articlePreviewBody').innerHTML,
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            tools.toast((data && data.message) || 'Could not save links', false);
            return;
        }
        const sub = data.submission || {};
        const html = sub.preview_html || document.getElementById('articlePreviewBody').innerHTML;
        const stillApproved = data.approved !== false;
        const editable = stillApproved;
        openPreviewModal(sub.title || previewModalState.title, html, sub.detected_links || links, previewModalState.submissionId, editable);
        if (!stillApproved) {
            const msg = data.message || (data.report && data.report.summary) || 'Content moderation failed after your link changes. Fix restricted links before ordering.';
            tools.toast(msg, false);
            goToLibraryResult(sub, msg, false);
            return;
        }
        tools.toast(data.message || 'Links saved — content re-checked and approved');
        if (data.approved === true) {
            const dest = libraryChipParams(sub);
            const here = new URL(window.location.href);
            const hereAvail = here.searchParams.get('availability') || 'available';
            if (hereAvail === 'needs_fix' && dest.availability === 'available') {
                goToLibraryResult(sub, data.message || 'Article approved — you can order it from here.', true);
                return;
            }
            showLibraryFlash(data.message || 'Article still approved after re-check.', true);
        }
    } catch (e) {
        tools.toast('Network error while saving links', false);
    } finally {
        btn.disabled = false;
    }
});

/**
 * Rewrite absolute /storage/... image URLs onto the current origin so previews
 * still work when APP_URL differs from the browser host.
 */
function fixPreviewImages(root) {
    if (!root) return;
    root.querySelectorAll('img').forEach(function (img) {
        const src = img.getAttribute('src') || '';
        const match = src.match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
        if (match) {
            img.setAttribute('src', match[1]);
        }
        img.addEventListener('error', function () {
            if (img.dataset.fallbackApplied) return;
            img.dataset.fallbackApplied = '1';
            // Last resort: if relative path failed and we still have an absolute, try same-origin.
            const again = (img.getAttribute('src') || '').match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
            if (again) {
                img.setAttribute('src', again[1]);
                return;
            }
            img.alt = 'Image failed to load';
            img.style.outline = '1px dashed #e2e8f0';
            img.style.minHeight = '48px';
            img.style.background = '#f8fafc';
        });
    });
}

function rewriteStorageUrlsInHtml(html) {
    return String(html || '').replace(/(?:https?:)?\/\/[^"'>\s]+(\/storage\/[^"'\s>]+)/gi, '$1');
}

function hideImageRemoveOverlay() {
    const btn = document.getElementById('articleImageRemoveBtn');
    if (btn) {
        btn.classList.add('d-none');
        btn.removeAttribute('data-image-index');
    }
    articleQuill?.root.querySelectorAll('img.is-selected').forEach(function (img) {
        img.classList.remove('is-selected');
    });
}

function imageIndexFromBlot(blot) {
    if (!articleQuill || !blot) return null;
    const node = blot.domNode;
    if (!node || node.tagName !== 'IMG') return null;
    try {
        return blot.offset(articleQuill.scroll);
    } catch (e) {
        return null;
    }
}

function selectedImageIndexFromRange(range) {
    if (!articleQuill || !range) return null;
    const index = range.length > 0 ? range.index : Math.max(0, range.index - (range.index > 0 ? 1 : 0));
    try {
        const leaf = articleQuill.getLeaf(range.length > 0 ? range.index : index);
        const blot = leaf && leaf[0];
        const imageIndex = imageIndexFromBlot(blot);
        if (imageIndex == null) return null;
        if (range.length > 0 && range.index !== imageIndex) return null;
        if (range.length === 0 && range.index !== imageIndex && range.index !== imageIndex + 1) return null;
        return imageIndex;
    } catch (e) {
        return null;
    }
}

function showImageRemoveOverlay(img, index) {
    const btn = document.getElementById('articleImageRemoveBtn');
    const shell = document.querySelector('.article-docs-shell');
    if (!btn || !shell || !img) return;
    hideImageRemoveOverlay();
    img.classList.add('is-selected');
    btn.dataset.imageIndex = String(index);
    btn.classList.remove('d-none');
    const shellRect = shell.getBoundingClientRect();
    const imgRect = img.getBoundingClientRect();
    const btnW = btn.offsetWidth || 30;
    // Pin to the top-right corner so a small figure stays visible.
    const top = imgRect.top - shellRect.top + 8;
    const left = imgRect.right - shellRect.left - btnW - 8;
    btn.style.top = Math.max(8, top) + 'px';
    btn.style.left = Math.max(8, left) + 'px';
}

function syncSelectedImageOverlay() {
    if (!articleQuill) {
        hideImageRemoveOverlay();
        return;
    }
    const range = articleQuill.getSelection(true);
    const imageIndex = selectedImageIndexFromRange(range);
    if (imageIndex == null) {
        hideImageRemoveOverlay();
        return;
    }
    try {
        const leaf = articleQuill.getLeaf(imageIndex);
        const blot = leaf && leaf[0];
        const img = blot && blot.domNode;
        if (img && img.tagName === 'IMG') {
            showImageRemoveOverlay(img, imageIndex);
            return;
        }
    } catch (e) { /* ignore */ }
    hideImageRemoveOverlay();
}

function removeSelectedEditorImage() {
    if (!articleQuill) return;
    const btn = document.getElementById('articleImageRemoveBtn');
    const fromBtn = btn ? parseInt(btn.dataset.imageIndex || '', 10) : NaN;
    const index = Number.isNaN(fromBtn) ? selectedImageIndexFromRange(articleQuill.getSelection(true)) : fromBtn;
    if (index == null || Number.isNaN(index)) return;
    articleQuill.deleteText(index, 1, 'user');
    hideImageRemoveOverlay();
    articleQuill.focus();
}

function bindBrokenEditorImages() {
    if (!articleQuill) return;
    articleQuill.root.querySelectorAll('img').forEach(function (img) {
        if (img.dataset.errorBound === '1') return;
        img.dataset.errorBound = '1';
        img.addEventListener('error', function () {
            img.classList.add('is-broken');
            if (!img.getAttribute('alt')) img.setAttribute('alt', 'Image failed to load');
        });
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) {
            img.classList.add('is-broken');
        }
    });
}

function loadArticleHtml(html) {
    if (!articleQuill) return;
    const raw = rewriteStorageUrlsInHtml((html && String(html).trim()) ? html : '<p><br></p>');
    if (typeof articleQuill.clipboard.dangerouslyPasteHTML === 'function') {
        articleQuill.clipboard.dangerouslyPasteHTML(raw, 'silent');
    } else {
        const delta = articleQuill.clipboard.convert({ html: raw, text: '' });
        articleQuill.setContents(delta, 'silent');
    }
    const history = articleQuill.getModule('history');
    if (history && typeof history.clear === 'function') {
        history.clear();
    }
    articleQuill.setSelection(0, 0, 'silent');
    hideImageRemoveOverlay();
    bindBrokenEditorImages();
}

function bindEditorImageChrome() {
    if (!articleQuill || articleQuill.root.dataset.imageChrome === '1') return;
    articleQuill.root.dataset.imageChrome = '1';

    articleQuill.root.addEventListener('click', function (e) {
        const img = e.target && e.target.closest ? e.target.closest('img') : null;
        if (!img || !articleQuill.root.contains(img)) return;
        const blot = typeof Quill.find === 'function' ? Quill.find(img) : null;
        if (!blot) return;
        const index = imageIndexFromBlot(blot);
        if (index == null) return;
        articleQuill.setSelection(index, 1, 'user');
        showImageRemoveOverlay(img, index);
    });

    articleQuill.root.addEventListener('keydown', function (e) {
        if (e.key !== 'Backspace' && e.key !== 'Delete') return;
        const range = articleQuill.getSelection();
        if (!range || range.length !== 1) return;
        const imageIndex = selectedImageIndexFromRange(range);
        if (imageIndex == null) return;
        e.preventDefault();
        articleQuill.deleteText(imageIndex, 1, 'user');
        hideImageRemoveOverlay();
    });

    articleQuill.on('selection-change', function (range) {
        if (!range) {
            hideImageRemoveOverlay();
            return;
        }
        syncSelectedImageOverlay();
    });

    articleQuill.root.addEventListener('scroll', syncSelectedImageOverlay);
    document.querySelector('#articleEditorModal .modal-body')?.addEventListener('scroll', syncSelectedImageOverlay);

    document.getElementById('articleImageRemoveBtn')?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        removeSelectedEditorImage();
    });
}

function patchQuillImageSanitize() {
    if (typeof Quill === 'undefined' || Quill.__libraryImagePatched) return;
    const Image = Quill.import('formats/image');
    if (!Image || typeof Image.sanitize !== 'function') return;
    const original = Image.sanitize.bind(Image);
    Image.sanitize = function (url) {
        const value = String(url || '');
        if (
            value.startsWith('/storage/')
            || value.startsWith('/media/')
            || value.startsWith('blob:')
            || value.startsWith('data:')
        ) {
            return value;
        }
        const next = original(value);
        if (next === '//:0' && value.charAt(0) === '/') {
            return value;
        }
        return next;
    };
    Quill.register(Image, true);
    Quill.__libraryImagePatched = true;
}

function hideBootstrapModal(el) {
    if (!el || typeof bootstrap === 'undefined') return;
    blurIfInside(el);
    bootstrap.Modal.getOrCreateInstance(el).hide();
}

function blurIfInside(el) {
    if (el && el.contains(document.activeElement) && document.activeElement.blur) {
        document.activeElement.blur();
    }
}

function bindLibraryModalA11y() {
    ['uploadContentModal', 'articleEditorModal', 'articlePreviewModal'].forEach(function (id) {
        const el = document.getElementById(id);
        if (!el || el.dataset.a11yBound === '1') return;
        el.dataset.a11yBound = '1';
        el.addEventListener('hide.bs.modal', function () {
            blurIfInside(el);
        });
        el.addEventListener('shown.bs.modal', function () {
            el.removeAttribute('aria-hidden');
            el.setAttribute('aria-modal', 'true');
        });
    });
}

function ensureArticleQuill() {
    if (articleQuill || typeof Quill === 'undefined') {
        return articleQuill;
    }

    patchQuillImageSanitize();

    const toolbarOptions = [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link', 'image'],
        ['undo', 'redo'],
        ['clean'],
    ];

    articleQuill = new Quill('#articleQuillEditor', {
        theme: 'snow',
        placeholder: 'Edit your article…',
        modules: {
            toolbar: {
                container: toolbarOptions,
                handlers: {
                    undo: function () {
                        const history = this.quill.getModule('history');
                        if (history) history.undo();
                    },
                    redo: function () {
                        const history = this.quill.getModule('history');
                        if (history) history.redo();
                    },
                },
            },
            history: { delay: 500, maxStack: 100, userOnly: true },
        },
    });

    const toolbar = articleQuill.getModule('toolbar');
    const undoBtn = toolbar.container.querySelector('.ql-undo');
    if (undoBtn) {
        undoBtn.innerHTML = '<i class="fa fa-undo" aria-hidden="true"></i>';
        undoBtn.setAttribute('aria-label', 'Undo');
        undoBtn.setAttribute('title', 'Undo (Ctrl+Z)');
        undoBtn.setAttribute('data-no-tip', '');
    }
    const redoBtn = toolbar.container.querySelector('.ql-redo');
    if (redoBtn) {
        redoBtn.innerHTML = '<i class="fa fa-redo" aria-hidden="true"></i>';
        redoBtn.setAttribute('aria-label', 'Redo');
        redoBtn.setAttribute('title', 'Redo (Ctrl+Shift+Z)');
        redoBtn.setAttribute('data-no-tip', '');
    }
    toolbar.addHandler('image', function () {
        const input = document.createElement('input');
        input.setAttribute('type', 'file');
        input.setAttribute('accept', 'image/png,image/jpeg,image/gif,image/webp');
        input.click();
        input.onchange = async function () {
            const file = input.files && input.files[0];
            if (!file) return;
            const feedback = document.getElementById('articleEditorFeedback');
            feedback.textContent = 'Uploading image…';
            const fd = new FormData();
            fd.append('image', file);
            try {
                const res = await fetch(libraryImageUploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok || !data.success || !data.url) {
                    setFeedbackHtml(feedback, false, firstErrorMessage(data, 'Image upload failed'));
                    return;
                }
                const range = articleQuill.getSelection(true) || { index: articleQuill.getLength() };
                articleQuill.insertEmbed(range.index, 'image', data.url, 'user');
                articleQuill.setSelection(range.index + 1);
                setFeedbackHtml(feedback, true, 'Image added. Select it and press Backspace, or use Remove.');
            } catch (e) {
                setFeedbackHtml(feedback, false, 'Network error while uploading image.');
            }
        };
    });

    bindEditorImageChrome();
    return articleQuill;
}

function openArticleEditor(submission) {
    if (!submission || !submission.id) return;
    articleEditorSubmissionId = submission.id;
    articleEditorDetectedLinks = Array.isArray(submission.detected_links) ? submission.detected_links : [];
    ensureArticleQuill();
    document.getElementById('articleEditorTitle').value = submission.title || '';
    const market = ((submission.country || '') + '/' + (submission.language || '')).toUpperCase();
    const status = submission.moderation_status || '';
    document.getElementById('articleEditorMeta').textContent =
        market + (status ? ' · ' + status.replace(/_/g, ' ') : '') +
        (submission.word_count ? ' · ' + submission.word_count + ' words' : '');
    document.getElementById('articleEditorFeedback').textContent = '';
    loadArticleHtml(submission.preview_html || '<p><br></p>');
    const needsRights = !!(submission.needs_image_rights || (submission.has_images && !submission.image_rights_covers));
    syncEditorImageRights(needsRights);
    const orderBtn = document.getElementById('articleEditorOrderBtn');
    if (orderBtn) {
        if (submission.can_order) {
            orderBtn.href = libraryOrderUrlBase + '/' + submission.id + '/order';
            orderBtn.classList.remove('d-none');
        } else {
            orderBtn.classList.add('d-none');
        }
    }
    showArticleEditorAfterUploadModal();
}

/**
 * Bootstrap cannot show a second modal while the upload dialog is still
 * hiding — the backdrop sticks and the editor never becomes usable.
 */
function showArticleEditorAfterUploadModal() {
    const editorEl = document.getElementById('articleEditorModal');
    const uploadModalEl = document.getElementById('uploadContentModal');
    const showEditor = function () {
        if (!editorEl || typeof bootstrap === 'undefined') return;
        bootstrap.Modal.getOrCreateInstance(editorEl).show();
    };
    if (uploadModalEl && uploadModalEl.classList.contains('show') && typeof bootstrap !== 'undefined') {
        uploadModalEl.addEventListener('hidden.bs.modal', function onUploadHidden() {
            uploadModalEl.removeEventListener('hidden.bs.modal', onUploadHidden);
            showEditor();
        });
        hideBootstrapModal(uploadModalEl);
        return;
    }
    showEditor();
}

function syncEditorImageRights(visible) {
    const wrap = document.getElementById('articleEditorImageRights');
    if (!wrap) return;
    wrap.classList.toggle('d-none', !visible);
    const noneChoice = wrap.querySelector('[data-image-rights-choice][value="none"]');
    noneChoice?.closest('.form-check')?.classList.toggle('d-none', !!visible);
}

/**
 * Only send a declaration when the editor is actually showing one, so a normal
 * text edit keeps whatever the article already declared.
 */
function articleEditorRightsPayload() {
    const wrap = document.getElementById('articleEditorImageRights');
    if (!wrap || wrap.classList.contains('d-none') || !window.readImageRights) {
        return {};
    }

    const rights = window.readImageRights(wrap);
    if (!rights.ok) {
        return {};
    }

    return rights.source
        ? { image_rights: rights.rights, image_rights_source: rights.source }
        : { image_rights: rights.rights };
}

async function saveArticleEditor() {
    if (!articleEditorSubmissionId || !articleQuill) return;
    const feedback = document.getElementById('articleEditorFeedback');
    const btn = document.getElementById('articleEditorSaveBtn');
    const html = articleQuill.root.innerHTML;
    const title = (document.getElementById('articleEditorTitle').value || '').trim();
    const wrap = document.getElementById('articleEditorImageRights');
    if (wrap && !wrap.classList.contains('d-none') && window.readImageRights) {
        const rights = window.readImageRights(wrap);
        if (!rights.ok) {
            setFeedbackHtml(feedback, false, rights.message);
            return;
        }
        if (/<img\b/i.test(html) && rights.rights === 'none') {
            setFeedbackHtml(feedback, false, 'This article contains images. Confirm you own them, or add the source URL or copyright details.');
            return;
        }
    }
    btn.disabled = true;
    feedback.textContent = 'Saving and re-checking content moderation…';
    try {
        const res = await fetch(libraryContentUrl + '/' + articleEditorSubmissionId + '/content', {
            method: 'PUT',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(Object.assign(
                { preview_html: html, title: title },
                articleEditorRightsPayload()
            )),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            setFeedbackHtml(feedback, false, data.message || 'Could not save article.');
            // Images were added without a declaration that covers them — reveal
            // the declaration so it can be answered without leaving the editor.
            if (data.needs_image_rights) {
                syncEditorImageRights(true);
            }
            btn.disabled = false;
            return;
        }
        const stillApproved = data.approved !== false;
        const msg = data.message
            || (stillApproved
                ? 'Article saved and re-approved.'
                : 'Article saved, but content moderation failed. Fix restricted links/keywords before ordering.');
        setFeedbackHtml(feedback, stillApproved, msg);
        goToLibraryResult(data.submission || { id: articleEditorSubmissionId }, msg, stillApproved);
    } catch (e) {
        setFeedbackHtml(feedback, false, 'Network error while saving.');
        btn.disabled = false;
    }
}

document.getElementById('articleEditorSaveBtn')?.addEventListener('click', saveArticleEditor);
document.getElementById('articleEditorPreviewBtn')?.addEventListener('click', function () {
    if (!articleQuill) return;
    const tools = window.ArticlePreviewTools;
    const html = articleQuill.root.innerHTML;
    let links = Array.isArray(articleEditorDetectedLinks) ? articleEditorDetectedLinks.slice() : [];
    if ((!links.length) && tools) {
        links = tools.extractLinksFromHtml(html);
    }
    const editorEl = document.getElementById('articleEditorModal');
    const openPreview = function () {
        openPreviewModal(
            document.getElementById('articleEditorTitle').value || 'Article preview',
            html,
            links,
            articleEditorSubmissionId,
            true
        );
    };
    if (editorEl && editorEl.classList.contains('show') && typeof bootstrap !== 'undefined') {
        skipEditorListLanding = true;
        editorEl.addEventListener('hidden.bs.modal', function onEditorHidden() {
            editorEl.removeEventListener('hidden.bs.modal', onEditorHidden);
            openPreview();
        });
        hideBootstrapModal(editorEl);
        return;
    }
    openPreview();
});

function returnToEditorFromPreview() {
    const previewEl = document.getElementById('articlePreviewModal');
    const editorEl = document.getElementById('articleEditorModal');
    const id = previewModalState.submissionId;
    const showEditor = function () {
        if (articleEditorSubmissionId && articleQuill && Number(articleEditorSubmissionId) === Number(id)) {
            if (editorEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(editorEl).show();
            }
            return;
        }
        if (!id) return;
        fetchSubmissionPayload(id).then(function (payload) {
            openArticleEditor(Object.assign({}, payload, {
                id: payload.id || id,
                preview_html: payload.preview_html || payload.html || '',
                detected_links: payload.detected_links || payload.links || [],
            }));
        }).catch(function (e) {
            showLibraryFlash((e && e.message) || 'Could not open editor', false);
        });
    };
    if (previewEl && previewEl.classList.contains('show') && typeof bootstrap !== 'undefined') {
        skipPreviewListLanding = true;
        previewEl.addEventListener('hidden.bs.modal', function onPreviewHidden() {
            previewEl.removeEventListener('hidden.bs.modal', onPreviewHidden);
            showEditor();
        });
        hideBootstrapModal(previewEl);
        return;
    }
    showEditor();
}

document.getElementById('articlePreviewEditBtn')?.addEventListener('click', function (e) {
    e.preventDefault();
    returnToEditorFromPreview();
});

function toggleLibraryTitleEdit(id, open) {
    const edit = document.querySelector('[data-title-edit="' + id + '"]');
    if (!edit) return;
    edit.classList.toggle('d-none', !open);
    if (open) {
        const input = document.querySelector('[data-title-input="' + id + '"]');
        input?.focus();
        input?.select();
    }
}

async function copyLibraryLiveUrl(btn) {
    const url = (btn?.getAttribute('data-copy-url') || '').trim();
    if (!url) return;
    const markCopied = function () {
        btn.classList.add('is-copied');
        const icon = btn.querySelector('i');
        if (icon) {
            icon.classList.remove('fa-copy');
            icon.classList.add('fa-check');
        }
        setTimeout(function () {
            btn.classList.remove('is-copied');
            if (icon) {
                icon.classList.remove('fa-check');
                icon.classList.add('fa-copy');
            }
        }, 1400);
    };
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(url);
            markCopied();
            return;
        }
    } catch (e) { /* fall through */ }
    const ta = document.createElement('textarea');
    ta.value = url;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        markCopied();
    } finally {
        document.body.removeChild(ta);
    }
}

async function saveLibraryTitle(id) {
    const input = document.querySelector('[data-title-input="' + id + '"]');
    if (!input) return;
    const title = (input.value || '').trim();
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ title: title }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not rename article.', false);
            return;
        }
        const display = document.querySelector('[data-title-display="' + id + '"]');
        const nextTitle = (data.submission && data.submission.title) || title || (data.submission && data.submission.original_filename) || 'Article';
        if (display) {
            display.textContent = nextTitle;
            display.title = nextTitle;
        }
        toggleLibraryTitleEdit(id, false);
        showLibraryFlash('Article renamed.', true);
    } catch (e) {
        showLibraryFlash('Network error while renaming.', false);
    }
}

async function deleteLibraryArticle(id, label) {
    const ok = await window.slbConfirm({
            title: 'Delete article?',
            text: 'Delete "' + (label || 'this article') + '"? This cannot be undone.',
            confirmText: 'Delete',
            danger: true,
        });
    if (!ok) {
        return;
    }
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not delete article.', false);
            if (window.slbAlert) await window.slbAlert({ icon: 'error', title: data.message || 'Could not delete article.' });
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article deleted.', true);
        if (window.slbAlert) await window.slbAlert({ icon: 'success', title: 'Article deleted.' });
    } catch (e) {
        showLibraryFlash('Network error while deleting.', false);
        if (window.slbAlert) await window.slbAlert({ icon: 'error', title: 'Network error while deleting.' });
    }
}

async function archiveLibraryArticle(id) {
    const ok = await window.slbConfirm({
            title: 'Archive article?',
            text: 'Archived articles are hidden from the active library. You can restore them later.',
            confirmText: 'Archive',
            icon: 'question',
        });
    if (!ok) {
        return;
    }
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id + '/archive', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not archive article.', false);
            if (window.slbAlert) await window.slbAlert({ icon: 'error', title: data.message || 'Could not archive article.' });
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article archived.', true);
        if (window.slbAlert) await window.slbAlert({ icon: 'success', title: 'Article archived.' });
    } catch (e) {
        showLibraryFlash('Network error while archiving.', false);
        if (window.slbAlert) await window.slbAlert({ icon: 'error', title: 'Network error while archiving.' });
    }
}

async function restoreLibraryArticle(id) {
    const ok = await window.slbConfirm({
            title: 'Restore article?',
            text: 'Move this article back to the active library?',
            confirmText: 'Restore',
            icon: 'question',
        });
    if (!ok) {
        return;
    }
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id + '/restore', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not restore article.', false);
            if (window.slbAlert) await window.slbAlert({ icon: 'error', title: data.message || 'Could not restore article.' });
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article restored.', true);
        if (window.slbAlert) await window.slbAlert({ icon: 'success', title: 'Article restored.' });
    } catch (e) {
        showLibraryFlash('Network error while restoring.', false);
        if (window.slbAlert) await window.slbAlert({ icon: 'error', title: 'Network error while restoring.' });
    }
}

document.getElementById('libraryUploadForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fileInput = document.getElementById('libraryFileInput');
    const file = fileInput && fileInput.files && fileInput.files[0];
    const feedback = document.getElementById('libraryUploadFeedback');
    const btn = document.getElementById('libraryUploadBtn');
    const progress = document.getElementById('libraryUploadProgress');
    const bar = progress ? progress.querySelector('.progress-bar') : null;

    if (!file) {
        setFeedbackHtml(feedback, false, 'Drop a .docx or click the box to choose a file.');
        return;
    }
    if (!/\.docx$/i.test(file.name)) {
        setFeedbackHtml(feedback, false, 'Word .docx only — not PDF, Google Doc, or pasted text.');
        return;
    }
    const langSelect = document.getElementById('libraryLanguage');
    if (!document.getElementById('libraryCountry').value || !langSelect?.value) {
        setFeedbackHtml(feedback, false, 'Please select country and language before uploading.');
        return;
    }
    // Disabled selects are omitted from FormData; language starts disabled
    // until a country is chosen.
    langSelect.disabled = false;

    const fd = new FormData(this);
    fd.set('file', file, file.name);
    if (btn) btn.disabled = true;
    progress?.classList.remove('d-none');
    if (bar) bar.style.width = '40%';
    if (feedback) feedback.textContent = 'Uploading your article…';

    let openedEditor = false;
    try {
        const res = await fetch(boot.uploadUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
            body: fd,
        });
        if (bar) bar.style.width = '100%';
        let data = {};
        try {
            data = await res.json();
        } catch (parseErr) {
            setFeedbackHtml(feedback, false, 'Upload failed. Please try again.');
            return;
        }
        if (!data.success) {
            setFeedbackHtml(feedback, false, firstErrorMessage(data, 'Upload failed. Use a Word .docx and try again.'));
            return;
        }
        setFeedbackHtml(feedback, true, 'Opening editor…');
        if (data.submission) {
            openedEditor = true;
            rememberLibraryLanding(
                data.submission,
                data.message,
                !!(data.approved || data.submission.can_order)
            );
            openArticleEditor(Object.assign({}, data.submission, {
                can_order: !!(data.submission.can_order || data.approved),
            }));
        } else {
            goToLibraryResult({}, data.message || 'Article uploaded.', !!data.approved);
        }
    } catch (err) {
        setFeedbackHtml(feedback, false, 'Network error while uploading.');
    } finally {
        if (!openedEditor && btn) btn.disabled = false;
        progress?.classList.add('d-none');
        if (bar) bar.style.width = '0%';
    }
});

if (libraryOpenUpload && libraryUploadsEnabled) {
    document.addEventListener('DOMContentLoaded', function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadContentModal')).show();
    });
}

if (window.location.hash === '#upload' && libraryUploadsEnabled) {
    document.addEventListener('DOMContentLoaded', function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadContentModal')).show();
    });
}

// Blade row actions call these from onclick="" — they must be global.
window.toggleLibraryTitleEdit = toggleLibraryTitleEdit;
window.saveLibraryTitle = saveLibraryTitle;
window.copyLibraryLiveUrl = copyLibraryLiveUrl;
window.archiveLibraryArticle = archiveLibraryArticle;
window.deleteLibraryArticle = deleteLibraryArticle;
window.restoreLibraryArticle = restoreLibraryArticle;
})();
