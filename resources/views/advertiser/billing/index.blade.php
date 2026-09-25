@extends('advertiser.layouts.app')

@section('title', 'Billing & Invoices')

@push('page-styles')
<link href="{{ asset('assets/css/single-select.css') }}?v={{ @filemtime(public_path('assets/css/single-select.css')) ?: '1' }}" rel="stylesheet">
<link href="{{ asset('assets/css/advertiser-billing.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-billing.css')) ?: '1' }}" rel="stylesheet">
@endpush

@section('content')
<div class="container-fluid billing-page">
    <div class="billing-page-header">
        <div>
            <h2 class="billing-page-title">Billing &amp; Invoices</h2>
            <p class="billing-page-sub">
                Download tax invoices, payment receipts, refunds, and wallet deposit receipts.
                Documents use your company name, address, and optional VAT / tax ID from checkout billing or
                <a href="{{ route('profile') }}">profile</a>.
                Unpaid bank, Wise, or crypto pay-in slips stay on
                <a href="{{ route('advertiser.add-funds') }}">Add Funds</a>
                until we credit your wallet — those are not tax invoices.
            </p>
        </div>
        <div class="billing-page-links">
            <a href="{{ route('advertiser.add-funds') }}" class="btn btn-sm btn-outline-secondary">Add funds</a>
            <a href="{{ route('advertiser.orders') }}" class="btn btn-sm btn-outline-secondary">View orders</a>
            <a href="{{ route('advertiser.billing.export', request()->query()) }}" class="btn btn-sm btn-primary">Export CSV</a>
        </div>
    </div>

    @if(($pendingPayIns ?? 0) > 0)
        <div class="alert alert-warning border mb-3" role="status">
            You have a pending pay-in on
            <a href="{{ route('advertiser.add-funds') }}" class="alert-link">Add Funds</a>.
            It will appear here as a deposit receipt after we credit your wallet.
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('advertiser.billing.index') }}" id="advertiserBillingFilters" class="billing-filter-bar">
                <div class="billing-filter-bar__row">
                    <div class="billing-filter-bar__search">
                        <x-slb-search-field
                            name="search"
                            id="advertiserBillingSearch"
                            :value="request('search')"
                            placeholder="Invoice #, order #, REF, transaction…"
                        />
                    </div>

                    <div class="billing-filter-bar__select">
                        <label class="form-label fw-semibold small text-muted mb-1" for="billingStatusFilter">Status</label>
                        @include('advertiser.partials.library-theme-select', [
                            'selectId' => 'billingStatusFilter',
                            'name' => 'status',
                            'label' => 'Status',
                            'current' => (string) request('status', ''),
                            'options' => array_merge(
                                [['value' => '', 'label' => 'All']],
                                collect(['paid','issued','pending','failed','refunded','cancelled'])->map(fn ($status) => [
                                    'value' => $status,
                                    'label' => ucfirst($status),
                                ])->all()
                            ),
                        ])
                    </div>

                    <div class="billing-filter-bar__select">
                        <label class="form-label fw-semibold small text-muted mb-1" for="billingTypeFilter">Type</label>
                        @include('advertiser.partials.library-theme-select', [
                            'selectId' => 'billingTypeFilter',
                            'name' => 'type',
                            'label' => 'Type',
                            'current' => (string) request('type', ''),
                            'options' => [
                                ['value' => '', 'label' => 'All'],
                                ['value' => 'tax_invoice', 'label' => 'Invoice'],
                                ['value' => 'payment_receipt', 'label' => 'Receipt'],
                                ['value' => 'refund_receipt', 'label' => 'Refund'],
                                ['value' => 'payment_failure', 'label' => 'Failed attempt'],
                                ['value' => 'deposit_receipt', 'label' => 'Deposit receipt'],
                            ],
                        ])
                    </div>

                    <div class="billing-filter-bar__dates">
                        <span class="form-label fw-semibold small text-muted mb-1">Date range</span>
                        <div class="billing-date-range">
                            <label class="visually-hidden" for="billingFrom">From</label>
                            <input type="date" name="from" id="billingFrom" value="{{ $filterFrom ?? '' }}" class="form-control form-control-sm">
                            <label class="visually-hidden" for="billingTo">To</label>
                            <input type="date" name="to" id="billingTo" value="{{ $filterTo ?? '' }}" class="form-control form-control-sm">
                        </div>
                    </div>

                    <div class="billing-filter-bar__actions">
                        <span class="form-label fw-semibold small text-muted mb-1 billing-filter-bar__action-label" aria-hidden="true">&nbsp;</span>
                        <div class="billing-filter-bar__action-row">
                            <button type="submit" class="btn btn-sm btn-primary px-3">
                                <i class="fa-solid fa-filter" aria-hidden="true"></i> Filter
                            </button>
                            <a href="{{ route('advertiser.billing.index') }}" class="btn btn-sm btn-cta-secondary px-3">
                                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Type</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($invoices as $invoice)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $invoice->invoice_number }}</div>
                                    @if($invoice->transaction_id)
                                        <div class="small text-muted text-truncate" style="max-width:180px;">{{ $invoice->transaction_id }}</div>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($invoice->order_id)
                                        <a href="{{ route('advertiser.orders') }}">{{ $invoice->referenceLabel() }}</a>
                                    @else
                                        {{ $invoice->referenceLabel() }}
                                    @endif
                                </td>
                                <td class="small">{{ optional($invoice->invoice_date)->format('M j, Y') }}</td>
                                <td class="fw-semibold">{{ format_money($invoice->total_amount) }}</td>
                                <td>
                                    <span class="billing-status billing-status--{{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span>
                                </td>
                                <td class="small">{{ $invoice->typeLabel() }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                        <a href="{{ route('advertiser.billing.show', $invoice) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                        @if($invoice->advertiserCanDownloadPdf())
                                            <a href="{{ route('advertiser.billing.view', $invoice) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">View PDF</a>
                                            <a href="{{ route('advertiser.billing.download', $invoice) }}" class="btn btn-sm btn-primary">Download PDF</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-5">
                                    <x-ui.empty-state
                                        icon="fa-file-invoice"
                                        title="No invoices yet"
                                        message="Tax invoices and receipts appear after a paid order. Wallet deposit receipts appear after we credit a top-up. Refunds and failed-payment documents show up here too."
                                        primary-label="Browse catalog"
                                        :primary-url="route('advertiser.catalog')"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($invoices->hasPages())
            <div class="card-footer bg-white border-0">{{ $invoices->links() }}</div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/single-select.js') }}?v={{ @filemtime(public_path('assets/js/single-select.js')) ?: '1' }}" defer></script>
<script src="{{ asset('assets/js/advertiser-billing.js') }}?v={{ @filemtime(public_path('assets/js/advertiser-billing.js')) ?: '1' }}" defer></script>
@endpush
