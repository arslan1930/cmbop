@component('mail::message')

# New Site Submitted for Review

A new site has been submitted by **{{ $publisherName }}** ({{ $publisherEmail }}) and requires your review.

## Site Details:

- **Site Name:** {{ $siteName }}
- **Site URL:** {{ $siteUrl }}
- **Category:** {{ $site->category }}
- **Price:** €{{ number_format($site->price, 2) }}
- **DA/DR:** {{ $site->da }}/{{ $site->dr }}
- **Traffic:** {{ number_format($site->traffic) }} monthly


@component('mail::button', ['url' => $adminUrl])
Review Site
@endcomponent

Thanks,<br>
{{ config('app.name') }}

@endcomponent