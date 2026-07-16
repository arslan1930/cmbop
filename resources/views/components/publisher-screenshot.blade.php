@props([
    'site',
    'size' => 'thumb', // thumb|full
])

@php
    $url = $size === 'full' ? $site->screenshot_url : $site->screenshot_thumb_url;
    $alt = ($site->site_name ?: $site->domain ?: 'Website').' homepage preview';
@endphp

@if($url)
    <img src="{{ $url }}"
         alt="{{ $alt }}"
         loading="lazy"
         decoding="async"
         {{ $attributes->class(['publisher-screenshot']) }}
         onerror="this.onerror=null;this.src='data:image/svg+xml,'+encodeURIComponent('<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;640&quot; height=&quot;360&quot;><rect fill=&quot;#f1f5f9&quot; width=&quot;100%&quot; height=&quot;100%&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; text-anchor=&quot;middle&quot; fill=&quot;#64748b&quot; font-family=&quot;sans-serif&quot; font-size=&quot;18&quot;>Preview unavailable</text></svg>');">
@else
    <div {{ $attributes->class(['publisher-screenshot publisher-screenshot--placeholder']) }} role="img" aria-label="Preview unavailable">
        <span>Preview unavailable</span>
    </div>
@endif
