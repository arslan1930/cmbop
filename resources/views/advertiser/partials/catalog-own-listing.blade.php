{{--
    Dual-role publisher shopping as advertiser: this row is their listing.
    Show the entered price (no fee) and no Add to cart.

    @param string $align  center (table) | start (card)
--}}
@php
    $ownAlign = ($align ?? 'center') === 'start' ? 'start' : 'center';
@endphp
<div class="catalog-own-listing catalog-own-listing--{{ $ownAlign }}">
    <span class="catalog-own-listing__badge">Your listing</span>
    <p class="catalog-own-listing__hint">{{ \App\Models\Site::cannotOrderOwnListingMessage() }}</p>
    <a href="{{ route('publisher.websites') }}" class="btn btn-sm btn-outline-secondary catalog-own-listing__link">
        My Sites
    </a>
</div>
