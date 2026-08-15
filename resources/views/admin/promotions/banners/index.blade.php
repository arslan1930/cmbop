@extends(staff_layout())

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Ad Banners</h1>
            <p class="text-muted mb-0">Upload sized creatives that fit header, sidebar, marketplace, and mobile slots.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ staff_route('promotions.index') }}" class="btn btn-sm btn-outline-secondary">Promotions Hub</a>
            <a href="{{ staff_route('promotions.banners.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> New Banner
            </a>
        </div>
    </div>

    @include('admin.promotions.partials.undo-bar')
    @include('admin.promotions.partials.filter-chips', ['statusCounts' => $statusCounts ?? []])

    <form method="GET" class="row g-2 mb-3">
        <input type="hidden" name="status" value="{{ search_text(request('status')) }}">
        <div class="col-md-4">
            <input type="search" name="q" class="form-control form-control-sm" value="{{ search_text(request('q')) }}" placeholder="Search name">
        </div>
        <div class="col-md-3">
            <select name="audience" class="form-select form-select-sm">
                <option value="">All audiences</option>
                @foreach(config('promotions.audiences') as $key => $label)
                    <option value="{{ $key }}" @selected(search_text(request('audience')) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="placement" class="form-select form-select-sm">
                <option value="">All placements</option>
                @foreach(config('promotions.banner_placements') as $key => $label)
                    <option value="{{ $key }}" @selected(search_text(request('placement')) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-outline-primary w-100" type="submit">Filter</button>
        </div>
    </form>

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
                            @php
                                $imps7 = 0;
                                $clicks7 = 0;
                                try {
                                    $since = now()->subDays(7)->startOfDay();
                                    $tracker = app(\App\Services\PromotionTrackingService::class);
                                    $imps7 = $tracker->countForSubjectSince($banner, 'impression', $since);
                                    $clicks7 = $tracker->countForSubjectSince($banner, 'click', $since);
                                } catch (\Throwable) {}
                                $ctr7 = $imps7 > 0 ? round(100 * $clicks7 / $imps7, 1) : 0;
                            @endphp
                            <tr>
                                <td>
                                    @if($banner->imageSrc())
                                        <img src="{{ $banner->imageSrc() }}" alt="{{ scalar_text($banner->alt_text ?: $banner->name) }}"
                                             class="rounded border" style="width:72px;height:48px;object-fit:cover;">
                                    @else
                                        <div class="bg-light rounded border d-flex align-items-center justify-content-center" style="width:72px;height:48px;">
                                            <i class="fa fa-image text-muted"></i>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ scalar_text($banner->name) }}</div>
                                    <div class="small text-muted">{{ scalar_text(config('promotions.audiences.'.scalar_text($banner->audience), $banner->audience)) }}</div>
                                </td>
                                <td class="small">{{ $banner->sizeLabel() }}</td>
                                <td class="small">{{ $banner->placementLabel() }}</td>
                                <td class="small text-muted">
                                    {{ number_format($banner->impressions) }} views · {{ number_format($banner->clicks) }} clicks
                                    <div>7d CTR {{ number_format($ctr7, 1) }}%</div>
                                </td>
                                <td>@include('admin.promotions.partials.status-badge', ['item' => $banner])</td>
                                <td class="text-end text-nowrap">
                                    @if($banner->trashed())
                                        <form action="{{ staff_route('promotions.banners.restore', $banner->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Restore</button>
                                        </form>
                                    @else
                                        <a href="{{ staff_route('promotions.banners.edit', $banner) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form action="{{ staff_route('promotions.banners.duplicate', $banner) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Duplicate</button>
                                        </form>
                                        <form action="{{ staff_route('promotions.banners.toggle', $banner) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">
                                                {{ $banner->is_active ? 'Pause' : 'Activate' }}
                                            </button>
                                        </form>
                                        <form action="{{ staff_route('promotions.banners.destroy', $banner) }}" method="POST" class="d-inline"
                                              data-slb-confirm="Delete this banner? You can undo for a few minutes."
                                              data-slb-confirm-title="Delete banner?"
                                              data-slb-confirm-text="Delete"
                                              data-slb-confirm-danger="1">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    @endif
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
