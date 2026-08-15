@extends('publisher.layouts.app')

@section('content')
<div class="container-fluid" style="max-width:720px;">
    <div class="mb-3">
        <a href="{{ route('publisher.billing.index', [], false) }}" class="btn btn-sm btn-outline-secondary">&larr; All documents</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <h2 class="h4 mb-1">{{ $invoice->invoice_number }}</h2>
                    <p class="text-muted mb-0">{{ $invoice->typeLabel() }} · {{ optional($invoice->invoice_date)->format('M j, Y') }}</p>
                </div>
                <div class="d-inline-flex flex-wrap gap-1">
                    <a href="{{ route('publisher.billing.view', $invoice, false) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">View PDF</a>
                    <a href="{{ route('publisher.billing.download', $invoice, false) }}" class="btn btn-sm btn-primary">Download PDF</a>
                </div>
            </div>

            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted">Reference</dt>
                <dd class="col-sm-8">{{ $invoice->reference_code }}</dd>
                <dt class="col-sm-4 text-muted">Gross</dt>
                <dd class="col-sm-8">€{{ number_format((float) $invoice->subtotal, 2) }}</dd>
                @if((float) $invoice->discount_amount > 0)
                    <dt class="col-sm-4 text-muted">Fee</dt>
                    <dd class="col-sm-8">€{{ number_format((float) $invoice->discount_amount, 2) }}</dd>
                @endif
                <dt class="col-sm-4 text-muted">Net payout</dt>
                <dd class="col-sm-8 fw-semibold">€{{ number_format((float) $invoice->total_amount, 2) }}</dd>
                <dt class="col-sm-4 text-muted">Method</dt>
                <dd class="col-sm-8">{{ \App\Models\Invoice::paymentMethodLabel($invoice->payment_method) }}</dd>
                @php
                    $dest = \App\Models\Invoice::maskedPayoutDestination(
                        data_get($invoice->billing_snapshot, 'payment_details'),
                        $invoice->payment_method
                    );
                @endphp
                @if($dest)
                    <dt class="col-sm-4 text-muted">Sent to</dt>
                    <dd class="col-sm-8">{{ $dest }}</dd>
                @endif
            </dl>

            @if($invoice->notes)
                <p class="small text-muted mt-3 mb-0">{{ $invoice->notes }}</p>
            @endif

            <div class="mt-3">
                <a href="{{ route('publisher.withdraw', [], false) }}" class="small">Open withdrawals</a>
            </div>
        </div>
    </div>
</div>
@endsection
