@extends(staff_layout())

@section('title', 'Agency CSV import #'.$import->id)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <a href="{{ staff_route('agency-imports.index') }}" class="small text-muted">&larr; All imports</a>
            <h4 class="mb-1 mt-1">Import #{{ $import->id }}</h4>
            <p class="text-muted small mb-0">
                {{ $import->publisher->name ?? 'Publisher' }}
                ({{ $import->publisher->email ?? '—' }})
                · status <span class="badge bg-secondary" id="importStatusBadge">{{ $import->status }}</span>
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
            @if(auth()->user()?->isAdmin() && $import->sites->isNotEmpty())
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-success" id="bulkVerifyBtn">Verify selected</button>
                    <button type="button" class="btn btn-sm btn-primary" id="bulkActivateBtn">Activate selected</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="bulkRejectBtn">Reject selected</button>
                    <span class="small text-muted align-self-center" id="bulkSelectedCount">0 selected</span>
                </div>
            @endif
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                @if(auth()->user()?->isAdmin())
                                    <th style="width:2rem;"><input type="checkbox" id="selectAllSites" aria-label="Select all"></th>
                                @endif
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
                                <tr data-site-id="{{ $site->id }}">
                                    @if(auth()->user()?->isAdmin())
                                        <td><input type="checkbox" class="site-select" value="{{ $site->id }}" aria-label="Select {{ $site->site_name }}"></td>
                                    @endif
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
                                    <td class="site-verified">{{ $site->verified ? 'Yes' : 'No' }}</td>
                                    <td class="site-active">{{ $site->active ? 'Yes' : 'No' }}</td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ staff_route('sites.index', ['needs_review' => 1, 'publisher' => $import->publisher_id, 'site' => $site->id]) }}">Open in Sites</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ auth()->user()?->isAdmin() ? 8 : 7 }}" class="text-center text-muted py-4">No sites were created in this import.</td></tr>
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

@if(auth()->user()?->isAdmin() && $import->sites->isNotEmpty())
<script>
(function () {
    const csrf = @json(csrf_token());
    const bulkUrl = @json(staff_route('agency-imports.bulk-action', $import));
    const selectAll = document.getElementById('selectAllSites');
    const countEl = document.getElementById('bulkSelectedCount');

    function selectedIds() {
        return Array.from(document.querySelectorAll('.site-select:checked')).map((el) => parseInt(el.value, 10));
    }

    function refreshCount() {
        const n = selectedIds().length;
        if (countEl) countEl.textContent = n + ' selected';
    }

    selectAll?.addEventListener('change', () => {
        document.querySelectorAll('.site-select').forEach((el) => { el.checked = selectAll.checked; });
        refreshCount();
    });
    document.querySelectorAll('.site-select').forEach((el) => el.addEventListener('change', refreshCount));

    async function runBulk(action) {
        const site_ids = selectedIds();
        if (!site_ids.length) {
            Swal.fire({ icon: 'info', title: 'Select at least one site' });
            return;
        }
        let reason = null;
        if (action === 'reject') {
            const { value, isConfirmed } = await Swal.fire({
                title: 'Reject & remove selected sites?',
                input: 'textarea',
                inputLabel: 'Reason (required)',
                inputPlaceholder: 'Why are these listings being rejected?',
                showCancelButton: true,
                confirmButtonText: 'Reject & remove',
                customClass: { confirmButton: 'slb-swal-danger' },
                preConfirm: (v) => {
                    if (!v || String(v).trim().length < 5) {
                        Swal.showValidationMessage('Reason must be at least 5 characters');
                        return false;
                    }
                    return String(v).trim();
                },
            });
            if (!isConfirmed) return;
            reason = value;
        } else {
            const { isConfirmed } = await Swal.fire({
                title: action === 'verify' ? 'Verify selected sites?' : 'Activate selected sites?',
                text: site_ids.length + ' site(s) will be updated.',
                showCancelButton: true,
                confirmButtonText: action === 'verify' ? 'Verify' : 'Activate',
            });
            if (!isConfirmed) return;
        }

        const res = await fetch(bulkUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ action, site_ids, reason }),
        });
        const data = await res.json().catch(() => ({}));
        await Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
        if (data.success) location.reload();
    }

    document.getElementById('bulkVerifyBtn')?.addEventListener('click', () => runBulk('verify'));
    document.getElementById('bulkActivateBtn')?.addEventListener('click', () => runBulk('activate'));
    document.getElementById('bulkRejectBtn')?.addEventListener('click', () => runBulk('reject'));
    refreshCount();
})();
</script>
@endif
@endsection
