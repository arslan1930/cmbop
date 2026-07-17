@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Blogs</h1>
            <p class="text-muted">Create, publish, and manage SEO blog posts and daily updates for the public blog page.</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.blogs.create') }}" class="btn btn-primary">
                <i class="fa fa-plus me-2"></i> Create New Blog
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Featured</th>
                            <th>Title</th>
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
                                    <img src="{{ Storage::url($blog->featured_image) }}" alt="{{ $blog->title }}" class="rounded" style="width: 50px; height: 50px; object-fit: cover;">
                                @else
                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <i class="fa fa-image text-muted"></i>
                                    </div>
                                @endif
                            </td>
                            <td>
                                <strong>{{ Str::limit($blog->title, 50) }}</strong>
                            </td>
                            <td>{{ $blog->author ?? $blog->creator->name ?? 'Admin' }}</td>
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
                            <td>{{ $blog->created_at->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="{{ route('admin.blogs.show', $blog->id) }}" class="btn btn-sm btn-outline-info" title="View">
                                        <i class="fa fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.blogs.edit', $blog->id) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                    <a href="{{ route('admin.blogs.toggle-status', $blog->id) }}" class="btn btn-sm btn-outline-warning" title="{{ $blog->status === 'published' ? 'Unpublish' : 'Publish' }}">
                                        <i class="fa {{ $blog->status === 'published' ? 'fa-eye-slash' : 'fa-check-circle' }}"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal{{ $blog->id }}" title="Delete">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteModal{{ $blog->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title">Confirm Delete</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                Are you sure you want to delete <strong>{{ $blog->title }}</strong>?
                                                <br><small class="text-muted">This action cannot be undone.</small>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <form action="{{ route('admin.blogs.destroy', $blog->id) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger">Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-5">
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

<style>
.table td, .table th {
    padding: 12px 15px;
    vertical-align: middle;
}
</style>
@endsection