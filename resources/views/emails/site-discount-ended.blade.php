<x-mail::message>
# Your discount has ended

Hi {{ $publisher->name ?? 'there' }},

Your configured timed discount on {{ $site->site_name }}
@if($percent)
(−{{ rtrim(rtrim(number_format($percent, 2), '0'), '.') }}%)
@endif
has ended. That percent is no longer taken off your list, and advertisers no longer see the sale at checkout.

@if($endedAt)
Ended: {{ $endedAt->timezone(config('app.timezone'))->format('d M Y H:i') }}
@endif

You can set a new timed discount anytime from your publisher websites catalog.

<x-mail::button :url="rtrim(app_public_url(), '/').route('publisher.websites', [], false)">
Manage my websites
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
