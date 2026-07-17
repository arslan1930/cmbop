@php
    $brand = config('email_notifications.brand', []);
    $logo = $brand['logo_url'] ?? null;
    $siteUrl = $brand['website_url'] ?? config('app.url');
    $support = $brand['support_email'] ?? null;
    $social = array_filter($brand['social'] ?? []);
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$siteUrl">
@if($logo)
<img src="{{ $logo }}" class="logo" alt="{{ $brand['name'] ?? config('app.name') }}" style="max-height:48px;width:auto;">
@else
{{ $brand['name'] ?? config('app.name') }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if($support)
Need help? Contact us at [{{ $support }}](mailto:{{ $support }})
@endif

@if(!empty($social))
@foreach($social as $network => $url)
[{{ ucfirst($network) }}]({{ $url }}){{ !$loop->last ? ' · ' : '' }}
@endforeach
@endif

[{{ $brand['name'] ?? config('app.name') }}]({{ $siteUrl }})

{{ $brand['copyright'] ?? ('© ' . date('Y') . ' ' . config('app.name') . '. All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
