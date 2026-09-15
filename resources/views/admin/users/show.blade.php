@extends('admin.layouts.app')

@section('content')
@php
    $t = $dossier['totals'] ?? [];
    $euro = fn ($n) => '€'.number_format((float) $n, 2);
    $adv = $dossier['advertiser_wallet'] ?? null;
    $pub = $dossier['publisher_wallet'] ?? null;
    $orders = $dossier['orders'] ?? collect();
    $withdrawals = $dossier['withdrawals'] ?? collect();
    $deposits = $dossier['deposits'] ?? collect();
    $roles = $user->roles->pluck('name')->all();
    $canSuspend = ! $user->hasRole('admin') && (int) $user->id !== (int) auth()->id();
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
        @php
            $staffTwoFactor = app(\App\Services\Auth\StaffTwoFactorService::class);
            $targetHasStaffTwoFactor = $staffTwoFactor->holdsStaffRole($user) && $staffTwoFactor->isConfirmed($user);
        @endphp
        @if($staffTwoFactor->holdsStaffRole($user))
            <span class="badge {{ $targetHasStaffTwoFactor ? 'text-bg-success' : 'text-bg-secondary' }}">
                2FA {{ $targetHasStaffTwoFactor ? 'on' : 'off' }}
            </span>
        @endif
        @if($user->isOnline())
            <span class="badge text-bg-success">Online</span>
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
                            <div>{{ $user->company_name ?: '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Joined</div>
                            <div>{{ $user->created_at?->format('d M Y, H:i') ?: '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Last activity</div>
                            <div>{{ $user->lastSeenLabel() ?: 'Never recorded' }}</div>
                        </div>
                        @if($user->isSuspended())
                            <div class="col-12">
                                <div class="alert alert-danger mb-0 py-2">
                                    <strong>Suspended</strong>
                                    @if($user->suspended_reason)
                                        — {{ $user->suspended_reason }}
                                    @endif
                                    @if($user->suspended_at)
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
                        @if(staff_can('support') && ! $user->hasVerifiedEmail())
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
                        @if(staff_can('support') && $canSuspend && ! $user->isSuspended())
                            <form method="POST" action="{{ route('admin.users.suspend', $user) }}" class="js-suspend-form">
                                @csrf
                                <label class="form-label small mb-1" for="suspendReason">Suspend reason</label>
                                <textarea name="reason" id="suspendReason" class="form-control form-control-sm mb-2" rows="2" required minlength="5" maxlength="500" placeholder="Why this account is being locked"></textarea>
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                    <i class="fa fa-ban me-1"></i> Suspend account
                                </button>
                            </form>
                        @elseif(staff_can('support') && $canSuspend && $user->isSuspended())
                            <form method="POST" action="{{ route('admin.users.unsuspend', $user) }}" class="js-unsuspend-form">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success w-100">
                                    <i class="fa fa-unlock me-1"></i> Reactivate account
                                </button>
                            </form>
                        @endif
                        @if(staff_is_unrestricted() && $targetHasStaffTwoFactor && (int) $user->id !== (int) auth()->id())
                            <form method="POST" action="{{ route('admin.users.two-factor.clear', $user) }}"
                                  data-slb-confirm="Clear two-factor authentication for this staff account? They can sign in with password only until they set it up again."
                                  data-slb-confirm-title="Clear two-factor?"
                                  data-slb-confirm-text="Clear 2FA"
                                  data-slb-confirm-danger="1">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-warning w-100">
                                    <i class="fa fa-shield-alt me-1"></i> Clear two-factor
                                </button>
                            </form>
                        @endif
                        @if(staff_can('finance'))
                        <a href="{{ route('admin.finance.user', $user) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-coins me-1"></i> Finance dossier
                        </a>
                        @endif
                        @if(staff_can('support'))
                        <a href="{{ route('admin.content-library.index', ['user_id' => $user->id]) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-folder-open me-1"></i> Articles
                        </a>
                        @endif
                        <a href="{{ route('admin.users.index', ['user' => $user->id]) }}#user-{{ $user->id }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-pen me-1"></i> Edit company / payout
                        </a>
                    </div>
                    @if(staff_is_unrestricted() && $user->hasRole('admin') && (int) $user->id !== (int) auth()->id())
                    <hr>
                    <h3 class="h6 mb-2">Admin access</h3>
                    <p class="small text-muted">No overlay is full admin. Limited accounts only get the boxes you tick.</p>
                    <form method="POST" action="{{ route('admin.users.capabilities', $user) }}">
                        @csrf
                        @php
                            $targetUnrestricted = $targetUnrestricted ?? false;
                            $targetCapabilities = $targetCapabilities ?? [];
                        @endphp
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="access" id="accessFull" value="full" @checked($targetUnrestricted)>
                            <label class="form-check-label" for="accessFull">Full admin</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="access" id="accessLimited" value="limited" @checked(! $targetUnrestricted)>
                            <label class="form-check-label" for="accessLimited">Limited</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="capabilities[]" value="finance" id="capFinance" @checked(in_array('finance', $targetCapabilities, true))>
                            <label class="form-check-label" for="capFinance">Finance</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="capabilities[]" value="support" id="capSupport" @checked(in_array('support', $targetCapabilities, true))>
                            <label class="form-check-label" for="capSupport">Support</label>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">Save access</button>
                    </form>
                    @elseif($user->hasRole('admin'))
                    <div class="small text-muted mt-2">Access: {{ !empty($targetUnrestricted) ? 'full admin' : implode(', ', $targetCapabilities ?? []) }}</div>
                    @endif
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
                    <span class="text-muted small">{{ $sites->count() }} shown</span>
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
                                        <a href="{{ route('admin.sites.edit', $site->id) }}">{{ $site->site_name ?: $site->domain }}</a>
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
                                    <td>WD-{{ $withdrawal->id }}</td>
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
                                    <td>{{ $deposit->reference_code ?: '#'.$deposit->id }}</td>
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

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><strong>Internal notes</strong></div>
                <div class="card-body">
                    @if(staff_can('support'))
                    <form method="POST" action="{{ route('admin.users.notes.store', $user) }}" class="mb-3">
                        @csrf
                        <label class="form-label small" for="adminNoteBody">Add a note (not visible to the user)</label>
                        <textarea name="body" id="adminNoteBody" class="form-control mb-2" rows="3" required minlength="3" maxlength="2000" placeholder="Support context, fraud flags, payout caveats…"></textarea>
                        <button type="submit" class="btn btn-sm btn-primary">Save note</button>
                    </form>
                    @endif
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
                    <a href="{{ route('admin.activity-logs.index', ['search' => $user->email]) }}" class="small">History</a>
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
<script>
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
