@extends('admin.layouts.app')

@section('content')
@php
    $rawDetails = \App\Models\Withdrawal::detailsArray($withdrawal->payment_details);
    $detail = fn (string $key) => \App\Models\Withdrawal::detailText($rawDetails, $key);
    $detailOrNa = fn (string $key) => ($value = $detail($key)) !== '' ? $value : 'N/A';
    $adminStatus = match ($withdrawal->status) {
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Paid',
        'cancelled' => 'Rejected',
        default => ucfirst((string) $withdrawal->status),
    };
    $statusClass = match ($withdrawal->status) {
        'pending' => 'status-pending',
        'processing' => 'status-processing',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled',
        default => 'status-pending',
    };
    $queue = in_array($withdrawal->status, ['completed', 'cancelled'], true) ? 'history' : 'open';
    $duplicateIds = is_array($withdrawal->duplicate_match_ids) ? $withdrawal->duplicate_match_ids : [];
    $lookbackDays = max(1, (int) config('billing.withdrawal_mark_paid_duplicate_lookback_days', 30));
@endphp
<div class="container-fluid py-3">
    <div class="mb-3">
        <a href="{{ route('admin.withdrawals', ['search' => (string) $withdrawal->id, 'queue' => $queue], false) }}"
           class="small text-muted text-decoration-none">
            <i class="fa fa-arrow-left me-1"></i> Back to payout queue
        </a>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">
                <span class="admin-id-clamp">WD-{{ $withdrawal->id }}</span>
            </h4>
            <p class="text-muted mb-0 small">
                <span class="status-badge {{ $statusClass }}">{{ $adminStatus }}</span>
                · requested {{ optional($withdrawal->created_at)->format('M j, Y g:i A') ?: '—' }}
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($withdrawal->user_id)
                <a href="{{ route('admin.finance.user', $withdrawal->user_id, false) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-user me-1"></i> Open publisher / edit payout
                </a>
            @endif
            @if(!empty($invoiceUrl))
                <a href="{{ $invoiceUrl }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-file-invoice-dollar me-1"></i> Open invoice
                </a>
            @endif
        </div>
    </div>

    @if($withdrawal->status === 'completed' && empty($invoiceUrl))
        <div class="alert alert-warning" role="alert">
            Payout statement is missing. Open the payout queue and choose <strong>Create statement</strong> for
            <span class="admin-id-clamp">WD-{{ $withdrawal->id }}</span>.
        </div>
    @endif

    @if($withdrawal->possible_duplicate && $duplicateIds !== [])
        <div class="alert alert-warning" role="alert">
            Same publisher was paid this net amount in the last {{ $lookbackDays }} days
            ({{ collect($duplicateIds)->map(fn ($id) => 'WD-'.$id)->implode(', ') }}).
            Confirm you are not paying twice.
        </div>
    @endif

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-3">Publisher</h6>
                    <p class="mb-1"><strong>Name:</strong> {{ $withdrawal->user?->name ?: 'N/A' }}</p>
                    <p class="mb-0"><strong>Email:</strong> {{ $withdrawal->user?->email ?: 'N/A' }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-3">Amounts</h6>
                    <p class="mb-1"><strong>Gross:</strong> €{{ number_format((float) $withdrawal->amount, 2) }}</p>
                    <p class="mb-1"><strong>Fee:</strong> €{{ number_format((float) $withdrawal->fee, 2) }}</p>
                    <p class="mb-0"><strong>Net to pay:</strong> <span class="text-success fw-bold">€{{ number_format((float) $withdrawal->net_amount, 2) }}</span></p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-3">Payout destination ({{ \App\Models\Invoice::paymentMethodLabel($withdrawal->payment_method) }})</h6>
                    @if($withdrawal->payment_method === 'bank')
                        <p class="mb-1"><strong>Bank Name:</strong> {{ $detailOrNa('bank_name') }}</p>
                        <p class="mb-1"><strong>Account Holder:</strong> {{ $detailOrNa('account_holder') }}</p>
                        <p class="mb-1"><strong>Account Number:</strong> {{ $detailOrNa('account_number') }}</p>
                        <p class="mb-0"><strong>SWIFT Code:</strong> {{ $detailOrNa('swift_code') }}</p>
                    @elseif(in_array($withdrawal->payment_method, ['paypal', 'wise'], true))
                        <p class="mb-0"><strong>Email:</strong> {{ $detailOrNa('email') }}</p>
                    @elseif($withdrawal->payment_method === 'crypto')
                        <p class="mb-1"><strong>Cryptocurrency:</strong> {{ $detailOrNa('crypto_type') }}</p>
                        <p class="mb-0"><strong>Wallet Address:</strong> {{ $detailOrNa('wallet_address') }}</p>
                    @else
                        <p class="mb-0 text-muted">{{ $withdrawal->destination_snippet ?: '—' }}</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-3">Request</h6>
                    <p class="mb-1"><strong>Reference:</strong> <span class="admin-id-clamp">WD-{{ $withdrawal->id }}</span></p>
                    <p class="mb-1"><strong>Status:</strong> <span class="status-badge {{ $statusClass }}">{{ $adminStatus }}</span></p>
                    @if($withdrawal->waiting_days !== null)
                        <p class="mb-1"><strong>Waiting:</strong> {{ $withdrawal->waiting_days }}d</p>
                    @endif
                    @if($withdrawal->processed_at)
                        <p class="mb-0"><strong>Processed:</strong> {{ $withdrawal->processed_at->format('M j, Y g:i A') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($withdrawal->admin_notes)
        <div class="alert alert-secondary mt-3 mb-0"><strong>Admin notes:</strong> {{ $withdrawal->admin_notes }}</div>
    @endif
</div>
@endsection
