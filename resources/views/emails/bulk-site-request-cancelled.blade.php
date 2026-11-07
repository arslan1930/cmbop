@component('mail::message')
# Your bulk website request was cancelled

Hi {{ $firstName }},

We have cancelled bulk request #{{ $bulkRequest->id }}@if($count > 0), which covered {{ $count }} {{ $count === 1 ? 'website' : 'websites' }}@endif. Those sites will not be prepared for listing.

@if($reason)
Reason: {{ $reason }}
@endif

Nothing else on your account is affected, and any websites already listed stay exactly as they were.

@component('mail::button', ['url' => $websitesUrl])
Add websites again
@endcomponent

If this looks like a mistake, open My Sites or contact support and we will take another look.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
