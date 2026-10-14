@component('mail::message')
@php
    $withdrawUrl = $withdrawUrl ?? rtrim(function_exists('brand_public_origin') ? brand_public_origin() : url('/'), '/').'/publisher/withdraw';
@endphp
# Withdrawal request received

Hi {{ $withdrawal->user?->name ?? 'Publisher' }},

We received your withdrawal request WD-{{ $withdrawal->id }}.

## Details

- Requested: {{ format_money_amount($withdrawal->amount, $withdrawal->currency ?? 'EUR') }}
@if((float) ($withdrawal->conversion_fee ?? 0) > 0)
- Conversion fee ({{ rtrim(rtrim(number_format((float) ($withdrawal->conversion_fee_percent ?? 0), 2), '0'), '.') }}%): {{ format_money_amount($withdrawal->conversion_fee, $withdrawal->currency ?? 'EUR') }}
@endif
@if((float) $withdrawal->fee > 0)
- Fee: {{ format_money_amount($withdrawal->fee, $withdrawal->currency ?? 'EUR') }}
@endif
@if((float) ($withdrawal->conversion_fee ?? 0) > 0 || (float) $withdrawal->fee > 0)
- You will receive: {{ format_money_amount($withdrawal->net_amount, $withdrawal->currency ?? 'EUR') }}
@endif
- Method: {{ strtoupper($withdrawal->payment_method) }}
- Status: Requested (pending review)

We usually process payouts within 1–2 business days. You can cancel while the status is still Requested.

@component('mail::button', ['url' => $withdrawUrl])
View withdrawals
@endcomponent

Thanks,<br>
{{ mail_brand_name() }}
@endcomponent
