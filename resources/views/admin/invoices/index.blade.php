@extends('admin.layouts.app')

@section('content')
@php
    $symbol = $currencySymbol ?? config('billing.currency_symbol', '€');
@endphp
<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-7">
            <h2 class="mb-1 fw-semibold">Invoices</h2>
            <p class="text-muted mb-0">Tax invoices, receipts, deposits, payouts, failures, and refunds.</p>
        </div>
        <div class="col-md-5">
            <form method="POST" action="{{ route('admin.invoices.generate', [], false) }}" class="d-flex gap-2 justify-content-md-end mb-2">
                @csrf
                <input type="number" name="order_id" class="form-control form-control-sm" style="max-width:160px;" placeholder="Order ID" required>
                <button type="submit" class="btn btn-sm btn-primary">Generate invoice</button>
            </form>
            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                <form method="POST" action="{{ route('admin.invoices.backfill-missing', [], false) }}">
                    @csrf
                    <input type="hidden" name="limit" value="50">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="(async (btn) => { const ok = await slbConfirm({ title: 'Backfill invoices?', text: 'Backfill tax invoices for up to 50 paid orders missing one?', confirmText: 'Backfill' }); if (ok) btn.closest('form').submit(); })(this)">
                        Backfill missing
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.invoices.regenerate-missing-pdfs', [], false) }}">
                    @csrf
                    <input type="hidden" name="limit" value="50">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="(async (btn) => { const ok = await slbConfirm({ title: 'Fix missing PDFs?', text: 'Regenerate up to 50 missing PDFs on disk?', confirmText: 'Regenerate' }); if (ok) btn.closest('form').submit(); })(this)">
                        Fix missing PDFs
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach([
            ['All documents', $stats['documents']],
            ['Tax invoices', $stats['tax_invoices']],
            ['Downloads', $stats['downloaded']],
            ['Emails sent', $stats['emailed']],
            ['Gen. failures', $stats['failures']],
            ['Payment failures', $stats['payment_failures']],
            ['Refund receipts', $stats['refunds']],
            ['Deposit receipts', $stats['deposits']],
            ['Payout statements', $stats['payouts']],
        ] as [$label, $value])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body py-3">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="fs-4 fw-bold" style="color:#1a585e;">{{ number_format($value) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <x-slb-search-field name="search" id="adminInvoicesSearch" :value="$filterSearch ?? ''" placeholder="Invoice, customer, order, email…" />
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
                        <option value="deposit_receipt" @selected(request('type')==='deposit_receipt')>Deposit</option>
                        <option value="withdrawal_payout" @selected(request('type')==='withdrawal_payout')>Payout</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">From</label>
                    <input type="date" name="from" value="{{ $filterFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">To</label>
                    <input type="date" name="to" value="{{ $filterTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('admin.invoices.index', [], false) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                        <th class="admin-id-col">Order</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                        @php
                            $refTitle = $invoice->order_number ?: $invoice->reference_code;
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $invoice->invoice_number }}</div>
                                @if(! $invoice->pdfExists())
                                    <span class="badge text-bg-warning">PDF missing</span>
                                @endif
                            </td>
                            <td class="small">
                                <div>
                                    @if($invoice->user_id)
                                        <a href="{{ route('admin.users.index', ['user' => $invoice->user_id], false) }}">{{ $invoice->customer_name ?: $invoice->user?->name ?: '—' }}</a>
                                    @else
                                        {{ $invoice->customer_name ?: '—' }}
                                    @endif
                                </div>
                                <div class="text-muted">{{ $invoice->customer_email }}</div>
                            </td>
                            <td class="small">
                                @if($invoice->relatedAdminUrl())
                                    <a href="{{ $invoice->relatedAdminUrl() }}" class="admin-id-clamp" title="{{ $refTitle }}">{{ $invoice->referenceLabel() }}</a>
                                @else
                                    <span class="admin-id-clamp" title="{{ $refTitle }}">{{ $invoice->referenceLabel() }}</span>
                                @endif
                            </td>
                            <td>{{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }}</td>
                            <td>
                                <span class="badge text-bg-{{ $invoice->statusBadgeClass() }}">{{ ucfirst($invoice->status) }}</span>
                            </td>
                            <td class="small">{{ $invoice->typeLabel() }}</td>
                            <td class="small">{{ optional($invoice->invoice_date)->format('Y-m-d') }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.invoices.show', $invoice, false) }}" class="btn btn-sm btn-outline-primary">Open</a>
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
