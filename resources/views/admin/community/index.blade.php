@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="mb-4">
        <h4 class="mb-1 fw-bold">Community feedback</h4>
        <p class="text-muted mb-0">Problem reports, suggestions, missing-website requests, and ownership claims.</p>
    </div>

    <ul class="nav nav-pills gap-2 mb-3 flex-wrap">
        @foreach([
            'problems' => 'Problem reports',
            'suggestions' => 'Suggestion box',
            'websites' => 'Website suggestions',
            'claims' => 'Site claims',
        ] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $key ? 'active' : '' }}"
                   href="{{ route('admin.community.index', ['tab' => $key] + request()->except('tab')) }}">
                    {{ $label }}
                    @if(($counts[$key] ?? 0) > 0)
                        <span class="badge bg-warning text-dark ms-1">{{ $counts[$key] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>

    <form method="get" class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2 align-items-end">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search…">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['pending','reviewed','resolved','accepted','rejected'] as $st)
                        <option value="{{ $st }}" @selected(request('status') === $st)>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">Filter</button>
                <a href="{{ route('admin.community.index', ['tab' => $tab]) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            @if($tab === 'problems')
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From</th>
                            <th>Subject</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>When</th>
                            <th width="200"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($problems as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->name ?: ($item->user->name ?? '—') }}</div>
                                    <div class="small text-muted">{{ $item->email ?: ($item->user->email ?? '') }}</div>
                                    @if($item->role_context)<div class="small text-muted">Role: {{ $item->role_context }}</div>@endif
                                </td>
                                <td class="fw-semibold">{{ $item->subject }}</td>
                                <td class="small" style="max-width:280px;">{{ Str::limit($item->message, 160) }}</td>
                                <td><span class="badge bg-secondary">{{ $item->status }}</span></td>
                                <td class="small text-muted">{{ optional($item->created_at)->diffForHumans() }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-status"
                                            data-url="{{ route('admin.community.problems.update', $item->id) }}"
                                            data-status="{{ $item->status }}"
                                            data-notes="{{ e($item->admin_notes) }}">Update</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No problem reports yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $problems->links() }}</div>
            @elseif($tab === 'suggestions')
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From</th>
                            <th>Category</th>
                            <th>Suggestion</th>
                            <th>Status</th>
                            <th>When</th>
                            <th width="200"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($suggestions as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->name ?: ($item->user->name ?? '—') }}</div>
                                    <div class="small text-muted">{{ $item->email ?: ($item->user->email ?? '') }}</div>
                                </td>
                                <td><span class="badge bg-info-subtle text-info border">{{ $item->category }}</span></td>
                                <td class="small" style="max-width:320px;">{{ Str::limit($item->message, 180) }}</td>
                                <td><span class="badge bg-secondary">{{ $item->status }}</span></td>
                                <td class="small text-muted">{{ optional($item->created_at)->diffForHumans() }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-status"
                                            data-url="{{ route('admin.community.suggestions.update', $item->id) }}"
                                            data-status="{{ $item->status }}"
                                            data-notes="{{ e($item->admin_notes) }}">Update</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No suggestions yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $suggestions->links() }}</div>
            @elseif($tab === 'websites')
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Suggested website</th>
                            <th>Requested by</th>
                            <th>Search / notes</th>
                            <th>Status</th>
                            <th>When</th>
                            <th width="200"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($websites as $item)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $item->website_name }}</div>
                                    <a href="{{ $item->website_url }}" target="_blank" rel="noopener" class="small">{{ $item->domain ?: $item->website_url }}</a>
                                </td>
                                <td>
                                    <div>{{ $item->user->name ?? '—' }}</div>
                                    <div class="small text-muted">{{ $item->user->email ?? '' }}</div>
                                </td>
                                <td class="small" style="max-width:260px;">
                                    @if($item->search_query)<div><strong>Search:</strong> {{ $item->search_query }}</div>@endif
                                    {{ $item->notes ?: '—' }}
                                </td>
                                <td><span class="badge bg-secondary">{{ $item->status }}</span></td>
                                <td class="small text-muted">{{ optional($item->created_at)->diffForHumans() }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-status"
                                            data-url="{{ route('admin.community.websites.update', $item->id) }}"
                                            data-status="{{ $item->status }}"
                                            data-notes="{{ e($item->admin_notes) }}"
                                            data-accept="1">Update</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No website suggestions yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $websites->links() }}</div>
            @else
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Claimed site</th>
                            <th>Claimer</th>
                            <th>Current owner</th>
                            <th>Verification</th>
                            <th>Status</th>
                            <th width="220"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($claims as $item)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $item->site->site_name ?? $item->website_name }}</div>
                                    <div class="small text-muted">{{ $item->domain }}</div>
                                    <div class="small">Provided name: <strong>{{ $item->website_name }}</strong></div>
                                </td>
                                <td>
                                    <div>{{ $item->claimer->name ?? '—' }}</div>
                                    <div class="small text-muted">{{ $item->contact_email ?: ($item->claimer->email ?? '') }}</div>
                                </td>
                                <td>
                                    <div>{{ $item->site->publisher->name ?? '—' }}</div>
                                    <div class="small text-muted">{{ $item->site->publisher->email ?? '' }}</div>
                                </td>
                                <td class="small" style="max-width:260px;">
                                    @if($item->name_matches)
                                        <span class="badge bg-success mb-1">Name matches</span>
                                    @else
                                        <span class="badge bg-warning text-dark mb-1">Name mismatch</span>
                                    @endif
                                    <div>{{ Str::limit($item->proof_message, 140) }}</div>
                                </td>
                                <td><span class="badge bg-secondary">{{ $item->status }}</span></td>
                                <td class="text-end">
                                    @if($item->status === 'pending')
                                        <button type="button" class="btn btn-sm btn-success btn-claim-action"
                                                data-url="{{ route('admin.community.claims.approve', $item->id) }}"
                                                data-mode="approve">Approve</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-claim-action"
                                                data-url="{{ route('admin.community.claims.reject', $item->id) }}"
                                                data-mode="reject">Reject</button>
                                    @else
                                        <span class="small text-muted">{{ optional($item->reviewed_at)->diffForHumans() }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No site claims yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $claims->links() }}</div>
            @endif
        </div>
    </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';

