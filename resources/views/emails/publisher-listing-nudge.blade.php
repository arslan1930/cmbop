@component('mail::message')
# Reminder — finish your listing

Hi {{ $publisherName }},

@if($kind === 'accept')
Our team added {{ $domain }} to your account. Please review it and accept it so it appears in your My Sites list.

After you accept:
- The site shows under My Sites
- Staff review the listing. The Verified badge is a separate TXT step
- Catalog Activate is not automatic
@else
{{ $domain }} is still waiting on listing details (description, niches, link type, turnaround, publication time, or example URL). It stays hidden from advertisers until you finish and our team approves.
@endif

@component('mail::button', ['url' => $ctaUrl])
@if($kind === 'accept')
Review & accept site
@else
Open Pending sites
@endif
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
