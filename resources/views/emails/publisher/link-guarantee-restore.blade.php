@component('mail::message')
# Restore the live link for order #{{ $orderNumber }}

Hi {{ $firstName }},

A monthly 12-month link guarantee check did not find the advertiser’s target URL as a hyperlink on the live page for {{ $siteName }}.

Please restore that hyperlink by {{ $due }}. If it is still missing after that date, we refund the guaranteed placement amount to the advertiser.

This check only looks for the agreed URL on the published page. It is not a ranking, traffic, or indexation promise.

@component('mail::button', ['url' => $tasksUrl])
Open the task
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
