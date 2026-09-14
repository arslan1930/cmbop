@component('mail::message')
# {{ $methodLabel }} deposit refunded

Dear {{ $deposit->user?->name ?? 'Advertiser' }},

**€{{ number_format((float) $deposit->amount, 2) }}** from your {{ $methodLabel }} Add Funds deposit was refunded and removed from your wallet.

## Deposit details

- **Amount:** €{{ number_format((float) $deposit->amount, 2) }}
- **Reference code:** {{ $deposit->reference_code }}
- **Payment method:** {{ $methodLabel }}
@if(($debt ?? 0) > 0.009)
- **Outstanding wallet debt:** €{{ number_format((float) $debt, 2) }}
@endif

@if(($debt ?? 0) > 0.009)
Part of this deposit had already been spent, so **€{{ number_format((float) $debt, 2) }}** remains as outstanding wallet debt.
@endif

@component('mail::button', ['url' => $balanceUrl, 'color' => 'primary'])
View Balance
@endcomponent

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
