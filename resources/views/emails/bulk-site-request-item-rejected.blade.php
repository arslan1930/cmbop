@component('mail::message')
# One website was not added

Hi {{ $firstName }},

We did not add **{{ $item->site_url }}** from bulk request **#{{ $bulkRequest->id }}**. The rest of that request is unchanged.

**Reason:** {{ $reason }}

Websites we already added stay in Pending sites. You can submit this URL again later if you want us to take another look.

@component('mail::button', ['url' => $websitesUrl])
Open your websites
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
