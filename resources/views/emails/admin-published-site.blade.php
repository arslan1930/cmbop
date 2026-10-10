@component('mail::message')
# A website is live on your account

Hi {{ $publisherName }},

Our team published {{ $domain }} on your account. It is live for advertisers and not verified yet. You do not need to Accept an invite.

@component('mail::button', ['url' => $sitesUrl])
Open My Sites
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
