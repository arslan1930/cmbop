@component('mail::message')
# @if($changeKind === 'created')
New order created
@elseif($changeKind === 'payment_status')
Payment status updated
@else
Order status updated
@endif

Hello {{ $firstName }},

{{ $copy }}

## Status

| | |
|---|---|
| **Previous** | {{ $previousLabel }} |
| **New** | **{{ $newLabel }}** |
| **Updated** | {{ $updatedAt }} |

## Order summary

| Detail | Value |
|--------|-------|
| **Order Number** | #{{ $order->order_number }} |
| **Website** | {{ $site->site_name ?? ($item->site_name ?? '—') }} |
| **Advertiser** | {{ $advertiserName }} |
| **Publisher** | {{ $publisherName }} |
| **Order Status** | {{ ucfirst($order->status) }} |
| **Payment Status** | {{ ucfirst($order->payment_status) }} |
| **Amount** | €{{ number_format((float) $order->total_amount, 2) }} |

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

If you have questions, reply to this email or contact support.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
