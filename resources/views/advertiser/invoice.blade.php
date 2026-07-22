<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $referenceCode }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: Arial, sans-serif; 
            color: #333; 
            background-color: #f5f5f5;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            font-size: 14px;
            padding: 20px;
        }
        
        .invoice-container { 
            max-width: 900px; 
            margin: 0 auto; 
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .invoice-header {
            padding: 30px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .invoice-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #2563eb;
            margin: 0;
            letter-spacing: 1px;
        }
        
        .invoice-body {
            padding: 30px;
        }
        
        .company-section {
            margin-bottom: 30px;
        }
        
        .company-logo {
            height: 50px;
            width: auto;
            margin-bottom: 15px;
        }
        
        .company-details {
            font-size: 13px;
            color: #555;
            line-height: 1.6;
        }
        
        .company-details p {
            margin: 4px 0;
        }
        
        .two-columns {
            display: flex;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .column {
            flex: 1;
        }
        
        .bill-to {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
        }
        
        .bill-to h3, .bank-details h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #1f2937;
            border-left: 3px solid #2563eb;
            padding-left: 10px;
        }
        
        .bill-to p, .bank-details p {
            margin: 6px 0;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .bank-details {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 10px;
        }
        
        .info-label {
            width: 100px;
            font-weight: 600;
            color: #4b5563;
        }
        
        .info-value {
            flex: 1;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th {
            background: #f3f4f6;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .totals {
            margin-top: 20px;
            text-align: right;
        }
        
        .total-row {
            display: flex;
            justify-content: flex-end;
            margin: 8px 0;
        }
        
        .total-label {
            font-weight: 600;
            width: 120px;
        }
        
        .total-value {
            width: 120px;
            text-align: right;
            font-weight: 600;
        }
        
        .grand-total {
            border-top: 2px solid #2563eb;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            z-index: 1000;
        }
        
        .print-btn:hover {
            background: #1d4ed8;
        }
        
        @media print {
            body {
                background-color: white;
                padding: 0;
            }
            .print-btn,
            .no-print {
                display: none !important;
            }
            .invoice-container {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()" type="button" aria-label="Print invoice">Print Invoice</button>
    
    <div class="invoice-container">
        <div class="invoice-header">
            <h1>INVOICE #{{ $referenceCode }}</h1>
            <!-- Date -->
            <div style="margin-top: 10px; color: #4b5563;">
                <span><strong>Date:</strong> {{ \Carbon\Carbon::now()->format('F j, Y') }}</span>
            </div>   
        </div>
        
        <div class="invoice-body">
            <div class="two-columns">
                <div class="column">
                    <div class="company-section">
                        <img src="{{ asset('assets/img/topurl-logo.png') }}" alt="TopURLZ" class="company-logo" onerror="this.style.display='none'">
                        <div class="company-details">
                            <p><strong>Seller / Service Provider:</strong> TopURLZ Ltd</p>
                            <p><strong>BIC (SWIFT):</strong> TRWIBEB1XXX</p>
                            <p><strong>IBAN:</strong> BE04905543949331</p>
                            <p><strong>Phone No:</strong> +44 7445 152374</p>
                            <p><strong>Address:</strong> 20 Wenlock Road, London, England, N1 7GU</p>
                            <p><strong>Registration No:</strong> 16607074</p>
                            <p><strong>VAT:</strong> Not VAT registered – no VAT charged</p>
                        </div>
                    </div>
                </div>
                
                <div class="column">
                    <div class="bill-to">
                        <h3>Bill To</h3>
                        <p><strong>Name:</strong> {{ $billingName }}</p>
                        @if($companyName)
                            <p><strong>Company:</strong> {{ $companyName }}</p>
                        @endif
                        <p><strong>Country:</strong> {{ $country }}</p>
                        <p><strong>City:</strong> {{ $city }}</p>
                        <p><strong>State/Province:</strong> {{ $state }}</p>
                        <p><strong>Address:</strong> {{ $address }}</p>
                        <p><strong>Postal Code:</strong> {{ $postalCode }}</p>
                        @if($vatNumber)
                            <p><strong>VAT Number:</strong> {{ $vatNumber }}</p>
                        @endif
                        <p><strong>Email:</strong> {{ $userEmail }}</p>
                    </div>
                </div>
            </div>
            
            
            @if($invoiceType == 'order' && isset($orderItems) && count($orderItems) > 0)
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th width="150">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orderItems as $item)
                    <tr>
                        <td>
                            <strong>{{ $item['site_name'] }}</strong>
                            @if(isset($item['site_url']))
                                <br><small style="color: #6b7280;">{{ $item['site_url'] }}</small>
                            @endif
                            @if(isset($item['sensitive_type']) && $item['sensitive_type'])
                                <br><small style="color: #16a34a;"><i class="fa fa-plus-circle"></i> {{ ucfirst($item['sensitive_type']) }} price included</small>
                            @endif
                        </td>
                        <td>€{{ number_format($item['price'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            
            @if(isset($totalBaseAmount) && $totalBaseAmount > 0)
            <div style="font-size: 12px; color: #6b7280; margin-top: -10px; margin-bottom: 10px;">
                <p>Base Amount: €{{ number_format($totalBaseAmount, 2) }} | Sensitive Add-ons: €{{ number_format($totalSensitiveAmount, 2) }}</p>
            </div>
            @endif
            @endif
            
            @if($invoiceType == 'deposit')
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th width="150">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Wallet Deposit - Reference: REF{{ $referenceCode }}<br>
                        </td>
                        <td>€{{ number_format($amount, 2) }}</td>
                    </tr>
                </tbody>
            </table>
            @endif
            
            <div class="totals">
                <div class="total-row">
                    <div class="total-label">Subtotal:</div>
                    <div class="total-value">€{{ number_format($amount, 2) }}</div>
                </div>
                <div class="total-row">
                    <div class="total-label">VAT (0%):</div>
                    <div class="total-value">€0.00</div>
                </div>
                <div class="total-row grand-total">
                    <div class="total-label">Total:</div>
                    <div class="total-value" style="font-size: 18px; color: #2563eb;">€{{ number_format($amount, 2) }}</div>
                </div>
            </div>
            
            
            <div class="footer">
                <p>Thank you for your business!</p>
                <p>For any questions regarding this invoice, please contact support@seolinkbuildings.com</p>
            </div>

            @if(($invoiceType ?? '') === 'deposit' && in_array(($paymentMethod ?? ''), ['wise', 'bank', 'crypto'], true))
                <div class="no-print" style="margin-top: 28px; padding: 18px; border: 1px solid #c8ebe9; border-radius: 12px; background: #f0fbfb;">
                    <div style="font-weight: 700; color: #185054; margin-bottom: 8px;">After you send the transfer</div>
                    <p style="margin: 0 0 12px; color: #64748b; font-size: 14px;">
                        Click the button below once you have paid. Your deposit stays <strong>Pending</strong> until we confirm funds and credit your wallet.
                    </p>
                    @if(!empty($userMarkedPaid))
                        <button type="button" disabled style="border:0; background:#059669; color:#fff; padding:10px 16px; border-radius:8px; font-weight:600;">
                            Payment reported — awaiting confirmation
                        </button>
                        @if(!empty($deposit?->user_marked_paid_at))
                            <div style="margin-top:8px; font-size:13px; color:#64748b;">
                                Reported {{ $deposit->user_marked_paid_at->format('M j, Y g:i A') }}
                                @if($deposit->user_payment_note)
                                    · Note: {{ $deposit->user_payment_note }}
                                @endif
                            </div>
                        @endif
                    @elseif(!empty($canMarkPaid) && !empty($markPaidUrl))
                        <button type="button" id="invoiceMarkPaidBtn"
                                style="border:0; background:#185054; color:#fff; padding:10px 16px; border-radius:8px; font-weight:600; cursor:pointer;">
                            OK, I have made the payment
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>
    @if(($invoiceType ?? '') === 'deposit' && !empty($canMarkPaid) && !empty($markPaidUrl))
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    document.getElementById('invoiceMarkPaidBtn')?.addEventListener('click', function () {
        Swal.fire({
            title: 'Confirm payment sent?',
            html: 'Have you already transferred <strong>€{{ number_format((float) $amount, 2) }}</strong> with <strong>REF{{ $referenceCode }}</strong> in the payment note?<br><br><span style="color:#64748b;font-size:13px;">Status stays Pending until we confirm and credit your wallet.</span>',
            icon: 'question',
            input: 'text',
            inputPlaceholder: 'Optional: Wise/bank transfer reference',
            showCancelButton: true,
            confirmButtonText: 'OK, I have made the payment',
            cancelButtonText: 'Not yet',
            confirmButtonColor: '#185054',
        }).then(function (result) {
            if (!result.isConfirmed) return;
            fetch(@json($markPaidUrl), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token()),
                },
                body: JSON.stringify({ user_payment_note: result.value || null }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    Swal.fire('Error', data.message || 'Could not mark payment as sent.', 'error');
                    return;
                }
                Swal.fire('Payment reported', data.message, 'success').then(function () {
                    window.location.reload();
                });
            })
            .catch(function () {
                Swal.fire('Error', 'Could not mark payment as sent. Please try again.', 'error');
            });
        });
    });
    </script>
    @endif
</body>
</html>