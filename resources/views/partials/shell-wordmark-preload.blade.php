@php
    $shellWordmark = brand_shell_wordmark_url();
@endphp
<link rel="preload" as="image" href="{{ $shellWordmark }}"@if(str_contains($shellWordmark, '.webp')) type="image/webp"@endif fetchpriority="high">
<link rel="preload" as="font" type="font/woff2" href="{{ asset('assets/fonts/poppins/poppins-400-latin.woff2') }}" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="{{ asset('assets/fonts/poppins/poppins-600-latin.woff2') }}" crossorigin>
