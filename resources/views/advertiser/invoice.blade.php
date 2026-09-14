<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $docStatus = $documentStatus ?? $status ?? '';
        $payStatus = $paymentStatus ?? '';
        $isClosed = $isClosed ?? (
            in_array($docStatus, ['rejected', 'refunded', 'cancelled', 'failed'], true)
            || in_array($payStatus, ['failed', 'refunded'], true)
        );
        $invoiceHeading = match (true) {
            $isClosed && in_array($docStatus, ['rejected', 'cancelled'], true) => 'CANCELLED',
            $isClosed && ($docStatus === 'refunded' || $payStatus === 'refunded') => 'REFUNDED',
            $isClosed && ($docStatus === 'failed' || $payStatus === 'failed') => 'PAYMENT FAILED',
            default => 'INVOICE',
        };
    @endphp
    <title>{{ $invoiceHeading }} #{{ $referenceCode }}</title>
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
            max-width: 220px;
            object-fit: contain;
            margin-bottom: 15px;
        }
        
        .company-details {
            font-size: 13px;
            color: #555;
            line-height: 1.6;
        }
        
        .company-details p {
            margin: 4px 0;
            overflow-wrap: anywhere;
            word-break: break-word;
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
            <h1>{{ $invoiceHeading }} #{{ $referenceCode }}</h1>
            <!-- Date -->
            <div style="margin-top: 10px; color: #4b5563;">
                <span><strong>Date:</strong> {{ \Carbon\Carbon::now()->format('F j, Y') }}</span>
                @if(!empty($paymentMethod))
                    <span style="margin-left: 16px;"><strong>Payment method:</strong> {{ \App\Models\Invoice::paymentMethodLabel($paymentMethod) }}</span>
                @endif
            </div> 
        </div>
        
        <div class="invoice-body">
            @if($isClosed)
                <div style="margin-bottom: 20px; padding: 12px 14px; border-radius: 8px; background: #eef2ff; color: #3730a3; font-weight: 600;">
                    This {{ ($invoiceType ?? '') === 'deposit' ? 'wallet deposit' : 'invoice' }} is {{ $docStatus ?: $payStatus }} and is not payable.
                </div>
            @endif
            <div class="two-columns">
                <div class="column">
                    <div class="company-section">
                        @php
                            $company = config('billing.company', []);
                            $depositPayment = config('billing.deposit_payment', []);
                            $invoiceLogo = billing_company_logo_data_uri() ?: asset(ltrim((string) ($company['logo_path'] ?? 'assets/img/email-logo.png'), '/'));
                            $isDepositInvoice = ($invoiceType ?? '') === 'deposit';
                        @endphp
                        <img src="{{ $invoiceLogo }}" alt="{{ $company['name'] ?? 'SEOLinkBuildings' }}" class="company-logo">
                        <div class="company-details">
                            @if($isDepositInvoice && $isClosed)
                                <p><strong>Seller / Service Provider:</strong> {{ $depositPayment['seller_name'] ?? 'SEOLinkBuildings Partner' }}</p>
                                <p>This wallet deposit is {{ $docStatus }} and is not payable.</p>
                            @elseif($isDepositInvoice)
                                <p><strong>Seller / Service Provider:</strong> {{ $depositPayment['seller_name'] ?? 'SEOLinkBuildings Partner' }}</p>
                                <p><strong>Beneficiary:</strong> {{ $depositPayment['beneficiary'] ?? 'Topurlz Ltd' }}</p>
                                @if(!empty($depositPayment['bic']))
                                    <p><strong>BIC (SWIFT):</strong> {{ $depositPayment['bic'] }}</p>
                                @endif
                                @if(!empty($depositPayment['iban']))
                                    <p><strong>IBAN:</strong> {{ $depositPayment['iban'] }}</p>
                                @endif
                                @if(!empty($depositPayment['phone']))
                                    <p><strong>Phone no:</strong> {{ $depositPayment['phone'] }}</p>
                                @endif
                                @foreach(($depositPayment['address_lines'] ?? []) as $line)
                                    <p><strong>{{ $loop->first ? 'Address:' : '' }}</strong> {{ $line }}</p>
                                @endforeach
                                @if(!empty($depositPayment['registration_no']))
                                    <p><strong>Registration No:</strong> {{ $depositPayment['registration_no'] }}</p>
                                @endif
                                <p><strong>VAT:</strong> {{ $depositPayment['vat_note'] ?? 'Not VAT registered – no VAT charged' }}</p>
                            @else
                                <p><strong>Seller / Service Provider:</strong> {{ $company['legal_name'] ?? $company['name'] ?? 'SEOLinkBuildings' }}</p>
                                @foreach(($company['address_lines'] ?? []) as $line)
                                    <p><strong>{{ $loop->first ? 'Address:' : '' }}</strong> {{ $line }}</p>
                                @endforeach
                                @if(!empty($company['registration_no']))
                                    <p><strong>Registration No:</strong> {{ $company['registration_no'] }}</p>
                                @endif
                                @if(!empty($company['support_email']))
                                    <p><strong>Email:</strong> {{ $company['support_email'] }}</p>
                                @endif
                                @if(!empty($company['vat_number']))
                                    <p><strong>VAT:</strong> {{ $company['vat_number'] }}</p>
                                @else
                                    <p><strong>VAT:</strong> {{ $company['vat_note'] ?? 'Not VAT registered – no VAT charged' }}</p>
                                @endif
                            @endif
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
                            @if(!empty($item['homepage_days']))
                                <br><small style="color: #0f766e;">
                                    Homepage {{ (int) $item['homepage_days'] }} day{{ (int) $item['homepage_days'] === 1 ? '' : 's' }}
                                    @if(($item['homepage_price'] ?? 0) > 0)
                                        (+€{{ number_format($item['homepage_price'], 2) }})
                                    @else
                                        (Free)
                                    @endif
                                </small>
                            @endif
                            @if(!empty($item['social_channels']) && is_array($item['social_channels']))
                                <br><small style="color: #6b7280;">
                                    Social: {{ collect($item['social_channels'])->map(fn ($c) => $c === 'x' ? 'X' : ucfirst((string) $c))->implode(', ') }} included
                                </small>
                            @endif
                        </td>
                        <td>€{{ number_format($item['price'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            
            @if(isset($totalBaseAmount) && $totalBaseAmount > 0)
            <div style="font-size: 12px; color: #6b7280; margin-top: -10px; margin-bottom: 10px;">
                <p>
                    Base Amount: €{{ number_format($totalBaseAmount, 2) }}
                    | Sensitive Add-ons: €{{ number_format($totalSensitiveAmount, 2) }}
                    @if(!empty($totalHomepageAmount) && $totalHomepageAmount > 0)
                        | Homepage: €{{ number_format($totalHomepageAmount, 2) }}
                    @endif
                </p>
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
                @if($isClosed)
                    <p>This document is not payable.</p>
                @else
                    <p>Thank you for your business!</p>
                @endif
                <p>For any questions regarding this invoice, please contact support@seolinkbuildings.com</p>
            </div>

            @if(! $isClosed && ($invoiceType ?? '') === 'deposit' && in_array(($paymentMethod ?? ''), ['wise', 'bank', 'crypto'], true))
                <div class="no-print" style="margin-top: 28px; padding: 18px; border: 1px solid #c8ebe9; border-radius: 12px; background: #f0fbfb;">
                    <div style="font-weight: 700; color: #1a585e; margin-bottom: 8px;">After you send the transfer</div>
                    <p style="margin: 0 0 12px; color: var(--brand-ink-muted, #75787B); font-size: 14px;">
                        Click the button below once you have paid. Your deposit stays <strong>Pending</strong> until we confirm funds and credit your wallet.
                    </p>
                    @if(!empty($userMarkedPaid))
                        <button type="button" disabled style="border:0; background:#059669; color:#fff; padding:10px 16px; border-radius:8px; font-weight:600;">
                            Payment reported — awaiting confirmation
                        </button>
                        @if(!empty($deposit?->user_marked_paid_at))
                            <div style="margin-top:8px; font-size:13px; color: var(--brand-ink-muted, #75787B);">
                                Reported {{ $deposit->user_marked_paid_at->format('M j, Y g:i A') }}
                                @if($deposit->user_payment_note)
                                    · Note: {{ $deposit->user_payment_note }}
                                @endif
                            </div>
                        @endif
                    @elseif(!empty($canMarkPaid) && !empty($markPaidUrl))
                        <button type="button" id="invoiceMarkPaidBtn"
                                style="border:0; background:#1a585e; color:#fff; padding:10px 16px; border-radius:8px; font-weight:600; cursor:pointer;">
                            OK, I have made the payment
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>
    @if(! $isClosed && ($invoiceType ?? '') === 'deposit' && !empty($canMarkPaid) && !empty($markPaidUrl))
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    document.getElementById('invoiceMarkPaidBtn')?.addEventListener('click', function () {
        Swal.fire({
            title: 'Confirm payment sent?',
            html: 'Have you already transferred <strong>€{{ number_format((float) $amount, 2) }}</strong> with <strong>REF{{ $referenceCode }}</strong> in the payment note?<br><br><span style="color: var(--brand-ink-muted, #75787B);font-size:13px;">Status stays Pending until we confirm and credit your wallet.</span>',
            icon: 'question',
            input: 'text',
            inputPlaceholder: 'Optional: Wise/bank transfer reference',
            showCancelButton: true,
            confirmButtonText: 'OK, I have made the payment',
            cancelButtonText: 'Not yet',
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