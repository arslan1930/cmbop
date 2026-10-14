@component('mail::message')
# New Deposit Request

A new deposit request has been submitted by {{ $user->name }} ({{ $user->email }}).

## Deposit Details:

- Amount: €{{ number_format($deposit->amount, 2) }}
@if(!empty($deposit->charge_currency) && strtoupper((string) $deposit->charge_currency) !== 'EUR')
- Charge: {{ strtoupper((string) $deposit->charge_currency) }} {{ number_format((float) ($deposit->charge_amount ?? 0), 2) }}
@endif
- Payment Method: {{ \App\Models\Invoice::paymentMethodLabel($deposit->payment_method) }}
- Reference Code: REF{{ $deposit->reference_code }}
- Submitted At: {{ $deposit->created_at->format('M d, Y H:i') }}

@php
    $depositFx = app(\App\Support\DepositFx::class);
    $depositCharge = strtoupper((string) ($deposit->charge_currency ?: 'EUR'));
@endphp
{{ $depositFx->sendLabel(
    $depositCharge,
    (float) ($deposit->charge_amount ?? $deposit->amount),
    (float) $deposit->amount
) }}
@foreach($depositFx->customerFxNotes($deposit, $depositCharge) as $fxNote)
{{ $fxNote }}
@endforeach

@component('mail::button', ['url' => $approveUrl])
Review & approve
@endcomponent

Opens a confirm page — wallet credit only happens after you confirm. Or [open the deposits list]({{ $adminUrl }}).

Thanks,<br>
{{ config('app.name') }}
@endcomponent
