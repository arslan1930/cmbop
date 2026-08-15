@extends('admin.layouts.app')

@section('content')
@php
    $u = $dossier['user'];
    $t = $dossier['totals'];
    $euro = fn ($n) => '€'.number_format((float) $n, 2);
    $adv = $dossier['advertiser_wallet'];
    $pub = $dossier['publisher_wallet'];
    $paidOrdersCount = (int) ($t['paid_orders_count'] ?? 0);
    $paidGmv = (float) ($t['gmv_as_advertiser'] ?? 0);
    $isRepeatBuyer = $paidOrdersCount > 1;
    $isHighSpender = $paidGmv >= 1000;
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Finance dossier</h4>
            <p class="mb-0">
                <strong>{{ $u->name }}</strong>
                <span class="text-muted">· {{ $u->email }}</span>
                @foreach($dossier['roles'] as $role)
                    <span class="badge bg-light text-dark border text-capitalize ms-1">{{ $role }}</span>
                @endforeach
                @if($isRepeatBuyer)
                    <span class="badge ms-1"
                          style="background:#dff3f4;color:#1a585e;border:1px solid #b9e3e5;"
                          title="{{ $paidOrdersCount }} paid orders">Repeat</span>
                @endif
                @if($isHighSpender)
                    <span class="badge ms-1"
                          style="background:#fff4d6;color:#8a6a12;border:1px solid #f0d789;"
                          title="Paid GMV {{ $euro($paidGmv) }}">€1k+</span>
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.users.index', ['user' => $u->id]) }}#user-{{ $u->id }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-user me-1"></i> Users / payout
            </a>
            <a href="{{ route('admin.finance.ledger', ['user_id' => $u->id]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-book me-1"></i> Full ledger
            </a>
            <a href="{{ route('admin.finance') }}" class="btn btn-sm btn-outline-secondary">Finance</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Deposits completed</div>
                    <div class="fs-4 fw-bold">{{ $euro($t['deposits_completed']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">GMV as advertiser</div>
                    <div class="fs-4 fw-bold">{{ $euro($t['gmv_as_advertiser']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Earnings as publisher</div>
                    <div class="fs-4 fw-bold text-success">{{ $euro($t['earnings_as_publisher']) }}</div>
                    <div class="small text-muted">Platform fees on their sites {{ $euro($t['platform_fees_on_their_sites']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Withdrawals paid / open</div>
                    <div class="fs-5 fw-bold">{{ $euro($t['withdrawals_paid_net']) }}</div>
                    <div class="small text-danger">Open {{ $euro($t['withdrawals_open_net']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Advertiser wallet</div>
                <div class="card-body">
                    @if($adv)
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Balance</span><strong>{{ $euro($adv->balance) }}</strong></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Bonus</span><span>{{ $euro($adv->bonus_balance ?? 0) }}</span></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Reserved</span><span>{{ $euro($adv->reserved_balance) }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Withdrawable</span><span>{{ $euro($adv->withdrawableBalance()) }}</span></div>
                    @else
                        <p class="text-muted mb-0">No advertiser wallet</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Publisher wallet &amp; payout</div>
                <div class="card-body">
                    @if($pub)
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Balance</span><strong>{{ $euro($pub->balance) }}</strong></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Withdrawable</span><span>{{ $euro($pub->withdrawableBalance()) }}</span></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Reserved</span><span>{{ $euro($pub->reserved_balance) }}</span></div>
                        <div class="d-flex justify-content-between mb-3 align-items-center">
                            <span class="text-muted">Clawback debt</span>
                            <strong class="{{ $pub->hasDebt() ? 'text-danger' : '' }}">{{ $euro($pub->debtBalance()) }}</strong>
                        </div>
                        @if($pub->hasDebt())
                            <form method="POST" action="{{ route('admin.finance.wallets.clear-debt', $pub) }}" class="mb-3"
                                  data-slb-confirm="Clear all outstanding clawback debt on this wallet?"
                                  data-slb-confirm-title="Clear wallet debt?"
                                  data-slb-confirm-text="Clear debt"
                                  data-slb-confirm-danger="1">
                                @csrf
                                <label class="form-label small text-muted mb-1">Clear debt reason</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="reason" class="form-control" required minlength="5" maxlength="1000"
                                           placeholder="Why is this debt being cleared?">
                                    <button type="submit" class="btn btn-outline-danger">Clear debt</button>
                                </div>
                            </form>
                        @endif
                    @else
                        <p class="text-muted">No publisher wallet</p>
                    @endif
                    @php $profile = $dossier['payout_profile']; @endphp
                    <div class="small">
                        <div class="fw-semibold mb-1">Payout profile {{ $dossier['payout_locked'] ? '(locked)' : '(unlocked)' }}</div>
                        <div class="text-muted">Method: {{ $profile['preferred_method'] ?? '—' }}</div>
                        @if(($profile['preferred_method'] ?? null) === 'paypal')
                            <div class="text-muted">{{ $profile['paypal_email'] ?? '—' }}</div>
                        @elseif(($profile['preferred_method'] ?? null) === 'wise')
                            <div class="text-muted">{{ $profile['wise_email'] ?? '—' }}</div>
                        @elseif(($profile['preferred_method'] ?? null) === 'bank')
                            <div class="text-muted">{{ $profile['bank_holder_name'] ?? '' }} · {{ $profile['bank_account'] ?? '' }}</div>
                        @elseif(($profile['preferred_method'] ?? null) === 'crypto')
                            <div class="text-muted">{{ $profile['crypto_type'] ?? '' }} · {{ $profile['crypto_wallet'] ?? '' }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Recent deposits</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Ref</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse($dossier['deposits'] as $dep)
                                <tr>
                                    <td class="small">
                                        <a href="{{ route('admin.deposits', ['search' => $dep->reference_code]) }}">{{ $dep->reference_code }}</a>
                                    </td>
                                    <td>{{ $euro($dep->amount) }}</td>
                                    <td class="small">{{ $dep->payment_method }}</td>
                                    <td class="small">{{ $dep->status }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">None</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Recent orders (as advertiser)</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Order</th><th>Total</th><th>Pay</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse($dossier['orders'] as $order)
                                <tr>
                                    <td class="small">
                                        <a href="{{ route('admin.orders.show', $order->id) }}">{{ $order->order_number ?? '#'.$order->id }}</a>
                                    </td>
                                    <td>{{ $euro($order->total_amount) }}</td>
                                    <td class="small">{{ $order->payment_method }} / {{ $order->payment_status }}</td>
                                    <td class="small">{{ $order->status }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">None</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Withdrawals</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>ID</th><th>Net</th><th>Method</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse($dossier['withdrawals'] as $w)
                                <tr>
                                    <td class="small">
                                        <a href="{{ route('admin.withdrawals.show', $w->id, false) }}">WD-{{ $w->id }}</a>
                                    </td>
                                    <td>{{ $euro($w->net_amount) }}</td>
                                    <td class="small">{{ $w->payment_method }}</td>
                                    <td class="small">{{ $w->publisher_status_label }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">None</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Recent ledger</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>When</th><th>Type</th><th>Amount</th></tr></thead>
                        <tbody>
                            @forelse($dossier['ledger'] as $tx)
                                <tr>
                                    <td class="small text-muted">{{ $tx->created_at?->format('M d H:i') }}</td>
                                    <td class="small text-capitalize">{{ str_replace('_', ' ', $tx->type) }}</td>
                                    <td class="small {{ $tx->direction === 'credit' ? 'text-success' : 'text-danger' }}">
                                        {{ $tx->direction === 'credit' ? '+' : '-' }}{{ $euro($tx->amount) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">No ledger rows</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
