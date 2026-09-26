@component('mail::message')
# Please review your websites

Hi {{ $publisherName }},

{{ $createdCount === 1 ? '1 website' : $createdCount.' websites' }} from bulk request #{{ $bulkRequest->id }} {{ $createdCount === 1 ? 'is' : 'are' }} ready for you to review. {{ $createdCount === 1 ? 'It is' : 'They are' }} not live yet.

Check the listing, then submit it. Our team verifies it after you submit.

@component('mail::button', ['url' => $reviewUrl])
Review & submit
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
