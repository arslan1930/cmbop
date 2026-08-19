@extends('publisher.layouts.app')

@section('title', 'Reports')

@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/publisher-reports.css') }}?v={{ @filemtime(public_path('assets/css/publisher-reports.css')) ?: '1' }}">
<div class="publisher-reports-container"
     data-stats-url="{{ route('publisher.reports.statistics', absolute: false) }}"
     data-orders-url="{{ route('publisher.reports.orders', absolute: false) }}"
     data-order-details-template="{{ route('publisher.reports.order.details', ['orderItemId' => '__ID__'], absolute: false) }}"
     data-withdrawals-url="{{ route('publisher.reports.withdrawals', absolute: false) }}"
     data-withdraw-url="{{ route('publisher.withdraw', absolute: false) }}"
     data-tasks-url="{{ route('publisher.tasks', absolute: false) }}">

    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Financial Reports</h2>
            <p class="text-muted mb-0">
                Earnings from completed placements and your withdrawal history.
            </p>
        </div>
    </div>

    <div class="row mb-4" id="reportsStatCards">
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Earned</h6>
                        <div class="text-muted small">Lifetime</div>
                        <h3 class="mb-0" id="totalEarned" style="color: #10b981;">€0.00</h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-euro-sign fa-2x text-success"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Completed Orders</h6>
                        <div class="text-muted small">Lifetime</div>
                        <h3 class="mb-0" id="completedOrders">0</h3>
                        <div class="text-muted small mt-1">
                            Open placements: <span id="openOrders">0</span>
                            ·
                            <a href="{{ route('publisher.tasks') }}">Tasks</a>
                        </div>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-check-circle fa-2x text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Withdrawn</h6>
                        <div class="text-muted small">Lifetime</div>
                        <h3 class="mb-0" id="totalWithdrawn" style="color: #ef4444;">€0.00</h3>
                        <div class="text-muted small mt-1" id="withdrawnFeesHint"></div>
                        <div class="text-muted small mt-1">
                            Pending payout: <span id="pendingPayout">€0.00</span>
                            ·
                            <a href="{{ route('publisher.withdraw') }}">Withdraw</a>
                        </div>
                    </div>
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-download fa-2x text-danger"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Available to Withdraw</h6>
                        <div class="text-muted small">Lifetime</div>
                        <h3 class="mb-0" id="availableToWithdraw">€0.00</h3>
                        <div class="small mt-1" id="availableNote">
                            <a href="{{ route('publisher.withdraw') }}">Go to Withdraw</a>
                        </div>
                    </div>
                    <div class="bg-info bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-wallet fa-2x text-info"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <ul class="nav nav-tabs publisher-reports-tabs" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders" type="button" role="tab">
                        <i class="fa fa-shopping-cart me-2"></i>Orders
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="withdrawals-tab" data-bs-toggle="tab" data-bs-target="#withdrawals" type="button" role="tab">
                        <i class="fa fa-download me-2"></i>Withdrawals
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="orders" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
                        <div>
                            <div class="fw-semibold"><i class="fa fa-shopping-cart me-2"></i><span id="ordersTabTitle">Orders</span></div>
                            <small class="text-muted" id="ordersResultsCount"></small>
                        </div>
                        <form id="ordersFilters" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="ordersDateFrom">From</label>
                                <input type="date" class="form-control form-control-sm" id="ordersDateFrom" name="date_from">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="ordersDateTo">To</label>
                                <input type="date" class="form-control form-control-sm" id="ordersDateTo" name="date_to">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="ordersStatus">Status</label>
                                <select class="form-select form-select-sm" id="ordersStatus" name="status">
                                    <option value="completed" selected>Completed</option>
                                    <option value="pending">Pending</option>
                                    <option value="processing">Processing</option>
                                    <option value="review">In Review</option>
                                    <option value="scheduled">Scheduled</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 publisher-reports-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th id="ordersDateHeading">Completed</th>
                                    <th>Site</th>
                                    <th>Base Price</th>
                                    <th>Sensitive Price</th>
                                    <th>Homepage</th>
                                    <th id="ordersPayoutHeading">You earned</th>
                                    <th>Order Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTableBody">
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">Loading orders...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-center">
                        <nav id="ordersPaginationNav"></nav>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="withdrawals" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
                        <div>
                            <div class="fw-semibold"><i class="fa fa-download me-2"></i><span id="withdrawalsTabTitle">Withdrawals</span></div>
                            <small class="text-muted" id="withdrawalsResultsCount"></small>
                        </div>
                        <form id="withdrawalsFilters" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="withdrawalsDateFrom">From</label>
                                <input type="date" class="form-control form-control-sm" id="withdrawalsDateFrom" name="date_from">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="withdrawalsDateTo">To</label>
                                <input type="date" class="form-control form-control-sm" id="withdrawalsDateTo" name="date_to">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="withdrawalsStatus">Status</label>
                                <select class="form-select form-select-sm" id="withdrawalsStatus" name="status">
                                    <option value="completed" selected>Paid</option>
                                    <option value="pending">Requested</option>
                                    <option value="processing">Processing</option>
                                    <option value="cancelled">Cancelled / Rejected</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 publisher-reports-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Fee</th>
                                    <th>Net Paid</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Reference</th>
                                    <th>Statement</th>
                                </tr>
                            </thead>
                            <tbody id="withdrawalsTableBody">
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="text-muted">Loading withdrawals...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-center">
                        <nav id="withdrawalsPaginationNav"></nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="orderDetailsModalLabel">Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="orderDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/publisher-reports.js') }}?v={{ @filemtime(public_path('assets/js/publisher-reports.js')) ?: '1' }}"></script>
@endpush
