@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Ad Banners</h1>
            <p class="text-muted mb-0">Upload sized creatives that fit header, sidebar, marketplace, and mobile slots.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.promotions.index') }}" class="btn btn-sm btn-outline-secondary">Promotions Hub</a>
            <a href="{{ route('admin.promotions.banners.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> New Banner
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
                            <th>Preview</th>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Placement</th>
                            <th>Stats</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($banners as $banner)
                            <tr>
                                <td>
                                    @if($banner->imageSrc())
                                        <img src="{{ $banner->imageSrc() }}" alt="{{ $banner->alt_text ?: $banner->name }}"
                                             class="rounded border" style="width:72px;height:48px;object-fit:cover;">
                                    @else
                                        <div class="bg-light rounded border d-flex align-items-center justify-content-center" style="width:72px;height:48px;">
                                            <i class="fa fa-image text-muted"></i>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $banner->name }}</div>
                                    <div class="small text-muted">{{ config('promotions.audiences.'.$banner->audience, $banner->audience) }}</div>
                                </td>
                                <td class="small">{{ $banner->sizeLabel() }}</td>
                                <td class="small">{{ $banner->placementLabel() }}</td>
                                <td class="small text-muted">
                                    {{ number_format($banner->impressions) }} views<br>
                                    {{ number_format($banner->clicks) }} clicks
                                </td>
                                <td>
                                    @if($banner->isCurrentlyLive())
                                        <span class="badge bg-success">Live</span>
                                    @elseif($banner->is_active)
                                        <span class="badge bg-warning text-dark">Scheduled</span>
                                    @else
                                        <span class="badge bg-secondary">Paused</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('admin.promotions.banners.edit', $banner) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form action="{{ route('admin.promotions.banners.toggle', $banner) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            {{ $banner->is_active ? 'Pause' : 'Activate' }}
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.promotions.banners.destroy', $banner) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this banner?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">No banners yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($banners->hasPages())
            <div class="card-footer bg-white">{{ $banners->links() }}</div>
        @endif
    </div>
</div>
@endsection
