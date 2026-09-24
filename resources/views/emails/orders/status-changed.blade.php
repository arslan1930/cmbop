@component('mail::message')
@if(!empty($advertiserCompleted))
Hi {{ $greetingName }},

We're happy to let you know that your order #{{ $order->order_number }} is now complete, and all links are live. You can find the full summary of your published links in the attached document.

We hope you're pleased with the results!

**Ready to place another order?**

Boost your online presence even further by securing additional high-quality links. Click below to get started on your next order and stay ahead of the competition:

@component('mail::button', ['url' => $catalogUrl])
Place a new order
@endcomponent

Thank you for choosing us, and we look forward to working with you again soon!
@else
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
| Previous | {{ $previousLabel }} |
| New | {{ $newLabel }} |
| Updated | {{ $updatedAt }} |

## Order summary

| Detail | Value |
|--------|-------|
| Order Number | #{{ $order->order_number }} |
| Website | {{ $site->site_name ?? ($item->site_name ?? '—') }} |
| Advertiser | {{ $advertiserName }} |
| Publisher | {{ $publisherName }} |
| Order Status | {{ ucfirst($order->status) }} |
| Payment Status | {{ ucfirst($order->payment_status) }} |
| Amount | €{{ number_format((float) $order->total_amount, 2) }} |

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

If you have questions, reply to this email or contact support.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endif
@endcomponent
