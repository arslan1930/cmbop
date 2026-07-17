@component('mail::message')
# Order Accepted

Dear Customer,

Your order **#{{ $order->order_number }}** has been **accepted** by the publisher.

## Order Details:

- **Site:** {{ $site->site_name }}
- **Order Number:** {{ $order->order_number }}
- **Reference Code:** {{ $order->reference_code }}
- **Content to be published:** <a href="{{ $orderItem->content_link }}">View Content</a>

The publisher has accepted your order and will start working on it.

You can track your order status from your dashboard.

@component('mail::button', ['url' => route('advertiser.orders')])
View My Orders
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent