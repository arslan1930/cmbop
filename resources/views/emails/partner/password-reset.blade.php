@component('mail::message')
Hello {{ $partnerName }},

Use this button to choose a new password for the SEOLinkBuildings partner dashboard.

@component('mail::button', ['url' => $actionUrl])
Reset password
@endcomponent

If you did not request this, you can ignore the email.

Teqno Ltd · SEOLinkBuildings partner desk
@endcomponent
