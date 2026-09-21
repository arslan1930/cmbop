@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Edit Blog</h1>
            <p class="text-muted">Update your blog post</p>
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
            <form action="{{ route('admin.blogs.update', $blog->id) }}" method="POST" enctype="multipart/form-data" id="blogForm">
                @csrf
                @method('PUT')

                <div class="row">
                    <div class="col-md-8">
                        @php
                            $translationMap = $blog->translations->keyBy('locale');
                        @endphp
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            @foreach(($locales ?? ((class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'supported')) ? \App\Support\PublicI18n::supported() : ['en'])) as $index => $locale)
                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link {{ $index === 0 ? 'active' : '' }}"
                                        data-bs-toggle="tab"
                                        data-bs-target="#locale-pane-{{ $locale }}"
                                        type="button"
                                        role="tab"
                                    >
                                        {{ (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'shortLabel')) ? \App\Support\PublicI18n::shortLabel($locale) : strtoupper($locale) }} {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        <div class="tab-content border rounded p-3 bg-white">
                            @foreach(($locales ?? ((class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'supported')) ? \App\Support\PublicI18n::supported() : ['en'])) as $index => $locale)
                                @php
                                    $prefix = "translations.$locale";
                                    $t = $translationMap[$locale] ?? null;
                                @endphp
                                <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="locale-pane-{{ $locale }}" role="tabpanel">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Title {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}</label>
                                        <input
                                            type="text"
                                            name="translations[{{ $locale }}][title]"
                                            class="form-control form-control-lg @error($prefix.'.title') is-invalid @enderror"
                                            value="{{ old_text('translations.'.$locale.'.title', $t?->title ?? ($locale === 'en' ? $blog->title : '')) }}"
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
                                            value="{{ old_text('translations.'.$locale.'.slug', $t?->slug ?? ($locale === 'en' ? $blog->slug : '')) }}"
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
                                        >{{ old_text('translations.'.$locale.'.excerpt', $t?->excerpt ?? ($locale === 'en' ? $blog->excerpt : '')) }}</textarea>
                                        @error($prefix.'.excerpt')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">SEO title</label>
                                        <input
                                            type="text"
                                            name="translations[{{ $locale }}][meta_title]"
                                            class="form-control @error($prefix.'.meta_title') is-invalid @enderror"
                                            value="{{ old_text('translations.'.$locale.'.meta_title', $t?->meta_title) }}"
                                            maxlength="70"
                                            placeholder="Optional. Defaults to the post title."
                                        >
                                        @error($prefix.'.meta_title')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">SEO description</label>
                                        <textarea
                                            name="translations[{{ $locale }}][meta_description]"
                                            rows="2"
                                            class="form-control @error($prefix.'.meta_description') is-invalid @enderror"
                                            maxlength="180"
                                            placeholder="Optional. Defaults to the meta excerpt."
                                        >{{ old_text('translations.'.$locale.'.meta_description', $t?->meta_description) }}</textarea>
                                        @error($prefix.'.meta_description')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="form-check mb-3">
                                        <input type="hidden" name="translations[{{ $locale }}][is_published]" value="0">
                                        <input
                                            type="checkbox"
                                            name="translations[{{ $locale }}][is_published]"
                                            id="published-{{ $locale }}"
                                            class="form-check-input"
                                            value="1"
                                            {{ old('translations.'.$locale.'.is_published', $t?->is_published ?? true) ? 'checked' : '' }}
                                        >
                                        <label class="form-check-label" for="published-{{ $locale }}">Publish this locale</label>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Content {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}</label>
                                        <div id="quillEditor-{{ $locale }}" class="border rounded bg-white" style="height: 320px;"></div>
                                        <input type="hidden" name="translations[{{ $locale }}][content]" id="contentInput-{{ $locale }}">
                                        <script type="application/json" id="existingContent-{{ $locale }}">{!! \App\Services\BlogHtmlSanitizer::encodeForEditor(old('translations.'.$locale.'.content', $t?->content ?? ($locale === 'en' ? $blog->content : ''))) !!}</script>
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
                        <!-- Featured Image -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Featured Image</label>
                            <div class="border rounded p-3 text-center" style="background: #f8f9fa;">
                                <div id="featuredImagePreview" class="mb-2">
                                    @if($blog->featured_image)
                                        <img src="{{ $blog->featuredImageUrl() }}" alt="Current Image" class="img-fluid rounded" style="max-height: 150px;">
                                    @else
                                        <div id="noImagePlaceholder">
                                            <i class="fa fa-image fa-3x text-muted mb-2"></i>
                                            <p class="text-muted small">No image selected</p>
                                        </div>
                                    @endif
                                </div>
                                <input type="file" name="featured_image" id="featuredImageInput" class="d-none" accept="image/*">
                                <input type="hidden" name="remove_featured_image" id="removeFeaturedImage" value="0">
                                <div class="d-flex flex-wrap justify-content-center gap-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="featuredImagePickBtn">
                                        <i class="fa fa-upload me-1"></i> {{ $blog->featured_image ? 'Change Image' : 'Choose Image' }}
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm {{ $blog->featured_image ? '' : 'd-none' }}" id="featuredImageRemoveBtn">
                                        <i class="fa fa-trash me-1"></i> Remove Image
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
                            <input type="text" name="author" class="form-control @error('author') is-invalid @enderror" value="{{ old_text('author', $blog->author) }}" maxlength="120">
                            @error('author')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Tags -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tags</label>
                            <input type="text" name="tags" class="form-control @error('tags') is-invalid @enderror" value="{{ old_text('tags', $blog->formatted_tags) }}" placeholder="laravel, php, web development">
                            <small class="text-muted">Comma-separated tags</small>
                            @error('tags')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Primary locale</label>
                            <select name="primary_locale" class="form-select @error('primary_locale') is-invalid @enderror">
                                <option value="">Auto (current URL locale)</option>
                                @foreach(($locales ?? ((class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'supported')) ? \App\Support\PublicI18n::supported() : ['en'])) as $code)
                                    <option value="{{ $code }}" {{ old_text('primary_locale', $blog->primary_locale) === $code ? 'selected' : '' }}>{{ strtoupper($code) }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Preferred canonical locale for this post</small>
                            @error('primary_locale')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Status -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                <option value="draft" {{ old('status', $blog->status) == 'draft' ? 'selected' : '' }}>Draft</option>
                                <option value="published" {{ old('status', $blog->status) == 'published' ? 'selected' : '' }}>Published</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary px-4" id="submitBtn">
                        <i class="fa fa-save me-2"></i> Update Blog
                    </button>
                    <a href="{{ route('admin.blogs.index') }}" class="btn btn-secondary px-4">
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
        '<div id="noImagePlaceholder">' +
        '<i class="fa fa-image fa-3x text-muted mb-2"></i>' +
        '<p class="text-muted small">No image selected</p>' +
        '</div>';
}

document.getElementById('featuredImagePickBtn').addEventListener('click', function () {
    document.getElementById('featuredImageInput').click();
});

document.getElementById('featuredImageInput').addEventListener('change', function () {
    var file = this.files && this.files[0];
    if (!file) {
        return;
    }
    document.getElementById('removeFeaturedImage').value = '0';
    document.getElementById('featuredImageRemoveBtn').classList.remove('d-none');
    var reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('featuredImagePreview').innerHTML =
            '<img src="' + e.target.result + '" alt="Preview" class="img-fluid rounded" style="max-height: 150px;">';
        if (articleImagesManager) {
            articleImagesManager.scheduleRender();
        }
    };
    reader.readAsDataURL(file);
});

document.getElementById('featuredImageRemoveBtn').addEventListener('click', function () {
    document.getElementById('featuredImageInput').value = '';
    document.getElementById('removeFeaturedImage').value = '1';
    showFeaturedPlaceholder();
    this.classList.add('d-none');
    document.getElementById('featuredImagePickBtn').innerHTML = '<i class="fa fa-upload me-1"></i> Choose Image';
});
</script>
@endsection