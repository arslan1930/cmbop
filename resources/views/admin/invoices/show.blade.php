@extends('admin.layouts.app')

@section('content')
@php
    $symbol = $currencySymbol ?? config('billing.currency_symbol', '€');
    $snapshot = is_array($invoice->billing_snapshot) ? $invoice->billing_snapshot : [];
    $lineItems = is_array($invoice->line_items) ? $invoice->line_items : [];
@endphp
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ route('admin.invoices.index', [], false) }}" class="small text-muted text-decoration-none">
            <i class="fa fa-arrow-left me-1"></i> All invoices
        </a>
    </div>

    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-6">
            <h2 class="mb-1 fw-semibold">{{ $invoice->invoice_number }}</h2>
            <p class="text-muted mb-0">
                {{ $invoice->typeLabel() }}
                · <span class="badge text-bg-{{ $invoice->statusBadgeClass() }}">{{ ucfirst($invoice->status) }}</span>
                · {{ $invoice->customer_email }}
                @if(! $invoice->pdfExists())
                    · <span class="badge text-bg-warning">PDF missing</span>
                @endif
            </p>
        </div>
        <div class="col-md-6 d-flex flex-wrap gap-2 justify-content-md-end">
            <a href="{{ route('admin.invoices.view', $invoice, false) }}" class="btn btn-sm btn-outline-secondary" target="_blank">View PDF</a>
            <a href="{{ route('admin.invoices.download', $invoice, false) }}" class="btn btn-sm btn-primary">Download PDF</a>
            <form method="POST" action="{{ route('admin.invoices.regenerate-pdf', $invoice, false) }}">
                @csrf
                <button class="btn btn-sm btn-outline-secondary">Regenerate PDF</button>
            </form>
            @if(! $invoice->isCancelled())
                <form method="POST" action="{{ route('admin.invoices.resend', $invoice, false) }}"
                      data-slb-confirm="Resend this document email to {{ $invoice->customer_email }}?"
                      data-slb-confirm-title="Resend email?"
                      data-slb-confirm-text="Resend">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Resend email</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-4">
                            <span class="text-muted d-block">Customer</span>
                            <strong>
                                @if($invoice->user_id)
                                    <a href="{{ route('admin.users.index', ['user' => $invoice->user_id]) }}">{{ $invoice->customer_name ?: $invoice->user?->name ?: '—' }}</a>
                                @else
                                    {{ $invoice->customer_name ?: '—' }}
                                @endif
                            </strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">{{ $invoice->order_id ? 'Order' : 'Reference' }}</span>
                            <strong>
                                @if(!empty($relatedUrl))
                                    <a href="{{ $relatedUrl }}">{{ $invoice->referenceLabel() }}</a>
                                @else
                                    {{ $invoice->referenceLabel() }}
                                @endif
                            </strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">Amount</span>
                            <strong>{{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">Invoice date</span>
                            <strong>{{ optional($invoice->invoice_date)->format('M j, Y g:i A') ?: '—' }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">Due date</span>
                            <strong>{{ optional($invoice->due_date)->format('M j, Y') ?: '—' }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">Payment status</span>
                            <strong>{{ $invoice->payment_status ? ucfirst((string) $invoice->payment_status) : '—' }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">Method</span>
                            <strong>{{ \App\Models\Invoice::paymentMethodLabel($invoice->payment_method) }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">Transaction</span>
                            <strong class="text-break">{{ $invoice->transaction_id ?: '—' }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block">Downloads / Emails</span>
                            <strong>{{ $invoice->download_count }} / {{ $invoice->email_count }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Line items</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr><th>Service</th><th>Website / reference</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>
                            @forelse($lineItems as $line)
                                @continue(! is_array($line))
                                <tr>
                                    <td>{{ $line['description'] ?? '—' }}</td>
                                    <td>{{ $line['publisher_website'] ?? $line['reference'] ?? '—' }}</td>
                                    <td class="text-end">{{ $symbol }}{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-4">No line items</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($invoice->isTaxInvoice() && ! $invoice->isCancelled())
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold">Cancel invoice</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.invoices.cancel', $invoice, false) }}"
                              data-slb-confirm="Cancel this invoice? The PDF will be retained."
                              data-slb-confirm-title="Cancel invoice?"
                              data-slb-confirm-text="Cancel invoice"
                              data-slb-confirm-danger="1">
                            @csrf
                            <label class="form-label small text-muted" for="cancelReason">Reason</label>
                            <textarea id="cancelReason" name="reason" class="form-control form-control-sm mb-3" rows="3" maxlength="1000" placeholder="Why is this invoice being cancelled?"></textarea>
                            <button class="btn btn-sm btn-outline-danger" type="submit">Cancel invoice</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small fw-semibold mb-3">Totals</h6>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><span>{{ $symbol }}{{ number_format((float) $invoice->subtotal, 2) }}</span></div>
                    @if((float) $invoice->discount_amount > 0)
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Discount</span><span>-{{ $symbol }}{{ number_format((float) $invoice->discount_amount, 2) }}</span></div>
                    @endif
                    @if((float) $invoice->tax_amount > 0)
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ $invoice->tax_label ?: 'Tax' }}</span><span>{{ $symbol }}{{ number_format((float) $invoice->tax_amount, 2) }}</span></div>
                    @endif
                    <div class="d-flex justify-content-between pt-2 border-top fw-bold">
                        <span>Total</span><span style="color:#1a585e;">{{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Billing snapshot</div>
                <div class="card-body small">
                    <div><strong>{{ $snapshot['name'] ?? $invoice->customer_name ?: '—' }}</strong></div>
                    @if(!empty($snapshot['company']))
                        <div>{{ $snapshot['company'] }}</div>
                    @endif
                    @foreach(array_filter([$snapshot['address'] ?? null, trim(implode(', ', array_filter([$snapshot['city'] ?? null, $snapshot['state'] ?? null, $snapshot['postal_code'] ?? null]))), $snapshot['country'] ?? null]) as $line)
                        <div class="text-muted">{{ $line }}</div>
                    @endforeach
                    <div class="text-muted">{{ $snapshot['email'] ?? $invoice->customer_email }}</div>
                    <div class="mt-2">VAT / tax ID: {{ $snapshot['vat_number'] ?? '—' }}</div>
                </div>
            </div>

            @if($invoice->parentInvoice || $invoice->childInvoices->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">Related documents</div>
                    <ul class="list-group list-group-flush">
                        @if($invoice->parentInvoice)
                            <li class="list-group-item small">
                                <div class="text-muted">Parent</div>
                                <a href="{{ route('admin.invoices.show', $invoice->parentInvoice) }}">{{ $invoice->parentInvoice->invoice_number }}</a>
                                <span class="text-muted">· {{ $invoice->parentInvoice->typeLabel() }}</span>
                            </li>
                        @endif
                        @foreach($invoice->childInvoices as $child)
                            <li class="list-group-item small">
                                <div class="text-muted">Related</div>
                                <a href="{{ route('admin.invoices.show', $child) }}">{{ $child->invoice_number }}</a>
                                <span class="text-muted">· {{ $child->typeLabel() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($invoice->notes || $invoice->isCancelled())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">Notes</div>
                    <div class="card-body small">
                        @if($invoice->notes)
                            <div class="mb-2">{{ $invoice->notes }}</div>
                        @endif
                        @if($invoice->isCancelled())
                            <div class="text-muted">Cancelled {{ optional($invoice->cancelled_at)->format('M j, Y g:i A') }}@if($invoice->cancelledBy) by {{ $invoice->cancelledBy->name }}@endif</div>
                            <div>{{ $invoice->cancel_reason ?: '—' }}</div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Payment history / events</div>
                <ul class="list-group list-group-flush">
                    @forelse($invoice->events as $event)
                        <li class="list-group-item small">
                            <div class="fw-semibold">{{ str_replace('_', ' ', $event->event_type) }}</div>
                            <div class="text-muted">{{ $event->created_at?->format('M j, Y g:i A') }}</div>
                        </li>
                    @empty
                        <li class="list-group-item text-muted small">No events logged.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
