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
            <form method="POST" action="{{ route('admin.invoices.generate') }}" class="d-flex gap-2 justify-content-md-end mb-2">
                @csrf
                <input type="text" name="order_ref" class="form-control form-control-sm" style="max-width:180px;" placeholder="Order number" required>
                <button type="submit" class="btn btn-sm btn-primary">Generate invoice</button>
            </form>
            @if(session('confirm_unpaid_order'))
                <form method="POST" action="{{ route('admin.invoices.generate') }}" class="d-flex gap-2 justify-content-md-end mb-2">
                    @csrf
                    <input type="hidden" name="order_ref" value="{{ session('confirm_unpaid_order') }}">
                    <input type="hidden" name="confirm" value="1">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Issue invoice for unpaid order</button>
                </form>
            @endif
            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                <form method="POST" action="{{ route('admin.invoices.backfill-missing') }}">
                    @csrf
                    <input type="hidden" name="limit" value="50">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="(async (btn) => { const ok = await slbConfirm({ title: 'Backfill invoices?', text: 'Backfill tax invoices for up to 50 paid orders missing one?', confirmText: 'Backfill' }); if (ok) btn.closest('form').submit(); })(this)">
                        Backfill missing
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.invoices.regenerate-missing-pdfs') }}">
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
            ['All documents', $stats['documents'], route('admin.invoices.index')],
            ['Tax invoices', $stats['tax_invoices'], route('admin.invoices.index', ['type' => 'tax_invoice'])],
            ['Payment failures', $stats['payment_failures'], route('admin.invoices.index', ['type' => 'payment_failure'])],
            ['Refund receipts', $stats['refunds'], route('admin.invoices.index', ['type' => 'refund_receipt'])],
            ['Deposit receipts', $stats['deposits'], route('admin.invoices.index', ['type' => 'deposit_receipt'])],
            ['Payout statements', $stats['payouts'], route('admin.invoices.index', ['type' => 'withdrawal_payout'])],
        ] as [$label, $value, $url])
            <div class="col-6 col-md-4 col-xl-2">
                <a href="{{ $url }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                    <div class="card-body py-3">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="fs-4 fw-bold" style="color:#1a585e;">{{ number_format($value) }}</div>
                    </div>
                </a>
            </div>
        @endforeach
        @foreach([
            ['Customer downloads', $stats['downloaded']],
            ['Emails sent', $stats['emailed']],
            ['Gen. failures', $stats['failures']],
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
        <div class="col-6 col-md-4 col-xl-2">
            <a href="{{ route('admin.invoices.index', ['pdf' => 'missing']) }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                <div class="card-body py-3">
                    <div class="small text-muted">PDF missing</div>
                    <div class="fs-4 fw-bold" style="color:#1a585e;">{{ number_format($missingPdfCount ?? 0) }}</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="{{ route('admin.invoices.index', ['queue' => 'missing']) }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                <div class="card-body py-3">
                    <div class="small text-muted">Missing tax invoices</div>
                    <div class="fs-4 fw-bold" style="color:#1a585e;">{{ number_format($missingTaxCount ?? 0) }}</div>
                </div>
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                @if(request('pdf') === 'missing')
                    <input type="hidden" name="pdf" value="missing">
                @endif
                @if(request('queue') === 'missing')
                    <input type="hidden" name="queue" value="missing">
                @endif
                <div class="col-md-4">
                    <x-slb-search-field name="search" id="adminInvoicesSearch" :value="$filterSearch ?? ''" placeholder="Invoice, customer, order, email…" />
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach(['paid','issued','pending','failed','refunded','cancelled'] as $status)
                            <option value="{{ $status }}" @selected(!($financeClock ?? false) && request('status')===$status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="tax_invoice" @selected(!($financeClock ?? false) && request('type')==='tax_invoice')>Invoice</option>
                        <option value="payment_receipt" @selected(!($financeClock ?? false) && request('type')==='payment_receipt')>Receipt</option>
                        <option value="refund_receipt" @selected(!($financeClock ?? false) && request('type')==='refund_receipt')>Refund</option>
                        <option value="payment_failure" @selected(!($financeClock ?? false) && request('type')==='payment_failure')>Failure</option>
                        <option value="deposit_receipt" @selected(!($financeClock ?? false) && request('type')==='deposit_receipt')>Deposit</option>
                        <option value="withdrawal_payout" @selected(!($financeClock ?? false) && request('type')==='withdrawal_payout')>Payout</option>
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
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Sort</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="">Newest</option>
                        <option value="oldest" @selected(request('sort')==='oldest')>Oldest</option>
                        <option value="amount" @selected(request('sort')==='amount')>Amount</option>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('admin.invoices.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    @unless($showMissingOrders ?? false)
                        <a href="{{ route('admin.invoices.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary">Export CSV</a>
                    @endunless
                </div>
            </form>
        </div>
    </div>

    @if($financeClock ?? false)
        <div class="alert alert-info">Finance documents for this period, dated by invoice date.</div>
    @endif
    @if(!empty($dateError))
        <div class="alert alert-warning">{{ $dateError }}</div>
    @endif
    @if($exportLimited ?? false)
        <div class="alert alert-warning">This filter matches more than 5,000 rows. The CSV includes the first 5,000 only.</div>
    @endif
    @unless($showMissingOrders ?? false)
        <p class="small text-muted mb-3">
            {{ number_format($matchCount ?? 0) }} match
            @foreach($currencyTotals ?? [] as $code => $amount)
                · {{ $code }} {{ number_format((float) $amount, 2) }}
            @endforeach
        </p>
    @endunless

    @if($showMissingOrders ?? false)
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Paid orders with no tax invoice</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Buyer</th>
                            <th>Amount</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($missingOrders as $order)
                            <tr>
                                <td><a href="{{ route('admin.orders.show', $order) }}">{{ $order->order_number }}</a></td>
                                <td class="small">
                                    @if($order->user_id)
                                        <a href="{{ route('admin.finance.user', $order->user_id) }}">{{ $order->user?->name ?: '—' }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $symbol }}{{ number_format((float) $order->total_amount, 2) }}</td>
                                <td class="small">{{ optional($order->paid_at)->format('Y-m-d H:i') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-5">{{ ($filterSearch ?? '') !== '' ? 'No missing tax invoices match this search.' : 'No paid orders are missing a tax invoice.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($missingOrders->hasPages())
                <div class="card-footer bg-white">{{ $missingOrders->links() }}</div>
            @endif
        </div>
    @else
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th class="admin-id-col">Order</th>
                        <th>Amount</th>
                        <th>Method</th>
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
                                        <a href="{{ route('admin.finance.user', $invoice->user_id) }}">{{ $invoice->customer_name ?: $invoice->user?->name ?: '—' }}</a>
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
                            <td>
                                {{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }}
                                @php $invoiceCode = strtoupper(trim((string) ($invoice->currency ?? ''))); @endphp
                                @if($invoiceCode !== '' && $invoiceCode !== 'EUR')
                                    <div class="small text-muted">{{ $invoiceCode }} {{ number_format((float) $invoice->total_amount, 2) }}</div>
                                @endif
                            </td>
                            <td class="small">{{ \App\Models\Invoice::paymentMethodLabel($invoice->payment_method) }}</td>
                            <td>
                                <span class="badge text-bg-{{ $invoice->statusBadgeClass() }}">{{ ucfirst($invoice->status) }}</span>
                            </td>
                            <td class="small">{{ $invoice->typeLabel() }}</td>
                            <td class="small">{{ optional($invoice->invoice_date)->format('Y-m-d H:i') ?: '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-5">No invoices found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($invoices->hasPages())
            <div class="card-footer bg-white">{{ $invoices->links() }}</div>
        @endif
    </div>
    @endif
</div>
@endsection
