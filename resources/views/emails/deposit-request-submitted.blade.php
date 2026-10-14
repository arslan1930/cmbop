@component('mail::message')
# New Deposit Request

A new deposit request has been submitted by {{ $user->name }} ({{ $user->email }}).

## Deposit Details:

- Amount: €{{ number_format($deposit->amount, 2) }}
@if(!empty($deposit->charge_currency) && strtoupper((string) $deposit->charge_currency) !== 'EUR')
- Charge: {{ strtoupper((string) $deposit->charge_currency) }} {{ number_format((float) ($deposit->charge_amount ?? 0), 2) }}
@endif
- Payment Method: {{ \App\Models\Invoice::paymentMethodLabel($deposit->payment_method) }}
- Currency: {{ strtoupper((string) ($deposit->charge_currency ?: 'EUR')) }}
@if($deposit->bank_account)
- Target bank: {{ $deposit->targetBankLabel() }}
@endif
- Payment reference: {{ $deposit->paymentReferenceLabel() }}
- Invoice REF: REF{{ $deposit->reference_code }}
- Submitted At: {{ $deposit->created_at->format('M d, Y H:i') }}
@php
    $pay = \App\Support\DepositPaymentConfig::depositPayment();
    $emailBeneficiary = \App\Support\DepositPaymentConfig::beneficiaryDisplayName($pay);
    $emailBank = $deposit->targetBank();
@endphp
- Beneficiary: {{ $emailBeneficiary }}
@if($emailBank === \App\Support\AccountCurrency::EUR_WISE)
- IBAN: {{ $pay['eur_iban'] }}
- {{ \App\Support\DepositPaymentConfig::EUR_SEPA_NOTE }}
- {{ \App\Support\DepositPaymentConfig::EUR_BIC_NOTE }}: {{ $pay['eur_bic'] }}
@elseif($emailBank === \App\Support\AccountCurrency::GBP_WISE)
- Sort code: {{ $pay['uk_sort_code'] }}
- Account number: {{ $pay['uk_account_number'] }}
@endif

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
