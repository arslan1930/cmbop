@component('mail::message')
# We did not add {{ $count === 1 ? 'a site' : 'some sites' }} from your bulk request

Hi {{ $firstName }},

We did not add {{ $count === 1 ? 'this website' : 'these websites' }} from bulk request #{{ $bulkRequest->id }}:

@foreach($domains as $domain)
- {{ $domain }}
@endforeach

@if($note)
Note from our team: {{ $note }}
@endif

Nothing else on your account is affected. Websites we already published stay on your account, and you can submit these URLs again at any time.

@component('mail::button', ['url' => $websitesUrl])
Open My Sites
@endcomponent

If this looks like a mistake, reply to this email and we will take another look.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
