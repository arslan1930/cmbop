/* Content Library page logic — boot config from window.ContentLibraryBoot */
(function () {
'use strict';
const boot = window.ContentLibraryBoot || {};

function librarySameOriginPath(url, fallback) {
    if (!url) return fallback || '';
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.pathname + (parsed.search || '');
    } catch (err) {
        return fallback || '';
    }
}

const libraryUpdateUrl = librarySameOriginPath(boot.libraryUpdateUrl, '/advertiser/content-submissions');
const libraryContentUrl = librarySameOriginPath(boot.libraryContentUrl, '/advertiser/content-submissions');
const libraryImageUploadUrl = librarySameOriginPath(boot.libraryImageUploadUrl, '');
const libraryPreviewUrlBase = librarySameOriginPath(boot.libraryPreviewUrlBase, '/advertiser/content-submissions');
const libraryCsrf = boot.libraryCsrf;
const libraryLanguageCountryMap = boot.libraryLanguageCountryMap || {};
const libraryCountryLanguageMap = boot.libraryCountryLanguageMap || {};
const libraryPreferredCountry = boot.libraryPreferredCountry || '';
const libraryPreferredLanguage = boot.libraryPreferredLanguage || '';
const libraryUploadsEnabled = !!boot.uploadsEnabled;
const libraryOpenUpload = !!boot.openUpload;
const libraryEditSubmission = boot.editSubmission || null;
const libraryIndexUrl = librarySameOriginPath(boot.libraryIndexUrl, '');
const libraryResultsUrl = librarySameOriginPath(boot.libraryResultsUrl, '');
const libraryUploadUrl = librarySameOriginPath(boot.uploadUrl, '');

let articleQuill = null;
let articleEditorSubmissionId = null;
let articleEditorDetectedLinks = [];
let previewModalState = { title: '', submissionId: null, editable: false, html: '' };
let pendingLibraryLanding = null;
let skipEditorListLanding = false;
let skipPreviewListLanding = false;
let previewOpenedFromEditor = false;
let libraryUploadAbort = null;
let libraryUploadHandoff = false;
let libraryUploadClosingForEditor = false;
let libraryUploadHandoffTimer = null;
let libraryUploadSavedSubmission = null;
let libraryUploadDismissGen = 0;

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
    bindLibraryUploadCancel();
    bindLibraryResultLanding();
    bindArticleEditorScrollport();
    bindArticleEditorWheel();
    applyLibraryResultFocus();
});
document.getElementById('uploadContentModal')?.addEventListener('shown.bs.modal', function () {
    libraryUploadDismissGen += 1;
    refreshLibraryLanguages(libraryPreferredLanguage || document.getElementById('libraryLanguage')?.value || '');
    if (!libraryUploadAbort && !libraryUploadHandoff) {
        const btn = document.getElementById('libraryUploadBtn');
        if (btn) btn.disabled = false;
    }
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
        .trim()
        .slice(0, 200);
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

function libraryFileTooLargeMessage(file) {
    if (!file) return '';
    if (file.size > 10240 * 1024) {
        return 'That file is over the 10 MB limit.';
    }
    return '';
}

function librarySafeDocxFilename(name) {
    const base = String(name || 'article')
        .replace(/\.docx$/i, '')
        .replace(/['’`]/g, '')
        .replace(/[^\w.\- ]+/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 80) || 'article';
    return base + '.docx';
}

function libraryNewUploadId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (ch) {
        const n = Math.random() * 16 | 0;
        const v = ch === 'x' ? n : (n & 0x3 | 0x8);
        return v.toString(16);
    });
}

function libraryUploadTransportMessage(status, fileBytes) {
    if (status === 419) {
        return 'Your session expired. Refresh the page and try again.';
    }
    if (status === 429) {
        return 'Too many upload attempts. Wait a minute and try again.';
    }
    // 413 / dropped connection: the file never reached Laravel (LiteSpeed / PHP pipe).
    // 500 / 502 / 504 / 408 are processing or gateway failures — do not mislabel those as size.
    if (status === 413 || status === 0) {
        return librarySizeAwareUploadMessage(fileBytes);
    }
    return 'Upload failed. Please try again.';
}

function librarySizeAwareUploadMessage(fileBytes, serverMessage) {
    const bytes = Number(fileBytes) || 0;
    const text = String(serverMessage || '').trim();
    if (bytes > 10240 * 1024) {
        return 'That file is over the 10 MB limit.';
    }
    if (text && !/under 10 MB/i.test(text) && !/over the 10 MB limit/i.test(text)) {
        return text;
    }
    return 'The article could not be uploaded. Please try again.';
}

const LIBRARY_UPLOAD_CHUNK_BYTES = 512 * 1024;
const LIBRARY_UPLOAD_PART_NAME = 'article.docx';
const LIBRARY_IMAGE_MAX_BYTES = 5120 * 1024;
const LIBRARY_IMAGE_MAX_PER_ARTICLE = 10;

function libraryImageTooLargeMessage(file) {
    if (file && file.size > LIBRARY_IMAGE_MAX_BYTES) {
        return 'The image could not be uploaded. Use a JPG, PNG, GIF, or WebP under 5 MB and try again.';
    }
    return '';
}

function libraryTooManyImagesMessage() {
    return 'This article can have up to 10 images. Remove one before adding another.';
}

function editorImageCount() {
    return articleQuill ? articleQuill.root.querySelectorAll('img').length : 0;
}

function updateEditorImageCount() {
    const el = document.getElementById('articleEditorImageCount');
    if (!el) {
        return;
    }
    const n = editorImageCount();
    el.textContent = n + ' / ' + LIBRARY_IMAGE_MAX_PER_ARTICLE + ' images';
    el.hidden = false;
    el.classList.toggle('is-over', n > LIBRARY_IMAGE_MAX_PER_ARTICLE);
}

function librarySizeAwareImageMessage(fileBytes, serverMessage) {
    const bytes = Number(fileBytes) || 0;
    const text = String(serverMessage || '').trim();
    if (bytes > LIBRARY_IMAGE_MAX_BYTES) {
        return 'The image could not be uploaded. Use a JPG, PNG, GIF, or WebP under 5 MB and try again.';
    }
    if (text && !/under 5 MB/i.test(text) && !/greater than 5120/i.test(text)) {
        return text;
    }
    return 'The image could not be uploaded. Please try again.';
}

async function uploadEditorImageFile(file) {
    const feedback = document.getElementById('articleEditorFeedback');
    if (!file || !articleQuill) return;
    if (editorImageCount() >= LIBRARY_IMAGE_MAX_PER_ARTICLE) {
        setFeedbackHtml(feedback, false, libraryTooManyImagesMessage());
        return;
    }
    const tooLarge = libraryImageTooLargeMessage(file);
    if (tooLarge) {
        setFeedbackHtml(feedback, false, tooLarge);
        return;
    }
    if (feedback) feedback.textContent = 'Uploading image…';
    const fd = new FormData();
    fd.append('image', file);
    fd.append('content_submission_id', String(articleEditorSubmissionId || ''));
    fd.append('current_image_count', String(editorImageCount()));
    try {
        const res = await fetch(libraryUrlWithClientBytes(libraryImageUploadUrl, file.size), {
            method: 'POST',
            headers: libraryClientByteHeaders(file.size),
            body: fd,
        });
        const data = await parseLibraryJson(res);
        if (res.status === 419) {
            setFeedbackHtml(feedback, false, 'Your session expired. Refresh the page and try again.');
            return;
        }
        if (!data || !res.ok || !data.success || !data.url) {
            const fallback = (res.status === 413 || res.status === 422)
                ? librarySizeAwareImageMessage(file.size)
                : 'Image upload failed';
            setFeedbackHtml(feedback, false, librarySizeAwareImageMessage(
                file.size,
                firstErrorMessage(data, fallback)
            ));
            return;
        }
        const range = articleQuill.getSelection(true) || { index: articleQuill.getLength() };
        articleQuill.insertEmbed(range.index, 'image', data.url, 'user');
        articleQuill.setSelection(range.index + 1);
        updateEditorImageCount();
        setFeedbackHtml(feedback, true, 'Image added. Select it and press Backspace, or use Remove.');
    } catch (e) {
        setFeedbackHtml(feedback, false, 'Network error while uploading image.');
    }
}

function bindEditorImagePasteAndDrop() {
    if (!articleQuill || !articleQuill.root || articleQuill.root.dataset.libraryImageIngest === '1') {
        return;
    }
    articleQuill.root.dataset.libraryImageIngest = '1';
    articleQuill.root.addEventListener('paste', function (e) {
        const items = e.clipboardData && e.clipboardData.items;
        if (!items) return;
        for (let i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && String(items[i].type || '').indexOf('image/') === 0) {
                e.preventDefault();
                e.stopPropagation();
                uploadEditorImageFile(items[i].getAsFile());
                return;
            }
        }
    }, true);
    articleQuill.root.addEventListener('drop', function (e) {
        const files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        let image = null;
        for (let i = 0; i < files.length; i++) {
            if (String(files[i].type || '').indexOf('image/') === 0) {
                image = files[i];
                break;
            }
        }
        if (!image) return;
        e.preventDefault();
        e.stopPropagation();
        uploadEditorImageFile(image);
    }, true);
}

function libraryUploadFields(form) {
    const fd = new FormData();
    if (!form) return fd;
    Array.from(form.elements).forEach(function (el) {
        if (!el || !el.name || el.disabled) return;
        if (el.type === 'file') return;
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
        fd.append(el.name, el.value);
    });
    return fd;
}

function libraryUploadPartData(form, filePart, originalName, bytes, chunkMeta) {
    const fd = libraryUploadFields(form);
    // Short ASCII name only — apostrophes/spaces in the real filename break
    // some LiteSpeed multipart parsers and the file never reaches PHP.
    fd.set('file', filePart, LIBRARY_UPLOAD_PART_NAME);
    fd.set('original_filename', librarySafeDocxFilename(originalName));
    fd.set('client_bytes', String(bytes));
    if (chunkMeta) {
        fd.set('chunk_index', String(chunkMeta.index));
        fd.set('chunk_total', String(chunkMeta.total));
        fd.set('upload_id', chunkMeta.uploadId);
    }
    return fd;
}

async function postLibraryUpload(form, file, signal, onProgress) {
    const safeName = librarySafeDocxFilename(file.name);
    const sendPart = async function (body, bytes) {
        const res = await fetch(libraryUrlWithClientBytes(libraryUploadUrl, bytes), {
            method: 'POST',
            headers: libraryClientByteHeaders(bytes),
            body: body,
            signal: signal,
        });
        let data = null;
        try {
            data = await res.json();
        } catch (parseErr) {
            data = null;
        }
        return { res: res, data: data };
    };

    if (file.size <= LIBRARY_UPLOAD_CHUNK_BYTES) {
        const fd = libraryUploadPartData(form, file, safeName, file.size, null);
        if (typeof onProgress === 'function') onProgress(1, 1);
        return sendPart(fd, file.size);
    }

    const total = Math.ceil(file.size / LIBRARY_UPLOAD_CHUNK_BYTES);
    const uploadId = libraryNewUploadId();
    let last = null;
    for (let i = 0; i < total; i++) {
        const blob = file.slice(i * LIBRARY_UPLOAD_CHUNK_BYTES, (i + 1) * LIBRARY_UPLOAD_CHUNK_BYTES);
        const fd = libraryUploadPartData(form, blob, safeName, file.size, {
            index: i,
            total: total,
            uploadId: uploadId,
        });
        if (typeof onProgress === 'function') onProgress(i + 1, total);
        last = await sendPart(fd, file.size);
        if (!last.data || !last.data.success) {
            return last;
        }
        if (i < total - 1) {
            if (!last.data.chunk_received) {
                return last;
            }
            continue;
        }
        if (last.data.chunk_received || !last.data.submission) {
            return {
                res: last.res,
                data: {
                    success: false,
                    message: 'The article could not be uploaded. Please try again.',
                },
            };
        }
    }
    return last;
}

function libraryClientByteHeaders(bytes) {
    return {
        'X-CSRF-TOKEN': libraryCsrf,
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Upload-Bytes': String(bytes),
    };
}

function libraryUrlWithClientBytes(url, bytes) {
    if (!url) return url;
    const join = url.indexOf('?') === -1 ? '?' : '&';
    return url + join + 'client_bytes=' + encodeURIComponent(String(bytes));
}

function assignLibraryFile(file, feedback) {
    const input = document.getElementById('libraryFileInput');
    if (!file || !input) return false;
    if (!/\.docx$/i.test(file.name)) {
        setFeedbackHtml(feedback, false, 'Word .docx only — not PDF, Google Doc, or pasted text.');
        document.getElementById('libraryDropzone')?.classList.add('is-error');
        return false;
    }
    const tooLarge = libraryFileTooLargeMessage(file);
    if (tooLarge) {
        setFeedbackHtml(feedback, false, tooLarge);
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
    let message = '';
    if (data && typeof data.message === 'string' && data.message.trim()) {
        message = data.message.trim();
    } else {
        const errors = data && data.errors;
        if (errors && typeof errors === 'object') {
            const keys = Object.keys(errors);
            for (let i = 0; i < keys.length; i++) {
                const val = errors[keys[i]];
                if (Array.isArray(val) && val[0]) {
                    message = String(val[0]);
                    break;
                }
                if (typeof val === 'string' && val.trim()) {
                    message = val.trim();
                    break;
                }
            }
        }
    }
    if (!message) message = fallback;
    const chosen = document.getElementById('libraryFileInput');
    const bytes = chosen && chosen.files && chosen.files[0] ? chosen.files[0].size : 0;
    return librarySizeAwareUploadMessage(bytes, message);
}

function showLibraryFlash(message, ok) {
    const el = document.getElementById('libraryFlash');
    if (!el) return;
    el.className = 'alert alert-' + (ok ? 'success' : 'danger');
    el.textContent = message;
    el.classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function libraryModerationPassed(data, submission) {
    const sub = submission || {};
    if (sub.needs_image_rights || sub.needs_correction) {
        return false;
    }
    const status = String(sub.moderation_status || '');
    if (status && status !== 'approved') {
        return false;
    }
    if (data && data.approved === true) {
        return true;
    }
    if (data && data.approved === false) {
        return false;
    }

    return status === 'approved';
}

function libraryChipParams(submission) {
    const availability = String((submission && submission.availability) || '');
    const status = String((submission && submission.moderation_status) || '');
    if (availability === 'archived') {
        return { status: 'all', availability: 'archived' };
    }
    if (availability === 'expired') {
        return { status: 'all', availability: 'expired' };
    }
    if (availability === 'in_progress') {
        return { status: 'all', availability: 'in_progress' };
    }
    if (availability === 'published') {
        return { status: 'all', availability: 'published' };
    }
    if (availability === 'evaluating') {
        return { status: 'all', availability: 'evaluating' };
    }
    if (
        availability === 'needs_fix'
        || status === 'needs_improvement'
        || status === 'rejected'
        || status === 'error'
        || (submission && submission.needs_correction)
        || (submission && submission.needs_image_rights)
        || (submission && submission.ready === false)
        || (submission && submission.can_order === false)
    ) {
        return { status: 'all', availability: 'needs_fix' };
    }
    return { status: 'approved', availability: 'available' };
}

function libraryResultMessage(submission, fallback, ok) {
    if (submission && submission.needs_image_rights) {
        return 'This article contains images. Confirm you own them, or add the source URL or copyright details.';
    }
    if (submission && submission.editor_notice) {
        return submission.editor_notice;
    }
    if (submission && submission.ready === false && submission.can_order !== false) {
        return 'Add anchor text and a valid HTTPS target URL, or clear both link fields.';
    }
    if (fallback) return fallback;
    if (!ok) {
        return 'Article needs corrections — it is listed here so you can fix and resubmit.';
    }
    const availability = String((submission && submission.availability) || '');
    const status = String((submission && submission.moderation_status) || '');
    if (availability === 'evaluating' || status === 'pending' || status === 'processing') {
        return 'Article is still evaluating — it stays on Evaluating until the check finishes.';
    }
    return 'Article approved — you can order it from here.';
}

function libraryDestinationUrl(submission) {
    const url = new URL(librarySameOriginPath(libraryIndexUrl, window.location.pathname), window.location.origin);
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
            if (previewOpenedFromEditor) {
                previewOpenedFromEditor = false;
                const editorEl = document.getElementById('articleEditorModal');
                if (articleEditorSubmissionId && articleQuill
                    && Number(articleEditorSubmissionId) === Number(previewModalState.submissionId)
                    && editorEl && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getOrCreateInstance(editorEl).show();
                }
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
        const canEdit = previewModalState.editable;
        editBtn.classList.toggle('d-none', !canEdit);
    }
    if (help) {
        help.textContent = previewModalState.editable
            ? 'Edit any anchor or URL, then save. The first link is used for checkout.'
            : 'Shown outside the article so you can review every anchor and URL.';
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('articlePreviewModal')).show();
}

async function parseLibraryJson(res) {
    try {
        return await res.json();
    } catch (e) {
        return null;
    }
}

async function submissionForEditor(submission) {
    if (!submission || !submission.id) {
        return submission;
    }
    if (submission.preview_html) {
        return submission;
    }
    try {
        const payload = await fetchSubmissionPayload(submission.id);
        return Object.assign({}, submission, payload, {
            preview_html: payload.preview_html || payload.html || '',
            detected_links: payload.detected_links || payload.links || submission.detected_links || [],
        });
    } catch (e) {
        return submission;
    }
}

async function fetchSubmissionPayload(submissionId) {
    const res = await fetch(libraryPreviewUrlBase + '/' + submissionId + '/preview', {
        headers: { 'Accept': 'application/json' },
    });
    const data = await parseLibraryJson(res);
    if (!data) {
        throw new Error('Could not load article');
    }
    if (!res.ok || !data.success) {
        throw new Error((data && data.message) || 'Could not load article');
    }
    return data;
}

document.addEventListener('click', async function (e) {
    const previewBtn = e.target.closest('.js-open-preview');
    const editorBtn = e.target.closest('.js-open-editor');
    const btn = previewBtn || editorBtn;
    if (!btn) return;

    const id = btn.getAttribute('data-submission-id');
    if (!id) {
        showLibraryFlash(previewBtn ? 'Could not open preview' : 'Could not open editor', false);
        return;
    }
    btn.disabled = true;
    try {
        const payload = await fetchSubmissionPayload(id);
        if (previewBtn) {
            openPreviewModal(
                payload.title || 'Article preview',
                payload.html || payload.preview_html || '',
                payload.links || payload.detected_links || [],
                payload.id || parseInt(id, 10),
                !!payload.editable
            );
            return;
        }
        if (!payload.editable) {
            showLibraryFlash('Expired articles are preview only.', false);
            return;
        }
        openArticleEditor(Object.assign({}, payload, {
            id: payload.id || parseInt(id, 10),
            preview_html: payload.preview_html || payload.html || '',
            detected_links: payload.detected_links || payload.links || [],
        }));
    } catch (err) {
        console.error(previewBtn ? 'Failed to open preview' : 'Failed to open editor', err);
        showLibraryFlash(err.message || (previewBtn ? 'Could not open preview' : 'Could not open editor'), false);
    } finally {
        btn.disabled = false;
    }
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
        const html = previewModalState.html || '';
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        await tools.copyHtml(html, tmp.innerText || body.innerText);
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
                preview_html: previewModalState.html || '',
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            tools.toast((data && data.message) || 'Could not save links', false);
            return;
        }
        const sub = data.submission || {};
        const html = sub.preview_html || previewModalState.html;
        const stillApproved = libraryModerationPassed(data, sub);
        const editable = sub.editable !== false;
        openPreviewModal(sub.title || previewModalState.title, html, sub.detected_links || links, previewModalState.submissionId, editable);
        if (!stillApproved) {
            const msg = data.message || (data.report && data.report.summary) || 'Content moderation failed after your link changes. Fix restricted links before ordering.';
            tools.toast(msg, false);
            goToLibraryResult(sub, msg, false);
            return;
        }
        tools.toast(data.message || sub.editor_notice || 'Links saved — content re-checked and approved');
        if (stillApproved && sub.ready) {
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
/**
 * Hostinger often 404s /storage when the public symlink is missing; /media
 * streams the same public-disk file. Catalog already walks that chain.
 */
function publicDiskTwinSrc(src) {
    const value = String(src || '').trim();
    const storage = value.match(/^(https?:\/\/[^/]+)?(\/storage\/)(.+)$/i);
    if (storage) {
        return (storage[1] || '') + '/media/' + storage[3];
    }
    const media = value.match(/^(https?:\/\/[^/]+)?(\/media\/)(.+)$/i);
    if (media) {
        return (media[1] || '') + '/storage/' + media[3];
    }
    return null;
}

function recoverPublicDiskImage(img) {
    if (!img || img.dataset.diskFallback === '1') {
        return false;
    }
    const next = publicDiskTwinSrc(img.getAttribute('src') || '');
    if (!next) {
        return false;
    }
    img.dataset.diskFallback = '1';
    img.setAttribute('src', next);
    return true;
}

function markBrokenLibraryImage(img) {
    if (!img) return;
    img.classList.add('is-broken');
    if (!img.getAttribute('alt')) {
        img.setAttribute('alt', 'Image failed to load');
    }
    img.style.outline = '1px dashed #e2e8f0';
    img.style.minHeight = '48px';
    img.style.background = '#f8fafc';
}

function fixPreviewImages(root) {
    if (!root) return;
    root.querySelectorAll('img').forEach(function (img) {
        const src = img.getAttribute('src') || '';
        const match = src.match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i)
            || src.match(/^(?:https?:)?\/\/[^/]+(\/media\/.+)$/i);
        if (match) {
            img.setAttribute('src', match[1]);
        }
        const rooted = img.getAttribute('src') || '';
        if (rooted.indexOf('/storage/') === 0) {
            img.setAttribute('src', '/media/' + rooted.slice('/storage/'.length));
        }
        img.addEventListener('error', function () {
            if (recoverPublicDiskImage(img)) {
                return;
            }
            markBrokenLibraryImage(img);
        });
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) {
            if (!recoverPublicDiskImage(img)) {
                markBrokenLibraryImage(img);
            }
        }
    });
}

function rewriteStorageUrlsInHtml(html) {
    return String(html || '')
        .replace(/(?:https?:)?\/\/[^"'>\s]+(\/storage\/[^"'\s>]+)/gi, '$1')
        .replace(/(src\s*=\s*["'])\/storage\//gi, '$1/media/');
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
            if (recoverPublicDiskImage(img)) {
                return;
            }
            markBrokenLibraryImage(img);
        });
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) {
            if (!recoverPublicDiskImage(img)) {
                markBrokenLibraryImage(img);
            }
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
    silenceArticleQuillSelectionScroll();
    updateEditorImageCount();
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

    articleQuill.on('text-change', function () {
        updateEditorImageCount();
    });

    articleQuill.on('selection-change', function (range) {
        if (!range) {
            hideImageRemoveOverlay();
            return;
        }
        syncSelectedImageOverlay();
    });

    articleQuill.root.addEventListener('scroll', syncSelectedImageOverlay);
    articleQuill.root.parentElement?.addEventListener('scroll', syncSelectedImageOverlay);
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

function resetLibraryUploadUi() {
    const btn = document.getElementById('libraryUploadBtn');
    const feedback = document.getElementById('libraryUploadFeedback');
    const progress = document.getElementById('libraryUploadProgress');
    const bar = progress ? progress.querySelector('.progress-bar') : null;
    if (btn) btn.disabled = false;
    if (feedback) feedback.textContent = '';
    progress?.classList.add('d-none');
    if (bar) bar.style.width = '0%';
}

function abortLibraryUpload() {
    if (!libraryUploadAbort) return;
    try { libraryUploadAbort.abort(); } catch (e) { /* ignore */ }
    libraryUploadAbort = null;
}

function clearLibraryUploadHandoffTimer() {
    if (!libraryUploadHandoffTimer) return;
    window.clearTimeout(libraryUploadHandoffTimer);
    libraryUploadHandoffTimer = null;
}

function isLibraryUploadAbortError(err) {
    return !!(err && (err.name === 'AbortError' || err.code === 20));
}

/**
 * Cancel/X/Escape must always get the user out of Upload article — including
 * the "Opening editor…" window after the POST already returned. Bootstrap can
 * no-op hide() if a previous hide stalled mid-transition.
 */
function forceDismissUploadModal() {
    const el = document.getElementById('uploadContentModal');
    if (!el) return;
    const gen = ++libraryUploadDismissGen;
    hideBootstrapModal(el);
    window.setTimeout(function () {
        if (gen !== libraryUploadDismissGen) return;
        const editorOpen = document.getElementById('articleEditorModal')?.classList.contains('show');
        const stillVisible = el.classList.contains('show') || el.style.display === 'block';
        if (editorOpen || !stillVisible) return;
        el.classList.remove('show');
        el.style.display = 'none';
        el.setAttribute('aria-hidden', 'true');
        el.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.remove();
        });
    }, 400);
}

function cancelLibraryUploadHandoffState() {
    libraryUploadHandoff = false;
    libraryUploadClosingForEditor = false;
    libraryUploadSavedSubmission = null;
    pendingLibraryLanding = null;
    clearLibraryUploadHandoffTimer();
    abortLibraryUpload();
    resetLibraryUploadUi();
}

function dismissLibraryUploadByUser() {
    const saved = libraryUploadSavedSubmission;
    cancelLibraryUploadHandoffState();
    forceDismissUploadModal();
    if (saved && saved.id) {
        const passed = libraryModerationPassed(saved, saved);
        if (
            saved.needs_image_rights
            || saved.availability === 'needs_fix'
            || saved.availability === 'in_progress'
            || saved.can_order === false
            || saved.ready === false
        ) {
            goToLibraryResult(saved, saved.editor_notice || '', passed);
            return;
        }
        showLibraryFlash('Article uploaded. It is in your library.', true);
    }
}

function bindLibraryUploadCancel() {
    const el = document.getElementById('uploadContentModal');
    if (!el || el.dataset.uploadCancelBound === '1') return;
    el.dataset.uploadCancelBound = '1';
    el.addEventListener('click', function (e) {
        if (!el.classList.contains('show')) return;
        if (!e.target.closest('[data-bs-dismiss="modal"]')) return;
        dismissLibraryUploadByUser();
    });
    el.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!el.classList.contains('show')) return;
        dismissLibraryUploadByUser();
    });
    el.addEventListener('hide.bs.modal', function () {
        abortLibraryUpload();
        if (libraryUploadClosingForEditor) return;
        cancelLibraryUploadHandoffState();
    });
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

function silenceArticleQuillSelectionScroll() {
    if (!articleQuill) {
        return;
    }
    const noop = function () {};
    ['scrollSelectionIntoView', 'scrollRectIntoView'].forEach(function (name) {
        if (typeof articleQuill[name] === 'function') {
            articleQuill[name] = noop;
        }
        if (articleQuill.scroll && typeof articleQuill.scroll[name] === 'function') {
            articleQuill.scroll[name] = noop;
        }
        if (articleQuill.selection && typeof articleQuill.selection[name] === 'function') {
            articleQuill.selection[name] = noop;
        }
    });
}

function ensureArticleEditorScrollWrap() {
    const container = document.querySelector('#articleEditorModal #articleQuillEditor.ql-container');
    const editor = container ? container.querySelector('.ql-editor') : null;
    if (!container || !editor) {
        return null;
    }
    if (editor.parentElement && editor.parentElement.classList.contains('article-editor-scroll')) {
        return editor.parentElement;
    }
    const wrap = document.createElement('div');
    wrap.className = 'article-editor-scroll';
    wrap.setAttribute('data-article-editor-scroll', '1');
    container.insertBefore(wrap, editor);
    wrap.appendChild(editor);
    if (articleQuill) {
        articleQuill.scrollingContainer = wrap;
        silenceArticleQuillSelectionScroll();
    }
    return wrap;
}

function applyArticleEditorScrollport() {
    const wrap = ensureArticleEditorScrollWrap();
    const container = document.querySelector('#articleEditorModal #articleQuillEditor.ql-container');
    const editor = wrap ? wrap.querySelector('.ql-editor') : (container ? container.querySelector('.ql-editor') : null);
    if (!container || !editor) {
        return;
    }
    container.style.minHeight = '0';
    container.style.height = '';
    container.style.overflow = 'hidden';
    if (wrap) {
        wrap.style.minHeight = '0';
        wrap.style.height = '100%';
        wrap.style.maxHeight = '100%';
        wrap.style.overflowX = 'hidden';
        wrap.style.overflowY = 'scroll';
        wrap.style.webkitOverflowScrolling = 'touch';
    }
    editor.style.height = 'auto';
    editor.style.maxHeight = 'none';
    editor.style.minHeight = '100%';
    editor.style.overflow = 'visible';
    silenceArticleQuillSelectionScroll();
}

function articleEditorScrollport() {
    const wrap = document.querySelector('#articleEditorModal .article-editor-scroll');
    const editor = document.querySelector('#articleEditorModal .ql-editor');
    const container = document.querySelector('#articleEditorModal #articleQuillEditor.ql-container');
    if (wrap && wrap.scrollHeight > wrap.clientHeight + 1) {
        return wrap;
    }
    if (editor && editor.scrollHeight > editor.clientHeight + 1) {
        return editor;
    }
    if (container && container.scrollHeight > container.clientHeight + 1) {
        return container;
    }
    return wrap || editor || container;
}

function bindArticleEditorWheel() {
    const modal = document.getElementById('articleEditorModal');
    if (!modal || modal.dataset.wheelBound === '1') {
        return;
    }
    modal.dataset.wheelBound = '1';
    modal.addEventListener('wheel', function (e) {
        const wrap = modal.querySelector('.article-editor-scroll');
        const editor = modal.querySelector('.ql-editor');
        const scroller = articleEditorScrollport();
        if (!scroller || !editor) {
            return;
        }
        if (!editor.contains(e.target) && !(wrap && wrap.contains(e.target))) {
            return;
        }
        let dy = e.deltaY;
        if (e.deltaMode === 1) {
            dy *= 16;
        } else if (e.deltaMode === 2) {
            dy *= scroller.clientHeight;
        }
        const max = scroller.scrollHeight - scroller.clientHeight;
        if (max <= 1) {
            return;
        }
        const next = Math.max(0, Math.min(max, scroller.scrollTop + dy));
        if (next === scroller.scrollTop) {
            return;
        }
        scroller.scrollTop = next;
        e.preventDefault();
        e.stopPropagation();
        window.requestAnimationFrame(function () {
            if (Math.abs(scroller.scrollTop - next) > 1) {
                scroller.scrollTop = next;
            }
        });
    }, { passive: false });
}

function bindArticleEditorScrollport() {
    const editorEl = document.getElementById('articleEditorModal');
    if (!editorEl || editorEl.dataset.scrollportBound === '1') return;
    editorEl.dataset.scrollportBound = '1';
    editorEl.addEventListener('shown.bs.modal', applyArticleEditorScrollport);
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
    silenceArticleQuillSelectionScroll();

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
        input.onchange = function () {
            uploadEditorImageFile(input.files && input.files[0]);
        };
    });
    bindEditorImagePasteAndDrop();

    applyArticleEditorScrollport();
    bindEditorImageChrome();
    return articleQuill;
}

function openArticleEditor(submission) {
    if (!submission || !submission.id) {
        libraryUploadHandoff = false;
        resetLibraryUploadUi();
        return;
    }
    try {
        if (typeof Quill === 'undefined') {
            throw new Error('quill-missing');
        }
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
        if (submission.editor_notice) {
            setFeedbackHtml(
                document.getElementById('articleEditorFeedback'),
                !!submission.editor_notice_ok,
                submission.editor_notice
            );
        }
        const needsRights = !!(submission.needs_image_rights || (submission.has_images && !submission.image_rights_covers));
        syncEditorImageRights(needsRights);
        showArticleEditorAfterUploadModal();
    } catch (e) {
        libraryUploadHandoff = false;
        libraryUploadClosingForEditor = false;
        resetLibraryUploadUi();
        const uploadEl = document.getElementById('uploadContentModal');
        const message = (typeof Quill === 'undefined')
            ? 'The article editor failed to load. Refresh the page and try again.'
            : 'Could not open the editor. Try again.';
        if (uploadEl && uploadEl.classList.contains('show')) {
            setFeedbackHtml(document.getElementById('libraryUploadFeedback'), false, message);
        } else {
            showLibraryFlash(message, false);
        }
    }
}

/**
 * Bootstrap cannot show a second modal while the upload dialog is still
 * hiding — the backdrop sticks and the editor never becomes usable.
 * Cancel during "Opening editor…" must not leave that overlay stuck, and
 * must not open the editor after the user dismissed Upload article.
 */
function showArticleEditorAfterUploadModal() {
    const editorEl = document.getElementById('articleEditorModal');
    const uploadModalEl = document.getElementById('uploadContentModal');
    const showEditor = function () {
        clearLibraryUploadHandoffTimer();
        if (!libraryUploadHandoff) {
            resetLibraryUploadUi();
            return;
        }
        libraryUploadHandoff = false;
        libraryUploadClosingForEditor = false;
        libraryUploadSavedSubmission = null;
        libraryUploadAbort = null;
        resetLibraryUploadUi();
        if (!editorEl || typeof bootstrap === 'undefined') return;
        bootstrap.Modal.getOrCreateInstance(editorEl).show();
    };
    libraryUploadHandoff = true;
    if (uploadModalEl && uploadModalEl.classList.contains('show') && typeof bootstrap !== 'undefined') {
        const onUploadHidden = function () {
            uploadModalEl.removeEventListener('hidden.bs.modal', onUploadHidden);
            showEditor();
        };
        uploadModalEl.addEventListener('hidden.bs.modal', onUploadHidden);
        libraryUploadClosingForEditor = true;
        hideBootstrapModal(uploadModalEl);
        clearLibraryUploadHandoffTimer();
        libraryUploadHandoffTimer = window.setTimeout(function () {
            if (!libraryUploadHandoff) return;
            uploadModalEl.removeEventListener('hidden.bs.modal', onUploadHidden);
            showEditor();
        }, 500);
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
    if (editorImageCount() > LIBRARY_IMAGE_MAX_PER_ARTICLE) {
        setFeedbackHtml(feedback, false, libraryTooManyImagesMessage());
        return;
    }
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
        const data = await parseLibraryJson(res);
        if (res.status === 419) {
            setFeedbackHtml(feedback, false, 'Your session expired. Refresh the page and try again.');
            btn.disabled = false;
            return;
        }
        if (!data) {
            setFeedbackHtml(feedback, false, 'Could not save article.');
            btn.disabled = false;
            return;
        }
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
        const sub = data.submission || { id: articleEditorSubmissionId };
        const stillApproved = libraryModerationPassed(data, sub);
        const msg = data.message
            || (stillApproved
                ? (sub.editor_notice || 'Article saved and re-approved.')
                : 'Article saved, but content moderation failed. Fix restricted links/keywords before ordering.');
        setFeedbackHtml(feedback, stillApproved, msg);
        goToLibraryResult(sub, msg, stillApproved);
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
    previewOpenedFromEditor = true;
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
    previewOpenedFromEditor = false;
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
            if (!payload.editable) {
                showLibraryFlash('Expired articles are preview only.', false);
                return;
            }
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

function libraryFilenameAsTitle(title, filename) {
    const t = String(title || '').trim();
    const f = String(filename || '').trim();
    if (!t) return true;
    if (!f) return false;
    const normalize = function (value) {
        let next = value.toLowerCase();
        if (next.endsWith('.docx')) next = next.slice(0, -5);
        return next;
    };
    return normalize(t) === normalize(f);
}

function paintLibraryTitleCell(id, submission, fallbackTitle) {
    const display = document.querySelector('[data-title-display="' + id + '"]');
    const title = (submission && submission.title) || fallbackTitle || '';
    const filename = (submission && submission.original_filename) || '';
    const leftover = libraryFilenameAsTitle(title, filename);
    const shown = leftover ? 'Untitled' : (title || filename || 'Untitled');
    const tooltip = leftover ? (filename || 'Untitled') : shown;
    if (display) {
        display.textContent = shown;
        display.title = tooltip;
    }
    const row = document.getElementById('library-row-' + id);
    if (!row) return;
    const hint = row.querySelector('.library-filename-hint');
    const rename = row.querySelector('.library-rename-link');
    if (leftover) {
        if (hint && filename) {
            hint.textContent = filename;
            hint.setAttribute('title', filename);
            hint.classList.remove('d-none');
        }
        if (rename) rename.classList.remove('d-none');
        return;
    }
    if (hint) hint.remove();
    if (rename) rename.remove();
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
        paintLibraryTitleCell(id, data.submission || {}, title);
        toggleLibraryTitleEdit(id, false);
        showLibraryFlash('Article renamed.', true);
        fetchLibraryResults(librarySearchParamsFromForm(), {
            historyMode: 'replace',
            keepFocus: false,
        });
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
        refreshLibraryListAfterRowChange(id);
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
        refreshLibraryListAfterRowChange(id);
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
        refreshLibraryListAfterRowChange(id);
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
    const tooLarge = libraryFileTooLargeMessage(file);
    if (tooLarge) {
        setFeedbackHtml(feedback, false, tooLarge);
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

    abortLibraryUpload();
    libraryUploadAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    libraryUploadHandoff = false;
    libraryUploadSavedSubmission = null;
    if (btn) btn.disabled = true;
    progress?.classList.remove('d-none');
    if (bar) bar.style.width = '10%';
    if (feedback) feedback.textContent = 'Uploading your article…';

    let openedEditor = false;
    try {
        if (!libraryUploadUrl) {
            setFeedbackHtml(feedback, false, 'Upload URL is missing');
            return;
        }
        const posted = await postLibraryUpload(
            this,
            file,
            libraryUploadAbort ? libraryUploadAbort.signal : undefined,
            function (part, total) {
                if (bar) bar.style.width = Math.round((part / total) * 100) + '%';
                if (feedback && total > 1) {
                    feedback.textContent = 'Uploading your article… (' + part + '/' + total + ')';
                }
            }
        );
        const res = posted.res;
        let data = posted.data;
        if (bar) bar.style.width = '100%';
        if (!data) {
            setFeedbackHtml(feedback, false, libraryUploadTransportMessage(res.status, file.size));
            return;
        }
        if (!data.success) {
            libraryUploadAbort = null;
            if (res.status === 419 || res.status === 429) {
                setFeedbackHtml(feedback, false, libraryUploadTransportMessage(res.status));
                return;
            }
            setFeedbackHtml(feedback, false, librarySizeAwareUploadMessage(
                file.size,
                firstErrorMessage(data, 'The article could not be uploaded. Please try again.')
            ));
            return;
        }
        libraryUploadAbort = null;
        setFeedbackHtml(feedback, true, 'Opening editor…');
        if (data.submission) {
            openedEditor = true;
            const saved = Object.assign({}, data.submission, {
                approved: data.approved === true,
            });
            libraryUploadSavedSubmission = saved;
            rememberLibraryLanding(
                saved,
                data.message,
                libraryModerationPassed(data, saved)
            );
            const submission = await submissionForEditor(Object.assign({}, data.submission, {
                editor_notice: data.submission.editor_notice
                    || (data.submission.needs_image_rights
                        ? (data.message || '')
                        : (data.approved ? '' : (data.message || ''))),
                editor_notice_ok: !!data.submission.editor_notice_ok,
            }));
            openArticleEditor(submission);
        } else {
            goToLibraryResult({}, data.message || 'Article uploaded.', !!data.approved);
        }
    } catch (err) {
        if (isLibraryUploadAbortError(err)) {
            resetLibraryUploadUi();
            return;
        }
        setFeedbackHtml(feedback, false, 'Upload failed. Please try again.');
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

function libraryFilterForm() {
    return document.querySelector('form.library-filter-bar');
}

function librarySearchParamsFromForm() {
    const form = libraryFilterForm();
    const next = new URLSearchParams();
    if (!form) return next;
    const fd = new FormData(form);
    fd.forEach(function (value, key) {
        const v = String(value == null ? '' : value).trim();
        if (v !== '') next.append(key, String(value));
    });
    return next;
}

function syncLibraryResetVisibility(params) {
    const reset = document.getElementById('libraryFilterReset');
    if (!reset) return;
    const q = (params.get('q') || '').trim();
    const country = params.get('country') || 'all';
    const language = params.get('language') || 'all';
    const availability = params.get('availability') || 'available';
    const show = q !== ''
        || (country !== '' && country !== 'all')
        || (language !== '' && language !== 'all')
        || (availability !== '' && availability !== 'available');
    reset.classList.toggle('d-none', !show);
}

function syncLibrarySearchInputFromParams(params) {
    const input = document.getElementById('librarySearchInput');
    if (!input) return;
    const next = params.get('q') || '';
    if (input.value !== next) input.value = next;
}

function libraryModifiedClick(e) {
    return !!(e && (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey));
}

function normalizeLibraryFilters(params) {
    const hasStatus = params.has('status');
    const hasAvailability = params.has('availability');
    let status = (hasStatus ? params.get('status') : 'approved') || 'approved';
    let availability = (hasAvailability ? params.get('availability') : 'available') || 'available';
    status = String(status).toLowerCase().trim();
    availability = String(availability).toLowerCase().trim();

    if (['all', 'approved', 'rejected', 'needs_improvement'].indexOf(status) === -1) {
        status = 'approved';
    }
    if (['all', 'available', 'evaluating', 'in_progress', 'published', 'completed', 'expired', 'archived', 'needs_fix', 'ordered'].indexOf(availability) === -1) {
        availability = 'available';
    }
    if (availability === 'ordered') availability = 'in_progress';
    // Form / chips / history use the UI key "completed". The controller maps
    // that to internal "published"; do not write "published" back into the form.
    if (availability === 'published') availability = 'completed';
    if (status === 'needs_improvement') {
        status = 'all';
        if (!hasAvailability) availability = 'needs_fix';
    }
    if (status === 'rejected' && !hasAvailability) {
        availability = 'all';
    }
    if (status === 'approved' && availability === 'all') {
        availability = 'available';
    }
    if (!hasStatus && ['needs_fix', 'expired', 'archived', 'in_progress', 'completed', 'evaluating'].indexOf(availability) !== -1) {
        status = 'all';
    }

    return { status: status, availability: availability };
}

function syncLibraryFiltersFromParams(params) {
    const form = libraryFilterForm();
    const setNamed = function (name, value) {
        const el = form ? form.querySelector('[name="' + name + '"]') : null;
        if (el && el.value !== value) el.value = value;
    };
    const normalized = normalizeLibraryFilters(params);
    syncLibrarySearchInputFromParams(params);
    setNamed('q', params.get('q') || '');
    setNamed('status', normalized.status);
    setNamed('availability', normalized.availability);
    setNamed('country', params.get('country') || 'all');
    setNamed('language', params.get('language') || 'all');
}

function refreshLibraryListAfterRowChange(id) {
    document.getElementById('library-row-' + id)?.remove();
    const remaining = document.querySelectorAll('#libraryLiveRegion tbody tr[id^="library-row-"]').length;
    fetchLibraryResults(librarySearchParamsFromForm(), {
        historyMode: 'replace',
        resetPage: remaining === 0,
        keepFocus: false,
    });
}

let libraryResultsAbort = null;
let libraryResultsSeq = 0;

function fetchLibraryResults(params, options) {
    const opts = options || {};
    const region = document.getElementById('libraryLiveRegion');
    const indexPath = librarySameOriginPath(libraryIndexUrl, window.location.pathname);
    const resultsUrl = librarySameOriginPath(libraryResultsUrl, '')
        || (indexPath ? indexPath.replace(/\/?$/, '/') + 'results' : '');
    if (!region || !resultsUrl) return false;

    const query = new URLSearchParams(params);
    if (opts.resetPage) query.delete('page');

    const href = resultsUrl + (query.toString() ? '?' + query.toString() : '');
    const pageHref = (indexPath || window.location.pathname)
        + (query.toString() ? '?' + query.toString() : '');

    if (libraryResultsAbort) {
        libraryResultsAbort.abort();
    }
    libraryResultsAbort = new AbortController();
    const seq = ++libraryResultsSeq;

    fetch(href, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'text/html',
        },
        credentials: 'same-origin',
        signal: libraryResultsAbort.signal,
    }).then(function (res) {
        if (!res.ok) throw new Error('Could not refresh library');
        return res.text();
    }).then(function (html) {
        if (seq !== libraryResultsSeq) return;
        region.innerHTML = html;
        syncLibraryResetVisibility(query);
        const historyMode = opts.historyMode || 'replace';
        try {
            if (historyMode === 'push') {
                window.history.pushState({ libraryLive: 1 }, '', pageHref);
            } else if (historyMode !== 'none') {
                window.history.replaceState({ libraryLive: 1 }, '', pageHref);
            }
        } catch (err) {
            console.error('Library history update failed', err);
        }
        if (opts.keepFocus) {
            const input = document.getElementById('librarySearchInput');
            if (input) {
                input.focus();
                const start = opts.selectionStart;
                const end = opts.selectionEnd;
                if (typeof start === 'number' && typeof end === 'number') {
                    try { input.setSelectionRange(start, end); } catch (err) { /* ignore */ }
                }
            }
        }
    }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        console.error('Library live search failed', err);
    });

    return true;
}

function bootLibraryLiveSearch() {
    const input = document.getElementById('librarySearchInput');
    if (!input) return;

    const runFetch = function (detail) {
        const params = librarySearchParamsFromForm();
        const keepFocus = !detail
            || detail.reason === 'input'
            || detail.reason === 'enter'
            || detail.reason === 'clear';
        fetchLibraryResults(params, {
            historyMode: (detail && detail.historyMode) || 'replace',
            resetPage: !detail || detail.reason !== 'pager',
            keepFocus: keepFocus,
            selectionStart: input.selectionStart,
            selectionEnd: input.selectionEnd,
        });
    };

    if (typeof window.SlbLiveSearch !== 'undefined' && typeof window.SlbLiveSearch.init === 'function') {
        window.SlbLiveSearch.init(input, {
            mode: 'event',
            statusEl: document.getElementById('librarySearchStatus'),
            clearBtn: document.getElementById('librarySearchClear'),
            onSearch: function (detail) {
                runFetch(detail);
            },
        });
    } else {
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            runFetch({ reason: 'enter', historyMode: 'push' });
        });
        document.getElementById('librarySearchClear')?.addEventListener('click', function () {
            input.value = '';
            runFetch({ reason: 'clear', historyMode: 'push' });
            input.focus();
        });
    }

    const form = libraryFilterForm();
    form?.addEventListener('submit', function (e) {
        if (!libraryResultsUrl) return;
        e.preventDefault();
        runFetch({ reason: 'enter', historyMode: 'push' });
    });

    ['libraryCountryFilter', 'libraryLanguageFilter'].forEach(function (id) {
        document.getElementById(id)?.addEventListener('change', function () {
            runFetch({ reason: 'filter', historyMode: 'push' });
        });
    });

    document.getElementById('libraryFilterReset')?.addEventListener('click', function (e) {
        const link = e.target.closest('a');
        if (!link) return;
        if (libraryModifiedClick(e)) return;
        e.preventDefault();
        syncLibraryFiltersFromParams(new URLSearchParams());
        fetchLibraryResults(librarySearchParamsFromForm(), {
            historyMode: 'push',
            resetPage: true,
            keepFocus: false,
        });
    });

    document.getElementById('libraryLiveRegion')?.addEventListener('click', function (e) {
        const chip = e.target.closest('a.library-status-box');
        const pageLink = e.target.closest('.pagination a');
        const link = chip || pageLink;
        if (!link || !this.contains(link)) return;
        if (libraryModifiedClick(e)) return;
        e.preventDefault();
        let params;
        try {
            params = new URL(link.href, window.location.origin).searchParams;
        } catch (err) {
            return;
        }
        syncLibraryFiltersFromParams(params);
        fetchLibraryResults(params, {
            historyMode: 'push',
            resetPage: !pageLink,
            keepFocus: false,
        });
    });

    window.addEventListener('popstate', function () {
        const params = new URLSearchParams(window.location.search);
        syncLibraryFiltersFromParams(params);
        fetchLibraryResults(params, { historyMode: 'none', resetPage: false, keepFocus: false });
    });
}

bootLibraryLiveSearch();

// Blade row actions call these from onclick="" — they must be global.
window.toggleLibraryTitleEdit = toggleLibraryTitleEdit;
window.saveLibraryTitle = saveLibraryTitle;
window.copyLibraryLiveUrl = copyLibraryLiveUrl;
window.archiveLibraryArticle = archiveLibraryArticle;
window.deleteLibraryArticle = deleteLibraryArticle;
window.restoreLibraryArticle = restoreLibraryArticle;
})();
