@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">{{ $blog->title }}</h1>
            <p class="text-muted">View blog details</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.blogs.index') }}" class="btn btn-secondary">
                <i class="fa fa-arrow-left me-2"></i> Back to Blogs
            </a>
            <a href="{{ route('admin.blogs.edit', $blog->id) }}" class="btn btn-primary">
                <i class="fa fa-edit me-2"></i> Edit
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @if($blog->featured_image)
                <div class="mb-4 text-center">
                    <img src="{{ Storage::url($blog->featured_image) }}" alt="{{ $blog->title }}" class="img-fluid rounded" style="max-height: 400px; width: auto;">
                </div>
            @endif

            <div class="mb-3">
                <h5>Status</h5>
                @if($blog->status === 'published')
                    <span class="badge bg-success">Published</span>
                @else
                    <span class="badge bg-warning text-dark">Draft</span>
                @endif
            </div>

            @if($blog->excerpt)
                <div class="mb-3">
                    <h5>Excerpt</h5>
                    <p class="text-muted">{{ $blog->excerpt }}</p>
                </div>
            @endif

            <div class="mb-3">
                <h5>Content</h5>
                <div class="blog-content">
                    {!! $safeContent ?? '' !!}
                </div>
            </div>

            @if($blog->tags)
                <div class="mb-3">
                    <h5>Tags</h5>
                    @foreach($blog->tags as $tag)
                        <span class="badge bg-secondary me-1">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif

            <div class="row mt-4 pt-3 border-top">
                <div class="col-md-6">
                    <small class="text-muted">
                        <strong>Author:</strong> {{ $blog->author ?? $blog->creator->name ?? 'Admin' }}
                    </small>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">
                        <strong>Created:</strong> {{ $blog->created_at->format('M d, Y H:i') }}
                    </small>
                    <br>
                    <small class="text-muted">
                        <strong>Published:</strong> {{ $blog->published_at ? $blog->published_at->format('M d, Y H:i') : 'Not published yet' }}
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection