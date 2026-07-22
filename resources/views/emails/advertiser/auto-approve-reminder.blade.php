@component('mail::message')
# 1 day left before auto-complete

Your guest post on **{{ $siteName }}** (order **#{{ $order->order_number }}**) is still under review.

If you take no action in about **{{ $hoursRemaining }} hour(s)**, the order will be **auto-completed** and the publisher will be paid.

@if($liveUrl)
**Live URL:** [{{ $liveUrl }}]({{ $liveUrl }})
@endif

You can:
- **Approve** the live post now, or
- **Request changes** if something needs fixing (this pauses auto-complete until the publisher resubmits)

@component('mail::button', ['url' => $ordersUrl])
Review order
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