document.querySelectorAll('.btn-status').forEach(btn => {
    btn.addEventListener('click', async () => {
        const allowAccept = btn.dataset.accept === '1';
        const statuses = allowAccept
            ? ['pending', 'reviewed', 'accepted', 'rejected']
            : ['pending', 'reviewed', 'resolved', 'rejected'];
        const { value: form } = await Swal.fire({
            title: 'Update status',
            html: `<select id="swal-status" class="swal2-select">
                     ${statuses.map(s => `<option value="${s}" ${s === btn.dataset.status ? 'selected' : ''}>${s}</option>`).join('')}
                   </select>
                   <textarea id="swal-notes" class="swal2-textarea" placeholder="Admin notes">${btn.dataset.notes || ''}</textarea>`,
            showCancelButton: true,
            confirmButtonText: 'Save',
            preConfirm: () => ({
                status: document.getElementById('swal-status').value,
                admin_notes: document.getElementById('swal-notes').value,
            }),
        });
        if (!form) return;
        const res = await fetch(btn.dataset.url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(form),
        });
        const data = await res.json().catch(() => ({}));
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' }).then(() => {
            if (data.success) location.reload();
        });
    });
});

document.querySelectorAll('.btn-claim-action').forEach(btn => {
    btn.addEventListener('click', async () => {
        const approve = btn.dataset.mode === 'approve';
        const { value: notes, isConfirmed } = await Swal.fire({
            title: approve ? 'Approve claim & transfer ownership?' : 'Reject claim?',
            input: 'textarea',
            inputLabel: 'Admin notes (optional)',
            showCancelButton: true,
            confirmButtonText: approve ? 'Approve & transfer' : 'Reject',
            confirmButtonColor: approve ? '#185054' : '#b91c1c',
        });
        if (!isConfirmed) return;
        const res = await fetch(btn.dataset.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ admin_notes: notes || null }),
        });
        const data = await res.json().catch(() => ({}));
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' }).then(() => {
            if (data.success) location.reload();
        });
    });
});
</script>
@endsection
