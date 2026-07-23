@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h3 class="mb-1">Bulk site requests</h3>
            <p class="text-muted small mb-0">
                Publishers submit <strong>URL + price</strong> only. Add metrics when seeding, then wait for them to finish details before approving.
            </p>
        </div>
        <span class="badge text-bg-secondary align-self-center">{{ $openCount }} open</span>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="GET" class="mb-3">
        <select name="status" class="form-select form-select-sm w-auto d-inline-block" onchange="this.form.submit()">
            <option value="all" @selected($status === 'all')>All statuses</option>
            @foreach(['requested','sheet_sent','seeded','awaiting_publisher','completed','cancelled'] as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ str_replace('_', ' ', $s) }}</option>
            @endforeach
        </select>
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
                            <td><span class="badge text-bg-light border text-capitalize">{{ str_replace('_', ' ', $req->status) }}</span></td>
                            <td>{{ $req->sites_count }}</td>
                            <td>{{ $req->awaiting_details_count }}</td>
                            <td>{{ $req->ready_count }}</td>
                            <td class="small">{{ $req->handler->name ?? '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.bulk-site-requests.show', $req) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No bulk requests yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $requests->links() }}</div>
</div>
@endsection
