@component('mail::message')
# Article ready to publish — order #{{ $orderNumber }}

Hi {{ $firstName }},

The advertiser approved the written article for {{ $siteName }}. Please publish it and submit the live URL.

@component('mail::button', ['url' => $tasksUrl])
Open the task
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
