@component('mail::message')
# We’d love your feedback, {{ $user->name }}

@if($order)
Thanks for completing order **#{{ $order->order_number }}**.
@else
Thanks for working with us.
@endif

If you had a great experience, a short Trustpilot review helps other marketers find trusted publishers.

@component('mail::button', ['url' => $reviewUrl])
Leave a Trustpilot Review
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
