@component('mail::message')
# Live URL Submitted

Dear Customer,

The publisher has submitted the live URL for your order #{{ $order->order_number }}.

## Order Details:

- Site: {{ $site->site_name }}
- Order Number: {{ $order->order_number }}
- Reference Code: {{ $order->reference_code }}

## Live URL:
<a href="{{ $liveUrl }}">{{ $liveUrl }}</a>

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
@foreach($mailItem->socialPostUrls() as $channel => $url)
- {{ $mailItem->socialChannelLabel($channel) }}: [{{ $url }}]({{ $url }})
@endforeach
@endif

## Next Steps:

If you do not approve the order within {{ $autoApproveHours ?? \App\Models\OrderItem::autoApproveHours() }} hours, it will be automatically approved. If you have any questions or concerns, please contact our support team.

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false)])
Review Order
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent