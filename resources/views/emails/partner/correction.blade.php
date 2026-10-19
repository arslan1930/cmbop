@component('mail::message')
Hello {{ $partnerName }},

A correction was requested for order **{{ $orderNumber }}** on **{{ $domain }}**.

@if(!empty($reason))
{{ $reason }}
@endif

Open the order to resubmit the live URL. Replies by email are not delivered.

@component('mail::button', ['url' => $actionUrl])
Open order
@endcomponent

Teqno Ltd · SEOLinkBuildings partner desk
@endcomponent
