@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ route('admin.invoices.index') }}" class="small text-muted text-decoration-none">
            <i class="fa fa-arrow-left me-1"></i> All invoices
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-7">
            <h2 class="mb-1 fw-semibold">{{ $invoice->invoice_number }}</h2>
            <p class="text-muted mb-0">{{ $invoice->typeLabel() }} · {{ ucfirst($invoice->status) }} · {{ $invoice->customer_email }}</p>
        </div>
        <div class="col-md-5 d-flex flex-wrap gap-2 justify-content-md-end">
            <a href="{{ route('admin.invoices.download', $invoice) }}" class="btn btn-sm btn-primary">Download PDF</a>
            <form method="POST" action="{{ route('admin.invoices.resend', $invoice) }}">
                @csrf
                <button class="btn btn-sm btn-outline-secondary">Resend email</button>
            </form>
            @if($invoice->type === 'tax_invoice' && $invoice->status !== 'cancelled')
                <form method="POST" action="{{ route('admin.invoices.cancel', $invoice) }}"
                      data-slb-confirm="Cancel this invoice? The PDF will be retained."
                      data-slb-confirm-title="Cancel invoice?"
                      data-slb-confirm-text="Cancel invoice"
                      data-slb-confirm-danger="1">
                    @csrf
                    <input type="hidden" name="reason" value="Cancelled by admin">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Cancel invoice</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-4"><span class="text-muted d-block">Customer</span><strong>{{ $invoice->customer_name }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Order</span><strong>#{{ $invoice->order_number }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Amount</span><strong>€{{ number_format((float) $invoice->total_amount, 2) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Method</span><strong>{{ ucfirst((string) $invoice->payment_method) }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Transaction</span><strong class="text-break">{{ $invoice->transaction_id ?: '—' }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Downloads / Emails</span><strong>{{ $invoice->download_count }} / {{ $invoice->email_count }}</strong></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Line items</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr><th>Service</th><th>Website</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>
                            @foreach(($invoice->line_items ?? []) as $line)
                                <tr>
                                    <td>{{ $line['description'] ?? '—' }}</td>
                                    <td>{{ $line['publisher_website'] ?? '—' }}</td>
                                    <td class="text-end">€{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
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
