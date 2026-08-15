@php
    $details = \App\Models\Withdrawal::detailsArray($withdrawal->payment_details);
    $detailOrNa = function (string $key) use ($details): string {
        $value = \App\Models\Withdrawal::destinationText($details, $key);

        return $value !== '' ? $value : 'N/A';
    };
@endphp
@component('mail::message')
# New Withdrawal Request

Dear Admin,

A new withdrawal request has been submitted and requires your attention.

## Request Details:

- **Publisher:** {{ $user?->name ?: 'Unknown' }} ({{ $user?->email ?: '—' }})
- **Request Date:** {{ optional($withdrawal->created_at)->format('F j, Y, g:i a') ?: '—' }}
- **Payment Method:** {{ strtoupper((string) $withdrawal->payment_method) }}

## Financial Breakdown:

- **Requested Amount:** €{{ number_format((float) $withdrawal->amount, 2) }}
- **Platform Fee ({{ $platformChargePercent ?? config('billing.withdrawal_fee_percent', 0) }}%):** -€{{ number_format((float) $withdrawal->fee, 2) }}
- **Net Amount to Pay:** €{{ number_format((float) $withdrawal->net_amount, 2) }}

## Payment Details:

@if($withdrawal->payment_method == 'bank')
- **Bank Name:** {{ $detailOrNa('bank_name') }}
- **Account Holder:** {{ $detailOrNa('account_holder') }}
- **Account Number:** {{ $detailOrNa('account_number') }}
@if($detailOrNa('swift_code') !== 'N/A')
- **SWIFT Code:** {{ $detailOrNa('swift_code') }}
@endif
@elseif($withdrawal->payment_method == 'paypal')
- **PayPal Email:** {{ $detailOrNa('email') }}
@elseif($withdrawal->payment_method == 'wise')
- **Wise Email:** {{ $detailOrNa('email') }}
@elseif($withdrawal->payment_method == 'crypto')
- **Cryptocurrency:** {{ $detailOrNa('crypto_type') }}
- **Wallet Address:** {{ $detailOrNa('wallet_address') }}
@endif

## Status:
**Pending** — wallet already deducted; mark paid only after you send the net amount outside the app.

@component('mail::button', ['url' => $markPaidUrl])
Mark paid (confirm)
@endcomponent

Opens a confirm page — status changes only after you confirm. Or [open the payout queue]({{ $adminUrl }}).

Thanks,<br>
{{ config('app.name') }}
@endcomponent
