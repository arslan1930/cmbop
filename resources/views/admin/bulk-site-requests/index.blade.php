@extends(staff_layout())

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h3 class="mb-1">Bulk site requests</h3>
            <p class="text-muted small mb-0">
                Publishers submit <strong>URL + price</strong>. Press <strong>Done</strong> to add drafts to their Pending sites and notify them. Verify/activate stays separate.
            </p>
        </div>
        <span class="badge text-bg-secondary align-self-center" data-bulk-waiting-on-you>{{ $waitingOnYouCount }} waiting on you</span>
    </div>

    <form method="GET" class="mb-3 d-flex flex-wrap align-items-center gap-2" data-bulk-index-filters>
        <select name="status" class="form-select form-select-sm w-auto d-inline-block" onchange="this.form.submit()">
            <option value="all" @selected($status === 'all')>All statuses</option>
            <option value="{{ \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER }}" @selected($status === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)>Waiting on you</option>
            @foreach(['requested','sheet_sent','seeded','awaiting_publisher','completed','cancelled'] as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ \App\Models\BulkSiteRequest::statusLabelFor($s) }}</option>
            @endforeach
        </select>
        @if(!empty($filtersActive))
            <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-sm btn-outline-secondary">Reset filter</a>
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
                                <div class="fw-semibold">{{ $req->publisher->name ?? '—' }}</div>
                                <div class="small text-muted">{{ $req->publisher->email ?? '' }}</div>
                            </td>
                            <td>{{ $req->estimated_count ?? '—' }}</td>
                            <td><span class="badge text-bg-light border">{{ $req->statusLabel() }}</span></td>
                            <td>{{ $req->sites_count }}</td>
                            <td>{{ $req->pending_items_count }}</td>
                            <td>{{ $req->awaiting_details_count }}</td>
                            <td>{{ $req->ready_count }}</td>
                            <td class="small">{{ $req->handler->name ?? '—' }}</td>
                            <td class="text-end">
                                <a href="{{ staff_route('bulk-site-requests.show', $req) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                @if(!empty($filtersActive))
                                    <div class="mb-2">No requests match this filter.</div>
                                    <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-sm btn-outline-secondary">Reset filter</a>
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
