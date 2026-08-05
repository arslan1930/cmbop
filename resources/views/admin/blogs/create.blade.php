@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Create New Blog</h1>
            <p class="text-muted">Add a new blog post</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.blogs.index') }}" class="btn btn-secondary">
                <i class="fa fa-arrow-left me-2"></i> Back to Blogs
            </a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            <strong>Please fix the following errors:</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form action="{{ route('admin.blogs.store') }}" method="POST" enctype="multipart/form-data" id="blogForm">
                @csrf

                <div class="row">
                    <div class="col-md-8">
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            @foreach(($locales ?? ['en','de','fr','nl']) as $index => $locale)
                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link {{ $index === 0 ? 'active' : '' }}"
                                        data-bs-toggle="tab"
                                        data-bs-target="#locale-pane-{{ $locale }}"
                                        type="button"
                                        role="tab"
                                    >
                                        {{ strtoupper($locale) }} {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        <div class="tab-content border rounded p-3 bg-white">
                            @foreach(($locales ?? ['en','de','fr','nl']) as $index => $locale)
                                @php($prefix = "translations.$locale")
                                <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="locale-pane-{{ $locale }}" role="tabpanel">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Title {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}</label>
                                        <input
                                            type="text"
                                            name="translations[{{ $locale }}][title]"
                                            class="form-control form-control-lg @error($prefix.'.title') is-invalid @enderror"
                                            value="{{ old_text('translations.'.$locale.'.title') }}"
                                            {{ $locale === 'en' ? 'required' : '' }}
                                        >
                                        @error($prefix.'.title')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Slug</label>
                                        <input
                                            type="text"
                                            name="translations[{{ $locale }}][slug]"
                                            class="form-control @error($prefix.'.slug') is-invalid @enderror"
                                            value="{{ old_text('translations.'.$locale.'.slug') }}"
                                            placeholder="Leave blank to auto-generate from title"
                                        >
                                        @error($prefix.'.slug')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Meta excerpt</label>
                                        <textarea
                                            name="translations[{{ $locale }}][excerpt]"
                                            rows="3"
                                            class="form-control @error($prefix.'.excerpt') is-invalid @enderror"
                                            maxlength="300"
                                        >{{ old_text('translations.'.$locale.'.excerpt') }}</textarea>
                                        @error($prefix.'.excerpt')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Content {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}</label>
                                        <div id="quillEditor-{{ $locale }}" class="border rounded bg-white" style="height: 320px;"></div>
                                        <input type="hidden" name="translations[{{ $locale }}][content]" id="contentInput-{{ $locale }}">
                                        @error($prefix.'.content')
                                            <div class="text-danger small mt-1">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @include('admin.blogs.partials.article-images-manager')
                    </div>

                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Featured Image <span class="text-muted small">(optional)</span></label>
                            <div class="border rounded p-3 text-center" style="background: #f8f9fa;">
                                <div id="featuredImagePreview" class="mb-2">
                                    <div id="noImagePlaceholder" class="text-center">
                                        <i class="fa fa-image fa-3x text-muted mb-2"></i>
                                        <p class="text-muted small">No image selected</p>
                                    </div>
                                </div>
                                <input type="file" name="featured_image" id="featuredImageInput" class="d-none" accept="image/*">
                                <div class="d-flex flex-wrap justify-content-center gap-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="featuredImagePickBtn">
                                        <i class="fa fa-upload me-1"></i> Choose Image
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm d-none" id="featuredImageClearBtn">
                                        <i class="fa fa-trash me-1"></i> Clear
                                    </button>
                                </div>
                                <small class="text-muted d-block mt-2">JPG, PNG, GIF, WEBP (max 5MB)</small>
                            </div>
                            @error('featured_image')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tags</label>
                            <input type="text" name="tags" class="form-control @error('tags') is-invalid @enderror" value="{{ old_text('tags') }}" placeholder="laravel, php, web development">
                            <small class="text-muted">Comma-separated tags</small>
                            @error('tags')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Primary locale</label>
                            <select name="primary_locale" class="form-select @error('primary_locale') is-invalid @enderror">
                                <option value="">Auto (current URL locale)</option>
                                @foreach(($locales ?? ['en','de','fr','nl']) as $code)
                                    <option value="{{ $code }}" {{ old_text('primary_locale') === $code ? 'selected' : '' }}>{{ strtoupper($code) }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Sets preferred canonical (e.g. DE posts → /de/blog/...)</small>
                            @error('primary_locale')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                <option value="draft" {{ old('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                                <option value="published" {{ old('status') == 'published' ? 'selected' : '' }}>Published</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary px-4" id="submitBtn">
                        <i class="fa fa-save me-2"></i> Create Blog
                    </button>
                    <a href="{{ route('admin.blogs.index') }}" class="btn btn-secondary px-4">
                        <i class="fa fa-times me-2"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('assets/js/admin-blog-images.js') }}"></script>

<input type="file" id="quillImageInput" class="d-none" accept="image/*">

<script>
var quillUploadUrl = @json(route('admin.blogs.upload-image'));
var quillDeleteUrl = @json(route('admin.blogs.delete-content-image'));
var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
var articleImagesManager = null;

var quills = {};
var activeLocale = 'en';
['en', 'de', 'fr', 'nl'].forEach(function (locale) {
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

function showFeaturedPlaceholder() {
    document.getElementById('featuredImagePreview').innerHTML =
        '<div id="noImagePlaceholder" class="text-center">' +
        '<i class="fa fa-image fa-3x text-muted mb-2"></i>' +
        '<p class="text-muted small">No image selected</p>' +
        '</div>';
    document.getElementById('featuredImageClearBtn').classList.add('d-none');
}

document.getElementById('featuredImagePickBtn').addEventListener('click', function () {
    document.getElementById('featuredImageInput').click();
});

document.getElementById('featuredImageInput').addEventListener('change', function () {
    var file = this.files && this.files[0];
    if (!file) {
        showFeaturedPlaceholder();
        return;
    }
    var reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('featuredImagePreview').innerHTML =
            '<img src="' + e.target.result + '" alt="Preview" class="img-fluid rounded" style="max-height: 150px;">';
        document.getElementById('featuredImageClearBtn').classList.remove('d-none');
        if (articleImagesManager) {
            articleImagesManager.scheduleRender();
        }
    };
    reader.readAsDataURL(file);
});

document.getElementById('featuredImageClearBtn').addEventListener('click', function () {
    document.getElementById('featuredImageInput').value = '';
    showFeaturedPlaceholder();
});

var form = document.getElementById('blogForm');
form.addEventListener('submit', function (e) {
    Object.keys(quills).forEach(function (locale) {
        var content = quills[locale].root.innerHTML.trim();
        document.getElementById('contentInput-' + locale).value = content;
    });

    var enContent = (document.getElementById('contentInput-en')?.value || '').trim();
    if (!enContent || enContent === '<p><br></p>' || enContent === '<p></p>') {
        e.preventDefault();
        Swal.fire('Error', 'Please enter English content before submitting.', 'error');
        return false;
    }
});
</script>
@endsection