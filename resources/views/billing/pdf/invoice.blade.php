<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @@page { margin: 36px 40px; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            color: {{ $colors['text'] ?? '#0f172a' }};
            font-size: 11px;
            line-height: 1.45;
            margin: 0;
        }
        .muted { color: {{ $colors['muted'] ?? '#75787B' }}; }
        .primary { color: {{ $colors['primary'] ?? '#1a585e' }}; }
        .header { width: 100%; margin-bottom: 22px; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .brand-name {
            font-size: 18px; font-weight: 700;
            color: {{ $colors['primary'] ?? '#1a585e' }};
            margin: 0 0 4px;
        }
        .doc-title {
            font-size: 20px; font-weight: 700; text-align: right;
            color: {{ $colors['primary'] ?? '#1a585e' }}; margin: 0 0 8px;
        }
        .header-meta { width: 100%; border-collapse: collapse; }
        .header-meta td { text-align: right; padding: 1px 0; }
        .badge {
            display: inline-block; padding: 3px 8px; border-radius: 4px;
            font-size: 10px; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase;
        }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-failed { background: #fee2e2; color: #991b1b; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-refunded { background: #e0e7ff; color: #3730a3; }
        .badge-cancelled { background: #f1f5f9; color: #475569; }
        .badge-issued { background: #e0f2fe; color: #075985; }
        .meta-table { width: 100%; margin: 0 0 20px; border-collapse: collapse; }
        .meta-table td.box {
            width: 49%; vertical-align: top;
            border: 1px solid {{ $colors['border'] ?? '#e2e8f0' }};
            border-radius: 6px; padding: 12px 14px;
        }
        .meta-table td.gap { width: 2%; border: 0; padding: 0; }
        .box {
            border: 1px solid {{ $colors['border'] ?? '#e2e8f0' }};
            border-radius: 6px; padding: 12px 14px;
        }
        .box h4, td.box h4 {
            margin: 0 0 8px; font-size: 10px; text-transform: uppercase;
            letter-spacing: .06em; color: {{ $colors['muted'] ?? '#75787B' }};
        }
        .kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 2px 0; vertical-align: top; }
        .kv .k { width: 34%; color: {{ $colors['muted'] ?? '#75787B' }}; padding-right: 10px; }
        .kv .v { font-weight: 700; }
        table.items { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 4px 0 0; }
        table.items th {
            text-align: left; font-size: 10px; text-transform: uppercase;
            letter-spacing: .04em; color: {{ $colors['muted'] ?? '#75787B' }};
            border-bottom: 1px solid {{ $colors['border'] ?? '#e2e8f0' }};
            padding: 8px 6px;
        }
        table.items td {
            padding: 10px 6px;
            border-bottom: 1px solid {{ $colors['border'] ?? '#e2e8f0' }};
            vertical-align: top;
        }
        table.items th.num,
        table.items td.num,
        .num { text-align: right; white-space: nowrap; }
        table.items tfoot td {
            border-bottom: none;
            padding: 5px 6px;
        }
        table.items tfoot tr.totals-first td { padding-top: 16px; }
        table.items tfoot .label { color: {{ $colors['muted'] ?? '#75787B' }}; font-weight: 400; }
        table.items tfoot tr.grand td {
            padding-top: 10px;
            border-top: 2px solid {{ $colors['primary'] ?? '#1a585e' }};
            font-size: 13px; font-weight: 700;
        }
        .footer {
            width: 100%; margin-top: 28px; padding-top: 14px;
            border-top: 1px solid {{ $colors['border'] ?? '#e2e8f0' }};
            border-collapse: collapse;
            font-size: 10px; color: {{ $colors['muted'] ?? '#75787B' }};
        }
        .footer td { vertical-align: top; padding: 0; }
        .footer .end { text-align: right; }
        .thankyou {
            margin-top: 22px; padding: 12px 14px;
            background: #e6f5f5; border-radius: 6px;
            color: {{ $colors['primary'] ?? '#1a585e' }};
        }
        .failed-banner {
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
            padding: 10px 12px; border-radius: 6px; margin-bottom: 16px;
            font-weight: 700; text-align: center; letter-spacing: .04em;
        }
    </style>
</head>
<body>
@php
    $company = function_exists('billing_company_for_documents')
        ? billing_company_for_documents()
        : ($company ?? config('billing.company'));
    $symbol = $currencySymbol ?? '€';
    $statusClass = match ($invoice->status) {
        'paid' => 'badge-paid',
        'failed' => 'badge-failed',
        'pending' => 'badge-pending',
        'refunded' => 'badge-refunded',
        'cancelled' => 'badge-cancelled',
        default => 'badge-issued',
    };
    $docHeading = match ($invoice->type) {
        'tax_invoice' => 'Invoice',
        'payment_receipt' => 'Payment Receipt',
        'payment_failure' => 'Payment Attempt Receipt',
        'refund_receipt' => 'Refund Receipt',
        'deposit_receipt' => 'Deposit Receipt',
        'withdrawal_payout' => 'Payout Statement',
        default => 'Document',
    };
    // A wallet top-up is money on account, not a supply, so it never carries tax.
    $isDeposit = $invoice->type === 'deposit_receipt';
    $isPayout = $invoice->type === 'withdrawal_payout';
@endphp

@if($invoice->type === 'payment_failure')
    <div class="failed-banner">PAYMENT FAILED</div>
@endif

<table class="header">
    <tr>
        <td width="58%">
            @php
                // Dompdf needs PHP GD for PNG data-URIs. InvoicePdfGenerator
                // sets includeLogo=false when gd is missing so PDFs still render.
                $includeLogo = $includeLogo ?? true;
                $logoDataUri = $includeLogo ? billing_company_logo_data_uri() : null;
            @endphp
            @if($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="{{ $company['name'] ?? 'SEOLinkBuildings' }}" style="height:42px;width:auto;max-width:220px;margin:0 0 10px 0;display:block;">
            @endif
            <p class="brand-name">{{ $company['name'] ?? 'SEOLinkBuildings' }}</p>
            @foreach(($company['address_lines'] ?? []) as $line)
                <div class="muted">{{ $line }}</div>
            @endforeach
            @if(!empty($company['support_email']))
                <div class="muted">{{ $company['support_email'] }}</div>
            @endif
            @if(!empty($company['website_url']))
                <div class="muted">{{ $company['website_url'] }}</div>
            @endif
            @if(!empty($company['vat_number']))
                <div class="muted">VAT: {{ $company['vat_number'] }}</div>
            @else
                <div class="muted">VAT: {{ $company['vat_note'] ?? 'Not VAT registered – no VAT charged' }}</div>
            @endif
            @if(!empty($company['registration_no']))
                <div class="muted">Registration No: {{ $company['registration_no'] }}</div>
            @endif
        </td>
        <td width="42%">
            <p class="doc-title">{{ $docHeading }}</p>
            <table class="header-meta">
                <tr>
                    <td>
                        <span class="badge {{ $statusClass }}">{{ strtoupper($invoice->status) }}</span>
                    </td>
                </tr>
                <tr>
                    <td><strong>{{ $invoice->invoice_number }}</strong></td>
                </tr>
                <tr>
                    <td class="muted">Date: {{ optional($invoice->invoice_date)->format('M j, Y') }}</td>
                </tr>
                @if($invoice->due_date)
                    <tr>
                        <td class="muted">Due: {{ $invoice->due_date->format('M j, Y') }}</td>
                    </tr>
                @endif
                @if($invoice->paid_at)
                    <tr>
                        <td class="muted">Paid: {{ $invoice->paid_at->format('M j, Y') }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table class="meta-table">
    <tr>
        <td class="box">
            <h4>{{ $isPayout ? 'Pay to' : 'Bill to' }}</h4>
            <div><strong>{{ $invoice->customer_name }}</strong></div>
            <div class="muted">{{ $invoice->customer_email }}</div>
            @php $bill = $invoice->billing_snapshot ?? []; @endphp
            @if(!empty($bill['company']) && trim((string) $bill['company']) !== trim((string) $invoice->customer_name))
                <div><strong>{{ $bill['company'] }}</strong></div>
            @endif
            @if(!empty($bill['address'])) <div class="muted">{{ $bill['address'] }}</div> @endif
            @if(!empty($bill['city']) || !empty($bill['state']) || !empty($bill['postal_code']))
                @php
                    $billLocality = trim(implode(', ', array_filter([
                        $bill['city'] ?? null,
                        $bill['state'] ?? null,
                        $bill['postal_code'] ?? null,
                    ])));
                @endphp
                @if($billLocality !== '')
                    <div class="muted">{{ $billLocality }}</div>
                @endif
            @endif
            @if(!empty($bill['country'])) <div class="muted">{{ $bill['country'] }}</div> @endif
            @if(!empty($bill['vat_number'])) <div class="muted">VAT / Tax ID: {{ $bill['vat_number'] }}</div> @endif
        </td>
        <td class="gap"></td>
        <td class="box">
            <h4>
                @if($isDeposit)
                    Payment details
                @elseif($isPayout)
                    Payout details
                @else
                    Payment &amp; order
                @endif
            </h4>
            <table class="kv">
                @unless($isDeposit || $isPayout)
                    <tr>
                        <td class="k">Order</td>
                        <td class="v">#{{ $invoice->order_number }}</td>
                    </tr>
                @endunless
                @if($invoice->reference_code)
                    <tr>
                        <td class="k">Ref</td>
                        <td class="v">{{ $invoice->reference_code }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="k">Method</td>
                    <td class="v">{{ \App\Models\Invoice::paymentMethodLabel($invoice->payment_method) }}</td>
                </tr>
                <tr>
                    <td class="k">Status</td>
                    <td class="v">{{ ucfirst((string) $invoice->payment_status) }}</td>
                </tr>
                @if($invoice->transaction_id && (string) $invoice->transaction_id !== (string) $invoice->reference_code)
                    <tr>
                        <td class="k">Txn</td>
                        <td class="v">{{ $invoice->transaction_id }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="k">Currency</td>
                    <td class="v">{{ $invoice->currency }}</td>
                </tr>
                <tr>
                    <td class="k">Amount</td>
                    <td class="v">{{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }}</td>
                </tr>
                @if($isPayout)
                    @php
                        $payoutDest = \App\Models\Invoice::maskedPayoutDestination(
                            data_get($invoice->billing_snapshot, 'payment_details'),
                            $invoice->payment_method
                        );
                    @endphp
                    @if($payoutDest)
                        <tr>
                            <td class="k">Sent to</td>
                            <td class="v">{{ $payoutDest }}</td>
                        </tr>
                    @endif
                @endif
            </table>
        </td>
    </tr>
</table>

@if($invoice->type === 'refund_receipt')
    <div class="box" style="margin-bottom:16px;">
        <h4>Refund details</h4>
        <div>Refund amount: <strong>{{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }}</strong></div>
        <div>Refund date: {{ optional($invoice->invoice_date)->format('M j, Y g:i A') }}</div>
        @if($invoice->parentInvoice)
            <div>Original invoice: <strong>{{ $invoice->parentInvoice->invoice_number }}</strong></div>
        @elseif(!empty(data_get($invoice->meta, 'original_invoice')))
            <div>Original invoice: <strong>{{ data_get($invoice->meta, 'original_invoice') }}</strong></div>
        @endif
        @if($invoice->notes)
            <div class="muted" style="margin-top:6px;">Reason: {{ $invoice->notes }}</div>
        @endif
    </div>
@endif

@if($invoice->type === 'payment_failure' && $invoice->notes)
    <div class="box" style="margin-bottom:16px;">
        <h4>Failure details</h4>
        <div>{{ $invoice->notes }}</div>
    </div>
@endif

<table class="items">
    <colgroup>
        <col style="width:40%;">
        <col style="width:24%;">
        <col style="width:8%;">
        <col style="width:14%;">
        <col style="width:14%;">
    </colgroup>
    <thead>
        <tr>
            <th>{{ ($isDeposit || $isPayout) ? 'Description' : 'Service' }}</th>
            <th>
                @if($isDeposit || $isPayout)
                    Reference
                @else
                    Publisher website
                @endif
            </th>
            <th class="num">Qty</th>
            <th class="num">Unit</th>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        @php
            // Legacy payout payloads stored the fee as a negative line item AND in totals.
            $displayLines = collect($invoice->line_items ?? [])
                ->filter(function ($line) use ($isPayout) {
                    if (! is_array($line)) {
                        return false;
                    }
                    if (! $isPayout) {
                        return true;
                    }
                    $total = (float) ($line['line_total'] ?? $line['unit_price'] ?? 0);
                    $desc = strtolower((string) ($line['description'] ?? ''));

                    return $total >= 0
                        && ! str_contains($desc, 'withdrawal fee')
                        && ! str_contains($desc, 'platform fee');
                })
                ->values();
        @endphp
        @forelse($displayLines as $line)
            <tr>
                <td>{{ $line['description'] ?? 'Service' }}</td>
                <td>
                    @if($isDeposit || $isPayout)
                        {{ $line['reference'] ?? '—' }}
                    @else
                        {{ $line['publisher_website'] ?? ($line['site_url'] ?? '—') }}
                    @endif
                </td>
                <td class="num">{{ $line['quantity'] ?? 1 }}</td>
                <td class="num">{{ $symbol }}{{ number_format((float) ($line['unit_price'] ?? $line['price'] ?? 0), 2) }}</td>
                <td class="num">{{ $symbol }}{{ number_format((float) ($line['line_total'] ?? $line['total'] ?? 0), 2) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="muted">No line items</td>
            </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr class="totals-first">
            <td colspan="4" class="num label">{{ $isPayout ? 'Gross' : 'Subtotal' }}</td>
            <td class="num">{{ $symbol }}{{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        @if((float) $invoice->discount_amount > 0)
            <tr>
                <td colspan="4" class="num label">
                    @if($isPayout)
                        Withdrawal fee
                    @else
                        Discount @if($invoice->coupon_code) ({{ $invoice->coupon_code }}) @endif
                    @endif
                </td>
                <td class="num">-{{ $symbol }}{{ number_format((float) $invoice->discount_amount, 2) }}</td>
            </tr>
        @endif
        @if(! $isDeposit && ! $isPayout && ((float) $invoice->tax_amount > 0 || $invoice->tax_label))
            <tr>
                <td colspan="4" class="num label">{{ $invoice->tax_label ?: 'Tax' }} @if((float)$invoice->tax_rate > 0) ({{ rtrim(rtrim(number_format((float)$invoice->tax_rate, 2), '0'), '.') }}%) @endif</td>
                <td class="num">{{ $symbol }}{{ number_format((float) $invoice->tax_amount, 2) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td colspan="4" class="num">{{ $isPayout ? 'Net payout' : 'Total' }}</td>
            <td class="num">{{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }}</td>
        </tr>
        @php
            $chargedCurrency = strtoupper((string) data_get($invoice->meta, 'charge_currency', ''));
            $chargedAmount = data_get($invoice->meta, 'charge_amount');
        @endphp
        @if($chargedCurrency !== '' && $chargedCurrency !== 'EUR' && $chargedAmount !== null)
            <tr>
                <td colspan="4" class="num label">Charged on card / PayPal</td>
                <td class="num">{{ $chargedCurrency }} {{ number_format((float) $chargedAmount, 2) }}</td>
            </tr>
        @endif
    </tfoot>
</table>

@if($isDeposit)
    <div class="box" style="margin-top:16px;">
        <h4>About this receipt</h4>
        <div class="muted">{{ $invoice->notes ?: config('billing.deposit_receipt_note') }}</div>
    </div>
@endif

@if($isPayout)
    <div class="box" style="margin-top:16px;">
        <h4>About this statement</h4>
        <div class="muted">{{ $invoice->notes ?: config('billing.withdrawal_payout_note') }}</div>
    </div>
@endif

@if($invoice->type === 'tax_invoice' || $invoice->type === 'payment_receipt')
    <div class="thankyou">
        Thank you for your business. This document was generated automatically for your records.
    </div>
@endif

<table class="footer">
    <tr>
        <td>{{ $company['legal_name'] ?? ($company['name'] ?? 'SEOLinkBuildings') }} · {{ $company['support_email'] ?? '' }}</td>
        <td class="end">Document {{ $invoice->invoice_number }} · Generated {{ now()->format('M j, Y g:i A') }}</td>
    </tr>
</table>
</body>
</html>
