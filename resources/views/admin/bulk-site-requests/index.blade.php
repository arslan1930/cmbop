@extends(staff_layout())

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h3 class="mb-1">Bulk site requests</h3>
            <p class="text-muted small mb-0">
                Publishers submit <strong>URL + price</strong>. On Done, <strong>Publish now</strong> puts the filled sites live (active, not verified). <strong>Send for review</strong> lets the publisher Accept (goes live) or Edit (then you Activate). Finished requests leave this list.
            </p>
        </div>
        <span class="badge text-bg-secondary align-self-center" data-bulk-waiting-on-you>{{ $waitingOnYouCount }} waiting on you</span>
    </div>

    <form method="GET" class="admin-deposits-filters staff-site-filters mb-3 d-flex flex-wrap align-items-end gap-2" data-bulk-index-filters>
        <div>
            <label class="form-label" for="bulkRequestSearch">Search</label>
            <input type="search"
               id="bulkRequestSearch"
               name="q"
               value="{{ $q ?? '' }}"
               class="form-control"
               style="min-width: 18rem;"
               placeholder="Request #, publisher, email, or domain"
               aria-label="Search bulk requests">
        </div>
        <div>
            <label class="form-label" for="bulkRequestStatus">Status</label>
        <select name="status" id="bulkRequestStatus" class="form-select">
            <option value="all" @selected($status === 'all')>All statuses</option>
            <option value="{{ \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER }}" @selected($status === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)>Waiting on you</option>
            @foreach(['requested','sheet_sent','seeded','awaiting_publisher'] as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ \App\Models\BulkSiteRequest::statusLabelFor($s) }}</option>
            @endforeach
        </select>
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        @if(!empty($filtersActive))
            <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-outline-secondary">Reset</a>
        @endif
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Publisher</th>
                        <th>Est.</th>
                        <th>Status</th>
                        <th>Sites</th>
                        <th>Pending to add</th>
                        <th>Awaiting details</th>
                        <th>Ready</th>
                        <th>Handler</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $req)
                        <tr>
                            <td>{{ $req->id }}</td>
                            <td>
                                <div class="fw-semibold">{{ $req->publisher?->name ?? '—' }}</div>
                                <div class="small text-muted">{{ $req->publisher?->email ?? '' }}</div>
                            </td>
                            <td>{{ $req->estimated_count ?? '—' }}</td>
                            <td><span class="badge text-bg-light border">{{ $req->statusLabel() }}</span></td>
                            <td>{{ $req->sites_count }}</td>
                            <td>{{ $req->pending_items_count }}</td>
                            <td>{{ (int) ($req->awaiting_details_count ?? 0) }}</td>
                            <td>{{ (int) ($req->ready_count ?? 0) }}</td>
                            <td class="small">{{ $req->handler?->name ?? '—' }}</td>
                            <td class="text-end">
                                <a href="{{ staff_route('bulk-site-requests.show', $req) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                @if(!empty($filtersActive))
                                    <div class="mb-2">No requests match this filter.</div>
                                    <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-outline-secondary">Reset filter</a>
                                @else
                                    No bulk requests yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $requests->links() }}</div>
</div>
@endsection
