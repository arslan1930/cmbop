@component('mail::message')
<div style="text-align:center; margin-bottom:20px;">
    <img src="https://seolinkbuildings.com/assets/img/logo1.png" alt="Seolinkbuildings Logo" width="150" style="display:block; margin:0 auto;">
</div>
# Order Approved! 🎉

Dear Publisher,

Great news! The advertiser has **approved** the order for your site.

## Order Details:

- **Order Number:** #{{ $order->order_number }}
- **Site:** {{ $site->site_name }}
- **Reference Code:** {{ $order->reference_code }}

## Content Details:

- **Content Link:** <a href="{{ $orderItem->content_link }}">View Content</a>
- **Live URL:** <a href="{{ $orderItem->live_url }}">{{ $orderItem->live_url }}</a>

## Payment Details:

- **Base Price:** €{{ number_format($basePrice, 2) }}
@if($orderItem->additional_price > 0)
- **{{ ucfirst($orderItem->sensitive_type) }}:** +€{{ number_format($orderItem->additional_price, 2) }}
@endif
- **Amount Credited:** €{{ number_format($payoutAmount, 2) }}

## What this means:

The advertiser has confirmed that the content is published correctly and meets their requirements. 
Your payout (listing price; platform fee excluded) has been credited to your publisher wallet.

You can view all your approved orders in your publisher dashboard.

@component('mail::button', ['url' => route('publisher.tasks')])
View My Tasks
@endcomponent

Thank you for your quality work!

Thanks,<br>
{{ config('app.name') }}
@endcomponent