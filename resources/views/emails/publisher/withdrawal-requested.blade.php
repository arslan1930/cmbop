@component('mail::message')
# Withdrawal request received

Hi {{ $userName ?? ($withdrawal->user?->name ?: 'Publisher') }},

We received your withdrawal request **WD-{{ $withdrawal->id }}**.

## Details

- **Requested:** €{{ number_format($withdrawal->amount, 2) }}
@if((float) $withdrawal->fee > 0)
- **Fee:** €{{ number_format($withdrawal->fee, 2) }}
- **You will receive:** €{{ number_format($withdrawal->net_amount, 2) }}
@endif
- **Method:** {{ strtoupper($withdrawal->payment_method) }}
- **Status:** Requested (pending review)

We usually process payouts within 1–2 business days. You can cancel while the status is still **Requested**.

@component('mail::button', ['url' => $withdrawUrl])
View withdrawals
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
