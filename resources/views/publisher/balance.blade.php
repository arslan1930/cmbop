@extends('publisher.layouts.app')

@section('title', 'Balance')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="mb-1 fw-semibold">Balance</h1>
            <p class="text-muted mb-0">
                View your balances and transfer funds from Publisher to Advertiser wallet.
            </p>
        </div>
    </div>

    <!-- Balance Cards -->
    <div class="row">
        <!-- Publisher Balance Card -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-wallet me-2 text-primary"></i> 
                    Current Balance
                </div>
                <div class="card-body text-center">
                    <h2 class="mb-0" id="publisherBalance" style="color: #10b981;">€{{ number_format($publisherBalance, 2) }}</h2>
                    <p class="text-muted small mt-2">Your wallet balance</p>
                </div>
            </div>
        </div>

        <!-- Advertiser Balance Card -->
        <!-- <div class="col-md-8 mb-4">
            
        </div> -->
    </div>

    <!-- Transfer Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-arrow-right-arrow-left me-2 text-info"></i> Transfer to Advertiser Wallet
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-12">
                    <div class="alert alert-success mb-4">
                        <i class="fa fa-check-circle me-2"></i>
                        <strong>0% Transfer Fee!</strong> Full amount will be transferred instantly.
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mx-auto">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Amount (€)</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light">
                                <i class="fa fa-euro-sign"></i>
                            </span>
                            <input type="number" id="amount" class="form-control" placeholder="0.00" step="0.01" min="1">
                        </div>
                        <small class="text-muted">Minimum transfer: €1.00</small>
                    </div>

                    <!-- Dynamic Balance Preview -->
                    <div class="mb-4 p-3 bg-light rounded">
    <div class="row align-items-center text-center">
        <!-- Left Side: Publisher Balance (After Transfer) -->
        <div class="col-md-5">
            <small class="text-muted">Publisher Balance</small>
            <div class="mt-2">
                <span class="text-muted text-decoration-line-through" id="currentPublisherBalance">€{{ number_format($publisherBalance, 2) }}</span>
                <i class="fa fa-minus-circle text-danger mx-1"></i>
                <span class="fw-bold" id="subtractAmount">€0.00</span>
                <i class="fa fa-equals text-muted mx-1"></i>
                <span class="fw-bold text-primary fs-5" id="newPublisherBalance">€{{ number_format($publisherBalance, 2) }}</span>
            </div>
        </div>

        <!-- Arrow Icon fa exchange-alt -->
        <div class="col-md-2">
            <i class="fa fa-exchange-alt fa-2x text-muted"></i>
            <i class="fa fa-arrow-down fa-2x text-muted d-md-none mt-2"></i>
        </div>

        <!-- Right Side: Advertiser Balance (After Transfer) -->
        <div class="col-md-5">
            <small class="text-muted">Advertiser Balance</small>
            <div class="mt-2">
                <span class="text-muted text-decoration-line-through" id="currentAdvertiserBalance">€{{ number_format($advertiserBalance, 2) }}</span>
                <i class="fa fa-plus-circle text-success mx-1"></i>
                <span class="fw-bold" id="addAmount">€0.00</span>
                <i class="fa fa-equals text-muted mx-1"></i>
                <span class="fw-bold text-success fs-5" id="newAdvertiserBalance">€{{ number_format($advertiserBalance, 2) }}</span>
            </div>
        </div>
    </div>
</div>

                    <div class="d-grid">
                        <button class="btn btn-primary btn-lg" id="transferBtn" disabled>
                            <i class="fa fa-exchange-alt me-2"></i> Transfer to Advertiser Wallet
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transfer History -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <div>
                <i class="fa fa-history me-2"></i> Transfer History
            </div>
            <div>
                <small class="text-muted" id="historyCount"></small>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Amount</th>
                            <th>Fee</th>
                            <th>Net</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="transferHistoryBody">
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading transfer history...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            <div class="d-flex justify-content-center">
                <nav id="historyPagination"></nav>
            </div>
        </div>
    </div>
</div>

<style>
.card-header {
    border-bottom: 1px solid #eee;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.status-completed {
    background-color: #d4edda;
    color: #155724;
}

.amount-debit {
    color: #ef4444;
    font-weight: 600;
}

