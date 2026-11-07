@component('mail::message')
# Order Rejected

Dear Customer,

We regret to inform you that your order #{{ $order->order_number }} has been rejected by the publisher.

## Order Details:

- Website: {{ $site->site_name }}@if(!empty($site->domain)) ({{ $site->domain }})@endif
- Order number: #{{ $order->order_number }}

## Reason for Rejection:
{{ $reason }}

## What's Next?

You can browse other publishers and place a new order.

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('advertiser.catalog', [], false)])
Browse Publishers
@endcomponent

If you have any questions, please contact our support team.

Thanks,<br>
{{ config('app.name') }}
@endcomponent