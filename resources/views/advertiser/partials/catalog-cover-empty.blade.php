{{-- Empty homepage preview: monogram + caption. Never emit a broken <img>. --}}
@php
    $emptyLabel = $label ?? 'this website';
    $emptySize = ($size ?? 'lg') === 'md' ? 'md' : 'lg';
    $emptyCaption = $caption ?? 'No cover yet';
@endphp
<div class="catalog-cover-empty"
     role="img"
     aria-label="{{ $emptyCaption }}">
    @include('advertiser.partials.catalog-site-tile', [
        'label' => $emptyLabel,
        'size' => $emptySize,
    ])
    <span class="catalog-cover-empty__caption">{{ $emptyCaption }}</span>
</div>
