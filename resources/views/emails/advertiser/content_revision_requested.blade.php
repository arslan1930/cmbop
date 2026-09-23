@component('mail::message')
# Revised article requested

Dear Customer,

The publisher for {{ $site->site_name }} asked you to send a revised article for order #{{ $order->order_number }}.

## Why they asked
> {{ $reason }}

## What to do
1. Open the order in your dashboard
2. Upload or link an updated article
3. The publisher will continue publishing once they have it

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false)])
Send revised article
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
