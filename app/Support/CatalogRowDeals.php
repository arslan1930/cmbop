<?php

namespace App\Support;

use App\Models\Site;
use App\Services\CartPricingService;

/**
 * Closed-row deal signals for the advertiser catalog.
 *
 * Qty-1 sale lives once beside the price. Multi-buy is a separate note only
 * when pack savings actually beat that painted percent.
 */
class CatalogRowDeals
{
    /**
     * @param  array<string, mixed>  $sensitivePrices
     * @param  array<int, mixed>  $homepageOptions
     * @return array{sale_percent: float|null, bulk_percent: float|null, bulk_note: string|null, has_paid_addons: bool}
     */
    public static function forSite(
        Site $site,
        float $listPrice,
        float $payPrice,
        float $publisherPrice,
        array $sensitivePrices = [],
        array $homepageOptions = []
    ): array {
        $salePercent = CatalogSaleBadge::percent($listPrice, $payPrice);
        $bulkPercent = null;
        $customPct = null;
        $nominalBulk = null;

        try {
            $customPct = $site->activeCustomDiscountPercent();
            $nominalBulk = $site->joinsBulkDiscount()
                ? (float) $site->bulk_discount_percent
                : null;
        } catch (\Throwable $e) {
            report($e);
        }

        if ($nominalBulk !== null && ($customPct === null || $nominalBulk > (float) $customPct)) {
            try {
                $packSite = clone $site;
                $packSite->price = $publisherPrice;
                $packPricing = app(CartPricingService::class)
                    ->priceForAdvertiser(
                        $packSite,
                        null,
                        (int) config('site_promotions.bulk.min_qty', 3)
                    );
                $effective = (float) ($packPricing['discount_percent'] ?? $nominalBulk);
                if ($effective > 0) {
                    $bulkPercent = $effective;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $minQty = (int) config('site_promotions.bulk.min_qty', 3);

        return [
            'sale_percent' => $salePercent,
            'bulk_percent' => $bulkPercent,
            'bulk_note' => CatalogSaleBadge::bulkNote($salePercent, $bulkPercent, $minQty),
            'has_paid_addons' => CatalogSaleBadge::hasPaidAddons($sensitivePrices, $homepageOptions),
        ];
    }
}
