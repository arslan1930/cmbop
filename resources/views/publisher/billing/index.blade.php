@extends('publisher.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-8">
            <h2 class="mb-1 fw-semibold">Payout documents</h2>
            <p class="text-muted mb-0">Download payout statements for completed withdrawals. These are not tax invoices.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <a href="{{ route('publisher.withdraw') }}" class="btn btn-sm btn-outline-secondary">Withdrawals</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('publisher.billing.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <x-slb-search-field name="search" id="publisherBillingSearch" :value="request('search')" placeholder="Statement #, WD reference…" />
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">From</label>
                    <input type="date" name="from" value="{{ scalar_text($filterFrom ?? request('from')) }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">To</label>
                    <input type="date" name="to" value="{{ scalar_text($filterTo ?? request('to')) }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('publisher.billing.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Statement</th>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Net payout</th>
                            <th>Method</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($documents as $doc)
                            <tr>
                                <td class="fw-semibold">{{ $doc->invoice_number }}</td>
                                <td class="small">{{ $doc->reference_code }}</td>
                                <td class="small">{{ optional($doc->invoice_date)->format('M j, Y') }}</td>
                                <td class="fw-semibold">€{{ number_format((float) $doc->total_amount, 2) }}</td>
                                <td class="small">{{ \App\Models\Invoice::paymentMethodLabel($doc->payment_method) }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                        <a href="{{ route('publisher.billing.show', $doc) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                        <a href="{{ route('publisher.billing.view', $doc) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">View PDF</a>
                                        <a href="{{ route('publisher.billing.download', $doc) }}" class="btn btn-sm btn-primary">Download PDF</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-5 text-center text-muted">
                                    No payout statements yet. They appear here after a withdrawal is marked paid.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($documents->hasPages())
            <div class="card-footer bg-white border-0">{{ $documents->links() }}</div>
        @endif
    </div>
</div>
@endsection
