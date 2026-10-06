@props([
    'site',
    'size' => 'thumb', // thumb|full
])

@php
    $chain = $size === 'full'
        ? $site->zoomPreviewUrlChain()
        : $site->listingPreviewUrlChain();
    $url = $chain[0] ?? null;
    $alt = ($site->site_name ?: $site->domain ?: 'Website').' homepage preview';
@endphp

@if($url)
    <img src="{{ $url }}"
         alt="{{ $alt }}"
         width="{{ $size === 'full' ? 640 : 300 }}"
         height="{{ $size === 'full' ? 360 : 188 }}"
         loading="lazy"
         decoding="async"
         data-preview-chain="{{ json_encode($chain, JSON_UNESCAPED_SLASHES) }}"
         data-preview-i="0"
         {{ $attributes->class(['publisher-screenshot']) }}
         onerror="(function(img){var c=[];try{c=JSON.parse(img.getAttribute('data-preview-chain')||'[]');}catch(e){c=[];}if(!Array.isArray(c))c=[];var i=parseInt(img.getAttribute('data-preview-i')||'0',10)||0;var n=i+1;if(n<c.length&&c[n]){img.setAttribute('data-preview-i',String(n));img.src=c[n];return;}img.onerror=null;img.src='data:image/svg+xml,'+encodeURIComponent('<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;640&quot; height=&quot;360&quot;><rect fill=&quot;#f1f5f9&quot; width=&quot;100%&quot; height=&quot;100%&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; text-anchor=&quot;middle&quot; fill=&quot;#75787B&quot; font-family=&quot;sans-serif&quot; font-size=&quot;18&quot;>Preview unavailable</text></svg>');})(this);">
@else
    <div {{ $attributes->class(['publisher-screenshot publisher-screenshot--placeholder']) }} role="img" aria-label="Preview unavailable">
        <span>Preview unavailable</span>
    </div>
@endif
