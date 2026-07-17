@component('mail::message')

Hello {{ $receiverName }},

You have received a new message from **{{ $sender->name }}** ({{ $senderType }}) regarding order **#{{ $order->order_number }}**.

## Message:
> {{ $message }}

@component('mail::button', ['url' => $url])
View Order & Reply
@endcomponent

Best regards,<br>
{{ config('app.name') }} Team

@endcomponent