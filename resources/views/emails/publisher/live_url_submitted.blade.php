@component('mail::message')
# Live URL Submitted

Dear Customer,

The publisher has submitted the live URL for your order **#{{ $order->order_number }}**.

## Order Details:

- **Site:** {{ $site->site_name }}
- **Order Number:** {{ $order->order_number }}
- **Reference Code:** {{ $order->reference_code }}

## Live URL:
<a href="{{ $liveUrl }}">{{ $liveUrl }}</a>

## Next Steps:

If you do not approve the order within 48 hours, it will be automatically approved. If you have any questions or concerns, please contact our support team.

@component('mail::button', ['url' => route('advertiser.orders')])
Review Order
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent