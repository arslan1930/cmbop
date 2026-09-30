@php
    use App\Support\AdminBlog;
    $formLocales = $formLocales ?? ['en'];
    $allLocales = $allLocales ?? AdminBlog::publicLocales();
    $addable = AdminBlog::addableLocales($formLocales);
    $translationMap = $translationMap ?? collect();
    $blog = $blog ?? null;
    $labels = [];
    foreach ($allLocales as $code) {
        $labels[$code] = AdminBlog::shortLabel($code);
    }
@endphp
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <ul class="nav nav-tabs mb-0 flex-grow-1" role="tablist" id="blogLocaleTabs">
        @foreach($formLocales as $index => $locale)
            <li class="nav-item" role="presentation" data-blog-locale-tab="{{ $locale }}">
                <button
                    class="nav-link {{ $index === 0 ? 'active' : '' }}"
                    data-bs-toggle="tab"
                    data-bs-target="#locale-pane-{{ $locale }}"
                    type="button"
                    role="tab"
                >
                    {{ $labels[$locale] ?? strtoupper($locale) }} {!! $locale === 'en' ? '<span class="text-danger">*</span>' : '' !!}
                </button>
            </li>
        @endforeach
    </ul>
    @if($addable !== [])
        <div class="ms-auto">
            <label class="form-label visually-hidden" for="blogAddLocale">Add locale</label>
            <select id="blogAddLocale" class="form-select form-select-sm" style="min-width: 10rem;">
                <option value="">Add locale…</option>
                @foreach($addable as $code)
                    <option value="{{ $code }}">{{ $labels[$code] ?? strtoupper($code) }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>

<div class="tab-content border rounded p-3 bg-white" id="blogLocalePanes">
    @foreach($formLocales as $index => $locale)
        @include('admin.blogs.partials.locale-pane', [
            'locale' => $locale,
            't' => $translationMap[$locale] ?? null,
            'blog' => $blog,
            'isFirst' => $index === 0,
        ])
    @endforeach
</div>

<template id="blogLocaleTabTemplate">
    <li class="nav-item" role="presentation" data-blog-locale-tab="__LOCALE__">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#locale-pane-__LOCALE__" type="button" role="tab">__LABEL__</button>
    </li>
</template>
<template id="blogLocalePaneTemplate">
    @include('admin.blogs.partials.locale-pane', [
        'locale' => '__LOCALE__',
        't' => null,
        'blog' => null,
        'isFirst' => false,
    ])
</template>
<script type="application/json" id="blogLocaleLabels">@json($labels)</script>
