@php
    use App\Support\AdminBlog;
    $prefix = 'translations.'.$locale;
    $t = $t ?? null;
    $isFirst = ! empty($isFirst);
    $blog = $blog ?? null;
    $titleValue = old_text('translations.'.$locale.'.title', $t?->title ?? ($locale === 'en' ? ($blog?->title ?? '') : ''));
    $slugValue = old_text('translations.'.$locale.'.slug', $t?->slug ?? ($locale === 'en' ? ($blog?->slug ?? '') : ''));
    $excerptValue = old_text('translations.'.$locale.'.excerpt', $t?->excerpt ?? ($locale === 'en' ? ($blog?->excerpt ?? '') : ''));
    $metaTitleValue = old_text('translations.'.$locale.'.meta_title', $t?->meta_title);
    $metaDescriptionValue = old_text('translations.'.$locale.'.meta_description', $t?->meta_description);
    $hintSlug = $slugValue !== '' ? $slugValue : \Illuminate\Support\Str::slug($titleValue);
@endphp
<div class="tab-pane fade {{ $isFirst ? 'show active' : '' }}" id="locale-pane-{{ $locale }}" role="tabpanel" data-blog-locale="{{ $locale }}">
    <div class="mb-3">
        <label class="form-label fw-semibold">Title {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}</label>
        <input
            type="text"
            name="translations[{{ $locale }}][title]"
            class="form-control form-control-lg js-blog-title @error($prefix.'.title') is-invalid @enderror"
            value="{{ $titleValue }}"
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
            class="form-control js-blog-slug @error($prefix.'.slug') is-invalid @enderror"
            value="{{ $slugValue }}"
            placeholder="Leave blank to auto-generate from title"
            data-blog-locale="{{ $locale }}"
        >
        <div class="form-text js-blog-slug-hint" data-blog-locale="{{ $locale }}">
            Public URL: <span class="js-blog-slug-path">{{ url(AdminBlog::publicBlogPathHint($locale, $hintSlug)) }}</span>
        </div>
        <small class="text-muted d-block">If the slug is taken, we append -1.</small>
        @error($prefix.'.slug')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Meta excerpt <span class="text-muted small" data-seo-count="excerpt-{{ $locale }}">{{ mb_strlen($excerptValue) }} / 300</span></label>
        <textarea
            name="translations[{{ $locale }}][excerpt]"
            rows="3"
            class="form-control @error($prefix.'.excerpt') is-invalid @enderror"
            maxlength="300"
            data-seo-count-for="excerpt-{{ $locale }}"
            data-seo-max="300"
        >{{ $excerptValue }}</textarea>
        @error($prefix.'.excerpt')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">SEO title <span class="text-muted small" data-seo-count="meta_title-{{ $locale }}">{{ mb_strlen($metaTitleValue) }} / 70</span></label>
        <input
            type="text"
            name="translations[{{ $locale }}][meta_title]"
            class="form-control @error($prefix.'.meta_title') is-invalid @enderror"
            value="{{ $metaTitleValue }}"
            maxlength="70"
            data-seo-count-for="meta_title-{{ $locale }}"
            data-seo-max="70"
            placeholder="Optional. Defaults to the post title."
        >
        @error($prefix.'.meta_title')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">SEO description <span class="text-muted small" data-seo-count="meta_description-{{ $locale }}">{{ mb_strlen($metaDescriptionValue) }} / 180</span></label>
        <textarea
            name="translations[{{ $locale }}][meta_description]"
            rows="2"
            class="form-control @error($prefix.'.meta_description') is-invalid @enderror"
            maxlength="180"
            data-seo-count-for="meta_description-{{ $locale }}"
            data-seo-max="180"
            placeholder="Optional. Defaults to the meta excerpt."
        >{{ $metaDescriptionValue }}</textarea>
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
            {{ \App\Support\AdminBlog::normalizeIncomplete(old('translations.'.$locale.'.is_published', $t?->is_published ?? true)) ? 'checked' : '' }}
        >
        <label class="form-check-label" for="published-{{ $locale }}">Publish this locale</label>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Content {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}</label>
        <div id="quillEditor-{{ $locale }}" class="border rounded bg-white" style="height: 320px;"></div>
        <input type="hidden" name="translations[{{ $locale }}][content]" id="contentInput-{{ $locale }}">
        <script type="application/json" id="existingContent-{{ $locale }}">{!! \App\Services\BlogHtmlSanitizer::encodeForEditor(old_text('translations.'.$locale.'.content', $t?->content ?? ($locale === 'en' ? ($blog?->content ?? '') : ''))) !!}</script>
        @error($prefix.'.content')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </div>
</div>
