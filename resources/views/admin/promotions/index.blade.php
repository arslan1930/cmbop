@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Promotions Center</h1>
            <p class="text-muted mb-0">Limited-time offers, new feature announcements, maintenance notices, and ad banners.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.promotions.announcements.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-bullhorn me-1"></i> New Announcement
            </a>
            <a href="{{ route('admin.promotions.banners.create') }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-image me-1"></i> New Banner
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-3 mb-4">
        @foreach($featuredNotices as $key => $notice)
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="fs-4 mb-1" aria-hidden="true">{{ $notice['emoji'] }}</div>
                                <h5 class="mb-1">{{ $notice['label'] }}</h5>
                            </div>
                            <span class="badge bg-light text-dark">
                                {{ $noticeCounts[$key]['live'] ?? 0 }} live
                            </span>
                        </div>
                        <p class="text-muted small flex-grow-1 mb-3">{{ $notice['description'] }}</p>
                        <div class="small text-muted mb-3">
                            Example: “{{ $notice['default_title'] }}”
                        </div>
                        <a href="{{ route('admin.promotions.announcements.create', ['preset' => $key]) }}"
                           class="btn btn-sm {{ $key === 'maintenance' ? 'btn-outline-warning' : ($key === 'new_feature' ? 'btn-outline-success' : 'btn-primary') }}">
                            <i class="fa {{ $notice['icon'] }} me-1"></i> Create {{ $notice['label'] }}
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Live Announcements</div>
                    <h3 class="mb-0">{{ $stats['announcements_live'] }}</h3>
                    <div class="small text-muted mt-1">{{ $stats['announcements_total'] }} total · {{ $stats['upcoming_announcements'] }} scheduled</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Live Banners</div>
                    <h3 class="mb-0">{{ $stats['banners_live'] }}</h3>
                    <div class="small text-muted mt-1">{{ $stats['banners_total'] }} total slots</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Banner Impressions</div>
                    <h3 class="mb-0">{{ number_format($stats['banner_impressions']) }}</h3>
                    <div class="small text-muted mt-1">All-time deliveries</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Banner Clicks</div>
                    <h3 class="mb-0">{{ number_format($stats['banner_clicks']) }}</h3>
                    <div class="small text-muted mt-1">Tracked outbound clicks</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-bullhorn me-2 text-primary"></i>Recent Announcements</strong>
                    <a href="{{ route('admin.promotions.announcements.index') }}" class="btn btn-sm btn-outline-secondary">Manage all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Audience</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($announcements as $item)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.promotions.announcements.edit', $item) }}" class="text-decoration-none">
                                                {{ \Illuminate\Support\Str::limit($item->title, 40) }}
                                            </a>
                                        </td>
                                        <td><span class="badge bg-light text-dark">{{ $item->typeLabel() }}</span></td>
                                        <td class="small text-muted">{{ config('promotions.audiences.'.$item->audience, $item->audience) }}</td>
                                        <td>
                                            @if($item->isCurrentlyLive())
                                                <span class="badge bg-success">Live</span>
                                            @elseif($item->is_active)
                                                <span class="badge bg-warning text-dark">Scheduled</span>
                                            @else
                                                <span class="badge bg-secondary">Paused</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No announcements yet. Create a discount or Black Friday notice.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-image me-2 text-primary"></i>Recent Banners</strong>
                    <a href="{{ route('admin.promotions.banners.index') }}" class="btn btn-sm btn-outline-secondary">Manage all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Size</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($banners as $banner)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.promotions.banners.edit', $banner) }}" class="text-decoration-none">
                                                {{ \Illuminate\Support\Str::limit($banner->name, 28) }}
                                            </a>
                                            <div class="small text-muted">{{ $banner->placementLabel() }}</div>
                                        </td>
                                        <td class="small">{{ $banner->width }}×{{ $banner->height }}</td>
                                        <td>
                                            @if($banner->isCurrentlyLive())
                                                <span class="badge bg-success">Live</span>
                                            @elseif($banner->is_active)
                                                <span class="badge bg-warning text-dark">Scheduled</span>
                                            @else
                                                <span class="badge bg-secondary">Paused</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">No banners yet. Upload a size that fits your layout.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0">
            <strong><i class="fa fa-ruler-combined me-2 text-primary"></i>Available Banner Sizes</strong>
            <div class="small text-muted">Use these presets so ads fit header, sidebar, marketplace, and mobile slots.</div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach($sizes as $key => $size)
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold">{{ $size['label'] }}</div>
                            <div class="text-muted small">
                                @if($key === 'custom')
                                    Custom width × height
                                @else
                                    {{ $size['width'] }}×{{ $size['height'] }} px
                                @endif
                            </div>
                            <div class="small mt-1">{{ $size['hint'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
