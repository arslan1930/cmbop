@extends(staff_layout())

@section('title', 'Partner imports')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Partner imports</h4>
            <p class="text-muted small mb-0">CSV / XLSX / JSON / XML. New rows land as unused. Casino is blocked. Teqno LTD — no fees for partners.</p>
        </div>
        <a href="{{ route('admin.partners.imports.template') }}" class="btn btn-sm btn-outline-secondary">Download CSV template</a>
    </div>
    <form method="POST" action="{{ route('admin.partners.imports.store') }}" enctype="multipart/form-data" class="card border-0 shadow-sm mb-4">
        @csrf
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Partner</label>
                <select name="partner_id" class="form-select" required>
                    <option value="">Choose…</option>
                    @foreach($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->displayName() }} ({{ $partner->user?->email }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">File</label>
                <input type="file" name="file" class="form-control" accept=".csv,.txt,.xlsx,.json,.xml" required>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="skip_reachability" value="1" id="skip_reach">
                    <label class="form-check-label small" for="skip_reach">Skip HTTPS check</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary" type="submit">Import</button>
            </div>
        </div>
    </form>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>ID</th><th>Partner</th><th>Source</th><th>Status</th><th>Counts</th><th></th></tr></thead>
                <tbody>
                    @forelse($jobs as $job)
                        <tr>
                            <td>{{ $job->id }}</td>
                            <td>{{ $job->partner?->displayName() }}</td>
                            <td>{{ $job->source }}</td>
                            <td>{{ $job->status }}</td>
                            <td class="small">{{ json_encode($job->counts) }}</td>
                            <td><a href="{{ route('admin.partners.imports.show', $job) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No imports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $jobs->links() }}</div>
</div>
@endsection
