@component('mail::message')
# Agency CSV import submitted

**Import:** #{{ $import->id }}  
**Publisher:** {{ $publisherName }} ({{ $publisherEmail }})  
**Sites submitted:** {{ $createdCount }}  
**Failed rows:** {{ $failedCount }}  
@if($import->original_filename)
**File:** {{ $import->original_filename }}
@endif

Metrics on these rows are publisher-supplied (CSV). Spot-check DA/DR/traffic before activating.

@component('mail::button', ['url' => $adminUrl])
Review this import
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
