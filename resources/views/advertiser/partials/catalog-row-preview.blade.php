{{--
    Desktop-framed site preview thumb (My Sites parity).
    Expects: $identityLabel, and optionally precomputed $rowPreviewPaths / $rowZoomPaths.
--}}
@php
    $rowPreviewPaths = $rowPreviewPaths ?? $site->homepagePreviewUrlChain();
    $rowPreviewUrl = $rowPreviewPaths[0] ?? null;
    $rowZoomPaths = $rowZoomPaths ?? $site->zoomPreviewUrlChain();
    if ($rowZoomPaths === [] && $rowPreviewPaths !== []) {
        $rowZoomPaths = $rowPreviewPaths;
    }
    $rowZoomUrl = $rowZoomPaths[0] ?? $rowPreviewUrl;
    $previewClass = trim('site-row-preview '.($previewClass ?? ''));
@endphp
@if($rowPreviewUrl)
    <span class="{{ $previewClass }}"
          role="img"
          tabindex="0"
          aria-label="{{ $identityLabel }} preview"
          data-zoom-src="{{ $rowZoomUrl }}"
          data-zoom-chain="{{ json_encode($rowZoomPaths, JSON_UNESCAPED_SLASHES) }}">
        <img src="{{ $rowPreviewUrl }}"
             alt="{{ $identityLabel }} preview"
             loading="lazy"
             decoding="async"
             data-preview-chain="{{ json_encode($rowPreviewPaths, JSON_UNESCAPED_SLASHES) }}"
             data-preview-i="0"
             onerror="if(window.catalogRowPreviewOnError){window.catalogRowPreviewOnError(this);}else{(function(img){var c=[];try{c=JSON.parse(img.getAttribute('data-preview-chain')||'[]');}catch(e){c=[];}if(!Array.isArray(c))c=[];var i=parseInt(img.getAttribute('data-preview-i')||'0',10)||0;var n=i+1;if(n&lt;c.length&amp;&amp;c[n]){img.setAttribute('data-preview-i',String(n));img.src=c[n];return;}img.onerror=null;img.removeAttribute('src');var w=img.closest('.site-row-preview');if(w){w.classList.add('is-empty');w.removeAttribute('data-zoom-src');w.removeAttribute('data-zoom-chain');w.innerHTML='<i class=\'fa fa-image\' aria-hidden=\'true\'></i>';}})(this);}">
    </span>
@else
    <span class="{{ $previewClass }} is-empty"
          data-glass-tip
          data-glass-tip-body="No preview yet"
          data-glass-tip-placement="top"
          data-glass-tip-hover-only="1"
          aria-label="No preview">
        <i class="fa fa-image" aria-hidden="true"></i>
    </span>
@endif
