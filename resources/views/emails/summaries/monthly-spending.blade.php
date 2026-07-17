@component('mail::message')
# {{ $monthLabel }} spending summary

Hello {{ $firstName }},

Your monthly spending overview is ready.

| Metric | Value |
|--------|-------|
| **Total spend** | €{{ number_format((float) ($payload['spend'] ?? 0), 2) }} |
| **Orders** | {{ $payload['orders'] ?? 0 }} |
| **Avg order value** | €{{ number_format((float) ($payload['aov'] ?? 0), 2) }} |

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
