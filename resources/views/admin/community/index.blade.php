@extends('admin.layouts.app')

@php
    use App\Support\CommunityInbox;
@endphp

@section('content')
<div class="container-fluid py-3">
    <div class="mb-4">
        <h4 class="mb-1 fw-bold">Community feedback</h4>
        <p class="text-muted mb-0">Problem reports, suggestions, missing-website requests, and ownership claims.</p>
    </div>

    <ul class="nav nav-pills gap-2 mb-3 flex-wrap">
        @foreach($tabs as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $key ? 'active' : '' }}"
                   href="{{ route('admin.community.index', $tabQueries[$key] ?? ['tab' => $key]) }}">
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
                <x-slb-search-field name="q" id="adminCommunitySearch" :value="$q" placeholder="Search…" />
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($statuses as $st)
                        <option value="{{ $st }}" @selected($status === $st)>{{ ucfirst($st) }}</option>
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
                            <th class="admin-actions-wide-col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($problems as $item)
                            @php $pageUrl = CommunityInbox::safeHttpUrl($item->page_url); @endphp
                            <tr>
                                <td>
                                    <div>
                                        @if($item->user_id)
                                            <a href="{{ route('admin.users.index', ['user' => $item->user_id]) }}#user-{{ $item->user_id }}">{{ $item->name ?: ($item->user?->name ?? 'User #'.$item->user_id) }}</a>
                                        @else
                                            {{ $item->name ?: '—' }}
                                        @endif
                                    </div>
                                    <div class="small text-muted">{{ $item->email ?: ($item->user?->email ?? '') }}</div>
                                    @if($item->role_context)<div class="small text-muted">Role: {{ $item->role_context }}</div>@endif
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $item->subject }}</div>
                                    @if($pageUrl)
                                        <a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer" class="small">{{ \Illuminate\Support\Str::limit($pageUrl, 42) }}</a>
                                    @endif
                                </td>
                                <td class="small" style="max-width:280px;">{{ \Illuminate\Support\Str::limit($item->message, 160) }}</td>
                                <td><span class="badge {{ CommunityInbox::statusBadgeClass($item->status) }}">{{ $item->status }}</span></td>
                                <td class="small text-muted">
                                    {{ optional($item->created_at)->diffForHumans() }}
                                    @if($item->status === 'pending' && $item->created_at?->lte(now()->subHours(48)))
                                        <div class="text-warning">Pending {{ $item->created_at->diffForHumans(now(), true) }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-community-drawer"
                                            data-title="Problem #{{ $item->id }}"
                                            data-template="community-detail-problems-{{ $item->id }}">Details</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-status"
                                            data-url="{{ route('admin.community.problems.update', $item->id) }}"
                                            data-status="{{ $item->status }}"
                                            data-statuses="{{ implode(',', $statuses) }}"
                                            data-notes="{{ $item->admin_notes }}">Update</button>
                                </td>
                            </tr>
                        @empty
                            @include('admin.community.empty-row', ['empty' => 'No problem reports yet.'])
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $problems->links() }}</div>
                @foreach($problems as $item)
                    @include('admin.community.detail', ['tab' => 'problems', 'item' => $item, 'pageUrl' => CommunityInbox::safeHttpUrl($item->page_url)])
                @endforeach
            @elseif($tab === 'suggestions')
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From</th>
                            <th>Category</th>
                            <th>Suggestion</th>
                            <th>Status</th>
                            <th>When</th>
                            <th class="admin-actions-wide-col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($suggestions as $item)
                            @php $pageUrl = CommunityInbox::safeHttpUrl($item->page_url); @endphp
                            <tr>
                                <td>
                                    <div>
                                        @if($item->user_id)
                                            <a href="{{ route('admin.users.index', ['user' => $item->user_id]) }}#user-{{ $item->user_id }}">{{ $item->name ?: ($item->user?->name ?? 'User #'.$item->user_id) }}</a>
                                        @else
                                            {{ $item->name ?: '—' }}
                                        @endif
                                    </div>
                                    <div class="small text-muted">{{ $item->email ?: ($item->user?->email ?? '') }}</div>
                                </td>
                                <td><span class="badge bg-info-subtle text-info border">{{ $item->category }}</span></td>
                                <td class="small" style="max-width:320px;">
                                    {{ \Illuminate\Support\Str::limit($item->message, 180) }}
                                    @if($pageUrl)
                                        <div><a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($pageUrl, 42) }}</a></div>
                                    @endif
                                </td>
                                <td><span class="badge {{ CommunityInbox::statusBadgeClass($item->status) }}">{{ $item->status }}</span></td>
                                <td class="small text-muted">
                                    {{ optional($item->created_at)->diffForHumans() }}
                                    @if($item->status === 'pending' && $item->created_at?->lte(now()->subHours(48)))
                                        <div class="text-warning">Pending {{ $item->created_at->diffForHumans(now(), true) }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-community-drawer"
                                            data-title="Suggestion #{{ $item->id }}"
                                            data-template="community-detail-suggestions-{{ $item->id }}">Details</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-status"
                                            data-url="{{ route('admin.community.suggestions.update', $item->id) }}"
                                            data-status="{{ $item->status }}"
                                            data-statuses="{{ implode(',', $statuses) }}"
                                            data-notes="{{ $item->admin_notes }}">Update</button>
                                </td>
                            </tr>
                        @empty
                            @include('admin.community.empty-row', ['empty' => 'No suggestions yet.'])
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $suggestions->links() }}</div>
                @foreach($suggestions as $item)
                    @include('admin.community.detail', ['tab' => 'suggestions', 'item' => $item, 'pageUrl' => CommunityInbox::safeHttpUrl($item->page_url)])
                @endforeach
            @elseif($tab === 'websites')
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Suggested website</th>
                            <th>Requested by</th>
                            <th>Search / notes</th>
                            <th>Status</th>
                            <th>When</th>
                            <th class="admin-actions-wide-col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($websites as $item)
                            @php $siteUrl = CommunityInbox::safeHttpUrl($item->website_url); @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $item->website_name }}</div>
                                    @if($siteUrl)
                                        <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="small">{{ $item->domain ?: $item->website_url }}</a>
                                    @else
                                        <div class="small text-muted">{{ $item->domain ?: $item->website_url }}</div>
                                    @endif
                                    @if($item->country || $item->language)
                                        <div class="small text-muted">{{ trim(($item->country ?: '—').' / '.($item->language ?: '—')) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div>
                                        @if($item->user_id)
                                            <a href="{{ route('admin.users.index', ['user' => $item->user_id]) }}#user-{{ $item->user_id }}">{{ $item->user?->name ?? 'User #'.$item->user_id }}</a>
                                        @else
                                            —
                                        @endif
                                    </div>
                                    <div class="small text-muted">{{ $item->user?->email ?? '' }}</div>
                                </td>
                                <td class="small" style="max-width:260px;">
                                    @if($item->search_query)<div><strong>Search:</strong> {{ $item->search_query }}</div>@endif
                                    {{ $item->notes ?: '—' }}
                                </td>
                                <td><span class="badge {{ CommunityInbox::statusBadgeClass($item->status) }}">{{ $item->status }}</span></td>
                                <td class="small text-muted">
                                    {{ optional($item->created_at)->diffForHumans() }}
                                    @if($item->status === 'pending' && $item->created_at?->lte(now()->subHours(48)))
                                        <div class="text-warning">Pending {{ $item->created_at->diffForHumans(now(), true) }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @include('admin.community.website-listing-action', ['item' => $item])
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-community-drawer"
                                            data-title="Website #{{ $item->id }}"
                                            data-template="community-detail-websites-{{ $item->id }}">Details</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-status"
                                            data-url="{{ route('admin.community.websites.update', $item->id) }}"
                                            data-status="{{ $item->status }}"
                                            data-statuses="{{ implode(',', $statuses) }}"
                                            data-notes="{{ $item->admin_notes }}">Update</button>
                                </td>
                            </tr>
                        @empty
                            @include('admin.community.empty-row', ['empty' => 'No website suggestions yet.'])
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $websites->links() }}</div>
                @foreach($websites as $item)
                    @include('admin.community.detail', ['tab' => 'websites', 'item' => $item, 'pageUrl' => CommunityInbox::safeHttpUrl($item->website_url)])
                @endforeach
            @else
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Claimed site</th>
                            <th>Claimer</th>
                            <th>Current owner</th>
                            <th>Verification</th>
                            <th>Status</th>
                            <th>When</th>
                            <th class="admin-actions-wide-col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($claims as $item)
                            @php
                                $ctx = $claimContexts[$item->id] ?? [];
                                $siblings = (int) ($claimSiblingPending[$item->id] ?? 0);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        @if($item->site_id)
                                            <a href="{{ route('admin.sites.edit', $item->site_id) }}">{{ $item->site->site_name ?? $item->website_name }}</a>
                                        @else
                                            {{ $item->website_name }}
                                        @endif
                                    </div>
                                    <div class="small text-muted">{{ $item->domain }}</div>
                                    <div class="small">Provided name: <strong>{{ $item->website_name }}</strong></div>
                                </td>
                                <td>
                                    <div>
                                        @if($item->claimer_id)
                                            <a href="{{ route('admin.users.index', ['user' => $item->claimer_id]) }}#user-{{ $item->claimer_id }}">{{ $item->claimer?->name ?? 'User #'.$item->claimer_id }}</a>
                                        @else
                                            —
                                        @endif
                                    </div>
                                    <div class="small text-muted">{{ $item->contact_email ?: ($item->claimer?->email ?? '') }}</div>
                                </td>
                                <td>
                                    <div>
                                        @if($item->site?->publisher_id)
                                            <a href="{{ route('admin.users.index', ['user' => $item->site->publisher_id]) }}#user-{{ $item->site->publisher_id }}">{{ $item->site->publisher?->name ?? 'User #'.$item->site->publisher_id }}</a>
                                        @else
                                            {{ $item->site->publisher?->name ?? '—' }}
                                        @endif
                                    </div>
                                    <div class="small text-muted">{{ $item->site->publisher?->email ?? '' }}</div>
                                </td>
                                <td class="small" style="max-width:260px;">
                                    @if($item->name_matches)
                                        <span class="badge bg-success mb-1">Name matches</span>
                                    @else
                                        <span class="badge bg-warning text-dark mb-1">Name mismatch</span>
                                    @endif
                                    @if(!empty($ctx['verified']))
                                        <span class="badge bg-info-subtle text-info border mb-1">Verified listing</span>
                                    @endif
                                    @if(($ctx['open_orders'] ?? 0) > 0)
                                        <div class="text-warning">{{ $ctx['open_orders'] }} open order(s)</div>
                                    @endif
                                    @if(($ctx['open_disputes'] ?? 0) > 0)
                                        <div class="text-warning">{{ $ctx['open_disputes'] }} open dispute(s)</div>
                                    @endif
                                    @if($siblings > 0)
                                        <div class="text-muted">{{ $siblings }} other pending claim(s)</div>
                                    @endif
                                    <div>{{ \Illuminate\Support\Str::limit($item->proof_message, 140) }}</div>
                                </td>
                                <td><span class="badge {{ CommunityInbox::statusBadgeClass($item->status) }}">{{ $item->status }}</span></td>
                                <td class="small text-muted">
                                    {{ optional($item->created_at)->diffForHumans() }}
                                    @if($item->status === 'pending' && $item->created_at?->lte(now()->subHours(48)))
                                        <div class="text-warning">Pending {{ $item->created_at->diffForHumans(now(), true) }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-community-drawer"
                                            data-title="Claim #{{ $item->id }}"
                                            data-template="community-detail-claims-{{ $item->id }}">Details</button>
                                    @if($item->status === 'pending')
                                        @if(auth()->user()->isAdmin())
                                            <button type="button" class="btn btn-sm btn-success btn-claim-action"
                                                    data-url="{{ route('admin.community.claims.approve', $item->id) }}"
                                                    data-open-orders="{{ (int) ($claimOpenOrders[$item->id] ?? 0) }}"
                                                    data-open-disputes="{{ (int) ($claimOpenDisputes[$item->id] ?? 0) }}"
                                                    data-mode="approve">Approve</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-claim-action"
                                                    data-url="{{ route('admin.community.claims.reject', $item->id) }}"
                                                    data-mode="reject">Reject</button>
                                        @else
                                            <span class="small text-muted">Awaiting admin review</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            @include('admin.community.empty-row', ['colspan' => 7, 'empty' => 'No site claims yet.'])
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $claims->links() }}</div>
                @foreach($claims as $item)
                    @include('admin.community.detail', [
                        'tab' => 'claims',
                        'item' => $item,
                        'pageUrl' => CommunityInbox::safeHttpUrl($item->website_url),
                        'ctx' => $claimContexts[$item->id] ?? [],
                        'siblings' => (int) ($claimSiblingPending[$item->id] ?? 0),
                    ])
                @endforeach
            @endif
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="communityDrawer" aria-labelledby="communityDrawerTitle">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="communityDrawerTitle">Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="communityDrawerBody"></div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';

