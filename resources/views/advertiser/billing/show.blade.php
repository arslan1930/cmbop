@extends('advertiser.layouts.app')

@php
    $snapshot = is_array($invoice->billing_snapshot) ? $invoice->billing_snapshot : [];
    $lineItems = is_array($invoice->line_items) ? $invoice->line_items : [];
    $canPdf = $invoice->advertiserCanDownloadPdf();
@endphp

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
            @if($canPdf)
                <a href="{{ route('advertiser.billing.view', $invoice) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">View PDF</a>
                <a href="{{ route('advertiser.billing.download', $invoice) }}" class="btn btn-sm btn-primary">Download PDF</a>
            @elseif($invoice->isCancelled() && $invoice->isTaxInvoice())
                <span class="small text-muted align-self-center">Cancelled — PDF unavailable</span>
            @endif
            @if(! $invoice->isCancelled())
                <form method="POST" action="{{ route('advertiser.billing.resend', $invoice) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Email me this invoice</button>
                </form>
            @endif
        </div>
    </div>

    @if($invoice->isCancelled() && $invoice->isTaxInvoice() && filled($invoice->cancel_reason))
        <div class="alert alert-secondary border mb-3" role="status">{{ $invoice->cancel_reason }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small fw-semibold mb-3">{{ $invoice->advertiserDetailsHeading() }}</h6>
                    <div class="row g-3 small">
                        <div class="col-md-4">
                            <span class="text-muted d-block">{{ $invoice->order_id ? 'Order' : 'Reference' }}</span>
                            <strong>{{ $invoice->referenceLabel() }}</strong>
                        </div>
                        <div class="col-md-4"><span class="text-muted d-block">Date</span><strong>{{ optional($invoice->invoice_date)->format('M j, Y g:i A') }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Amount</span><strong>{{ format_money($invoice->total_amount) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Payment method</span><strong>{{ \App\Models\Invoice::paymentMethodLabel($invoice->payment_method) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Payment status</span><strong>{{ ucfirst((string) $invoice->payment_status) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Transaction</span><strong class="text-break">{{ $invoice->transaction_id ?: '—' }}</strong></div>
                    </div>
                    @if(filled($invoice->notes))
                        <p class="small text-muted mb-0 mt-3">{{ $invoice->notes }}</p>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:48%;">Service</th>
                                    <th style="width:32%;">{{ $invoice->isDepositReceipt() || $invoice->isWithdrawalPayout() ? 'Reference' : 'Website' }}</th>
                                    <th class="text-end" style="width:20%;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($lineItems as $line)
                                    <tr>
                                        <td>{{ $line['description'] ?? 'Service' }}</td>
                                        <td class="small text-break">{{ $line['publisher_website'] ?? $line['reference'] ?? $line['site_url'] ?? '—' }}</td>
                                        <td class="text-end text-nowrap">{{ format_money($line['line_total'] ?? $line['total'] ?? 0) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="small text-muted text-center py-3">No line items</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" class="text-end text-muted">Subtotal</td>
                                    <td class="text-end">{{ format_money($invoice->subtotal) }}</td>
                                </tr>
                                @if((float) $invoice->discount_amount > 0)
                                    <tr>
                                        <td colspan="2" class="text-end text-muted">Discount</td>
                                        <td class="text-end">-{{ format_money($invoice->discount_amount) }}</td>
                                    </tr>
                                @endif
                                @if((float) $invoice->tax_amount > 0)
                                    <tr>
                                        <td colspan="2" class="text-end text-muted">{{ $invoice->tax_label ?: 'Tax' }}</td>
                                        <td class="text-end">{{ format_money($invoice->tax_amount) }}</td>
                                    </tr>
                                @endif
                                <tr class="fw-semibold">
                                    <td colspan="2" class="text-end">Total</td>
                                    <td class="text-end" style="color:#1a585e;">{{ format_money($invoice->total_amount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small fw-semibold mb-3">Totals</h6>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><span>{{ format_money($invoice->subtotal) }}</span></div>
                    @if((float) $invoice->discount_amount > 0)
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Discount</span><span>-{{ format_money($invoice->discount_amount) }}</span></div>
                    @endif
                    @if((float) $invoice->tax_amount > 0)
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ $invoice->tax_label ?: 'Tax' }}</span><span>{{ format_money($invoice->tax_amount) }}</span></div>
                    @endif
                    <div class="d-flex justify-content-between pt-2 border-top fw-bold">
                        <span>Total</span><span style="color:#1a585e;">{{ format_money($invoice->total_amount) }}</span>
                    </div>
                    @if($invoice->parentInvoice)
                        <hr>
                        <div class="small text-muted">Related invoice</div>
                        <a href="{{ route('advertiser.billing.show', $invoice->parentInvoice) }}" class="fw-semibold">
                            {{ $invoice->parentInvoice->invoice_number }}
                        </a>
                    @endif
                    @if($invoice->childInvoices->isNotEmpty())
                        <hr>
                        <div class="small text-muted mb-1">Related documents</div>
                        <ul class="list-unstyled mb-0 small">
                            @foreach($invoice->childInvoices as $child)
                                <li>
                                    <a href="{{ route('advertiser.billing.show', $child) }}">{{ $child->invoice_number }}</a>
                                    <span class="text-muted">· {{ $child->typeLabel() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @if($snapshot !== [])
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small fw-semibold mb-3">Billed to</h6>
                        <div class="small">
                            <div class="fw-semibold">{{ $snapshot['name'] ?? $invoice->customer_name ?: '—' }}</div>
                            @if(! empty($snapshot['company']))
                                <div>{{ $snapshot['company'] }}</div>
                            @endif
                            @if(! empty($snapshot['address']))
                                <div>{{ $snapshot['address'] }}</div>
                            @endif
                            <div>
                                {{ collect([$snapshot['city'] ?? null, $snapshot['state'] ?? null, $snapshot['postal_code'] ?? null])->filter()->implode(', ') }}
                            </div>
                            @if(! empty($snapshot['country']))
                                <div>{{ $snapshot['country'] }}</div>
                            @endif
                            @if(! empty($snapshot['vat_number']))
                                <div class="mt-2 text-muted">VAT / tax ID: {{ $snapshot['vat_number'] }}</div>
                            @endif
                            @if(! empty($snapshot['email']) || $invoice->customer_email)
                                <div class="text-muted">{{ $snapshot['email'] ?? $invoice->customer_email }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
