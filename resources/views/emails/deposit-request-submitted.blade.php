@component('mail::message')
# New Deposit Request

A new deposit request has been submitted by **{{ $user?->name ?? 'An advertiser' }}** ({{ $user?->email ?? 'unknown' }}).

## Deposit Details:

- **Amount:** €{{ number_format((float) $deposit->amount, 2) }}
- **Payment Method:** {{ ucfirst((string) ($deposit->payment_method ?: '—')) }}
- **Reference Code:** REF{{ $deposit->reference_code }}
- **Submitted At:** {{ optional($deposit->created_at)->format('M d, Y H:i') }}

@component('mail::button', ['url' => $approveUrl])
Review & approve
@endcomponent

Opens a confirm page — wallet credit only happens after you confirm. Or [open the deposits list]({{ $adminUrl }}).

Thanks,<br>
{{ config('app.name') }}
@endcomponent
