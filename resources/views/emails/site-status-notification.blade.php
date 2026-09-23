@component('mail::message')
Dear {{ $site->publisher?->name ?? 'Publisher' }},

{{-- Markdown mail treats 4+ space indents as a code block, which prints leftover **asterisks** instead of bold. Keep body lines at column 0. --}}
@switch($action)
@case('update')
Your site <strong>{{ $site->site_name }}</strong> has been updated by our team.

@if($oldData)
Changes made:

@if($oldData['site_name'] != $site->site_name)
- Site Name: {{ $oldData['site_name'] }} → {{ $site->site_name }}
@endif
@if($oldData['site_url'] != $site->site_url)
- Site URL: {{ $oldData['site_url'] }} → {{ $site->site_url }}
@endif
@if(($oldData['da'] ?? null) != $site->da)
- DA: {{ $oldData['da'] ?? 'N/A' }} → {{ $site->da }}
@endif
@if(($oldData['dr'] ?? null) != $site->dr)
- DR: {{ $oldData['dr'] ?? 'N/A' }} → {{ $site->dr }}
@endif
@if(($oldData['traffic'] ?? null) != $site->traffic)
- Traffic: {{ number_format($oldData['traffic'] ?? 0) }} → {{ number_format($site->traffic) }}
@endif
@endif
@break

@case('activated')
@if(method_exists($site, 'isCatalogVisible') ? $site->isCatalogVisible() : (bool) $site->active)
Your site <strong>{{ $site->site_name }}</strong> has been approved and is now live on our platform.

Next steps:
- Your site will appear in our catalog
- Advertisers can now view and purchase placements
- You will receive notifications when orders are placed
@else
Your site <strong>{{ $site->site_name }}</strong> was marked active, but it is not listed in the catalog.

This usually means the bulk request for this listing was cancelled. Contact support if you think this is a mistake.
@endif
@break

@case('deactivated')
Your site <strong>{{ $site->site_name }}</strong> has been <strong>deactivated</strong>.

What this means:
- Your site is no longer visible in our catalog
- New orders cannot be placed
- Existing orders will be fulfilled as agreed
@if(!empty($reason))

<strong>Reason:</strong>
{{ $reason }}
@endif

Please contact support if you believe this is an error.
@break

@case('verified')
Congratulations! Your site <strong>{{ $site->site_name }}</strong> has been <strong>verified</strong>.

Benefits of verification:
- Higher trust from advertisers
- Priority placement in search results
- Verified badge displayed on your site listing
@break

@case('unverified')
Your site <strong>{{ $site->site_name }}</strong> has been <strong>unverified</strong>.

Please review your site information and ensure it meets our quality guidelines.
@if(!empty($reason))

<strong>Reason:</strong>
{{ $reason }}
@endif

Contact support if you believe this decision was made in error.
@break

@case('removed')
Your submission for <strong>{{ $site->site_name }}</strong> has been removed and will not be listed.
@if(!empty($reason))

<strong>Reason:</strong>
{{ $reason }}
@endif

You are welcome to submit the site again once the points above are addressed, or contact support if you believe this was a mistake.
@break

@case('archived')
Your site <strong>{{ $site->site_name }}</strong> has been archived and is hidden from the catalog.

What this means:
- Your site is no longer visible to advertisers
- New orders cannot be placed
- Existing orders are unchanged
@if(!empty($reason))

<strong>Reason:</strong>
{{ $reason }}
@endif

Please contact support if you believe this is an error.
@break

@default
There has been a status change for your site <strong>{{ $site->site_name }}</strong>.
@endswitch

### Current Site Details

- Site Name: {{ $site->site_name }}
- Site URL: {{ $site->site_url }}
- Category: {{ $site->category }}
- Price: €{{ number_format($site->price, 2) }}
- DA/DR: {{ $site->da }}/{{ $site->dr }}
- Traffic: {{ number_format($site->traffic) }} monthly visitors

@component('mail::button', ['url' => rtrim(app_public_url(), '/').(\Illuminate\Support\Facades\Route::has('publisher.websites') ? route('publisher.websites', [], false) : '/publisher/websites')])
View Your Sites
@endcomponent

If you have any questions, please don't hesitate to contact our support team.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
