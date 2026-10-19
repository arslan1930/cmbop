@component('mail::message')
Hello {{ $partnerName }},

Confirm this address (**{{ $newEmail }}**) to finish changing your partner dashboard email. The change only applies after you click the button. This link expires in 24 hours.

@component('mail::button', ['url' => $actionUrl])
Confirm new email
@endcomponent

If you did not request this, ignore this message.

Teqno Ltd · SEOLinkBuildings partner desk
@endcomponent
