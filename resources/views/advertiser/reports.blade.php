@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Reports</h2>
            <p class="text-muted mb-0">
                View your campaign performance, funds activity, and order history.
            </p>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <ul class="nav nav-tabs nav-tabs-custom" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="funds-tab" data-bs-toggle="tab" data-bs-target="#funds" type="button" role="tab">
                        <i class="fa fa-wallet me-2"></i>Funds Activity
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders" type="button" role="tab">
                        <i class="fa fa-shopping-cart me-2"></i>Orders
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Funds Activity Tab -->
        <div class="tab-pane fade show active" id="funds" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-wallet me-2"></i> Funds Activity (Recent Transactions)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($fundsActivity as $activity)
                                @php
                                    $isCompleted = $activity->status == 'completed';
                                    $isDeposit = $activity->type == 'deposit';
                                @endphp
                                <tr>
                                    <td>{{ $activity->created_at->format('M d, Y') }}</td>
                                    <td><code class="small">{{ $activity->reference_code }}</code></td>
                                    <td class="fw-semibold {{ $isCompleted && $isDeposit ? 'text-success' : ($isCompleted && !$isDeposit ? 'text-danger' : 'text-muted') }}">
                                        @if($isDeposit)
                                            + €{{ number_format($activity->amount, 2) }}
                                        @else
                                            - €{{ number_format($activity->amount, 2) }}
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">{{ ucfirst($activity->payment_method) }}</span>
                                    </td>
                                    <td>
                                        @if($activity->status == 'pending')
                                            <span class="badge bg-warning">Pending</span>
                                        @elseif($activity->status == 'approved')
                                            <span class="badge bg-info">Approved</span>
                                        @elseif($activity->status == 'completed')
                                            <span class="badge bg-success">Completed</span>
                                        @elseif($activity->status == 'rejected')
                                            <span class="badge bg-danger">Rejected</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">{{ ucfirst($activity->type) }}</span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No funds activity found</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Tab -->
        <div class="tab-pane fade" id="orders" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-shopping-cart me-2"></i> Orders (Recent Orders)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Site</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Payment Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($orders as $order)
                                <tr>
                                    <td><code class="fw-semibold">#{{ $order->order_number }}</code></td>
                                    <td>{{ $order->created_at->format('M d, Y') }}</td>
                                    <td>
                                        @if($order->items && $order->items->count() > 0)
                                            {{ $order->items->first()->site_name }}
                                            @if($order->items->count() > 1)
                                                <span class="badge bg-secondary ms-1">+{{ $order->items->count() - 1 }}</span>
                                            @endif
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="fw-semibold text-primary">€{{ number_format($order->total_amount, 2) }}</td>
                                    <td>
                                        <span class="badge bg-secondary">{{ ucfirst($order->payment_method) }}</span>
                                    </td>
                                    <td>
                                        @if($order->status == 'pending')
                                            <span class="badge bg-warning">Pending</span>
                                        @elseif($order->status == 'processing')
                                            <span class="badge bg-info">Processing</span>
                                        @elseif($order->status == 'completed')
                                            <span class="badge bg-success">Completed</span>
                                        @elseif($order->status == 'cancelled')
                                            <span class="badge bg-danger">Cancelled</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($order->payment_status == 'pending')
                                            <span class="badge bg-warning">Pending</span>
                                        @elseif($order->payment_status == 'paid')
                                            <span class="badge bg-success">Paid</span>
                                        @elseif($order->payment_status == 'failed')
                                            <span class="badge bg-danger">Failed</span>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No orders found</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.nav-tabs-custom {
    border-bottom: 1px solid #e5e7eb;
    padding: 0 20px;
    background: white;
    border-radius: 8px 8px 0 0;
}

.nav-tabs-custom .nav-link {
    border: none;
    padding: 12px 20px;
    color: #6b7280;
    font-weight: 500;
    transition: all 0.2s;
}

.nav-tabs-custom .nav-link:hover {
    color: #3b82f6;
    background: transparent;
}

.nav-tabs-custom .nav-link.active {
    color: #3b82f6;
    border-bottom: 2px solid #3b82f6;
    background: transparent;
}

.bg-opacity-10 {
    --bs-bg-opacity: 0.1;
}

.table td, .table th {
    padding: 12px 15px;
    vertical-align: middle;
}

.badge {
    font-size: 11px;
    padding: 4px 8px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips if any
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});
</script>

@endsection