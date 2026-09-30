@php
    $prefix = 'translations.'.$locale;
    $t = $t ?? null;
    $isFirst = ! empty($isFirst);
    $blog = $blog ?? null;
@endphp
<div class="tab-pane fade {{ $isFirst ? 'show active' : '' }}" id="locale-pane-{{ $locale }}" role="tabpanel" data-blog-locale="{{ $locale }}">
    <div class="mb-3">
        <label class="form-label fw-semibold">Title {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}</label>
        <input
            type="text"
            name="translations[{{ $locale }}][title]"
            class="form-control form-control-lg @error($prefix.'.title') is-invalid @enderror"
            value="{{ old_text('translations.'.$locale.'.title', $t?->title ?? ($locale === 'en' ? ($blog?->title ?? '') : '')) }}"
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
            value="{{ old_text('translations.'.$locale.'.slug', $t?->slug ?? ($locale === 'en' ? ($blog?->slug ?? '') : '')) }}"
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
        >{{ old_text('translations.'.$locale.'.excerpt', $t?->excerpt ?? ($locale === 'en' ? ($blog?->excerpt ?? '') : '')) }}</textarea>
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
        <script type="application/json" id="existingContent-{{ $locale }}">{!! \App\Services\BlogHtmlSanitizer::encodeForEditor(old_text('translations.'.$locale.'.content', $t?->content ?? ($locale === 'en' ? ($blog?->content ?? '') : ''))) !!}</script>
        @error($prefix.'.content')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </div>
</div>
