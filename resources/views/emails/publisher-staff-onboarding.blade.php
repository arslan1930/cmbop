@component('mail::message')
# {{ $heading }}

{{ $hiLine }}

{{ $intro }}

@if($count > 0)
{{ $sitesLine }}

@foreach($domains as $domain)
- {{ $domain }}
@endforeach
@if($extra > 0)
- {{ $moreLine }}
@endif
@endif

{{ $afterLine }}

@if(!empty($needsPassword))
@component('mail::button', ['url' => $setPasswordUrl])
{{ $ctaPassword }}
@endcomponent

{{ $expiresLine }}

{{ $googleLine }}
@else
@component('mail::button', ['url' => $invitesUrl])
{{ $ctaAccept }}
@endcomponent
@endif

{{ $payoutLine }}

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} · Teqno Ltd
@endcomponent
