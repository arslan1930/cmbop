@extends('admin.layouts.app')

@section('content')
@php
    $t = $dossier['totals'] ?? [];
    $euro = fn ($n) => '€'.number_format((float) $n, 2);
    $adv = $dossier['advertiser_wallet'] ?? null;
    $pub = $dossier['publisher_wallet'] ?? null;
    $orders = collect($dossier['orders'] ?? []);
    $withdrawals = collect($dossier['withdrawals'] ?? []);
    $deposits = collect($dossier['deposits'] ?? []);
    try {
        $roles = $user->roles->pluck('name')->all();
    } catch (\Throwable) {
        $roles = [];
    }
    $canSuspend = ! in_array('admin', $roles, true)
        && ! $user->hasRole('admin')
        && (int) $user->id !== (int) auth()->id();
    $sites = collect($sites ?? []);
    $related = is_array($related ?? null) ? $related : [];
    $copyLabel = $user->catalogCopyStatusLabel();
    $userRoleNames = $roles;
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => $user->name,
        'subtitle' => $user->email,
        'actionUrl' => route('admin.users.index'),
        'actionLabel' => 'All users',
        'actionIcon' => 'fa-arrow-left',
    ])

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach($roles as $roleName)
            <span class="badge {{ $roleName === $user->activeRole() ? 'bg-primary' : 'bg-secondary' }} text-capitalize">{{ $roleName }}</span>
        @endforeach
        @if($user->isSuspended())
            <span class="badge text-bg-danger">Suspended</span>
        @else
            <span class="badge text-bg-success">Active</span>
        @endif
        @if($user->hasVerifiedEmail())
            <span class="badge text-bg-success">Email verified</span>
        @else
            <span class="badge text-bg-warning text-dark">Unverified</span>
        @endif
        @if($user->isOnline())
            <span class="badge text-bg-success">Online</span>
        @endif
        @if($copyLabel)
            <span class="badge {{ $user->inCatalogHideMode() ? 'text-bg-danger' : 'text-bg-warning text-dark' }}">{{ $copyLabel }}</span>
        @endif
        @if(! empty($user->google_id))
            <span class="badge text-bg-light border">Google sign-in</span>
        @endif
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Account</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted">User ID</div>
                            <div class="fw-semibold">#{{ $user->id }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Phone</div>
                            <div>{{ $user->phone ?: '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Country</div>
                            <div>{{ $user->country ?: '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Company</div>
                            <div>
                                <span class="company-text" data-id="{{ $user->id }}">{{ $user->company_name ?: '—' }}</span>
                                <button type="button" class="btn btn-sm btn-link text-primary p-0 ms-2 btn-edit-company" data-id="{{ $user->id }}">Edit</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Joined</div>
                            <div>{{ $user->created_at?->format('d M Y, H:i') ?: '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Last activity</div>
                            <div>{{ $user->lastSeenLabel() ?: 'Never recorded' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Sign-in</div>
                            <div>{{ ! empty($user->google_id) ? 'Google' : 'Email and password' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Payout</div>
                            <div>
                                @if($user->payoutProfileLocked())
                                    <span class="badge status-pending">Locked</span>
                                    <span class="text-muted small">{{ strtoupper((string) $user->payout_preferred_method) ?: '—' }}</span>
                                @else
                                    <span class="text-muted">Not set</span>
                                @endif
                                <button type="button"
                                        class="btn btn-sm btn-link text-primary p-0 ms-2 btn-edit-payout"
                                        data-id="{{ $user->id }}"
                                        data-method="{{ $user->payout_preferred_method ?: 'paypal' }}"
                                        data-paypal="{{ $user->payout_paypal_email }}"
                                        data-wise="{{ $user->payout_wise_email }}"
                                        data-bank-name="{{ $user->payout_bank_name }}"
                                        data-holder="{{ $user->payout_bank_holder_name }}"
                                        data-account="{{ $user->payout_bank_account }}"
                                        data-swift="{{ $user->payout_bank_swift }}"
                                        data-crypto-type="{{ $user->payout_crypto_type ?: 'USDT' }}"
                                        data-wallet="{{ $user->payout_crypto_trx_wallet }}">
                                    Edit
                                </button>
                            </div>
                        </div>
                        @if($copyLabel)
                            <div class="col-12">
                                <div class="alert {{ $user->inCatalogHideMode() ? 'alert-danger' : 'alert-warning' }} mb-0 py-2">
                                    <strong>{{ $copyLabel }}</strong>
                                    @if($user->inCatalogHideMode() && $user->catalog_hide_until instanceof \DateTimeInterface)
                                        — until {{ $user->catalog_hide_until->format('d M Y, H:i') }}
                                    @endif
                                    <div class="small">Copy strikes: {{ (int) ($user->catalog_copy_strike_count ?? 0) }}</div>
                                    <a href="{{ $related['catalog_url'] ?? route('admin.catalog-activity.show', $user) }}">Open catalog activity</a>
                                </div>
                            </div>
                        @endif
                        @if($user->isSuspended())
                            <div class="col-12">
                                <div class="alert alert-danger mb-0 py-2">
                                    <strong>Suspended</strong>
                                    @if($user->suspended_reason)
                                        — {{ $user->suspended_reason }}
                                    @endif
                                    @if($user->suspended_at instanceof \DateTimeInterface)
                                        <div class="small">Since {{ $user->suspended_at->format('d M Y, H:i') }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Actions</h2>
                    <div class="d-flex flex-column gap-2">
                        @if(! $user->hasVerifiedEmail())
                            <form method="POST" action="{{ route('admin.users.verify-email', $user) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success w-100">
                                    <i class="fa fa-check me-1"></i> Mark email verified
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.users.resend-verification', $user) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                    <i class="fa fa-envelope me-1"></i> Resend verification
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.users.send-password-reset', $user) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                <i class="fa fa-key me-1"></i> Send password reset
                            </button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-secondary action-roles w-100" data-id="{{ $user->id }}">
                            <i class="fa fa-bullhorn me-1"></i> Marketing access
                        </button>
                        @if($canSuspend && ! $user->isSuspended())
                            <form method="POST" action="{{ route('admin.users.suspend', $user) }}" class="js-suspend-form">
                                @csrf
                                <label class="form-label small mb-1" for="suspendReason">Suspend reason</label>
                                <textarea name="reason" id="suspendReason" class="form-control form-control-sm mb-2" rows="2" required minlength="5" maxlength="500" placeholder="Why this account is being locked"></textarea>
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                    <i class="fa fa-ban me-1"></i> Suspend account
                                </button>
                            </form>
                        @elseif($canSuspend && $user->isSuspended())
                            <form method="POST" action="{{ route('admin.users.unsuspend', $user) }}" class="js-unsuspend-form">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success w-100">
                                    <i class="fa fa-unlock me-1"></i> Reactivate account
                                </button>
                            </form>
                        @endif
                        <a href="{{ route('admin.finance.user', $user) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-coins me-1"></i> Finance dossier
                        </a>
                        <a href="{{ route('admin.content-library.index', ['user_id' => $user->id]) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-folder-open me-1"></i> Articles
                        </a>
                        <a href="{{ $related['catalog_url'] ?? route('admin.catalog-activity.show', $user) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-eye me-1"></i> Catalog activity
                        </a>
                        <a href="{{ $related['sites_url'] ?? staff_route('sites.index', ['publisher' => $user->id]) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-globe me-1"></i> Sites list
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Advertiser wallet</div>
                    <div class="fs-5 fw-bold">{{ $euro($adv?->balance ?? 0) }}</div>
                    <div class="small text-muted">Bonus {{ $euro($adv?->bonus_balance ?? 0) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Publisher wallet</div>
                    <div class="fs-5 fw-bold">{{ $euro($pub?->balance ?? 0) }}</div>
                    <div class="small text-muted">Open withdrawals {{ $euro($t['withdrawals_open_net'] ?? 0) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Paid GMV</div>
                    <div class="fs-5 fw-bold">{{ $euro($t['current_paid_gmv'] ?? 0) }}</div>
                    <div class="small text-muted">{{ (int) ($t['paid_orders_count'] ?? 0) }} paid orders</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Publisher earnings</div>
                    <div class="fs-5 fw-bold">{{ $euro($t['earnings_as_publisher'] ?? 0) }}</div>
                    <div class="small text-muted">Paid out {{ $euro($t['withdrawals_paid_net'] ?? 0) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Sites</strong>
                    <a href="{{ $related['sites_url'] ?? staff_route('sites.index', ['publisher' => $user->id]) }}" class="small">
                        {{ (int) ($related['sites_count'] ?? $sites->count()) }} on Sites
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Site</th>
                                    <th>Status</th>
                                    <th class="text-end">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($sites as $site)
                                <tr>
                                    <td>
                                        <a href="{{ \App\Support\CatalogProblemReport::staffListingUrl($site) ?: staff_route('sites.index', array_filter(['publisher' => $site->publisher_id, 'site' => $site->id])) }}">{{ $site->site_name ?: $site->domain }}</a>
                                        <div class="small text-muted">{{ $site->domain }}</div>
                                    </td>
                                    <td>
                                        @if($site->verified)
                                            <span class="badge text-bg-success">Verified</span>
                                        @else
                                            <span class="badge text-bg-warning text-dark">Unverified</span>
                                        @endif
                                        @if($site->active)
                                            <span class="badge text-bg-primary">Live</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $euro($site->price) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">No websites.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Recent orders</strong>
                    <a href="{{ route('admin.orders.index', ['search' => $user->email]) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Status</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($orders as $order)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.orders.show', $order) }}">{{ $order->order_number }}</a>
                                        <div class="small text-muted">{{ $order->created_at?->format('d M Y') }}</div>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-light border">{{ $order->status }}</span>
                                        <span class="badge text-bg-light border">{{ $order->payment_status }}</span>
                                    </td>
                                    <td class="text-end">{{ $euro($order->total_amount) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">No orders.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Withdrawals</strong>
                    <a href="{{ route('admin.withdrawals', ['search' => $user->email]) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Status</th>
                                    <th class="text-end">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($withdrawals as $withdrawal)
                                <tr>
                                    <td><a href="{{ route('admin.withdrawals.show', $withdrawal->id) }}">WD-{{ $withdrawal->id }}</a></td>
                                    <td>{{ $withdrawal->status }}</td>
                                    <td class="text-end">{{ $euro($withdrawal->net_amount) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">No withdrawals.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Deposits</strong>
                    <a href="{{ route('admin.deposits', ['search' => $user->email]) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Status</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($deposits as $deposit)
                                <tr>
                                    <td><a href="{{ route('admin.deposits.show', $deposit->id) }}">{{ $deposit->reference_code ?: '#'.$deposit->id }}</a></td>
                                    <td>{{ $deposit->status }}</td>
                                    <td class="text-end">{{ $euro($deposit->amount) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">No deposits.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><strong>Related inboxes</strong></div>
        <div class="card-body d-flex flex-wrap gap-3">
            <a href="{{ $related['problems_url'] ?? route('admin.community.index', ['tab' => 'problems', 'q' => $user->email]) }}">Problems ({{ (int) ($related['problems_count'] ?? 0) }})</a>
            <a href="{{ $related['suggestions_url'] ?? route('admin.community.index', ['tab' => 'suggestions', 'q' => $user->email]) }}">Suggestions ({{ (int) ($related['suggestions_count'] ?? 0) }})</a>
            <a href="{{ $related['websites_url'] ?? route('admin.community.index', ['tab' => 'websites', 'q' => $user->email]) }}">Website requests ({{ (int) ($related['websites_count'] ?? 0) }})</a>
            <a href="{{ $related['claims_url'] ?? route('admin.community.index', ['tab' => 'claims', 'q' => $user->email]) }}">Claims ({{ (int) ($related['claims_count'] ?? 0) }})</a>
            <a href="{{ $related['bulk_url'] ?? route('admin.bulk-site-requests.index', ['q' => $user->email, 'status' => 'all']) }}">Bulk site requests ({{ (int) ($related['bulk_count'] ?? 0) }})</a>
            <a href="{{ $related['catalog_url'] ?? route('admin.catalog-activity.show', $user) }}">Catalog activity</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><strong>Internal notes</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.users.notes.store', $user) }}" class="mb-3">
                        @csrf
                        <label class="form-label small" for="adminNoteBody">Add a note (not visible to the user)</label>
                        <textarea name="body" id="adminNoteBody" class="form-control mb-2" rows="3" required minlength="3" maxlength="2000" placeholder="Support context, fraud flags, payout caveats…"></textarea>
                        <button type="submit" class="btn btn-sm btn-primary">Save note</button>
                    </form>
                    @forelse($notes as $note)
                        <div class="border-bottom pb-2 mb-2">
                            <div class="small text-muted">
                                {{ $note->admin?->name ?: 'Admin' }}
                                · {{ $note->created_at?->format('d M Y H:i') }}
                            </div>
                            <div>{{ $note->body }}</div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No notes yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Activity</strong>
                    <a href="{{ route('admin.activity-logs.index', ['user_id' => $user->id]) }}" class="small">History</a>
                </div>
                <div class="card-body">
                    @forelse($activities as $activity)
                        <div class="mb-2 pb-2 border-bottom">
                            <div class="fw-semibold small">{{ $activity->action }}</div>
                            <div>{{ $activity->description }}</div>
                            <div class="small text-muted">{{ $activity->created_at?->format('d M Y H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No activity logged yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
<div class="d-none main-row" data-id="{{ $user->id }}" data-name="{{ $user->name }}" data-roles="{{ implode(',', $userRoleNames) }}"></div>
<script>
const ROLE_UPDATE_URL = @json(route('admin.users.updateRoles', ['id' => '__ID__']));
const COMPANY_UPDATE_URL = @json(route('admin.users.updateCompany', ['id' => '__ID__']));
const PAYOUT_UPDATE_URL = @json(route('admin.users.updatePayoutProfile', ['id' => '__ID__']));
function roleUpdateUrl(id) {
    return ROLE_UPDATE_URL.replace('__ID__', encodeURIComponent(String(id)));
}
function companyUpdateUrl(id) {
    return COMPANY_UPDATE_URL.replace('__ID__', encodeURIComponent(String(id)));
}
function payoutUpdateUrl(id) {
    return PAYOUT_UPDATE_URL.replace('__ID__', encodeURIComponent(String(id)));
}
function escapeHtml(str) {
    if (str == null || str === '') return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
let marketingSeatsUsed = {{ (int) ($marketingCount ?? 0) }};
const MARKETING_SEATS_MAX = {{ (int) ($maxMarketing ?? 5) }};

document.addEventListener('click', function (e) {
    const rolesBtn = e.target.closest('.action-roles');
    if (rolesBtn) {
        e.preventDefault();
        const id = rolesBtn.dataset.id;
        const row = document.querySelector('.main-row[data-id="'+id+'"]');
        const name = row?.dataset.name || 'user';
        const current = (row?.dataset.roles || '').split(',').filter(Boolean);
        const hasMarketing = current.includes('marketing');
        const seatsFull = !hasMarketing && marketingSeatsUsed >= MARKETING_SEATS_MAX;
        Swal.fire({
            title: 'Marketing Access',
            html: `
                <p class="text-muted mb-3" style="font-size:14px;">
                    Grant or revoke <strong>Marketing</strong> for <strong>${escapeHtml(name)}</strong>
                    (${marketingSeatsUsed}/${MARKETING_SEATS_MAX} seats used).
                </p>
                ${seatsFull ? `<div class="alert alert-warning py-2 px-3 text-start mb-3">All seats are taken.</div>` : ''}
                <label class="d-flex align-items-center gap-2 border rounded p-3 text-start">
                    <input type="checkbox" class="form-check-input mt-0" id="marketingToggle" ${hasMarketing ? 'checked' : ''} ${seatsFull ? 'disabled' : ''}>
                    <span>Marketing team member</span>
                </label>`,
            showCancelButton: true,
            confirmButtonText: seatsFull ? 'Close' : 'Save',
            preConfirm: () => {
                if (seatsFull) return { skip: true };
                return { skip: false, marketing: !!document.getElementById('marketingToggle')?.checked };
            }
        }).then((result) => {
            if (!result.isConfirmed || !result.value || result.value.skip) return;
            if (!!result.value.marketing === hasMarketing) {
                Swal.fire({ icon: 'info', title: 'No change', text: 'Marketing permissions are already set this way.' });
                return;
            }
            fetch(roleUpdateUrl(id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                },
                credentials: 'same-origin',
                body: JSON.stringify({ marketing: !!result.value.marketing })
            }).then(async (res) => {
                let data = null;
                try { data = await res.json(); } catch (err) { data = null; }
                if (res.ok && data && data.success) {
                    if (row) row.dataset.roles = (data.roles || []).join(',');
                    if (typeof data.marketing_count === 'number') marketingSeatsUsed = data.marketing_count;
                    Swal.fire({ icon: 'success', title: 'Updated!', text: data.message || 'Saved.' }).then(() => window.location.reload());
                    return;
                }
                Swal.fire({ icon: 'error', title: 'Error!', text: (data && data.message) || 'Something went wrong.' });
            }).catch(() => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }));
        });
        return;
    }

    const editBtn = e.target.closest('.btn-edit-company');
    if (editBtn) {
        const id = editBtn.dataset.id;
        const span = document.querySelector('.company-text[data-id="'+id+'"]');
        const current = span && span.innerText.trim() !== '—' ? span.innerText.trim() : '';
        Swal.fire({ title: 'Edit Company', input: 'text', inputValue: current, showCancelButton: true, confirmButtonText: 'Update' })
            .then((result) => {
                if (!result.isConfirmed) return;
                fetch(companyUpdateUrl(id), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ company_name: result.value })
                }).then(async (res) => {
                    let data = null;
                    try { data = await res.json(); } catch (err) { data = null; }
                    if (res.ok && data && data.success) {
                        if (span) span.innerText = result.value || '—';
                        Swal.fire('Updated!', data.message || '', 'success');
                    } else {
                        Swal.fire('Error!', (data && data.message) || 'Update failed', 'error');
                    }
                }).catch(() => Swal.fire('Error!', 'Request failed.', 'error'));
            });
        return;
    }

    const payoutBtn = e.target.closest('.btn-edit-payout');
    if (payoutBtn) {
        const id = payoutBtn.dataset.id;
        const method = payoutBtn.dataset.method || 'paypal';
        Swal.fire({
            title: 'Edit payout details',
            html: `
                <select id="swalMethod" class="swal2-input">
                    <option value="paypal" ${method==='paypal'?'selected':''}>PayPal</option>
                    <option value="wise" ${method==='wise'?'selected':''}>Wise</option>
                    <option value="bank" ${method==='bank'?'selected':''}>Bank</option>
                    <option value="crypto" ${method==='crypto'?'selected':''}>Crypto</option>
                </select>
                <input id="swalPaypal" class="swal2-input" placeholder="PayPal email" value="${escapeHtml(payoutBtn.dataset.paypal || '')}">
                <input id="swalWise" class="swal2-input" placeholder="Wise email" value="${escapeHtml(payoutBtn.dataset.wise || '')}">
                <input id="swalBankName" class="swal2-input" placeholder="Bank name" value="${escapeHtml(payoutBtn.dataset.bankName || '')}">
                <input id="swalHolder" class="swal2-input" placeholder="Account holder" value="${escapeHtml(payoutBtn.dataset.holder || '')}">
                <input id="swalAccount" class="swal2-input" placeholder="IBAN / account" value="${escapeHtml(payoutBtn.dataset.account || '')}">
                <input id="swalSwift" class="swal2-input" placeholder="SWIFT (optional)" value="${escapeHtml(payoutBtn.dataset.swift || '')}">
                <input id="swalCryptoType" class="swal2-input" placeholder="Crypto type" value="${escapeHtml(payoutBtn.dataset.cryptoType || 'USDT')}">
                <input id="swalWallet" class="swal2-input" placeholder="Wallet address" value="${escapeHtml(payoutBtn.dataset.wallet || '')}">
            `,
            showCancelButton: true,
            confirmButtonText: 'Update & notify',
            preConfirm: () => ({
                payment_method: document.getElementById('swalMethod').value,
                paypal_email: document.getElementById('swalPaypal').value,
                wise_email: document.getElementById('swalWise').value,
                bank_name: document.getElementById('swalBankName').value,
                account_holder: document.getElementById('swalHolder').value,
                account_number: document.getElementById('swalAccount').value,
                swift_code: document.getElementById('swalSwift').value,
                crypto_type: document.getElementById('swalCryptoType').value,
                wallet_address: document.getElementById('swalWallet').value,
            })
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(payoutUpdateUrl(id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                },
                credentials: 'same-origin',
                body: JSON.stringify(result.value)
            }).then(async (res) => {
                let data = null;
                try { data = await res.json(); } catch (err) { data = null; }
                if (res.ok && data && data.success) {
                    Swal.fire('Updated!', data.message, 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Error', (data && data.message) || 'Update failed', 'error');
                }
            }).catch(() => Swal.fire('Error', 'Network error', 'error'));
        });
    }
});

document.querySelector('.js-suspend-form')?.addEventListener('submit', async function (e) {
    if (typeof slbConfirm !== 'function') return;
    e.preventDefault();
    const form = this;
    const ok = await slbConfirm({
        title: 'Suspend this account?',
        text: 'They will be signed out and cannot log in until you reactivate them.',
        confirmText: 'Suspend',
        danger: true,
    });
    if (ok) form.submit();
});
document.querySelector('.js-unsuspend-form')?.addEventListener('submit', async function (e) {
    if (typeof slbConfirm !== 'function') return;
    e.preventDefault();
    const form = this;
    const ok = await slbConfirm({
        title: 'Reactivate this account?',
        text: 'They will be able to sign in again.',
        confirmText: 'Reactivate',
    });
    if (ok) form.submit();
});
</script>
@endsection
