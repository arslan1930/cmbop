@extends('admin.layouts.app')

@php
    use App\Support\AdminBlog;
    $locales = $locales ?? AdminBlog::publicLocales();
    $translations = $blog->relationLoaded('translations')
        ? $blog->translations->keyBy('locale')
        : $blog->translations()->get()->keyBy('locale');
@endphp

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">{{ $blog->title }}</h1>
            <p class="text-muted">Blog details</p>
        </div>
        <div class="col-md-6 text-end d-flex flex-wrap justify-content-end gap-2">
            <a href="{{ route('admin.blogs.index') }}" class="btn btn-secondary">
                <i class="fa fa-arrow-left me-2"></i> Back to Blogs
            </a>
            <a href="{{ route('admin.blogs.preview', $blog->id) }}" class="btn btn-outline-secondary">
                Preview
            </a>
            <a href="{{ route('admin.blogs.edit', $blog->id) }}" class="btn btn-primary">
                <i class="fa fa-edit me-2"></i> Edit
            </a>
            @if($blog->status === 'published')
                <a href="{{ $blog->canonicalUrl() }}" class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer">
                    <i class="fa fa-external-link me-2"></i> View live
                </a>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    @if($blog->featured_image)
                        <div class="mb-4 text-center">
                            <img src="{{ $blog->featuredImageUrl() }}" alt="{{ $blog->title }}" class="img-fluid rounded" style="max-height: 400px; width: auto;">
                        </div>
                    @endif

                    <div class="mb-3">
                        @if($blog->status === 'published')
                            <span class="badge bg-success">Published</span>
                        @else
                            <span class="badge bg-warning text-dark">Draft</span>
                        @endif
                        @if($blog->curated_key)
                            <span class="badge bg-info-subtle text-info-emphasis">Curated · {{ $blog->curated_key }}</span>
                        @endif
                    </div>

                    @if($blog->excerpt)
                        <div class="mb-3">
                            <h2 class="h6">Excerpt</h2>
                            <p class="text-muted mb-0">{{ $blog->excerpt }}</p>
                        </div>
                    @endif

                    <div class="mb-3">
                        <h2 class="h6">Content</h2>
                        <div class="blog-content">
                            {!! $safeContent ?? '' !!}
                        </div>
                    </div>

                    @if($blog->tags)
                        <div class="mb-0">
                            <h2 class="h6">Tags</h2>
                            @foreach($blog->tags as $tag)
                                <span class="badge bg-secondary me-1">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6 mb-3">Locales</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Locale</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($translations as $row)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ AdminBlog::shortLabel($row->locale) }}</div>
                                            <div class="small text-muted">{{ $row->slug }}</div>
                                        </td>
                                        <td>
                                            @if($row->is_published)
                                                <span class="badge bg-success">Published</span>
                                            @else
                                                <span class="badge bg-warning text-dark">Draft</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('admin.blogs.preview', ['id' => $blog->id, 'locale' => $row->locale]) }}">Preview</a>
                                            @if($blog->status === 'published' && $row->is_published)
                                                <div><a href="{{ $blog->canonicalUrl($row->locale) }}" target="_blank" rel="noopener noreferrer">Open live</a></div>
                                            @endif
                                            <div><a href="{{ route('admin.blogs.edit', $blog->id) }}#locale-pane-{{ $row->locale }}">Edit</a></div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-body small">
                    <div class="mb-2"><strong>Author:</strong> {{ $blog->author ?? $blog->creator?->name ?? 'Admin' }}</div>
                    @if($blog->created_by)
                        <div class="mb-2"><a href="{{ route('admin.users.show', $blog->created_by) }}">Open user</a></div>
                    @endif
                    <div class="mb-2"><strong>Created:</strong> {{ optional($blog->created_at)->format('M d, Y H:i') ?: '—' }}</div>
                    <div class="mb-2"><strong>Updated:</strong> {{ optional($blog->updated_at)->format('M d, Y H:i') ?: '—' }}
                        @if($blog->updater)
                            · {{ $blog->updater->name }}
                        @endif
                    </div>
                    <div class="mb-2"><strong>Published:</strong>
                        @if($blog->status === 'published' && $blog->published_at instanceof \DateTimeInterface)
                            {{ $blog->published_at->format('M d, Y H:i') }}
                        @elseif($blog->published_at instanceof \DateTimeInterface)
                            Draft (was {{ $blog->published_at->format('M d, Y H:i') }})
                        @else
                            Not published yet
                        @endif
                    </div>
                    @if($blog->manually_edited_at instanceof \DateTimeInterface)
                        <div><strong>Last staff edit:</strong> {{ $blog->manually_edited_at->format('M d, Y H:i') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
