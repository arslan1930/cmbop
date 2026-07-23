@component('mail::message')
# New bulk site request

**Publisher:** {{ $publisherName }} ({{ $publisherEmail }})  
**Sites submitted:** {{ $bulkRequest->items->count() ?: ($bulkRequest->estimated_count ?? '—') }}  
**Status:** {{ $bulkRequest->status }}

@if($bulkRequest->publisher_note)
**Note from publisher:**  
{{ $bulkRequest->publisher_note }}
@endif

@if($bulkRequest->items->isNotEmpty())
**URL + price (from publisher):**

@foreach($bulkRequest->items as $item)
- {{ $item->site_url }} — €{{ number_format((float) $item->price, 2) }}
@endforeach
@endif

Next steps:
1. Open the request and review the submitted URL + price list.
2. Add DR / DA / traffic / language / country when seeding drafts.
3. Ask the publisher to finish description, niches, link type, and timing.
4. Approve + activate only after details are complete.

@component('mail::button', ['url' => $adminUrl])
Open bulk request
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
