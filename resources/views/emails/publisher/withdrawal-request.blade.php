{{-- resources/views/emails/publisher/withdrawal-request.blade.php --}}
@component('mail::message')
# New Withdrawal Request

Dear Admin,

A new withdrawal request has been submitted and requires your attention.

## Request Details:

- **Publisher:** {{ $user->name }} ({{ $user->email }})
- **Request Date:** {{ $withdrawal->created_at->format('F j, Y, g:i a') }}
- **Payment Method:** {{ strtoupper($withdrawal->payment_method) }}

## Financial Breakdown:

- **Requested Amount:** €{{ number_format($withdrawal->amount, 2) }}
- **Platform Fee ({{ $platformChargePercent ?? 18 }}%):** -€{{ number_format($withdrawal->fee, 2) }}
- **Net Amount to Pay:** €{{ number_format($withdrawal->net_amount, 2) }}

## Payment Details:

@if($withdrawal->payment_method == 'bank')
- **Bank Name:** {{ $withdrawal->payment_details['bank_name'] ?? 'N/A' }}
- **Account Holder:** {{ $withdrawal->payment_details['account_holder'] ?? 'N/A' }}
- **Account Number:** {{ $withdrawal->payment_details['account_number'] ?? 'N/A' }}
@if(isset($withdrawal->payment_details['swift_code']))
- **SWIFT Code:** {{ $withdrawal->payment_details['swift_code'] }}
@endif
@elseif($withdrawal->payment_method == 'paypal')
- **PayPal Email:** {{ $withdrawal->payment_details['email'] ?? 'N/A' }}
@elseif($withdrawal->payment_method == 'wise')
- **Wise Email:** {{ $withdrawal->payment_details['email'] ?? 'N/A' }}
@elseif($withdrawal->payment_method == 'crypto')
- **Cryptocurrency:** {{ $withdrawal->payment_details['crypto_type'] ?? 'N/A' }}
- **Wallet Address:** {{ $withdrawal->payment_details['wallet_address'] ?? 'N/A' }}
@endif

## Status:
**Pending** - Awaiting admin review

@component('mail::button', ['url' => $url ?? '#'])
Review Withdrawal Request
@endcomponent

Please review and process this withdrawal request as soon as possible.

If you have any questions, please contact the publisher directly.

Thanks,<br>
{{ config('app.name') }}
@endcomponent