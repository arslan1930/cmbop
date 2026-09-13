@php
    $placeholderListing = $site ?? null;
    $showPlaceholderWarn = $placeholderListing instanceof \App\Models\Site
        && class_exists(\App\Support\CatalogPlaceholderListing::class)
        && \App\Support\CatalogPlaceholderListing::matches($placeholderListing);
@endphp
@if($showPlaceholderWarn)
    <div class="alert alert-warning border-0 py-2 px-3 small mb-3 catalog-placeholder-warning" role="status">
        {{ \App\Support\CatalogPlaceholderListing::BUYER_CAPTION }}
    </div>
@endif
