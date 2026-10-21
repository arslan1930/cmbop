@component('mail::message')
# Revision requested — order #{{ $orderNumber }}

Hi {{ $firstName }},

The advertiser asked for one free revision on the written article for {{ $siteName }}.

## Notes
{{ $reason }}

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
