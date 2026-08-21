@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    <h1 class="h3 mb-4">User Management</h1>
    <p class="text-muted mb-3">
        Regular users get <strong>Advertiser</strong> + <strong>Publisher</strong> at registration.
        Only <strong>admins</strong> can grant or revoke <strong>Marketing</strong> (max {{ $maxMarketing ?? 5 }} people).
        Admin is limited to {{ $adminCount ?? 0 }}/{{ \App\Http\Controllers\Admin\UserController::MAX_ADMINS }} accounts and is not assignable here.
    </p>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge text-bg-light border px-3 py-2" id="marketingSeatsBadge">
            Marketing seats: <strong id="marketingSeatsCount">{{ (int) ($marketingCount ?? 0) }}</strong>/{{ (int) ($maxMarketing ?? 5) }}
        </span>
        <span class="badge text-bg-light border px-3 py-2">
            Admin seats: <strong>{{ (int) ($adminCount ?? 0) }}</strong>/{{ \App\Http\Controllers\Admin\UserController::MAX_ADMINS }}
        </span>
    </div>





<!-- SEARCH -->
<div class="mb-3 d-flex flex-wrap align-items-center gap-2" style="max-width: 520px;">
    <div class="flex-grow-1" style="max-width: 400px;">
        <x-slb-search-field name="user_search" id="userSearch" placeholder="Search users (name, email, company…)" input-class="form-control" mode="" />
    </div>
    @if(request()->integer('user') > 0)
        <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">All users</a>
    @endif
</div>

<div class="table-responsive admin-table-fit">

<table class="table table-striped modern-table">
    <thead>
        <tr>
            <th class="admin-num-col">#</th>
            <th class="admin-col-identity">Name</th>
            <th class="admin-col-email">Email</th>
            <th class="admin-narrow-col">Phone</th>
            <th class="admin-narrow-col">Country</th>
            <th class="admin-col-start">Role</th>
            <th class="admin-narrow-col">Joined</th>
            <th class="admin-actions-col">Actions</th>
        </tr>
    </thead>

    <tbody>
    @forelse($users as $index => $user)

    @php
        $userRoleNames = $user->roles->pluck('name')->all();
        $activeRoleName = $user->activeRole();
        $paidOrdersCount = (int) ($user->paid_orders_count ?? 0);
        $paidOrdersTotal = (float) ($user->paid_orders_total ?? 0);
        $isRepeatBuyer = $paidOrdersCount > 1;
        $isHighSpender = $paidOrdersTotal >= 1000;
        $isHighlighted = $isRepeatBuyer || $isHighSpender;
    @endphp

    <tr class="main-row {{ $isHighlighted ? 'user-highlight-row' : '' }}" id="user-{{ $user->id }}" data-id="{{ $user->id }}"
        data-name="{{ $user->name }}"
        data-roles="{{ implode(',', $userRoleNames) }}"
        data-active-role="{{ $activeRoleName }}"
        data-paid-orders="{{ $paidOrdersCount }}"
        data-paid-gmv="{{ number_format($paidOrdersTotal, 2, '.', '') }}">

        <td>
            {{ $users->firstItem() + $index }}
            {{-- Must live inside a cell: a bare input under <tr> is invalid and browsers relocate it. --}}
            <input type="hidden" class="role-id" value="{{ $user->active_role_id }}">
        </td>
        <td class="admin-col-identity">
            <div class="user-name-cell">
                <span>{{ $user->name }}</span>
                @if($isRepeatBuyer || $isHighSpender)
                    <div class="d-flex flex-wrap gap-1">
                        @if($isRepeatBuyer)
                            <span class="badge user-value-badge user-value-badge--repeat"
                                  title="{{ $paidOrdersCount }} paid orders">Repeat</span>
                        @endif
                        @if($isHighSpender)
                            <span class="badge user-value-badge user-value-badge--spender"
                                  title="Paid GMV €{{ number_format($paidOrdersTotal, 2) }}">€1k+</span>
                        @endif
                    </div>
                @endif
            </div>
        </td>
        <td class="admin-col-email">{{ $user->email }}</td>
        <td class="admin-narrow-col">{{ $user->phone ?? '-' }}</td>
        <td class="admin-narrow-col">{{ $user->country ?? '-' }}</td>
        <td class="admin-col-start">
            <div class="role-badges admin-role-badges" data-id="{{ $user->id }}">
                @forelse($userRoleNames as $roleName)
                    <span class="badge {{ $roleName === $activeRoleName ? 'bg-primary' : 'bg-secondary' }} text-capitalize"
                          title="{{ $roleName === $activeRoleName ? 'Active role' : 'Assigned role' }}">
                        {{ $roleName }}
                        @if($roleName === $activeRoleName)
                            <i class="fa fa-circle-check ms-1"></i>
                        @endif
                    </span>
                @empty
                    <span class="badge bg-light text-dark">No role</span>
                @endforelse
            </div>
        </td>
        <td>{{ $user->created_at ? $user->created_at->format('d M Y') : '-' }}</td>

        <td>
            <div class="dropdown admin-manage-dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    Manage
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <button type="button" class="dropdown-item action-view" data-id="{{ $user->id }}">
                            <i class="fa fa-eye me-2"></i><span class="btn-text">View</span>
                        </button>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ route('admin.finance.user', $user) }}">
                            <i class="fa fa-coins me-2"></i>Finance
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ route('admin.content-library.index', ['user_id' => $user->id]) }}">
                            <i class="fa fa-folder-open me-2"></i>Articles
                        </a>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item action-roles" data-id="{{ $user->id }}">
                            <i class="fa fa-bullhorn me-2"></i><span class="btn-text">Marketing</span>
                        </button>
                    </li>
                </ul>
            </div>
        </td>
    </tr>

