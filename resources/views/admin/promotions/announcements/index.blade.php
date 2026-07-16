@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Announcements</h1>
            <p class="text-muted mb-0">Limited-time offers, new features, maintenance notices, and more.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.promotions.index') }}" class="btn btn-sm btn-outline-secondary">Promotions Hub</a>
            <a href="{{ route('admin.promotions.announcements.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> New Announcement
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
                            <th>Title</th>
                            <th>Type</th>
                            <th>Audience</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($announcements as $item)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $item->title }}</div>
                                    <div class="small text-muted">{{ \Illuminate\Support\Str::limit(strip_tags($item->message), 70) }}</div>
                                </td>
                                <td><span class="badge bg-light text-dark"><i class="fa {{ $item->typeIcon() }} me-1"></i>{{ $item->typeLabel() }}</span></td>
                                <td class="small">{{ config('promotions.audiences.'.$item->audience, $item->audience) }}</td>
                                <td class="small text-muted">
                                    @if($item->starts_at || $item->ends_at)
                                        {{ optional($item->starts_at)->format('M j') ?? 'Now' }}
                                        →
                                        {{ optional($item->ends_at)->format('M j, Y') ?? '∞' }}
                                    @else
                                        Always
                                    @endif
                                </td>
                                <td>
                                    @if($item->isCurrentlyLive())
                                        <span class="badge bg-success">Live</span>
                                    @elseif($item->is_active)
                                        <span class="badge bg-warning text-dark">Scheduled</span>
                                    @else
                                        <span class="badge bg-secondary">Paused</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('admin.promotions.announcements.edit', $item) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form action="{{ route('admin.promotions.announcements.toggle', $item) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            {{ $item->is_active ? 'Pause' : 'Activate' }}
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.promotions.announcements.destroy', $item) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this announcement?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">No announcements yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($announcements->hasPages())
            <div class="card-footer bg-white">{{ $announcements->links() }}</div>
        @endif
    </div>
</div>
@endsection
