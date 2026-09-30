@extends('admin.layouts.app')

@php
    $listUrl = \App\Support\AdminBlog::listUrl($indexQuery ?? []);
@endphp

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Create New Blog',
        'subtitle' => 'Write English first. Add other locales only when you need them.',
        'actionUrl' => $listUrl,
        'actionLabel' => 'Back to Blogs',
        'actionIcon' => 'fa-arrow-left',
    ])

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form action="{{ route('admin.blogs.store') }}" method="POST" enctype="multipart/form-data" id="blogForm" class="admin-deposits-filters" data-admin-filter-live="1" data-blog-unsaved-guard="1">
                @csrf
                <input type="hidden" name="status" id="blogStatusInput" value="{{ \App\Support\AdminBlog::normalizeStatus(old_text('status', 'draft')) ?: 'draft' }}">
                <input type="hidden" name="intent" id="blogIntentInput" value="draft">

                <div class="row">
                    <div class="col-md-8">
                        @include('admin.blogs.partials.locale-tabs', [
                            'formLocales' => $formLocales ?? ['en'],
                            'allLocales' => $locales ?? \App\Support\AdminBlog::publicLocales(),
                            'blog' => null,
                            'translationMap' => collect(),
                        ])

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
                            <label class="form-label fw-semibold">Author</label>
                            <input type="text" name="author" class="form-control @error('author') is-invalid @enderror" value="{{ old_text('author', auth()->user()?->name) }}" maxlength="120">
                            @error('author')
                                <div class="invalid-feedback">{{ $message }}</div>
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
                                <option value="" {{ old_text('primary_locale') === '' ? 'selected' : '' }}>English (UK) — default canonical</option>
                                @foreach(($locales ?? \App\Support\AdminBlog::publicLocales()) as $code)
                                    <option value="{{ $code }}" {{ old_text('primary_locale') === $code ? 'selected' : '' }}>{{ \App\Support\AdminBlog::shortLabel($code) }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Public <code>/blog/{slug}</code> uses this locale’s slug. English title and body are still required.</small>
                            @error('primary_locale')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-outline-primary px-4" id="blogDraftBtn">
                        <i class="fa fa-save me-2"></i> Save draft
                    </button>
                    <button type="button" class="btn btn-primary px-4" id="blogPublishBtn">
                        <i class="fa fa-globe me-2"></i> Publish
                    </button>
                    <a href="{{ $listUrl }}" class="btn btn-secondary px-4" id="blogCancelLink">
                        <i class="fa fa-times me-2"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

@include('admin.blogs.partials.quill-editors')

<script>
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

(function () {
    var form = document.getElementById('blogForm');
    var statusInput = document.getElementById('blogStatusInput');
    var intentInput = document.getElementById('blogIntentInput');
    var publishBtn = document.getElementById('blogPublishBtn');
    var draftBtn = document.getElementById('blogDraftBtn');
    if (!form || !statusInput || !intentInput || !publishBtn) {
        return;
    }

    draftBtn?.addEventListener('click', function () {
        statusInput.value = 'draft';
        intentInput.value = 'draft';
    });

    publishBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var go = function () {
            statusInput.value = 'published';
            intentInput.value = 'publish';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        };
        if (!window.Swal) {
            go();
            return;
        }
        Swal.fire({
            title: 'Publish this post?',
            text: 'This goes live on the public blog.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Publish',
            cancelButtonText: 'Cancel'
        }).then(function (result) {
            if (result.isConfirmed) {
                go();
            }
        });
    });
})();
</script>
@endsection
