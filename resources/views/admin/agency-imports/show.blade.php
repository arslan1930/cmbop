@extends('admin.layouts.app')

@section('title', 'Agency CSV import #'.$import->id)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <a href="{{ route('admin.agency-imports.index') }}" class="small text-muted">&larr; All imports</a>
            <h4 class="mb-1 mt-1">Import #{{ $import->id }}</h4>
            <p class="text-muted small mb-0">
                {{ $import->publisher->name ?? 'Publisher' }}
                ({{ $import->publisher->email ?? '—' }})
                · status <span class="badge bg-secondary">{{ $import->status }}</span>
                · {{ optional($import->created_at)->toDayDateTimeString() }}
            </p>
        </div>
        <div class="text-end small">
            <div><strong>{{ $import->created_count }}</strong> created</div>
            <div><strong>{{ $import->failed_count }}</strong> failed</div>
            @if($import->original_filename)
                <div class="text-muted">{{ $import->original_filename }}</div>
            @endif
        </div>
    </div>

    <div class="alert alert-light border small">
        Publisher-supplied DA/DR/traffic on these rows — use the <strong>CSV metrics — spot-check</strong> chip on each site before activating.
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#importSites">Sites ({{ $import->sites->count() }})</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#importFailures">Failed rows ({{ $import->failures->count() }})</a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="importSites">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Site</th>
                                <th>DA</th>
                                <th>DR</th>
                                <th>Traffic</th>
                                <th>Verified</th>
                                <th>Active</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($import->sites as $site)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            {{ $site->site_name }}
                                            @if($site->metrics_manual)
                                                <span class="badge text-bg-light border ms-1">CSV metrics — spot-check</span>
                                            @endif
                                        </div>
                                        <div class="small text-muted">{{ $site->domain }}</div>
                                    </td>
                                    <td>{{ $site->da }}</td>
                                    <td>{{ $site->dr }}</td>
                                    <td>{{ number_format((int) $site->traffic) }}</td>
                                    <td>{{ $site->verified ? 'Yes' : 'No' }}</td>
                                    <td>{{ $site->active ? 'Yes' : 'No' }}</td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.sites.index', ['needs_review' => 1, 'publisher' => $import->publisher_id, 'site' => $site->id]) }}">Open in Sites</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">No sites were created in this import.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="importFailures">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Row</th>
                                <th>Site</th>
                                <th>Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($import->failures as $failure)
                                <tr>
                                    <td>{{ $failure->row_number }}</td>
                                    <td>
                                        <div>{{ $failure->site_name ?: '—' }}</div>
                                        <div class="small text-muted">{{ $failure->site_url ?: '—' }}</div>
                                    </td>
                                    <td class="small">
                                        <ul class="mb-0 ps-3">
                                            @foreach(($failure->errors ?? []) as $error)
                                                <li>{{ $error }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-4">No failed rows.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
