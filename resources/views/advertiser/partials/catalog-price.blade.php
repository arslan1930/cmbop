{{--
    Price for a listing: what you pay, what it was, and why.

    This used to live inside the Buy button as "Buy €113.00 €90.40", which made
    the CTA carry three competing bits of text. The number is the thing shoppers
    compare, so it gets its own line above the button.

    The .list-price-display / .base-price-display hooks stay: catalog.js rewrites
    them when a sensitive-topic add-on changes the total.

    @param float      $listPrice
    @param float|null $salePrice   null when there is no active offer
    @param float|null $salePercent
    @param string     $align       center (table) | start (card)
    @param float|null $bulkPercent effective pack savings, when bulk wins
    @param int|null   $bulkMinQty
    @param int|null   $siteId      jumps the bulk rail to this listing
--}}
@php
    $priceAlign = ($align ?? 'center') === 'start' ? 'start' : 'center';
    $hasOffer = ($salePrice ?? null) !== null;
    $payPrice = $hasOffer ? $salePrice : $listPrice;
    $bulkOfferPct = isset($bulkPercent) && $bulkPercent !== null && (float) $bulkPercent > 0
        ? (float) $bulkPercent
        : null;
    $bulkOfferQty = (int) ($bulkMinQty ?? config('site_promotions.bulk.min_qty', 3));
@endphp

<div class="catalog-price catalog-price--{{ $priceAlign }}">
    <div class="catalog-price__row">
        <span class="catalog-price__pay base-price-display">{{ format_money($payPrice) }}</span>
        @if(! empty($featured))
            <span class="catalog-site-featured-mark" aria-label="Featured">
                <i class="fa-solid fa-bolt-fill catalog-site-featured-mark__icon" data-slb-inlined="1" aria-hidden="true"></i>
            </span>
        @endif
        <span class="catalog-price__list list-price-display" {{ $hasOffer ? '' : 'hidden' }}>{{ format_money($listPrice) }}</span>
    </div>
    @if($hasOffer && $salePercent)
        {{-- salePercent is effective (post floor); list/pay euros are the source of truth --}}
        <span class="catalog-price__offer" data-catalog-offer-pct>
            <i class="fa-solid fa-tag" aria-hidden="true"></i>
            <span class="catalog-price__offer-text">{{ rtrim(rtrim(number_format((float) $salePercent, 1), '0'), '.') }}% off</span>
        </span>
    @endif
    @if($bulkOfferPct !== null)
        <button type="button"
                class="catalog-bulk-note catalog-price__bulk"
                data-bulk-jump="{{ (int) ($siteId ?? 0) }}"
                aria-label="Show this site in Bulk discount deals">
            <span class="catalog-price__bulk-label">−{{ rtrim(rtrim(number_format($bulkOfferPct, 1), '0'), '.') }}% on {{ $bulkOfferQty }}+</span>
        </button>
    @endif
</div>
