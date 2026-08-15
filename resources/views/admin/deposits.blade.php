@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Deposit Requests</h2>
            <p class="text-muted">Manage and approve user deposit requests</p>
        </div>
    </div>

    @php
        $status = $status ?? '';
        $reportedPaid = $reported_paid ?? false;
        $search = $search ?? search_text(request('search'));
    @endphp
    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'pending']) }}" class="text-decoration-none text-reset">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Pending</h6>
                                <h2 class="mb-0 text-warning">{{ $stats['pending'] }}</h2>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded">
                                <i class="fa fa-clock fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['reported_paid' => 1]) }}" class="text-decoration-none text-reset">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">User reported paid</h6>
                                <h2 class="mb-0 text-success">{{ $stats['user_reported_paid'] ?? 0 }}</h2>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded">
                                <i class="fa fa-user-check fa-2x text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'completed']) }}" class="text-decoration-none text-reset">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Completed</h6>
                                <h2 class="mb-0 text-success">{{ $stats['completed'] }}</h2>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded">
                                <i class="fa fa-check-double fa-2x text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'rejected']) }}" class="text-decoration-none text-reset">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Rejected</h6>
                                <h2 class="mb-0 text-danger">{{ $stats['rejected'] }}</h2>
                            </div>
                            <div class="bg-danger bg-opacity-10 p-3 rounded">
                                <i class="fa fa-times-circle fa-2x text-danger"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total completed</h6>
                            <h2 class="mb-0 text-primary">€{{ number_format($stats['total_amount'], 2) }}</h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded">
                            <i class="fa fa-euro-sign fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="pending" @selected($status === 'pending')>Pending</option>
                        <option value="reported_paid" @selected($reportedPaid)>Pending (reported paid)</option>
                        <option value="completed" @selected($status === 'completed')>Completed</option>
                        <option value="rejected" @selected($status === 'rejected')>Rejected</option>
                        <option value="approved" @selected($status === 'approved')>Approved</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <x-slb-search-field name="search" id="adminDepositsSearch" :value="$search" placeholder="Reference, Name, Email" input-class="form-control" label-class="form-label fw-semibold" />
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa fa-search me-1"></i> Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">&nbsp;</label>
                    <a href="{{ route('admin.deposits') }}" class="btn btn-outline-secondary w-100">
                        <i class="fa fa-refresh me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Deposits Table -->
    <div class="card border-0 shadow-sm admin-table-fit">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 admin-deposits-table">
                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col">ID</th>
                            <th>User</th>
                            <th>Reference Code</th>
                            <th class="admin-narrow-col">Amount</th>
                            <th>Payment Method</th>
                            <th class="admin-status-col">Status</th>
                            <th class="admin-narrow-col">Date</th>
                            <th class="admin-actions-col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deposits as $deposit)
                        <tr>
                            <td>#{{ $deposit->id }}</td>
                            <td>
                                @php
                                    $depositUser = $deposit->user;
                                    $depositUserName = $depositUser->name ?? 'Unknown';
                                    $depositUserInitial = strtoupper(substr($depositUserName, 0, 1) ?: '?');
                                @endphp
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-2" style="width: 32px; height: 32px; background: linear-gradient(135deg, #1a585e 0%, #3faeb2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                        {{ $depositUserInitial }}
                                    </div>
                                    <div>
                                        <strong>{{ $depositUserName }}</strong><br>
                                        @if($depositUser?->email)
                                            <small class="text-muted slb-text-break">{{ $depositUser->email }}</small>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td><code class="font-monospace">{{ $deposit->reference_code }}</code></td>
                            <td class="fw-semibold text-primary">€{{ number_format($deposit->amount, 2) }}</td>
                            <td>
                                <span class="badge bg-secondary">{{ ucfirst($deposit->payment_method) }}</span>
                            </td>
                            <td>
                                @if($deposit->status == 'pending')
                                    <span class="badge bg-warning text-dark">Pending</span>
                                    @if($deposit->user_marked_paid_at)
                                        <div class="small text-success mt-1">
                                            <i class="fa fa-check-circle"></i> User reported paid
                                        </div>
                                    @endif
                                @elseif($deposit->status == 'approved')
                                    <span class="badge bg-info text-dark">Approved</span>
                                @elseif($deposit->status == 'completed')
                                    <span class="badge bg-success">Completed</span>
                                @elseif($deposit->status == 'rejected')
                                    <span class="badge bg-danger">Rejected</span>
                                @endif
                            </td>
                            <td>{{ $deposit->created_at->format('M d, Y') }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <button class="btn btn-sm btn-outline-primary view-deposit"
                                            data-id="{{ $deposit->id }}"
                                            data-show-url="{{ route('admin.deposits.show', $deposit->id) }}">
                                        <i class="fa fa-eye"></i> View
                                    </button>
                                    @if(!empty($invoiceLinks[$deposit->id]))
                                        <a href="{{ $invoiceLinks[$deposit->id]['url'] }}" class="btn btn-sm btn-outline-secondary">
                                            Invoice
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No deposit requests found</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="mt-3">
        {{ $deposits->links() }}
    </div>
</div>

<!-- Deposit Details Modal -->
<div class="modal fade" id="depositModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Deposit Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="depositModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = @json(csrf_token());
    const approveUrlTemplate = @json(route('admin.deposits.approve', ['id' => '__ID__']));
    const rejectUrlTemplate = @json(route('admin.deposits.reject', ['id' => '__ID__']));

    function depositActionUrl(template, id) {
        return String(template).replace('__ID__', encodeURIComponent(id));
    }

    function jsonHeaders(extra) {
        return Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        }, extra || {});
    }

    function readJsonResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            const expired = response.status === 419;
            return Promise.reject(new Error(expired
                ? 'Your session expired. Refresh the page and try again.'
                : 'Request failed (' + response.status + ').'));
        }

        return response.json().then(function (data) {
            if (!data || typeof data !== 'object') {
                return { success: false, message: 'Request failed (' + response.status + ').' };
            }
            if (!data.message && !response.ok) {
                data.message = 'Request failed (' + response.status + ').';
            }
            return data;
        });
    }

    // View deposit details
    document.querySelectorAll('.view-deposit').forEach(button => {
        button.addEventListener('click', function() {
            const url = this.dataset.showUrl;
            if (!url) {
                Swal.fire('Error', 'Failed to load deposit details', 'error');
                return;
            }

            fetch(url, {
                method: 'GET',
                headers: jsonHeaders()
            })
            .then(readJsonResponse)
            .then(data => {
                if (data.success) {
                    renderDepositModal(data.deposit, data.invoice);
                    const modal = new bootstrap.Modal(document.getElementById('depositModal'));
                    modal.show();
                } else {
                    Swal.fire('Error', data.message || 'Failed to load deposit details', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', error.message || 'Failed to load deposit details', 'error');
            });
        });
    });
    
    function renderDepositModal(deposit, invoice) {
        let statusBadge = '';
        if (deposit.status === 'pending') {
            statusBadge = '<span class="badge bg-warning">Pending</span>';
        } else if (deposit.status === 'approved') {
            statusBadge = '<span class="badge bg-info">Approved</span>';
        } else if (deposit.status === 'completed') {
            statusBadge = '<span class="badge bg-success">Completed</span>';
        } else if (deposit.status === 'rejected') {
            statusBadge = '<span class="badge bg-danger">Rejected</span>';
        }
        
        const user = deposit.user || {};
        const userName = user.name || 'Unknown';
        const userEmail = user.email || '';
        const userInitial = String(userName).charAt(0).toUpperCase() || '?';

        let html = `
            <div class="mb-3">
                <label class="fw-semibold text-muted small">User Information</label>
                <div class="border rounded p-3 mt-1 bg-light">
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle me-3" style="width: 48px; height: 48px; background: linear-gradient(135deg, #1a585e 0%, #3faeb2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: 600;">
                            ${escapeHtml(userInitial)}
                        </div>
                        <div>
                            <h6 class="mb-1">${escapeHtml(userName)}</h6>
                            ${userEmail ? `<small class="text-muted">${escapeHtml(userEmail)}</small>` : ''}
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="fw-semibold text-muted small">Deposit Details</label>
                <div class="border rounded p-3 mt-1 bg-light">
                    <div class="row">
                        <div class="col-6 mb-2">
                            <small class="text-muted">Reference Code</small>
                            <div><code class="font-monospace">${escapeHtml(deposit.reference_code)}</code></div>
                        </div>
                        <div class="col-6 mb-2">
                            <small class="text-muted">Amount</small>
                            <div class="fw-bold text-primary">€${parseFloat(deposit.amount).toFixed(2)}</div>
                        </div>
                        <div class="col-6 mb-2">
                            <small class="text-muted">Payment Method</small>
                            <div>${escapeHtml(String(deposit.payment_method || '').toUpperCase())}</div>
                        </div>
                        <div class="col-6 mb-2">
                            <small class="text-muted">Status</small>
                            <div>${statusBadge}</div>
                        </div>
                        <div class="col-6 mb-2">
                            <small class="text-muted">User reported paid</small>
                            <div>${deposit.user_marked_paid_at
                                ? `<span class="badge bg-success">Yes</span> <small class="text-muted">${new Date(deposit.user_marked_paid_at).toLocaleString()}</small>`
                                : '<span class="text-muted">Not yet</span>'}</div>
                        </div>
                        ${deposit.user_payment_note ? `
                        <div class="col-12 mb-2">
                            <small class="text-muted">User payment note</small>
                            <div>${escapeHtml(deposit.user_payment_note)}</div>
                        </div>` : ''}
                        <div class="col-12">
                            <small class="text-muted">Submitted Date</small>
                            <div>${new Date(deposit.created_at).toLocaleString()}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        if (invoice && invoice.url) {
            html += `
                <div class="mb-3">
                    <label class="fw-semibold text-muted small">Invoice</label>
                    <div class="border rounded p-3 mt-1 bg-light">
                        <a href="${escapeHtml(invoice.url)}">${escapeHtml(invoice.invoice_number || 'Open invoice')}</a>
                        <span class="text-muted"> · ${escapeHtml(invoice.type_label || 'Deposit Receipt')}</span>
                    </div>
                </div>
            `;
        }
        
        if (deposit.admin_notes) {
            html += `
                <div class="mb-3">
                    <label class="fw-semibold text-muted small">Admin Notes</label>
                    <div class="border rounded p-3 mt-1 bg-light">
                        ${escapeHtml(deposit.admin_notes)}
                    </div>
                </div>
            `;
        }
        
        if (deposit.status === 'pending') {
            html += `
                <hr>
                <div class="mb-3">
                    <label class="fw-semibold text-muted small">Admin Notes (Optional)</label>
                    <textarea id="adminNotes" class="form-control" rows="3" placeholder="Add notes about this deposit..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-success approve-deposit" data-id="${deposit.id}">
                        <i class="fa fa-check"></i> Approve & Add Funds
                    </button>
                    <button class="btn btn-danger reject-deposit" data-id="${deposit.id}">
                        <i class="fa fa-times"></i> Reject
                    </button>
                </div>
            `;
        }
        
        document.getElementById('depositModalBody').innerHTML = html;
        
        // Attach event listeners to new buttons
        document.querySelectorAll('.approve-deposit').forEach(btn => {
            btn.addEventListener('click', function() {
                approveDeposit(this.dataset.id);
            });
        });
        
        document.querySelectorAll('.reject-deposit').forEach(btn => {
            btn.addEventListener('click', function() {
                rejectDeposit(this.dataset.id);
            });
        });
    }
    
    function approveDeposit(id) {
        const notes = document.getElementById('adminNotes')?.value || '';
        
        Swal.fire({
            title: 'Approve Deposit?',
            text: 'This will add funds to the user\'s wallet.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Approve',
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                fetch(depositActionUrl(approveUrlTemplate, id), {
                    method: 'POST',
                    headers: jsonHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ admin_notes: notes })
                })
                .then(readJsonResponse)
                .then(data => {
                    if (data.success) {
                        let message = data.message;
                        if (data.email_sent) {
                            message += ' ✓ Email sent to user.';
                        } else {
                            message += ' ⚠ Email could not be sent.';
                        }
                        Swal.fire('Success', message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to approve deposit', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', error.message || 'Failed to approve deposit', 'error');
                });
            }
        });
    }
    
    function rejectDeposit(id) {
        const notes = document.getElementById('adminNotes')?.value || '';
        
        Swal.fire({
            title: 'Reject Deposit?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Reject',
            cancelButtonText: 'Cancel',
            customClass: { confirmButton: 'slb-swal-danger' }
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                fetch(depositActionUrl(rejectUrlTemplate, id), {
                    method: 'POST',
                    headers: jsonHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ admin_notes: notes })
                })
                .then(readJsonResponse)
                .then(data => {
                    if (data.success) {
                        let message = data.message;
                        if (data.email_sent) {
                            message += ' ✓ Email sent to user.';
                        } else {
                            message += ' ⚠ Email could not be sent.';
                        }
                        Swal.fire('Success', message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to reject deposit', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', error.message || 'Failed to reject deposit', 'error');
                });
            }
        });
    }
    
    function escapeHtml(str) {
        if (str == null || str === '') return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
});
</script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

@endsection