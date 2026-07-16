@component('mail::message')

# Payment Confirmed! 🎉

Hello {{ $user->name }},

Great news! Your payment for Order **#{{ $order->order_number }}** has been confirmed.

## Order Details

| Detail | Information |
|--------|-------------|
| **Order Number** | #{{ $order->order_number }} |
| **Reference Code** | {{ $order->reference_code }} |   
| **Order Date** | {{ $orderDate }} |   
| **Payment Date** | {{ $paidDate }} |
| **Payment Method** | {{ ucfirst($order->payment_method) }} |
| **Total Amount** | €{{ number_format($totalAmount, 2) }} |

## Items Ordered

@foreach($orderItems as $item)
- **{{ $item->site_name }}**
  - URL: {{ $item->site_url }}
  - Price: €{{ number_format($item->price, 2) }}
  @if($item->content_link)
  - Content Link: <a href="{{ $item->content_link }}">{{ Illuminate\Support\Str::limit($item->content_link, 50) }}</a>
  @endif
@endforeach

## What's Next?

Your order is now being processed. You can track your order status from your dashboard.

@component('mail::button', ['url' => route('advertiser.orders')])
View My Orders
@endcomponent

If you have any questions about your order, please contact our support team.

Thank you for choosing {{ config('app.name') }}!

Best regards,<br>
{{ config('app.name') }} Team

@component('mail::subcopy')
This is a confirmation that your payment has been received. Please keep this email for your records.
@endcomponent
@endcomponent