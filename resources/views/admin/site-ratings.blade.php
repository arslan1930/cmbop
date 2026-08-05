@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Publisher Ratings</h4>
            <p class="text-muted mb-0">Review, edit, hide, or delete advertiser ratings for catalog sites.</p>
        </div>
        <button type="button" class="btn btn-sm btn-primary" id="addRatingBtn">Add / upsert rating</button>
    </div>

    <form method="get" class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Site, user, comment…">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Site</label>
                <select name="site_id" class="form-select form-select-sm">
                    <option value="">All sites</option>
                    @foreach($sites as $site)
                        <option value="{{ $site->id }}" @selected((string) request('site_id') === (string) $site->id)>
                            {{ $site->site_name }} ({{ $site->domain }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['approved','hidden','pending'] as $st)
                        <option value="{{ $st }}" @selected(request('status') === $st)>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">Filter</button>
                <a href="{{ route('admin.site-ratings.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Site</th>
                        <th>Advertiser</th>
                        <th>Rating</th>
                        <th>Comment</th>
                        <th>Status</th>
                        <th>When</th>
                        <th class="admin-actions-wide-col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ratings as $rating)
                        <tr data-id="{{ $rating->id }}">
                            <td>
                                <div class="fw-semibold">{{ $rating->site->site_name ?? '—' }}</div>
                                <div class="small text-muted">{{ $rating->site->domain ?? '' }}</div>
                                @if($rating->site)
                                    <div class="small text-muted">Avg {{ number_format($rating->site->rating_avg ?? 0, 1) }} ({{ $rating->site->rating_count ?? 0 }})</div>
                                @endif
                            </td>
                            <td>
                                @if($rating->user)
                                    <div>{{ $rating->user->name }}</div>
                                    <div class="small text-muted">{{ $rating->user->email }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                                @if($rating->is_admin)
                                    <span class="badge bg-info-subtle text-info border">Admin</span>
                                @endif
                            </td>
                            <td>
                                {{-- Stars are decorative; the score is announced once via aria-label. --}}
                                <span role="img" aria-label="{{ $rating->rating }} out of 5 stars">
                                    @for($i = 1; $i <= 5; $i++)
                                        <i class="fa-{{ $i <= $rating->rating ? 'solid' : 'regular' }} fa-star {{ $i <= $rating->rating ? 'text-warning' : 'text-muted' }}" aria-hidden="true"></i>
                                    @endfor
                                </span>
                                <span class="ms-1" aria-hidden="true">{{ $rating->rating }}/5</span>
                            </td>
                            <td class="small" style="max-width:260px;">{{ $rating->comment ?: '—' }}</td>
                            <td>
                                <span class="badge {{ $rating->status === 'approved' ? 'bg-success' : ($rating->status === 'hidden' ? 'bg-secondary' : 'bg-warning text-dark') }}">
                                    {{ $rating->status }}
                                </span>
                            </td>
                            <td class="small text-muted">{{ optional($rating->created_at)->diffForHumans() }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary edit-rating"
                                        data-id="{{ $rating->id }}"
                                        data-rating="{{ $rating->rating }}"
                                        data-comment="{{ e($rating->comment) }}"
                                        data-status="{{ $rating->status }}">Edit</button>
                                <button type="button" class="btn btn-sm btn-outline-danger delete-rating" data-id="{{ $rating->id }}">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No ratings yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $ratings->links() }}</div>
    </div>
</div>

<script>
const CSRF = '{{ csrf_token() }}';
const SITE_OPTIONS = @json($sites->map(fn ($s) => ['id' => $s->id, 'label' => $s->site_name.' ('.$s->domain.')'])->values());

// Site names and rating comments are publisher/advertiser text, and these
// dialogs are built as HTML strings, so escape before interpolating.
function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

document.getElementById('addRatingBtn')?.addEventListener('click', async () => {
    const siteOptionsHtml = SITE_OPTIONS
        .map(s => `<option value="${escapeHtml(s.id)}">${escapeHtml(s.label)}</option>`)
        .join('');
    const { value: form } = await Swal.fire({
        title: 'Add / upsert rating',
        html: `
            <select id="swal-site" class="swal2-input" style="width:90%">${siteOptionsHtml}</select>
            <input id="swal-rating" type="number" min="1" max="5" class="swal2-input" placeholder="Rating 1–5" value="5">
            <input id="swal-comment" class="swal2-input" placeholder="Optional comment">
            <select id="swal-status" class="swal2-input" style="width:90%">
                <option value="approved">approved</option>
                <option value="hidden">hidden</option>
                <option value="pending">pending</option>
            </select>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save',
        preConfirm: () => ({
            site_id: document.getElementById('swal-site').value,
            rating: document.getElementById('swal-rating').value,
            comment: document.getElementById('swal-comment').value,
            status: document.getElementById('swal-status').value,
        }),
    });
    if (!form) return;
    const res = await fetch(@json(route('admin.site-ratings.store')), {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json'},
        body: JSON.stringify(form),
    });
    const data = await res.json();
    showAppToast(data.message || 'Done', data.success ? 'success' : 'error');
    if (data.success) location.reload();
});

document.querySelectorAll('.edit-rating').forEach(btn => {
    btn.addEventListener('click', async () => {
        const { value: form } = await Swal.fire({
            title: 'Edit rating',
            html: `
                <input id="swal-rating" type="number" min="1" max="5" class="swal2-input" value="${escapeHtml(btn.dataset.rating)}">
                <input id="swal-comment" class="swal2-input" placeholder="Comment">
                <select id="swal-status" class="swal2-input" style="width:90%">
                    ${['approved','hidden','pending'].map(s => `<option value="${s}" ${s===btn.dataset.status?'selected':''}>${s}</option>`).join('')}
                </select>
            `,
            // Set the comment as a value, never as markup.
            didOpen: () => {
                document.getElementById('swal-comment').value = btn.dataset.comment || '';
            },
            showCancelButton: true,
            confirmButtonText: 'Update',
            preConfirm: () => ({
                rating: document.getElementById('swal-rating').value,
                comment: document.getElementById('swal-comment').value,
                status: document.getElementById('swal-status').value,
            }),
        });
        if (!form) return;
        const res = await fetch(`/admin/site-ratings/${btn.dataset.id}`, {
            method: 'PUT',
            headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json'},
            body: JSON.stringify(form),
        });
        const data = await res.json();
        showAppToast(data.message || 'Done', data.success ? 'success' : 'error');
        if (data.success) location.reload();
    });
});

document.querySelectorAll('.delete-rating').forEach(btn => {
    btn.addEventListener('click', async () => {
        const confirmed = await slbConfirm({
            title: 'Delete rating?',
            text: 'This removes the rating and recalculates the site average.',
            confirmText: 'Delete',
            danger: true,
        });
        if (!confirmed) return;
        const res = await fetch(`/admin/site-ratings/${btn.dataset.id}`, {
            method: 'DELETE',
            headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'},
        });
        const data = await res.json();
        showAppToast(data.message || 'Done', data.success ? 'success' : 'error');
        if (data.success) location.reload();
    });
});
</script>
@endsection
