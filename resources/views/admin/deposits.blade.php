@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Deposit Requests</h2>
            <p class="text-muted">Manage and approve user deposit requests</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-lg">
            <div class="card border-0 shadow-sm">
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
        </div>
        <div class="col-md-3 col-lg">
            <div class="card border-0 shadow-sm">
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
        </div>
        <div class="col-md-3 col-lg">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Approved</h6>
                            <h2 class="mb-0 text-info">{{ $stats['approved'] }}</h2>
                        </div>
                        <div class="bg-info bg-opacity-10 p-3 rounded">
                            <i class="fa fa-check-circle fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-lg">
            <div class="card border-0 shadow-sm">
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
        </div>
        <div class="col-md-3 col-lg">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Amount</h6>
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
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Reference, Name, Email" value="{{ request('search') }}">
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
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Reference Code</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deposits as $deposit)
                        <tr>
                            <td>#{{ $deposit->id }}</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-2" style="width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                        {{ strtoupper(substr($deposit->user->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <strong>{{ $deposit->user->name }}</strong><br>
                                        <small class="text-muted">{{ $deposit->user->email }}</small>
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
                                    <span class="badge bg-warning">Pending</span>
                                    @if($deposit->user_marked_paid_at)
                                        <div class="small text-success mt-1">
                                            <i class="fa fa-check-circle"></i> User reported paid
                                        </div>
                                    @endif
                                @elseif($deposit->status == 'approved')
                                    <span class="badge bg-info">Approved</span>
                                @elseif($deposit->status == 'completed')
                                    <span class="badge bg-success">Completed</span>
                                @elseif($deposit->status == 'rejected')
                                    <span class="badge bg-danger">Rejected</span>
                                @endif
                            </td>
                            <td>{{ $deposit->created_at->format('M d, Y') }}</td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary view-deposit" data-id="{{ $deposit->id }}">
                                    <i class="fa fa-eye"></i> View
                                </button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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

<style>
.font-monospace {
    font-family: monospace;
    letter-spacing: 0.5px;
}

.bg-opacity-10 {
    --bs-bg-opacity: 0.1;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // View deposit details
    document.querySelectorAll('.view-deposit').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.dataset.id;
            
            fetch('/admin/deposits/' + id, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderDepositModal(data.deposit);
                    const modal = new bootstrap.Modal(document.getElementById('depositModal'));
                    modal.show();
                } else {
                    Swal.fire('Error', data.message || 'Failed to load deposit details', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'Failed to load deposit details', 'error');
            });
        });
    });
    
    function renderDepositModal(deposit) {
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
        
        let html = `
            <div class="mb-3">
                <label class="fw-semibold text-muted small">User Information</label>
                <div class="border rounded p-3 mt-1 bg-light">
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle me-3" style="width: 48px; height: 48px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: 600;">
                            ${deposit.user.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                            <h6 class="mb-1">${escapeHtml(deposit.user.name)}</h6>
                            <small class="text-muted">${escapeHtml(deposit.user.email)}</small>
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
                            <div><code class="font-monospace">${deposit.reference_code}</code></div>
                        </div>
                        <div class="col-6 mb-2">
                            <small class="text-muted">Amount</small>
                            <div class="fw-bold text-primary">€${parseFloat(deposit.amount).toFixed(2)}</div>
                        </div>
                        <div class="col-6 mb-2">
                            <small class="text-muted">Payment Method</small>
                            <div>${deposit.payment_method.toUpperCase()}</div>
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
            confirmButtonColor: '#28a745'
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
                
                fetch(`/admin/deposits/${id}/approve`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ admin_notes: notes })
                })
                .then(response => response.json())
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
                    Swal.fire('Error', 'Failed to approve deposit', 'error');
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
            confirmButtonColor: '#dc3545'
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
                
                fetch(`/admin/deposits/${id}/reject`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ admin_notes: notes })
                })
                .then(response => response.json())
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
                    Swal.fire('Error', 'Failed to reject deposit', 'error');
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