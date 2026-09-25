{{-- Recommended catalog row. $showLanguage: new-advertiser rail only.
     Open site always goes through /advertiser/go/{id} — never a raw publisher href. --}}
@php
    $canSeeUrl = $urlVisibility->canSee(auth()->user(), $site);
    $displayUrl = $urlVisibility->hostFor(auth()->user(), $site);
    $catalogHref = route('advertiser.catalog', ['site' => $site->id]);
    $href = $canSeeUrl
        ? route('advertiser.catalog.visit', $site->id)
        : $catalogHref;
    $showLanguage = $showLanguage ?? false;
@endphp
<div class="recommended-site">
    <div>
        <a href="{{ $href }}"
           @if($canSeeUrl) target="_blank" rel="noopener noreferrer" @endif
           class="rs-name">{{ $displayUrl }}</a>
        <p class="rs-meta mb-0">
            DR {{ $site->dr }}@if($showLanguage) · {{ fullLanguage($site->language) }}@endif
        </p>
    </div>
    <a href="{{ $catalogHref }}" class="rs-price">{{ format_money($site->display_price) }}</a>
</div>
