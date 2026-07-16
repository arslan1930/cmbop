@component('mail::message')
# Deposit Approved

Dear {{ $deposit->user->name }},

Your deposit request has been **approved** and the funds have been added to your wallet.

## Deposit Details:

- **Amount:** €{{ number_format($deposit->amount, 2) }}
- **Reference Code:** {{ $deposit->reference_code }}
- **Approved At:** {{ $deposit->approved_at->format('M d, Y H:i') }}

## Your Current Balance:

**€{{ number_format($deposit->user->activeWallet()?->balance ?? 0, 2) }}**

@component('mail::button', ['url' => route('advertiser.dashboard')])
View Dashboard
@endcomponent

Thank you for using {{ config('app.name') }}!

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent