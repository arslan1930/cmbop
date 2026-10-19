@component('mail::message')
Hello {{ $partnerName }},

Your payout of **{{ $amountLabel }}** has been released.

Sign in to the partner dashboard to view Money. This email cannot be switched off.

@component('mail::button', ['url' => $actionUrl])
Sign in
@endcomponent

Teqno Ltd · SEOLinkBuildings partner desk
@endcomponent