function communityFetchMessage(res, data, fallback) {
    if (data.message) {
        return data.message;
    }
    if (data.errors && typeof data.errors === 'object') {
        const first = Object.values(data.errors).flat()[0];
        if (first) {
            return first;
        }
    }
    return res.ok ? fallback : 'Network error';
}

document.querySelectorAll('.btn-community-drawer').forEach(btn => {
    btn.addEventListener('click', () => {
        const drawer = document.getElementById('communityDrawer');
        const title = document.getElementById('communityDrawerTitle');
        const body = document.getElementById('communityDrawerBody');
        const tpl = document.getElementById(btn.dataset.template || '');
        if (!drawer || !body || !tpl) return;
        if (title) title.textContent = btn.dataset.title || 'Details';
        body.replaceChildren(tpl.content.cloneNode(true));
        bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
    });
});

document.querySelectorAll('.btn-status').forEach(btn => {
    btn.addEventListener('click', async () => {
        const statuses = (btn.dataset.statuses || '')
            .split(',')
            .map(s => s.trim())
            .filter(s => /^[a-z]+$/.test(s));
        const current = /^[a-z]+$/.test(btn.dataset.status || '') ? btn.dataset.status : '';
        const { value: form } = await Swal.fire({
            title: 'Update status',
            html: `<select id="swal-status" class="swal2-select">
                     ${statuses.map(s => `<option value="${s}" ${s === current ? 'selected' : ''}>${s}</option>`).join('')}
                   </select>
                   <textarea id="swal-notes" class="swal2-textarea" placeholder="Admin notes"></textarea>`,
            showCancelButton: true,
            confirmButtonText: 'Save',
            didOpen: () => {
                const notes = document.getElementById('swal-notes');
                if (notes) {
                    notes.value = btn.dataset.notes || '';
                }
            },
            preConfirm: () => ({
                status: document.getElementById('swal-status').value,
                admin_notes: document.getElementById('swal-notes').value,
            }),
        });
        if (!form) return;
        try {
            const res = await fetch(btn.dataset.url, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(form),
            });
            const data = await res.json().catch(() => ({}));
            const ok = res.ok && data.success;
            await Swal.fire({
                icon: ok ? 'success' : 'error',
                title: ok ? (data.message || 'Updated.') : communityFetchMessage(res, data, 'Update failed'),
            });
            if (ok) location.reload();
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Network error' });
        }
    });
});