.amount-credit {
    color: #10b981;
    font-weight: 600;
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let currentPage = 1;
let publisherBalance = parseFloat('{{ $publisherBalance }}');
let advertiserBalance = parseFloat('{{ $advertiserBalance }}');
const baseUrl = window.location.origin;

$(document).ready(function() {
    loadTransferHistory();
    updatePreview();
    
    $('#amount').on('keyup change', function() {
        updatePreview();
        validateTransfer();
    });
    
    $('#transferBtn').on('click', function() {
        let amount = parseFloat($('#amount').val());
        
        if (!amount || amount < 1) {
            Swal.fire('Error', 'Please enter a valid amount (minimum €1)', 'error');
            return;
        }
        
        if (amount > publisherBalance) {
            Swal.fire('Error', `Insufficient balance. Your Publisher balance is €${publisherBalance.toFixed(2)}`, 'error');
            return;
        }
        
        Swal.fire({
            title: 'Confirm Transfer',
            html: `<div class="text-start">
                <p><strong>From:</strong> Publisher Wallet</p>
                <p><strong>To:</strong> Advertiser Wallet</p>
                <p><strong>Amount:</strong> €${amount.toFixed(2)}</p>
                <p><strong>Fee:</strong> €0.00 (0%)</p>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Confirm Transfer',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#28a745'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: baseUrl + '/publisher/balance/transfer',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        amount: amount
                    },
                    beforeSend: function() {
                        $('#transferBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Processing...');
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Success!', response.message, 'success');
                            publisherBalance = response.publisher_balance;
                            advertiserBalance = response.advertiser_balance;
                            $('#publisherBalance').html('€' + publisherBalance.toFixed(2));
                            $('#advertiserBalance').html('€' + advertiserBalance.toFixed(2));
                            $('#currentPublisherBalance').html('€' + publisherBalance.toFixed(2));
                            $('#currentAdvertiserBalance').html('€' + advertiserBalance.toFixed(2));
                            $('#amount').val('');
                            updatePreview();
                            loadTransferHistory();
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function(xhr) {
                        let errorMsg = xhr.responseJSON?.message || 'Transfer failed';
                        Swal.fire('Error!', errorMsg, 'error');
                    },
                    complete: function() {
                        $('#transferBtn').prop('disabled', false).html('<i class="fa fa-exchange-alt me-2"></i> Transfer to Advertiser Wallet');
                        validateTransfer();
                    }
                });
            }
        });
    });
});

function updatePreview() {
    let amount = parseFloat($('#amount').val()) || 0;
    let fee = 0;
    
    let newPublisherBalance = publisherBalance - amount;
    let newAdvertiserBalance = advertiserBalance + amount;
    
    $('#subtractAmount').html('€' + amount.toFixed(2));
    $('#addAmount').html('€' + amount.toFixed(2));
    $('#newPublisherBalance').html('€' + newPublisherBalance.toFixed(2));
    $('#newAdvertiserBalance').html('€' + newAdvertiserBalance.toFixed(2));
    
    // Change color if negative
    if (newPublisherBalance < 0) {
        $('#newPublisherBalance').css('color', '#ef4444');
    } else {
        $('#newPublisherBalance').css('color', '#10b981');
    }
}

function validateTransfer() {
    let amount = parseFloat($('#amount').val()) || 0;
    
    if (amount >= 1 && amount <= publisherBalance) {
        $('#transferBtn').prop('disabled', false);
    } else {
        $('#transferBtn').prop('disabled', true);
    }
}

function loadTransferHistory(page = 1) {
    currentPage = page;
    
    $.ajax({
        url: baseUrl + '/publisher/balance/history?page=' + page,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderTransferHistory(response.transfers);
                renderPagination(response.pagination);
                let from = response.pagination.from || 0;
                let to = response.pagination.to || 0;
                let total = response.pagination.total || 0;
                $('#historyCount').html('Showing ' + from + ' to ' + to + ' of ' + total + ' entries');
            } else {
                console.error('Failed to load history:', response);
                $('#transferHistoryBody').html('\
                    <tr>\
                        <td colspan="8" class="text-center py-5 text-danger">\
                            Failed to load transfer history\
                        <\/td>\
                    </tr>\
                ');
            }
        },
        error: function(xhr) {
            console.error('AJAX Error:', xhr.status, xhr.statusText);
            $('#transferHistoryBody').html('\
                <tr>\
                    <td colspan="8" class="text-center py-5 text-danger">\
                        Error loading transfer history (' + xhr.status + ')\
                    <\/td>\
                <tr>\
            ');
        }
    });
}

function renderTransferHistory(transfers) {
    if (!transfers || transfers.length === 0) {
        $('#transferHistoryBody').html('\
            <tr>\
                <td colspan="8" class="text-center py-5">\
                    <i class="fa fa-inbox fa-3x text-muted"></i>\
                    <p class="mt-2">No transfers found</p>\
                <\/td>\
            </table>\
        ');
        return;
    }
    
    let html = '';
    
    transfers.forEach(function(transfer) {
        let statusClass = transfer.status === 'completed' ? 'status-completed' : '';
        let statusText = transfer.status.charAt(0).toUpperCase() + transfer.status.slice(1);
        
        html += '<tr>' +
            '<td><small>' + new Date(transfer.created_at).toLocaleDateString() + '</small></td>' +
            '<td><code class="small">' + escapeHtml(transfer.reference_code) + '</code></td>' +
            '<td><span class="badge bg-primary">PUBLISHER</span></td>' +
            '<td><span class="badge bg-success">ADVERTISER</span></td>' +
            '<td class="amount-debit">- €' + parseFloat(transfer.amount).toFixed(2) + '</td>' +
            '<td class="text-muted">€' + parseFloat(transfer.fee).toFixed(2) + '</td>' +
            '<td class="amount-credit">+ €' + parseFloat(transfer.net_amount).toFixed(2) + '</td>' +
            '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>' +
            '</tr>';
    });
    
    $('#transferHistoryBody').html(html);
}

function renderPagination(pagination) {
    if (!pagination || pagination.last_page <= 1) {
        $('#historyPagination').html('');
        return;
    }
    
    let paginationHtml = '<ul class="pagination justify-content-center mb-0">';
    
    if (pagination.current_page > 1) {
        paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page - 1) + '">Previous</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    for (let i = 1; i <= pagination.last_page; i++) {
        if (i === pagination.current_page) {
            paginationHtml += '<li class="page-item active"><span class="page-link">' + i + '</span></li>';
        } else if (i >= pagination.current_page - 2 && i <= pagination.current_page + 2) {
            paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + i + '">' + i + '</button></li>';
        }
    }
    
    if (pagination.current_page < pagination.last_page) {
        paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page + 1) + '">Next</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    paginationHtml += '</ul>';
    $('#historyPagination').html(paginationHtml);
    
    $('.page-link[data-page]').off('click').on('click', function() {
        let page = parseInt($(this).data('page'));
        if (page) {
            loadTransferHistory(page);
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        }
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
</script>

@endsection