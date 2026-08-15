@extends(staff_layout())

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Announcements</h1>
            <p class="text-muted mb-0">Site notices only — they do not change catalog prices.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ staff_route('promotions.index') }}" class="btn btn-sm btn-outline-secondary">Promotions Hub</a>
            <a href="{{ staff_route('promotions.announcements.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> New Announcement
            </a>
        </div>
    </div>

    @include('admin.promotions.partials.undo-bar')
    @include('admin.promotions.partials.filter-chips', ['statusCounts' => $statusCounts ?? []])

    <form method="GET" class="row g-2 mb-3">
        <input type="hidden" name="status" value="{{ search_text(request('status')) }}">
        <div class="col-md-4">
            <input type="search" name="q" class="form-control form-control-sm" value="{{ search_text(request('q')) }}" placeholder="Search title or message">
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
            <select name="type" class="form-select form-select-sm">
                <option value="">All types</option>
                @foreach(config('promotions.announcement_types') as $key => $meta)
                    <option value="{{ $key }}" @selected(search_text(request('type')) === $key)>{{ $meta['label'] }}</option>
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
                            <th>Title</th>
                            <th>Type</th>
                            <th>Audience</th>
                            <th>Schedule</th>
                            <th>Stats</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($announcements as $item)
                            @php
                                $campaignAudience = $item->audience === 'publisher' ? 'publishers' : 'advertisers';
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ scalar_text($item->title) }}</div>
                                    <div class="small text-muted">{{ \Illuminate\Support\Str::limit(strip_tags(scalar_text($item->message)), 70) }}</div>
                                </td>
                                <td><span class="badge bg-light text-dark"><i class="fa {{ $item->typeIcon() }} me-1"></i>{{ $item->typeLabel() }}</span></td>
                                <td class="small">{{ scalar_text(config('promotions.audiences.'.scalar_text($item->audience), $item->audience)) }}</td>
                                <td class="small text-muted">@include('admin.promotions.partials.schedule', ['item' => $item])</td>
                                <td class="small text-muted">{{ number_format((int) $item->clicks) }} clicks</td>
                                <td>@include('admin.promotions.partials.status-badge', ['item' => $item])</td>
                                <td class="text-end text-nowrap">
                                    @if($item->trashed())
                                        <form action="{{ staff_route('promotions.announcements.restore', $item->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Restore</button>
                                        </form>
                                    @else
                                        <a href="{{ staff_route('promotions.announcements.edit', $item) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form action="{{ staff_route('promotions.announcements.duplicate', $item) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Duplicate</button>
                                        </form>
                                        @if(auth()->user()?->isAdmin())
                                            <a href="{{ route('admin.campaigns.index', [
                                                'audience' => $campaignAudience,
                                                'subject' => $item->title,
                                                'cta_label' => $item->cta_label,
                                                'cta_url' => $item->cta_url,
                                            ]) }}" class="btn btn-sm btn-outline-secondary" title="Campaigns email marketplace users, not public visitors">Email</a>
                                        @endif
                                        <form action="{{ staff_route('promotions.announcements.toggle', $item) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">
                                                {{ $item->is_active ? 'Pause' : 'Activate' }}
                                            </button>
                                        </form>
                                        <form action="{{ staff_route('promotions.announcements.destroy', $item) }}" method="POST" class="d-inline"
                                              data-slb-confirm="Delete this announcement? You can undo for a few minutes."
                                              data-slb-confirm-title="Delete announcement?"
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
                                <td colspan="7" class="text-center text-muted py-5">No announcements yet. Create a site notice (maintenance, feature, or offer copy).</td>
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