document.querySelectorAll('.btn-claim-action').forEach(btn => {
    btn.addEventListener('click', async () => {
        const approve = btn.dataset.mode === 'approve';
        const openOrders = parseInt(btn.dataset.openOrders || '0', 10) || 0;
        const openDisputes = parseInt(btn.dataset.openDisputes || '0', 10) || 0;
        const blocked = approve && (openOrders > 0 || openDisputes > 0);
        let warning = '';
        if (approve && openOrders > 0) {
            warning += `<div class="alert alert-warning small text-start mb-2">This site has <strong>${openOrders}</strong> open order(s). Approving is blocked until they are completed, cancelled, or resolved.</div>`;
        }
        if (approve && openDisputes > 0) {
            warning += `<div class="alert alert-warning small text-start mb-2">This site has <strong>${openDisputes}</strong> open dispute(s). Approving is blocked until they are resolved (clawback would hit the new owner).</div>`;
        }
        const { value: notes, isConfirmed } = await Swal.fire({
            title: approve ? 'Approve claim & transfer ownership?' : 'Reject claim?',
            input: 'textarea',
            inputLabel: 'Admin notes (optional)',
            html: warning || undefined,
            showCancelButton: true,
            showConfirmButton: !blocked,
            confirmButtonText: approve ? 'Approve & transfer' : 'Reject',
            customClass: { confirmButton: approve ? '' : 'slb-swal-danger' },
        });
        if (!isConfirmed || blocked) return;
        try {
            const res = await fetch(btn.dataset.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ admin_notes: notes || null }),
            });
            const data = await res.json().catch(() => ({}));
            const ok = res.ok && data.success;
            await Swal.fire({
                icon: ok ? 'success' : 'error',
                title: ok ? (data.message || 'Updated.') : communityFetchMessage(res, data, 'Update failed'),
            });
            if (ok) location.reload();
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Network error' });
        }
    });
});
</script>
@endsection
