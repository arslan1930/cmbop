@component('mail::message')
# Websites from your bulk request still need you

Hi {{ $publisherName }},

Bulk request #{{ $bulkRequest->id }} is still waiting on you.

{{ $nextStep }}

{{ $count === 1 ? 'Please open the listing and finish it' : 'Please open the listings and finish them' }} — Accept to go live, or Edit if something looks wrong.

@component('mail::button', ['url' => $reviewUrl])
Review websites
@endcomponent

Or open [My Sites]({{ $websitesUrl }}) if you are still adding details.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
