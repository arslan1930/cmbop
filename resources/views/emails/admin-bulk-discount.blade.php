<x-mail::message>
# Pack discount {{ $change === 'removed' ? 'removed' : ($change === 'updated' ? 'updated' : 'added') }}

Hi {{ $publisherName }},

@if($change === 'removed')
Staff removed the 3 / 5 / 10-article pack discount on **{{ $site->site_name }}**.
@if($fromLabel)
The previous offer was {{ $fromLabel }}.
@endif
Advertisers no longer see a pack discount on this listing.
@elseif($change === 'updated')
Staff updated the pack discount on **{{ $site->site_name }}**.

@if($fromLabel)
Previously: {{ $fromLabel }}
@endif

Now: {{ $toLabel ?? 'off' }}
@else
Staff set a pack discount on **{{ $site->site_name }}**.

Offer: {{ $toLabel ?? 'off' }}
@endif

You can change or remove these percents anytime from your publisher websites page. The 3-article rate applies when an advertiser orders 3–4 articles, the 5-article rate at 5–9, and the 10-article rate at 10 or more.

<x-mail::button :url="$manageUrl">
Manage my websites
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
