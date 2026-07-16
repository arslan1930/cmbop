@component('mail::message')
# Your week in review

Hello {{ $firstName }},

Here’s a quick look at your activity this week.

| Metric | Value |
|--------|-------|
| **Orders** | {{ $payload['orders'] ?? 0 }} |
| **Spend** | €{{ number_format((float) ($payload['spend'] ?? 0), 2) }} |
| **Completed** | {{ $payload['completed'] ?? 0 }} |

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
