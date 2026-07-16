@component('mail::message')
# Deposit Request Update

Dear {{ $deposit->user->name }},

We regret to inform you that your deposit request has been **rejected**.

## Deposit Details:

- **Amount:** €{{ number_format($deposit->amount, 2) }}
- **Reference Code:** {{ $deposit->reference_code }}
- **Rejected At:** {{ $deposit->rejected_at->format('M d, Y H:i') }}

@if($deposit->admin_notes)
## Admin Notes:
{{ $deposit->admin_notes }}
@endif

If you believe this is an error, please contact our support team.

@component('mail::button', ['url' => route('advertiser.add-funds')])
Try Again
@endcomponent

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent