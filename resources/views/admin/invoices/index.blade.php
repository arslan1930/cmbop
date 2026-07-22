@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-7">
            <h2 class="mb-1 fw-semibold">Invoices</h2>
            <p class="text-muted mb-0">All generated invoices, receipts, failures, and refunds.</p>
        </div>
        <div class="col-md-5">
            <form method="POST" action="{{ route('admin.invoices.generate') }}" class="d-flex gap-2 justify-content-md-end">
                @csrf
                <input type="number" name="order_id" class="form-control form-control-sm" style="max-width:160px;" placeholder="Order ID" required>
                <button type="submit" class="btn btn-sm btn-primary">Generate invoice</button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-3 mb-4">
        @foreach([
            ['Invoices generated', $stats['generated']],
            ['Downloads', $stats['downloaded']],
            ['Emails sent', $stats['emailed']],
            ['Gen. failures', $stats['failures']],
            ['Payment failures', $stats['payment_failures']],
            ['Refund receipts', $stats['refunds']],
        ] as [$label, $value])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body py-3">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="fs-4 fw-bold" style="color:#185054;">{{ number_format($value) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="Invoice, order, email…">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach(['paid','issued','pending','failed','refunded','cancelled'] as $status)
                            <option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="tax_invoice" @selected(request('type')==='tax_invoice')>Invoice</option>
                        <option value="payment_receipt" @selected(request('type')==='payment_receipt')>Receipt</option>
                        <option value="refund_receipt" @selected(request('type')==='refund_receipt')>Refund</option>
                        <option value="payment_failure" @selected(request('type')==='payment_failure')>Failure</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('admin.invoices.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Order</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                        <tr>
                            <td class="fw-semibold">{{ $invoice->invoice_number }}</td>
                            <td class="small">
                                <div>{{ $invoice->customer_name }}</div>
                                <div class="text-muted">{{ $invoice->customer_email }}</div>
                            </td>
                            <td class="small">#{{ $invoice->order_number }}</td>
                            <td>€{{ number_format((float) $invoice->total_amount, 2) }}</td>
                            <td><span class="badge text-bg-secondary">{{ $invoice->status }}</span></td>
                            <td class="small">{{ $invoice->typeLabel() }}</td>
                            <td class="small">{{ optional($invoice->invoice_date)->format('Y-m-d') }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-5">No invoices found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($invoices->hasPages())
            <div class="card-footer bg-white">{{ $invoices->links() }}</div>
        @endif
    </div>
</div>
@endsection
