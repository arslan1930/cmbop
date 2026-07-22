@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-8">
            <a href="{{ route('advertiser.billing.index') }}" class="small text-muted text-decoration-none">
                <i class="fa fa-arrow-left me-1"></i> Billing &amp; Invoices
            </a>
            <h2 class="mb-1 fw-semibold mt-1">{{ $invoice->invoice_number }}</h2>
            <p class="text-muted mb-0">{{ $invoice->typeLabel() }} · {{ ucfirst($invoice->status) }}</p>
        </div>
        <div class="col-md-4 text-md-end d-flex flex-wrap gap-2 justify-content-md-end">
            <a href="{{ route('advertiser.billing.view', $invoice) }}" class="btn btn-sm btn-outline-secondary" target="_blank">View PDF</a>
            <a href="{{ route('advertiser.billing.download', $invoice) }}" class="btn btn-sm btn-primary">Download PDF</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small fw-semibold mb-3">Order details</h6>
                    <div class="row g-3 small">
                        <div class="col-md-4"><span class="text-muted d-block">Order</span><strong>#{{ $invoice->order_number }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Date</span><strong>{{ optional($invoice->invoice_date)->format('M j, Y g:i A') }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Amount</span><strong>€{{ number_format((float) $invoice->total_amount, 2) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Payment method</span><strong>{{ ucfirst((string) $invoice->payment_method) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Payment status</span><strong>{{ ucfirst((string) $invoice->payment_status) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Transaction</span><strong class="text-break">{{ $invoice->transaction_id ?: '—' }}</strong></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Service</th>
                                    <th>Website</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(($invoice->line_items ?? []) as $line)
                                    <tr>
                                        <td>{{ $line['description'] ?? 'Service' }}</td>
                                        <td class="small">{{ $line['publisher_website'] ?? '—' }}</td>
                                        <td class="text-end">€{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small fw-semibold mb-3">Totals</h6>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><span>€{{ number_format((float) $invoice->subtotal, 2) }}</span></div>
                    @if((float) $invoice->discount_amount > 0)
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Discount</span><span>-€{{ number_format((float) $invoice->discount_amount, 2) }}</span></div>
                    @endif
                    @if((float) $invoice->tax_amount > 0)
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ $invoice->tax_label ?: 'Tax' }}</span><span>€{{ number_format((float) $invoice->tax_amount, 2) }}</span></div>
                    @endif
                    <div class="d-flex justify-content-between pt-2 border-top fw-bold">
                        <span>Total</span><span style="color:#185054;">€{{ number_format((float) $invoice->total_amount, 2) }}</span>
                    </div>
                    @if($invoice->parentInvoice)
                        <hr>
                        <div class="small text-muted">Related invoice</div>
                        <a href="{{ route('advertiser.billing.show', $invoice->parentInvoice) }}" class="fw-semibold">
                            {{ $invoice->parentInvoice->invoice_number }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
