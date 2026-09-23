@component('mail::message')
# Order Accepted

Dear Customer,

Your order #{{ $order->order_number }} has been accepted by the publisher.

## Order Details:

- Site: {{ $site->site_name }}
- Order Number: {{ $order->order_number }}
- Reference Code: {{ $order->reference_code }}
- Content to be published: <a href="{{ $orderItem->content_link }}">View Content</a>

@php
    $mailItem = $orderItem ?? null;
@endphp
@if($mailItem && $mailItem->hasHomepagePlacement())
## Homepage placement:
{{ (int) $mailItem->homepage_days }} day{{ (int) $mailItem->homepage_days === 1 ? '' : 's' }}
@if((float) ($mailItem->homepage_price ?? 0) > 0)
(+€{{ number_format((float) $mailItem->homepage_price, 2) }})
@else
(Free)
@endif
@endif

@if($mailItem && $mailItem->offersSocialPromotion())
## Social promotion:
{{ collect($mailItem->enabledSocialChannels())->map(fn ($c) => $mailItem->socialChannelLabel($c))->implode(', ') }} (included)

The publisher will share your article on these channels when the post goes live. Post links are optional and may be added after publication.
@endif

The publisher has accepted your order and will start working on it.

You can track your order status from your dashboard.

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false)])
View My Orders
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent