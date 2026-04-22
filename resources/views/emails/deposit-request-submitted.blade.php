
@component('mail::message')
# New Deposit Request

A new deposit request has been submitted by **{{ $user->name }}** ({{ $user->email }}).

## Deposit Details:

- **Amount:** €{{ number_format($deposit->amount, 2) }}
- **Payment Method:** {{ ucfirst($deposit->payment_method) }}
- **Reference Code:** {{ $deposit->reference_code }}
- **Submitted At:** {{ $deposit->created_at->format('M d, Y H:i') }}

@component('mail::button', ['url' => $adminUrl])
Review Deposit Request
@endcomponent

Please review and approve this deposit request.

Thanks,<br>
{{ config('app.name') }}
@endcomponent