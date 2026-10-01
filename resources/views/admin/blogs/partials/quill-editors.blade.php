<link href="{{ asset('assets/vendor/quill-2.0.2/quill.snow.css') }}?v={{ @filemtime(public_path('assets/vendor/quill-2.0.2/quill.snow.css')) ?: '1' }}" rel="stylesheet">
<script src="{{ asset('assets/vendor/quill-2.0.2/quill.js') }}?v={{ @filemtime(public_path('assets/vendor/quill-2.0.2/quill.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/vendor/sweetalert2/sweetalert2.min.js') }}?v={{ @filemtime(public_path('assets/vendor/sweetalert2/sweetalert2.min.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/admin-blog-images.js') }}"></script>

<input type="file" id="quillImageInput" class="d-none" accept="image/*">

<script>
var quillUploadUrl = @json(route('admin.blogs.upload-image'));
var quillDeleteUrl = @json(route('admin.blogs.delete-content-image'));
var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
var articleImagesManager = null;
@php($editorLocales = $formLocales ?? $locales ?? ['en'])
var blogEditorLocales = @json($editorLocales);

function isEmptyQuillHtml(html) {
    var value = String(html || '').trim();
    if (value === '' || value === '<p><br></p>' || value === '<p></p>') {
        return true;
    }
    if (/<(img|iframe|video|figure|hr)\b/i.test(value)) {
        return false;
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = value;
    return (tmp.textContent || '').trim() === '';
}

var quills = {};
var activeLocale = 'en';

function initBlogQuill(locale) {
    var el = document.getElementById('quillEditor-' + locale);
    if (!el || quills[locale]) {
        return;
    }
    quills[locale] = new Quill(el, {
        theme: 'snow',
        placeholder: 'Write blog content for ' + locale.toUpperCase() + '...',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                ['link', 'image', 'video'],
                ['clean']
            ]
        }
    });
    var existingContentNode = document.getElementById('existingContent-' + locale);
    if (existingContentNode && existingContentNode.textContent) {
        try {
            var existingContent = JSON.parse(existingContentNode.textContent);
            if (existingContent) quills[locale].root.innerHTML = existingContent;
        } catch (e) {}
    }
    quills[locale].getModule('toolbar').addHandler('image', function () {
        activeLocale = locale;
        document.getElementById('quillImageInput').click();
    });
    quills[locale].on('text-change', function () {
        if (typeof markBlogFormDirty === 'function') {
            markBlogFormDirty();
        }
    });
}

blogEditorLocales.forEach(initBlogQuill);

(function mountAddLocale() {
    var select = document.getElementById('blogAddLocale');
    var tabs = document.getElementById('blogLocaleTabs');
    var panes = document.getElementById('blogLocalePanes');
    var tabTpl = document.getElementById('blogLocaleTabTemplate');
    var paneTpl = document.getElementById('blogLocalePaneTemplate');
    var labelsNode = document.getElementById('blogLocaleLabels');
    if (!select || !tabs || !panes || !tabTpl || !paneTpl) {
        return;
    }
    var labels = {};
    try {
        labels = JSON.parse(labelsNode && labelsNode.textContent ? labelsNode.textContent : '{}');
    } catch (e) {
        labels = {};
    }

    select.addEventListener('change', function () {
        var locale = String(select.value || '');
        select.value = '';
        if (!locale || document.getElementById('locale-pane-' + locale)) {
            return;
        }
        var label = labels[locale] || locale.toUpperCase();
        var tabHtml = tabTpl.innerHTML.replaceAll('__LOCALE__', locale).replaceAll('__LABEL__', label);
        var paneHtml = paneTpl.innerHTML.replaceAll('__LOCALE__', locale);
        tabs.insertAdjacentHTML('beforeend', tabHtml);
        panes.insertAdjacentHTML('beforeend', paneHtml);
        initBlogQuill(locale);
        var opt = select.querySelector('option[value="' + locale + '"]');
        if (opt) {
            opt.remove();
        }
        if (!select.querySelector('option[value]:not([value=""])')) {
            select.closest('div')?.classList.add('d-none');
        }
        select.dispatchEvent(new Event('admin-select-refresh'));
        var trigger = tabs.querySelector('[data-bs-target="#locale-pane-' + locale + '"]');
        if (trigger && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(trigger).show();
        }
        if (typeof refreshBlogSlugHints === 'function') {
            refreshBlogSlugHints();
        }
        if (typeof refreshAllSeoCounts === 'function') {
            refreshAllSeoCounts();
        }
    });

    document.querySelectorAll('select[name="primary_locale"]').forEach(function (primary) {
        primary.addEventListener('change', function () {
            var locale = String(primary.value || '');
            if (locale && !document.getElementById('locale-pane-' + locale)) {
                select.value = locale;
                select.dispatchEvent(new Event('change'));
            }
        });
    });
})();

