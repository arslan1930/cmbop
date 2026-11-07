@component('mail::message')
# Your link is live

Hi {{ $firstName }},

The publisher has submitted the live link for your guest post on {{ $siteName }} (order #{{ $order->order_number }}).

@php $safeLive = \App\Support\MailNotificationPresenter::safeLiveUrl($liveUrl ?? null); @endphp
@if($safeLive)
Live article URL: [{{ $safeLive }}]({{ $safeLive }})
@endif

Worth a quick check: that the anchor text and target URL are right, the link is not marked nofollow when you paid for dofollow, and the article reads as agreed.

@component('mail::button', ['url' => $ordersUrl])
Check and approve
@endcomponent

If nothing needs changing you do not have to do anything — the order completes automatically on {{ \App\Support\MailNotificationPresenter::formatDateTime($autoCompletesAt) }} and the publisher is paid. If something is wrong, request changes from the order page before then.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
