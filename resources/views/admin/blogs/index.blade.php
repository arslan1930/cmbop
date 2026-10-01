@extends('admin.layouts.app')

@php
    use App\Support\AdminBlog;
    $filters = $filters ?? AdminBlog::listFilters(request());
    $filtered = $filters['q'] !== '' || $filters['status'] !== '' || $filters['locale'] !== ''
        || $filters['kind'] !== '' || $filters['incomplete'] || $filters['sort'] !== 'newest';
    $indexQuery = AdminBlog::indexQuery(request());
@endphp

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
                        data-slb-confirm="This updates unedited pillar posts from code. Posts you edited are kept. Deleted pillar posts stay deleted."
                        data-slb-confirm-title="Sync curated SEO blogs?"
                        data-slb-confirm-text="Sync">
                    <i class="fa fa-sync me-2"></i> Sync curated SEO blogs
                </button>
            </form>
            <a href="{{ route('admin.blogs.create', $indexQuery) }}" class="btn btn-primary">
                <i class="fa fa-plus me-2"></i> Create New Blog
            </a>
        </div>
    </div>

    <div class="alert alert-light border mb-4">
        <strong>Missing curated posts?</strong>
        Code deploy alone does not insert blog rows. Click <em>Sync curated SEO blogs</em> (or run <code>php artisan blog:upsert-curated</code>) to load pillar posts so you can edit, unpublish, or delete them here.
    </div>

    <div class="card border-0 shadow-sm mb-4 admin-deposits-filter-card">
    <div class="card-body">
    <form method="GET" action="{{ route('admin.blogs.index') }}" class="admin-deposits-filters admin-orders-filters">
        <div class="admin-orders-filters__grid">
        <div class="admin-orders-filters__search">
            <x-slb-search-field name="q" id="adminBlogsSearch" :value="$filters['q']" placeholder="Title, slug, author…" input-class="form-control" label-class="form-label" />
        </div>
        <div>
            <label class="form-label" for="adminBlogsStatus">Status</label>
            <select name="status" id="adminBlogsStatus" class="form-select">
                <option value="">All</option>
                <option value="published" @selected($filters['status'] === 'published')>Published</option>
                <option value="draft" @selected($filters['status'] === 'draft')>Draft</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="adminBlogsLocale">Primary locale</label>
            <select name="locale" id="adminBlogsLocale" class="form-select">
                <option value="">All</option>
                @foreach(AdminBlog::publicLocales() as $code)
                    <option value="{{ $code }}" @selected($filters['locale'] === $code)>{{ AdminBlog::shortLabel($code) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="adminBlogsKind">Kind</label>
            <select name="kind" id="adminBlogsKind" class="form-select">
                <option value="">All</option>
                <option value="curated" @selected($filters['kind'] === 'curated')>Curated</option>
                <option value="custom" @selected($filters['kind'] === 'custom')>Custom</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="adminBlogsSort">Sort</label>
            <select name="sort" id="adminBlogsSort" class="form-select">
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest created</option>
                <option value="published" @selected($filters['sort'] === 'published')>Newest published</option>
                <option value="title" @selected($filters['sort'] === 'title')>Title</option>
            </select>
        </div>
        <div>
            <div class="form-check mt-4">
                <input type="checkbox" name="missing_translations" value="1" id="adminBlogsMissing"
                       class="form-check-input" @checked($filters['incomplete'])>
                <label class="form-check-label" for="adminBlogsMissing">Incomplete locales</label>
            </div>
        </div>
        <div class="admin-deposits-filters__actions admin-orders-filters__actions">
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('admin.blogs.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
        </div>
    </form>
    </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0 admin-blogs-table">
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
                                @foreach(AdminBlog::publicLocales() as $code)
                                    @php
                                        $translation = $blog->relationLoaded('translations')
                                            ? $blog->translations->firstWhere('locale', $code)
                                            : null;
                                    @endphp
                                    @if($translation && $translation->is_published)
                                        <span class="badge bg-success-subtle text-success-emphasis text-uppercase">{{ $code }}</span>
                                    @elseif($translation)
                                        <span class="badge bg-warning-subtle text-warning-emphasis text-uppercase">{{ $code }}</span>
                                    @endif
                                @endforeach
                            </td>
                            <td>
                                {{ $blog->author ?? $blog->creator?->name ?? 'Admin' }}
                                @if($blog->created_by)
                                    <div><a class="small" href="{{ route('admin.users.show', $blog->created_by) }}">Open user</a></div>
                                @endif
                            </td>
                            <td>
                                @if($blog->status === 'published')
                                    <span class="badge bg-success">Published</span>
                                @else
                                    <span class="badge bg-warning text-dark">Draft</span>
                                @endif
                            </td>
                            <td>
                                @if($blog->status === 'published' && $blog->published_at instanceof \DateTimeInterface)
                                    {{ $blog->published_at->format('M d, Y') }}
                                @elseif($blog->published_at instanceof \DateTimeInterface)
                                    <span class="text-muted">Was {{ $blog->published_at->format('M d, Y') }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ optional($blog->created_at)->format('M d, Y') ?: '—' }}</td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="{{ route('admin.blogs.show', $blog->id) }}" class="btn btn-sm btn-outline-info"
                                       aria-label="View {{ $blog->title }}">
                                        <i class="fa fa-eye" aria-hidden="true"></i>
                                    </a>
                                    @if($blog->status === 'published')
                                        <a href="{{ $blog->canonicalUrl() }}" class="btn btn-sm btn-outline-secondary"
                                           target="_blank" rel="noopener noreferrer"
                                           aria-label="View live {{ $blog->title }}">
                                            <i class="fa fa-external-link" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('admin.blogs.preview', $blog->id) }}" class="btn btn-sm btn-outline-secondary"
                                       aria-label="Preview {{ $blog->title }}">
                                        <i class="fa fa-file-alt" aria-hidden="true"></i>
                                    </a>
                                    <a href="{{ route('admin.blogs.edit', $blog->id) }}" class="btn btn-sm btn-outline-primary"
                                       aria-label="Edit {{ $blog->title }}">
                                        <i class="fa fa-edit" aria-hidden="true"></i>
                                    </a>
                                    @php $toggleLabel = $blog->status === 'published' ? 'Unpublish' : 'Publish'; @endphp
                                    <button type="submit" form="toggleBlog{{ $blog->id }}"
                                            class="btn btn-sm btn-outline-warning"
                                            aria-label="{{ $toggleLabel }} {{ $blog->title }}">
                                        <i class="fa {{ $blog->status === 'published' ? 'fa-eye-slash' : 'fa-check-circle' }}" aria-hidden="true"></i>
                                    </button>
                                    <button type="submit" form="deleteBlog{{ $blog->id }}"
                                            class="btn btn-sm btn-outline-danger"
                                            data-slb-confirm="Delete “{{ $blog->title }}”? This cannot be undone."
                                            data-slb-confirm-title="Delete blog post?"
                                            data-slb-confirm-text="Delete"
                                            data-slb-confirm-danger="1"
                                            aria-label="Delete {{ $blog->title }}">
                                        <i class="fa fa-trash" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <form id="toggleBlog{{ $blog->id }}" class="d-none"
                                      action="{{ route('admin.blogs.toggle-status', $blog->id) }}" method="POST">
                                    @csrf
                                    @foreach($indexQuery as $key => $value)
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endforeach
                                </form>
                                <form id="deleteBlog{{ $blog->id }}" class="d-none"
                                      action="{{ route('admin.blogs.destroy', $blog->id) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    @foreach($indexQuery as $key => $value)
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endforeach
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <i class="fa fa-blog fa-3x text-muted mb-3"></i>
                                @if($filtered)
                                    <p class="text-muted">No matches for this filter.</p>
                                    <a href="{{ route('admin.blogs.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                                @else
                                    <p class="text-muted">No blogs found. Create your first blog post!</p>
                                    <a href="{{ route('admin.blogs.create', $indexQuery) }}" class="btn btn-primary btn-sm">
                                        <i class="fa fa-plus me-2"></i> Create Blog
                                    </a>
                                @endif
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