document.getElementById('quillImageInput').addEventListener('change', function () {
    var file = this.files && this.files[0];
    this.value = '';
    if (!file) {
        return;
    }

    var formData = new FormData();
    formData.append('image', file);

    fetch(quillUploadUrl, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData,
        credentials: 'same-origin'
    })
        .then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        })
        .then(function (result) {
            if (!result.ok || !result.data.success || !result.data.url) {
                throw new Error((result.data && result.data.error) || 'Image upload failed.');
            }
            var editor = quills[activeLocale] || quills.en;
            if (!editor) {
                throw new Error('Open a locale tab before inserting an image.');
            }
            var range = editor.getSelection(true) || { index: editor.getLength(), length: 0 };
            editor.insertEmbed(range.index, 'image', result.data.url, 'user');
            editor.setSelection(range.index + 1, 0, 'silent');
            if (articleImagesManager) {
                articleImagesManager.scheduleRender();
            }
        })
        .catch(function (error) {
            Swal.fire('Error', error.message || 'Failed to upload image.', 'error');
        });
});

articleImagesManager = new AdminBlogImages({
    quills: quills,
    uploadUrl: quillUploadUrl,
    deleteUrl: quillDeleteUrl,
    csrfToken: csrfToken
});

function blogPublicPathHint(locale, slug) {
    slug = String(slug || '').trim().replace(/^\/+|\/+$/g, '') || 'your-slug';
    locale = String(locale || 'en').toLowerCase();
    if (locale === 'en' || locale === '' || locale === '__locale__') {
        return '/blog/' + slug;
    }
    return '/' + locale + '/blog/' + slug;
}

function slugifyTitle(title) {
    return String(title || '').toLowerCase().trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function refreshBlogSlugHints() {
    document.querySelectorAll('.js-blog-slug-hint').forEach(function (hint) {
        var locale = hint.getAttribute('data-blog-locale') || 'en';
        var pane = document.getElementById('locale-pane-' + locale);
        if (!pane) {
            return;
        }
        var slugInput = pane.querySelector('.js-blog-slug');
        var titleInput = pane.querySelector('.js-blog-title');
        var raw = ((slugInput && slugInput.value) || '').trim() || slugifyTitle(titleInput && titleInput.value);
        var path = hint.querySelector('.js-blog-slug-path');
        if (path) {
            path.textContent = window.location.origin + blogPublicPathHint(locale, raw);
        }
    });
}

function refreshSeoCount(input) {
    if (!input || !input.getAttribute) {
        return;
    }
    var key = input.getAttribute('data-seo-count-for');
    if (!key) {
        return;
    }
    var max = parseInt(input.getAttribute('data-seo-max') || input.getAttribute('maxlength') || '0', 10);
    var el = document.querySelector('[data-seo-count="' + key + '"]');
    if (el) {
        el.textContent = String((input.value || '').length) + ' / ' + max;
    }
}

function refreshAllSeoCounts() {
    document.querySelectorAll('[data-seo-count-for]').forEach(refreshSeoCount);
}

var blogFormDirty = false;
function markBlogFormDirty() {
    blogFormDirty = true;
}

document.addEventListener('input', function (e) {
    var target = e.target;
    if (!target) {
        return;
    }
    if (typeof target.matches === 'function' && target.matches('.js-blog-slug, .js-blog-title')) {
        refreshBlogSlugHints();
    }
    if (typeof target.getAttribute === 'function' && target.getAttribute('data-seo-count-for')) {
        refreshSeoCount(target);
    }
});

refreshBlogSlugHints();
refreshAllSeoCounts();

var form = document.getElementById('blogForm');
if (form && form.getAttribute('data-blog-unsaved-guard') === '1') {
    form.addEventListener('input', markBlogFormDirty);
    form.addEventListener('change', markBlogFormDirty);
    window.addEventListener('beforeunload', function (e) {
        if (!blogFormDirty) {
            return;
        }
        e.preventDefault();
        e.returnValue = '';
    });
}

if (form) form.addEventListener('submit', function (e) {
    Object.keys(quills).forEach(function (locale) {
        var input = document.getElementById('contentInput-' + locale);
        if (!input) {
            return;
        }
        var content = quills[locale].root.innerHTML.trim();
        input.value = isEmptyQuillHtml(content) ? '' : content;
    });

    var enContent = (document.getElementById('contentInput-en')?.value || '').trim();
    if (isEmptyQuillHtml(enContent)) {
        e.preventDefault();
        Swal.fire('Error', 'Please enter English content before submitting.', 'error');
        return false;
    }
    blogFormDirty = false;
});
</script>
