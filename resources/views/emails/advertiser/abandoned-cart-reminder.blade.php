@component('mail::message')
# @if($stage === 1)
You still have sites in your cart
@else
Your cart is still waiting
@endif

Hi {{ $firstName }},

@if($stage === 1)
You added these placements and have not checked out yet. Prices below are what you would pay today, including any active offer.
@else
These placements are still in your cart. Open checkout when you are ready — prices include any active offer on the listing.
@endif

@component('mail::table')
| Site | Qty | Price |
| :--- | ---: | ---: |
@foreach($lines as $line)
| {{ $line['domain'] }} | {{ (int) $line['quantity'] }} | @if(!empty($line['discount']) && !empty($line['was']))~~€{{ number_format((float) $line['was'], 2) }}~~ €{{ number_format((float) $line['price'], 2) }}@else€{{ number_format((float) $line['price'], 2) }}@endif |
@endforeach
@endcomponent

@component('mail::button', ['url' => $cartUrl])
Return to cart
@endcomponent

You can turn off marketing emails anytime in notification settings.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team

@component('mail::subcopy')
You received this email because you have an account at {{ $brand['name'] ?? config('app.name') }}.
[Unsubscribe from marketing emails]({{ $unsubscribeUrl }})
@endcomponent
@endcomponent
