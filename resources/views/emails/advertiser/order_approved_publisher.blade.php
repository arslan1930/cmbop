@component('mail::message')
# Order approved

Dear Publisher,

Great news! The advertiser has approved the order for your site.

## Order Details:

- Order number: #{{ $order->order_number }}
- Website: {{ $site->site_name }}@if(!empty($site->domain)) ({{ $site->domain }})@endif

## Content Details:

@php
    $publisherContentLink = $orderItem instanceof \App\Models\OrderItem
        ? $orderItem->publisherContentLink()
        : null;
@endphp
@if($publisherContentLink)
- Content Link: <a href="{{ $publisherContentLink }}">View Content</a>
@endif
@php $approvedLive = \App\Support\MailNotificationPresenter::safeLiveUrl($orderItem->live_url ?? null); @endphp
@if($approvedLive)
- Live article URL: <a href="{{ $approvedLive }}">{{ $approvedLive }}</a>
@endif

## Payment Details:

- Base Price: €{{ number_format($basePrice, 2) }}
@if($orderItem->additional_price > 0)
- {{ ucfirst($orderItem->sensitive_type) }}: +€{{ number_format($orderItem->additional_price, 2) }}
@endif
@if($orderItem->hasHomepagePlacement())
- Homepage ({{ (int) $orderItem->homepage_days }} day{{ (int) $orderItem->homepage_days === 1 ? '' : 's' }}): @if((float) ($orderItem->homepage_price ?? 0) > 0)+€{{ number_format((float) $orderItem->homepage_price, 2) }}@else Free @endif
@endif
@if($orderItem->offersSocialPromotion())
- Social: {{ collect($orderItem->enabledSocialChannels())->map(fn ($c) => $orderItem->socialChannelLabel($c))->implode(', ') }} (included)
@endif
- Amount Credited: €{{ number_format($payoutAmount, 2) }}

## What this means:

The advertiser has confirmed that the content is published correctly and meets their requirements. 
Your payout (listing price; platform fee excluded) has been credited to your publisher wallet.

You can view all your approved orders in your publisher dashboard.

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false)])
View My Tasks
@endcomponent

Thank you for your quality work!

Thanks,<br>
{{ config('app.name') }}
@endcomponent