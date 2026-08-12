@extends(staff_layout())

@section('title', 'Agency CSV imports')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">Agency CSV imports</h4>
            <p class="text-muted small mb-0">Publisher bulk CSV submissions awaiting review. Open queue: {{ $openCount }}</p>
        </div>
    </div>

    <form method="get" action="{{ staff_route('agency-imports.index') }}" class="row g-2 mb-3">
        <div class="col-auto">
            <select name="status" class="form-select form-select-sm">
                <option value="open" @selected($status === 'open')>Open</option>
                <option value="all" @selected($status === 'all')>All</option>
                <option value="processing" @selected($status === 'processing')>Processing</option>
                <option value="submitted" @selected($status === 'submitted')>Submitted</option>
                <option value="partial" @selected($status === 'partial')>Partial</option>
                <option value="reviewed" @selected($status === 'reviewed')>Reviewed</option>
                <option value="failed" @selected($status === 'failed')>Failed</option>
                <option value="closed" @selected($status === 'closed')>Closed</option>
            </select>
        </div>
        <div class="col-md-4">
            <input type="search" name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="Search publisher, file, or import #">
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Publisher</th>
                        <th>File</th>
                        <th>Created</th>
                        <th>Failed</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($imports as $import)
                        <tr>
                            <td>{{ $import->id }}</td>
                            <td>
                                <div class="fw-semibold">{{ $import->publisher->name ?? '—' }}</div>
                                <div class="small text-muted">{{ $import->publisher->email ?? '' }}</div>
                            </td>
                            <td class="small">{{ $import->original_filename ?: '—' }}</td>
                            <td>{{ $import->created_count }}</td>
                            <td>{{ $import->failed_count }}</td>
                            <td><span class="badge bg-secondary">{{ $import->status }}</span></td>
                            <td class="small text-muted">{{ optional($import->created_at)->diffForHumans() }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="{{ staff_route('agency-imports.show', $import) }}">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No agency CSV imports found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $imports->links() }}</div>
    </div>
</div>
@endsection
