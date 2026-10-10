@component('mail::message')
# Websites are live on your account

Hi {{ $publisherName }},

Our team published {{ $count === 1 ? '1 website' : $count.' websites' }} on your account. {{ $count === 1 ? 'It is' : 'They are' }} live for advertisers and not verified yet. You do not need to Accept invites.

@if(count($domains) > 0)
@foreach($domains as $domain)
- {{ $domain }}
@endforeach
@if($extra > 0)
- and {{ $extra }} more
@endif
@endif

@component('mail::button', ['url' => $sitesUrl])
Open My Sites
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
