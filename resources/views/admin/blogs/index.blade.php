@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Blogs</h1>
            <p class="text-muted">Create, publish, and manage SEO blog posts and daily updates for the public blog page.</p>
        </div>
        <div class="col-md-6 admin-blogs-header-actions">
            <form action="{{ route('admin.blogs.sync-curated') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-outline-primary"
                        title="Import/update curated SEO pillar posts into this list"
                        data-slb-confirm="This updates unedited pillar posts from code. Posts you edited are kept. Deleted pillar posts stay deleted."
                        data-slb-confirm-title="Sync curated SEO blogs?"
                        data-slb-confirm-text="Sync">
                    <i class="fa fa-sync me-2"></i> Sync curated SEO blogs
                </button>
            </form>
            <a href="{{ route('admin.blogs.create') }}" class="btn btn-primary">
                <i class="fa fa-plus me-2"></i> Create New Blog
            </a>
        </div>
    </div>



    <div class="alert alert-light border mb-4">
        <strong>Missing curated posts?</strong>
        Code deploy alone does not insert blog rows. Click <em>Sync curated SEO blogs</em> (or run <code>php artisan blog:upsert-curated</code>) to load pillar posts so you can edit, unpublish, or delete them here.
    </div>

    <form method="GET" action="{{ route('admin.blogs.index') }}" class="row g-2 align-items-end mb-4">
        <div class="col-md-3">
            <x-slb-search-field name="q" id="adminBlogsSearch" :value="request('q')" placeholder="Title, slug, author…" />
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1" for="adminBlogsStatus">Status</label>
            <select name="status" id="adminBlogsStatus" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="published" @selected(request('status') === 'published')>Published</option>
                <option value="draft" @selected(request('status') === 'draft')>Draft</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1" for="adminBlogsLocale">Primary locale</label>
            <select name="locale" id="adminBlogsLocale" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach(((class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'supported')) ? \App\Support\PublicI18n::supported() : ['en']) as $code)
                    <option value="{{ $code }}" @selected(request('locale') === $code)>{{ strtoupper($code) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1" for="adminBlogsKind">Kind</label>
            <select name="kind" id="adminBlogsKind" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="curated" @selected(request('kind') === 'curated')>Curated</option>
                <option value="custom" @selected(request('kind') === 'custom')>Custom</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <div class="form-check mt-4">
                <input type="checkbox" name="missing_translations" value="1" id="adminBlogsMissing"
                       class="form-check-input" @checked(request()->boolean('missing_translations'))>
                <label class="form-check-label small" for="adminBlogsMissing">Missing translations</label>
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 admin-blogs-table">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Featured</th>
                            <th>Title</th>
                            <th>Locale</th>
                            <th>Author</th>
                            <th>Status</th>
                            <th>Published Date</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($blogs as $blog)
                        <tr>
                            <td>{{ $blog->id }}</td>
                            <td>
                                @if($blog->featured_image)
                                    <img src="{{ $blog->featuredImageUrl() }}" alt="{{ $blog->title }}" class="rounded" style="width: 50px; height: 50px; object-fit: cover;">
                                @else
                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <i class="fa fa-image text-muted"></i>
                                    </div>
                                @endif
                            </td>
                            <td>
                                <strong>{{ Str::limit($blog->title, 50) }}</strong>
                                @if($blog->curated_key)
                                    <span class="badge bg-info-subtle text-info-emphasis ms-1">Curated</span>
                                @endif
                                <div class="small text-muted">{{ parse_url($blog->canonicalUrl(), PHP_URL_PATH) }}</div>
                            </td>
                            <td>
                                @foreach(((class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'supported')) ? \App\Support\PublicI18n::supported() : ['en']) as $code)
                                    @php
                                        $translation = $blog->translations->firstWhere('locale', $code);
                                    @endphp
                                    @if($translation && $translation->is_published)
                                        <span class="badge bg-success-subtle text-success-emphasis text-uppercase">{{ $code }}</span>
                                    @elseif($translation)
                                        <span class="badge bg-warning-subtle text-warning-emphasis text-uppercase">{{ $code }}</span>
                                    @else
                                        <span class="badge bg-light text-muted text-uppercase">{{ $code }}</span>
                                    @endif
                                @endforeach
                            </td>
                            <td>{{ $blog->author ?? $blog->creator?->name ?? 'Admin' }}</td>
                            <td>
                                @if($blog->status === 'published')
                                    <span class="badge bg-success">Published</span>
                                @else
                                    <span class="badge bg-warning text-dark">Draft</span>
                                @endif
                            </td>
                            <td>
                                @if($blog->published_at)
                                    {{ $blog->published_at->format('M d, Y') }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ optional($blog->created_at)->format('M d, Y') ?: '—' }}</td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="{{ route('admin.blogs.show', $blog->id) }}" class="btn btn-sm btn-outline-info"
                                       title="View" aria-label="View {{ $blog->title }}">
                                        <i class="fa fa-eye" aria-hidden="true"></i>
                                    </a>
                                    @if($blog->status === 'published')
                                        <a href="{{ $blog->canonicalUrl() }}" class="btn btn-sm btn-outline-secondary"
                                           target="_blank" rel="noopener noreferrer"
                                           title="View live" aria-label="View live {{ $blog->title }}">
                                            <i class="fa fa-external-link" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('admin.blogs.edit', $blog->id) }}" class="btn btn-sm btn-outline-primary"
                                       title="Edit" aria-label="Edit {{ $blog->title }}">
                                        <i class="fa fa-edit" aria-hidden="true"></i>
                                    </a>
                                    @php $toggleLabel = $blog->status === 'published' ? 'Unpublish' : 'Publish'; @endphp
                                    <button type="submit" form="toggleBlog{{ $blog->id }}"
                                            class="btn btn-sm btn-outline-warning"
                                            title="{{ $toggleLabel }}" aria-label="{{ $toggleLabel }} {{ $blog->title }}">
                                        <i class="fa {{ $blog->status === 'published' ? 'fa-eye-slash' : 'fa-check-circle' }}" aria-hidden="true"></i>
                                    </button>
                                    {{-- Same confirm helper as every other destructive admin action.
                                         The form lives outside the group so the button stays a direct
                                         .btn-group child and keeps its grouped shape. --}}
                                    <button type="submit" form="deleteBlog{{ $blog->id }}"
                                            class="btn btn-sm btn-outline-danger"
                                            data-slb-confirm="Delete “{{ $blog->title }}”? This cannot be undone."
                                            data-slb-confirm-title="Delete blog post?"
                                            data-slb-confirm-text="Delete"
                                            data-slb-confirm-danger="1"
                                            title="Delete" aria-label="Delete {{ $blog->title }}">
                                        <i class="fa fa-trash" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <form id="toggleBlog{{ $blog->id }}" class="d-none"
                                      action="{{ route('admin.blogs.toggle-status', $blog->id) }}" method="POST">
                                    @csrf
                                </form>
                                <form id="deleteBlog{{ $blog->id }}" class="d-none"
                                      action="{{ route('admin.blogs.destroy', $blog->id) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <i class="fa fa-blog fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No blogs found. Create your first blog post!</p>
                                <a href="{{ route('admin.blogs.create') }}" class="btn btn-primary btn-sm">
                                    <i class="fa fa-plus me-2"></i> Create Blog
                                </a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $blogs->links() }}
        </div>
    </div>
</div>

@endsection