@extends('admin.layouts.app')

@section('title', 'Edit '.$meta['label'])

@section('content')
<div class="container-fluid py-3">
    @include('admin.partials.page-header', [
        'title' => $meta['label'],
        'subtitle' => 'Publishing replaces the built-in translated page for this locale only. Other locales keep their translations.',
        'actionUrl' => route('admin.legal.index'),
        'actionLabel' => 'Legal & FAQ',
        'actionIcon' => 'fa-arrow-left',
    ])

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach($locales as $loc)
            <a href="{{ route('admin.legal.edit', ['slug' => $slug, 'locale' => $loc]) }}"
               class="btn btn-sm {{ $locale === $loc ? 'btn-primary' : 'btn-outline-secondary' }}">
                {{ strtoupper($loc) }}
                @if(in_array($loc, $publishedLocales, true))
                    · live
                @endif
            </a>
        @endforeach
        <a href="{{ $publicUrl }}" class="btn btn-sm btn-link" target="_blank" rel="noopener">View public page</a>
    </div>

    @if($slug === \App\Models\LegalPageOverride::SLUG_FAQ)
        <div class="alert alert-warning" role="note">
            Publishing replaces the built-in accordion and FAQPage schema for this locale.
            Welcome-credit question #4 is no longer auto-hidden when grants are off — write that copy yourself if you still want it.
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.legal.update', $slug) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="locale" value="{{ $locale }}">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="legalTitle">Title override</label>
                    <input type="text" name="title" id="legalTitle" class="form-control" maxlength="180"
                           value="{{ old('title', $override?->title) }}"
                           placeholder="{{ __('messages.'.$meta['hero']) }}">
                    <div class="form-text">Leave blank to keep the translated heading.</div>
                    @error('title')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="legalBody">HTML body</label>
                    <textarea name="body_html" id="legalBody" class="form-control font-monospace" rows="18"
                              required>{{ old('body_html', $override?->body_html) }}</textarea>
                    <div class="form-text">Headings, lists, links, and paragraphs are kept. Scripts are stripped on save.</div>
                    @error('body_html')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="publish" id="legalPublish" value="1"
                           @checked(old('publish', $override?->isPublished()))>
                    <label class="form-check-label" for="legalPublish">Publish for {{ strtoupper($locale) }}</label>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    @if($override)
                        <button type="submit" form="legalRevertForm" class="btn btn-outline-danger">Revert to built-in</button>
                    @endif
                </div>
            </form>
            @if($override)
                <form id="legalRevertForm" method="POST" action="{{ route('admin.legal.revert', $slug) }}" class="d-none">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
