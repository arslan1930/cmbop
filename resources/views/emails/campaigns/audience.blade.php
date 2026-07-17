@component('mail::message')
# Hello {{ $firstName }},

{!! $bodyHtml !!}

@if(!empty($ctaUrl) && !empty($ctaLabel))
@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent
@endif

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
