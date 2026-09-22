@component('mail::message')
# Your bulk sites are active

Hi {{ $publisherName }},

{{ $createdCount === 1 ? '1 website' : $createdCount.' websites' }} from bulk request #{{ $bulkRequest->id }} {{ $createdCount === 1 ? 'is' : 'are' }} now active on your account.

@component('mail::button', ['url' => $completeUrl])
Open My Sites
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
