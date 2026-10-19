@component('mail::message')
Hello {{ $partnerName }},

Someone asked to change the email on your SEOLinkBuildings partner account to **{{ $newEmail }}**. If this was you, no action is needed on this message — confirm from the new address instead.

If this was not you, cancel the change. That signs out every session and locks the account for a security review.

@component('mail::button', ['url' => $actionUrl])
This wasn’t me
@endcomponent

Teqno Ltd · SEOLinkBuildings partner desk
@endcomponent
