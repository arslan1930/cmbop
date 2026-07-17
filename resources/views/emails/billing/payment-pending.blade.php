@component('mail::message')
# Payment Pending Verification

Hello {{ $user->name }},

We received your order and your payment is currently **pending verification**.

| | |
|:--|:--|
| **Order number** | #{{ $order->order_number }} |
| **Payment amount** | {{ $symbol }}{{ number_format((float) $order->total_amount, 2) }} |
| **Payment method** | {{ ucfirst((string) $order->payment_method) }} |
| **Current status** | Pending verification |
| **Estimated time** | Usually within {{ $hours }} hours |

We’ll email you as soon as the payment is confirmed.

@component('mail::button', ['url' => $statusUrl, 'color' => 'primary'])
View Payment Status
@endcomponent

@endcomponent
