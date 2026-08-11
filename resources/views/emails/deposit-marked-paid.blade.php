@component('mail::message')
# Payment reported

**{{ $user->name ?? 'An advertiser' }}** ({{ $user->email ?? 'unknown' }}) has confirmed sending the transfer for their deposit. The funds have **not** been credited — please check the account and approve once you can see the money.

## Deposit details

- **Amount:** €{{ number_format((float) $deposit->amount, 2) }}
- **Payment method:** {{ ucfirst((string) $deposit->payment_method) }}
- **Reference code:** REF{{ $deposit->reference_code }}
- **Requested:** {{ optional($deposit->created_at)->format('M d, Y H:i') }}
- **Reported paid:** {{ optional($deposit->user_marked_paid_at)->format('M d, Y H:i') }}
@if($deposit->user_payment_note)
- **Their transfer reference:** {{ $deposit->user_payment_note }}
@endif

@component('mail::button', ['url' => $approveUrl])
Approve & credit wallet
@endcomponent

The button opens a confirm page — nothing is credited until you confirm. Or [open the deposits list]({{ $adminUrl }}).

Thanks,<br>
{{ config('app.name') }}
@endcomponent
