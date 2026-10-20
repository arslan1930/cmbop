@component('mail::message')
Hello {{ $partnerName }},

A new message arrived on order **{{ $orderNumber }}** ({{ $domain }}).

@if(!empty($preview))
{{ $preview }}
@endif

Open the order to reply. Replies by email are not delivered.

@component('mail::button', ['url' => $actionUrl])
Open order
@endcomponent

Teqno Ltd · SEOLinkBuildings partner desk
@endcomponent
