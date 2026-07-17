@component('mail::message')
# Welcome aboard, {{ $firstName }}!

Thanks for joining **{{ $brand['name'] ?? config('app.name') }}**. Your account is ready — explore verified publishers and place your first order whenever you’re ready.

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

Prefer to start from your dashboard? [Open dashboard]({{ $dashboardUrl }})

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
