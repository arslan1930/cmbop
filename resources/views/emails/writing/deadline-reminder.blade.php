@component('mail::message')
# Writing deadline soon — order #{{ $orderNumber }}

Hi {{ $firstName }},

The written article for {{ $siteName }} is due soon. Upload the draft so the advertiser can approve it.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
