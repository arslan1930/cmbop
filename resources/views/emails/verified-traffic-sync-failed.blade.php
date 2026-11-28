<x-mail::message>
# Verified traffic needs attention

Hello{{ $publisher?->name ? ' '.$publisher->name : '' }},

We could not refresh verified traffic for **{{ $site->site_name }}**
({{ $site->domain ?: $site->site_url }}).

@if($authFailure)
Google access expired or was revoked. Reconnect Google Analytics / Search Console to keep the Verified traffic badge on your listing.
@else
The listing still shows the last monthly visits number, without the Verified badge, until the next successful sync.
@endif

<x-mail::button :url="rtrim(app_public_url(), '/').route('publisher.sites.google.properties', $site, false)">
Reconnect Google
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
