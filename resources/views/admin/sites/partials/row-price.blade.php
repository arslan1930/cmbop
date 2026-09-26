@php
    $salePrice = null;
    try {
        $offer = $site->catalogPricesForViewer(null);
        $salePrice = $offer['sale'] ?? null;
    } catch (\Throwable $e) {
        $salePrice = null;
    }
    $featured = false;
    $bulkDiscount = false;
    try {
        $featured = $site->isFeatured();
        $bulkDiscount = $site->joinsBulkDiscount();
    } catch (\Throwable $e) {
        $featured = false;
        $bulkDiscount = false;
    }
@endphp
<div>€{{ number_format((float) $site->price, 2) }}</div>
@if($salePrice !== null)
    <div class="small text-muted">Sale €{{ number_format((float) $salePrice, 2) }}</div>
@endif
@if($featured)
    <span class="badge text-bg-primary">Featured</span>
@endif
@if($bulkDiscount)
    <span class="badge text-bg-info">Bulk</span>
@endif
