@extends('admin.layouts.app')

@php
    use App\Support\AdminBlog;
    $title = $translation?->title ?: $blog->title;
@endphp

@section('content')
<div class="container-fluid">
    <div class="alert alert-warning mb-3">
        Staff preview only — not public. Search engines should not index this page.
        @if($blog->status !== 'published')
            This post is a draft.
        @endif
        @if($translation && ! $translation->is_published)
            This locale is unpublished.
        @endif
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">{{ $title }}</h1>
        <div class="d-flex flex-wrap gap-2">
            <form method="GET" action="{{ route('admin.blogs.preview', $blog->id) }}" class="d-flex gap-2">
                <select name="locale" class="form-select form-select-sm" onchange="this.form.submit()">
                    @foreach($blog->translations as $row)
                        <option value="{{ $row->locale }}" @selected($locale === $row->locale)>{{ AdminBlog::shortLabel($row->locale) }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.blogs.show', $blog->id) }}" class="btn btn-sm btn-secondary">Details</a>
            <a href="{{ route('admin.blogs.edit', $blog->id) }}" class="btn btn-sm btn-primary">Edit</a>
        </div>
    </div>
    @if($translation?->excerpt)
        <p class="text-muted">{{ $translation->excerpt }}</p>
    @endif
    <div class="card border-0 shadow-sm">
        <div class="card-body blog-content">
            {!! $safeContent ?? '' !!}
        </div>
    </div>
</div>
@endsection
