@component('mail::message')
# Withdrawal Request {{ ucfirst($newStatus) }}

Dear {{ $withdrawal->user->name }},

Your withdrawal request has been **{{ ucfirst($newStatus) }}**.

## Request Details:

- **Request Date:** {{ $withdrawal->created_at->format('F j, Y,') }}
- **Requested Amount:** €{{ number_format($withdrawal->amount, 2) }}
- **Payment Method:** {{ strtoupper($withdrawal->payment_method) }}

## Status Updated:

@if($notes)
## Admin Notes:

{{ $notes }}
@endif

@if($newStatus == 'completed')
The amount of **€{{ number_format($withdrawal->amount, 2) }}** has been sent to your {{ strtoupper($withdrawal->payment_method) }} account.

@elseif($newStatus == 'cancelled')
The amount of **€{{ number_format($withdrawal->amount, 2) }}** has been refunded to your wallet balance.

@elseif($newStatus == 'processing')
Your withdrawal request is now being processed. You will be notified once it's completed.

@endif

@component('mail::button', ['url' => route('publisher.withdraw')])
View Withdrawals
@endcomponent

If you have any questions, please contact our support team.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent