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
@php($editorLocales = $locales ?? ((class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'supported')) ? \App\Support\PublicI18n::supported() : ['en']))
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
blogEditorLocales.forEach(function (locale) {
    var el = document.getElementById('quillEditor-' + locale);
    if (!el) return;
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
});

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

var form = document.getElementById('blogForm');
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
});
</script>
