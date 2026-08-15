@component('mail::message')
# Withdrawal Request {{ ucfirst($newStatus) }}

Dear {{ $withdrawal->user?->name ?: 'Publisher' }},

Your withdrawal request has been **{{ ucfirst($newStatus) }}**.

## Request Details:

- **Request Date:** {{ optional($withdrawal->created_at)->format('F j, Y') ?: '—' }}
- **Requested Amount:** €{{ number_format((float) $withdrawal->amount, 2) }}
@if((float) ($withdrawal->fee ?? 0) > 0)
- **Platform Fee:** -€{{ number_format((float) $withdrawal->fee, 2) }}
@endif
- **Net Payout:** €{{ number_format((float) ($withdrawal->net_amount ?? ((float) $withdrawal->amount - (float) ($withdrawal->fee ?? 0))), 2) }}
- **Payment Method:** {{ \App\Models\Invoice::paymentMethodLabel($withdrawal->payment_method) }}

@if($notes)
## Admin Notes:

{{ $notes }}
@endif

@if($newStatus == 'completed')
@php
    $netPaid = (float) ($withdrawal->net_amount ?? ((float) $withdrawal->amount - (float) ($withdrawal->fee ?? 0)));
@endphp
The amount of **€{{ number_format($netPaid, 2) }}** has been sent to your {{ \App\Models\Invoice::paymentMethodLabel($withdrawal->payment_method) }} account.

@if(!empty($hasStatement) && !empty($statementUrl))
@component('mail::button', ['url' => $statementUrl])
Download payout statement
@endcomponent

You can also review past payouts under [Payout documents]({{ $documentsUrl }}) or [Withdrawals]({{ $withdrawUrl }}).
@else
@component('mail::button', ['url' => $documentsUrl])
View payout documents
@endcomponent
@endif

@elseif($newStatus == 'cancelled')
The amount of **€{{ number_format((float) $withdrawal->amount, 2) }}** has been refunded to your wallet balance.

@component('mail::button', ['url' => $withdrawUrl])
View Withdrawals
@endcomponent

@elseif($newStatus == 'processing')
Your withdrawal request is now being processed. You will be notified once it's completed.

@component('mail::button', ['url' => $withdrawUrl])
View Withdrawals
@endcomponent

@else
@component('mail::button', ['url' => $withdrawUrl])
View Withdrawals
@endcomponent
@endif

If you have any questions, please contact our support team.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
