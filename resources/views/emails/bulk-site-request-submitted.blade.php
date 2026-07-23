@component('mail::message')
# New bulk site request

**Publisher:** {{ $publisherName }} ({{ $publisherEmail }})  
**Estimated sites:** {{ $bulkRequest->estimated_count ?? '—' }}  
**Status:** {{ $bulkRequest->status }}

@if($bulkRequest->publisher_note)
**Note from publisher:**  
{{ $bulkRequest->publisher_note }}
@endif

Next steps:
1. Email them a simple sheet (URL + price columns only), or seed rows in the admin panel.
2. Add DR / DA / traffic / language / country when seeding.
3. Ask the publisher to finish description, niches, link type, and timing.
4. Approve + activate only after details are complete.

@component('mail::button', ['url' => $adminUrl])
Open bulk request
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
