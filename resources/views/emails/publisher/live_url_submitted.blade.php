@component('mail::message')
# Ready for review

Hello {{ $firstName ?? 'there' }},

The guest post is live and ready for your review. Please check the published article, then approve it or request corrections from the order page.

@include('emails.partials.placement-urls', [
    'placements' => $placements ?? [],
    'missingLabel' => 'Not submitted yet',
    'urlHeading' => 'Live article URL',
])

@include('emails.partials.summary-table', ['rows' => [
    ['label' => 'Order number', 'value' => '#'.$order->order_number],
]])

@php
    $mailItem = $orderItem ?? null;
@endphp
@if($mailItem && $mailItem->hasHomepagePlacement())
Homepage placement: {{ (int) $mailItem->homepage_days }} day{{ (int) $mailItem->homepage_days === 1 ? '' : 's' }}
@endif

@if($mailItem && $mailItem->offersSocialPromotion())
Social promotion: {{ collect($mailItem->enabledSocialChannels())->map(fn ($c) => $mailItem->socialChannelLabel($c))->implode(', ') }} (included)
@foreach($mailItem->socialPostUrls() as $channel => $url)
@php $safeSocial = \App\Support\MailNotificationPresenter::safeLiveUrl($url); @endphp
@if($safeSocial)
- {{ $mailItem->socialChannelLabel($channel) }}: [{{ $safeSocial }}]({{ $safeSocial }})
@endif
@endforeach
@endif

If you take no action within {{ $autoApproveHours ?? \App\Models\OrderItem::autoApproveHours() }} hours, the order is auto-approved. To request corrections, open the order page — there is no one-click approve link in this email.

@component('mail::button', ['url' => $ctaUrl ?? rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false)])
View Order Details
@endcomponent

@include('emails.partials.mail-support', ['supportLine' => $supportLine ?? null, 'intent' => 'order'])

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