<tr class="expand-row" id="expand-{{ $user->id }}">
    <td colspan="8">
        <div class="expand-box">

            <div class="row text-start">

                <div class="col-md-4">
                    <strong>User Info:</strong>

                    <div class="detail-line">
                        <strong>Full Name:</strong> {{ $user->name }}
                    </div>

                    <div class="detail-line">
                        <strong>Email:</strong> {{ $user->email }}
                    </div>

                    <div class="detail-line">
                        <strong>Phone:</strong> {{ $user->phone ?? '-' }}
                    </div>
                </div>

                <div class="col-md-4">
                    <strong>Billing Info:</strong>

                    <div class="detail-line">
                        <strong>Company:</strong> 
                        <span class="company-text" data-id="{{ $user->id }}">
                            {{ $user->company_name ?? '-' }}
                        </span>

                        <button class="btn btn-sm btn-link text-primary p-0 ms-2 btn-edit-company" data-id="{{ $user->id }}">
                            Edit
                        </button>
                    </div>

                    <div class="detail-line">
                        <strong>Payout:</strong>
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
                            Edit payout
                        </button>
                    </div>

                    <div class="detail-line">
                        <strong>Billing Name:</strong> {{ $user->billing_name ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>VAT Number:</strong> {{ $user->vat_number ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>Country:</strong> {{ $user->country ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>State:</strong> {{ $user->state ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>City:</strong> {{ $user->city ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>Address:</strong> {{ $user->address ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>Postal Code:</strong> {{ $user->postal_code ?? '-' }}
                    </div>
                </div>

                <div class="col-md-4 mt-3 text-start">
                    <strong>Social Profiles:</strong><br>

                    @if($user->facebook)
                        <a href="{{ $user->facebook }}" target="_blank" class="me-2 badge bg-primary">Facebook</a>
                    @endif

                    @if($user->twitter)
                        <a href="{{ $user->twitter }}" target="_blank" class="me-2 badge bg-info">Twitter</a>
                    @endif

                    @if($user->linkedin)
                        <a href="{{ $user->linkedin }}" target="_blank" class="me-2 badge bg-dark">LinkedIn</a>
                    @endif

                    @if(!$user->facebook && !$user->twitter && !$user->linkedin)
                        <span class="text-muted">No social profiles</span>
                    @endif
                </div>

            </div>

            <div class="mt-3 text-start">
                <div class="detail-line">
                    <strong>Joined:</strong> {{ $user->created_at ? $user->created_at->format('d M Y, h:i A') : '-' }}
                </div>

                <div class="detail-line">
                    <strong>Last Updated:</strong> {{ $user->updated_at ? $user->updated_at->format('d M Y, h:i A') : '-' }}
                </div>
            </div>

        </div>
    </td>
</tr>

    @empty
    <tr>
        <td colspan="8" class="text-center text-muted">
            No users found.
        </td>
    </tr>
    @endforelse
    </tbody>
</table>

</div>

<div class="mt-3">
    {{ $users->links() }}
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

function refreshMarketingSeatsBadge(count) {
    if (typeof count === 'number') {
        marketingSeatsUsed = count;
    }
    const el = document.getElementById('marketingSeatsCount');
    if (el) el.textContent = String(marketingSeatsUsed);
}

document.addEventListener('click', function(e){

    // ✅ Grant / revoke Marketing for team members only (admin-only endpoint)
    const rolesBtn = e.target.closest('.action-roles');
    if(rolesBtn){
        e.preventDefault();
        e.stopPropagation();

        const id  = rolesBtn.dataset.id;
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
                    <br><small>Advertiser &amp; Publisher stay on the account. Granting Marketing switches their active workspace to Marketing (admins stay in Admin).</small>
                </p>
                ${seatsFull ? `<div class="alert alert-warning py-2 px-3 text-start mb-3" style="font-size:13px;">
                    All ${MARKETING_SEATS_MAX} Marketing seats are taken. Revoke someone else first before granting access.
                </div>` : ''}
                <label for="marketingToggle" class="d-flex align-items-center gap-2 border rounded p-3 text-start mb-2 ${seatsFull ? 'opacity-75' : ''}" style="cursor:${seatsFull ? 'not-allowed' : 'pointer'}; user-select:none;">
                    <input type="checkbox" class="form-check-input mt-0" id="marketingToggle"
                           ${hasMarketing ? 'checked' : ''} ${seatsFull ? 'disabled' : ''}>
                    <span>
                        <span class="fw-semibold">Marketing team member</span><br>
                        <small class="text-muted">Can review and activate sites in the marketing panel — no payments or orders. The Verify button stays admin-only; Activate also verifies review-ready listings.</small>
                    </span>
                </label>`,
            showCancelButton: true,
            confirmButtonText: seatsFull ? 'Close' : 'Save',
            cancelButtonText: 'Cancel',
            customClass: { confirmButton: seatsFull ? 'slb-swal-muted' : '' },
            focusConfirm: false,
            allowOutsideClick: () => !Swal.isLoading(),
            didOpen: () => {
                const toggle = document.getElementById('marketingToggle');
                if (!toggle || seatsFull) return;
                toggle.addEventListener('click', (ev) => ev.stopPropagation());
                const label = toggle.closest('label');
                if (label) label.addEventListener('click', (ev) => ev.stopPropagation());
            },
            preConfirm: () => {
                if (seatsFull) {
                    return { skip: true, marketing: hasMarketing };
                }
                const toggle = document.getElementById('marketingToggle');
                if (!toggle) {
                    Swal.showValidationMessage('Could not read the Marketing checkbox. Please try again.');
                    return false;
                }
                return {
                    skip: false,
                    marketing: !!toggle.checked,
                };
            }
        }).then((result) => {
            if (!result.isConfirmed || !result.value || result.value.skip) return;

            const wantMarketing = !!result.value.marketing;
            if (wantMarketing === hasMarketing) {
                Swal.fire({
                    icon: 'info',
                    title: 'No change',
                    text: 'Marketing permissions are already set this way.',
                });
                return;
            }

            Swal.fire({
                title: 'Saving Marketing access…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            fetch(roleUpdateUrl(id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                        || '{{ csrf_token() }}'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    marketing: wantMarketing,
                })
            })
            .then(async (res) => {
                let data = null;
                const text = await res.text();
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (err) {
                    data = null;
                }
                return { ok: res.ok, status: res.status, data };
            })
            .then(({ ok, status, data }) => {
                if (ok && data && data.success) {
                    updateRoleBadges(id, data.roles, data.active_role);
                    if (row) {
                        row.dataset.roles = (data.roles || []).join(',');
                    }
                    if (typeof data.marketing_count === 'number') {
                        refreshMarketingSeatsBadge(data.marketing_count);
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: data.message || 'Marketing access saved.',
                    });
                    return;
                }

                const message = (data && data.message)
                    || (status === 403 ? 'Only an admin can change Marketing access.' : null)
                    || (status === 419 ? 'Session expired. Refresh the page and try again.' : null)
                    || 'Something went wrong.';
                Swal.fire({ icon: 'error', title: 'Error!', text: message,});
            })
            .catch(() => Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Request failed. Please try again.',
            }));
        });

        return;
    }

    const viewBtn = e.target.closest('.action-view');
    if(viewBtn){
        e.stopPropagation();

        let id = viewBtn.dataset.id;
        let expandRow = document.getElementById('expand-' + id);

        if(!expandRow) return;

        expandRow.classList.toggle('expanded');

        let icon = viewBtn.querySelector('i');
        let text = viewBtn.querySelector('.btn-text');

        if (expandRow.classList.contains('expanded')) {
            icon.classList.replace('fa-eye', 'fa-eye-slash');
            if(text) text.textContent = 'Hide';
        } else {
            icon.classList.replace('fa-eye-slash', 'fa-eye');
            if(text) text.textContent = 'View';
        }
    }

    const deleteBtn = e.target.closest('.btn-delete');
    if(deleteBtn){
        let form = deleteBtn.closest('form');

        Swal.fire({
            title: 'Are you sure?',
            text: "This user will be deleted permanently!",
            icon: 'warning',
            showCancelButton: true,
            customClass: { confirmButton: 'slb-swal-danger' },
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    }

    const editBtn = e.target.closest('.btn-edit-company');
    if(editBtn){
        let id = editBtn.dataset.id;
        let span = document.querySelector('.company-text[data-id="'+id+'"]');
        let current = span.innerText.trim() === '-' ? '' : span.innerText.trim();

        Swal.fire({
            title: 'Edit Company',
            input: 'text',
            inputValue: current,
            showCancelButton: true,
            confirmButtonText: 'Update'
        }).then((result) => {
            if(result.isConfirmed){

                fetch(companyUpdateUrl(id), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                            || '{{ csrf_token() }}'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        company_name: result.value
                    })
                })
                .then(async (res) => {
                    let data = null;
                    try { data = await res.json(); } catch (err) { data = null; }
                    return { ok: res.ok, data };
                })
                .then(({ ok, data }) => {
                    if(ok && data && data.success){
                        span.innerText = result.value || '-';
                        Swal.fire('Updated!', data.message || '', 'success');
                    } else {
                        Swal.fire('Error!', (data && data.message) || 'Update failed', 'error');
                    }
                })
                .catch(() => Swal.fire('Error!', 'Request failed. Please try again.', 'error'));
            }
        });
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
                <input id="swalPaypal" class="swal2-input" placeholder="PayPal email" value="${payoutBtn.dataset.paypal || ''}">
                <input id="swalWise" class="swal2-input" placeholder="Wise email" value="${payoutBtn.dataset.wise || ''}">
                <input id="swalBankName" class="swal2-input" placeholder="Bank name" value="${payoutBtn.dataset.bankName || ''}">
                <input id="swalHolder" class="swal2-input" placeholder="Account holder" value="${payoutBtn.dataset.holder || ''}">
                <input id="swalAccount" class="swal2-input" placeholder="IBAN / account" value="${payoutBtn.dataset.account || ''}">
                <input id="swalSwift" class="swal2-input" placeholder="SWIFT (optional)" value="${payoutBtn.dataset.swift || ''}">
                <input id="swalCryptoType" class="swal2-input" placeholder="Crypto type (BTC/ETH/USDT/BNB)" value="${payoutBtn.dataset.cryptoType || 'USDT'}">
                <input id="swalWallet" class="swal2-input" placeholder="Wallet address" value="${payoutBtn.dataset.wallet || ''}">
                <p class="small text-muted mt-2">Publisher will be emailed after you save.</p>
            `,
            showCancelButton: true,
            confirmButtonText: 'Update & notify',
            width: 520,
            preConfirm: () => {
                return {
                    payment_method: document.getElementById('swalMethod').value,
                    paypal_email: document.getElementById('swalPaypal').value,
                    wise_email: document.getElementById('swalWise').value,
                    bank_name: document.getElementById('swalBankName').value,
                    account_holder: document.getElementById('swalHolder').value,
                    account_number: document.getElementById('swalAccount').value,
                    swift_code: document.getElementById('swalSwift').value,
                    crypto_type: document.getElementById('swalCryptoType').value,
                    wallet_address: document.getElementById('swalWallet').value,
                };
            }
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(payoutUpdateUrl(id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                        || '{{ csrf_token() }}'
                },
                credentials: 'same-origin',
                body: JSON.stringify(result.value)
            })
            .then(async (res) => {
                let data = null;
                try { data = await res.json(); } catch (err) { data = null; }
                return { ok: res.ok, data };
            })
            .then(({ ok, data }) => {
                if (ok && data && data.success) {
                    Swal.fire('Updated!', data.message, 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Error', (data && data.message) || 'Update failed', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error', 'error'));
        });
    }

});

// Re-render the role badges for a user after an update (no reload needed)
function updateRoleBadges(id, roles, activeRole){
    const container = document.querySelector('.role-badges[data-id="'+id+'"]');
    if(!container) return;

    if(!roles || roles.length === 0){
        container.innerHTML = '<span class="badge bg-light text-dark">No role</span>';
        return;
    }

    container.innerHTML = roles.map(name => {
        const isActive = name === activeRole;
        const cls = isActive ? 'bg-primary' : 'bg-secondary';
        const check = isActive ? ' <i class="fa fa-circle-check ms-1"></i>' : '';
        const title = isActive ? 'Active role' : 'Assigned role';
        return `<span class="badge ${cls} text-capitalize" title="${title}">${escapeHtml(name)}${check}</span>`;
    }).join(' ');
}

// SEARCH (Catalog-parity live search)
(function initAdminUsersLiveSearch() {
    function filterUsers(query) {
        var value = String(query || '').toLowerCase();
        document.querySelectorAll('tbody tr.main-row').forEach(function (row) {
            var text = row.innerText.toLowerCase();
            var id = row.dataset.id;
            var expandRow = document.getElementById('expand-' + id);
            if (text.includes(value)) {
                row.style.display = '';
                if (expandRow) expandRow.style.display = '';
            } else {
                row.style.display = 'none';
                if (expandRow) expandRow.style.display = 'none';
            }
        });
    }

    function boot() {
        if (typeof window.SlbLiveSearch !== 'undefined') {
            window.SlbLiveSearch.init(document.getElementById('userSearch'), {
                mode: 'client',
                statusEl: document.getElementById('userSearchStatus'),
                clearBtn: document.getElementById('userSearchClear'),
                onSearch: function (detail) { filterUsers(detail.query); },
            });
            return;
        }
        document.getElementById('userSearch').addEventListener('keyup', function () {
            filterUsers(this.value);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

// Deep-link from Orders / finance: /admin/users?user={id}#user-{id}
(function openUserFromHash() {
    const hash = window.location.hash || '';
    const match = hash.match(/^#user-(\d+)$/);
    if (!match) return;
    const row = document.getElementById('user-' + match[1]);
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.classList.add('table-warning');
    const expand = document.getElementById('expand-' + match[1]);
    if (expand && expand.style.display === 'none') {
        row.click();
    }
})();
</script>

@endsection 