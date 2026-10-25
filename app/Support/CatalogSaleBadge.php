<?php

namespace App\Support;

/**
 * Catalog sale chip / "% off" label.
 *
 * Publisher-configured custom_discount_percent is nominal. Discounts can only
 * eat the portal fee, so the painted pay price is often higher than a raw
 * percent-off. The badge must match the euros on the row:
 * (list − displayed) / list.
 */
class CatalogSaleBadge
{
    public static function percent(float $list, float $displayed): ?float
    {
        $list = round($list, 2);
        $displayed = round($displayed, 2);
        if ($list <= 0 || $displayed >= $list) {
            return null;
        }

        return round((($list - $displayed) / $list) * 100, 1);
    }

    public static function label(float $list, float $displayed): ?string
    {
        $percent = self::percent($list, $displayed);
        if ($percent === null || $percent <= 0) {
            return null;
        }

        return self::formatPercent($percent);
    }

    public static function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1), '0'), '.').'%';
    }

    public static function offerText(float $list, float $displayed): ?string
    {
        $label = self::label($list, $displayed);
        if ($label === null) {
            return null;
        }

        return __('messages.catalog_percent_off', ['percent' => $label]);
    }

    /**
     * Multi-buy copy only when pack savings beat the qty-1 sale (or there is no sale).
     */
    public static function bulkNote(?float $salePercent, ?float $bulkPercent, int $minQty = 3): ?string
    {
        if ($bulkPercent === null || $bulkPercent <= 0) {
            return null;
        }

        if ($salePercent !== null && round($bulkPercent, 1) <= round($salePercent, 1)) {
            return null;
        }

        return __('messages.catalog_bulk_when_buying', [
            'percent' => self::formatPercent((float) $bulkPercent),
            'qty' => max(2, $minQty),
        ]);
    }

    /**
     * Pack copy listing every set tier, e.g. "3 for −10% · 5 for −15% · 10 for −25%".
     * Hidden when a qty-1 sale is at least as strong as every pack tier.
     *
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $tiers
     */
    public static function bulkTiersNote(?float $salePercent, array $tiers): ?string
    {
        $label = BulkDiscount::label($tiers);
        if ($label === null) {
            return null;
        }

        $best = BulkDiscount::bestPercent($tiers);
        if ($best === null) {
            return null;
        }
        if ($salePercent !== null && round($best, 1) <= round($salePercent, 1)) {
            return null;
        }

        return $label;
    }

    /**
     * Extra paid options that make the closed-row price a starting "from".
     *
     * @param  array<string, mixed>  $sensitivePrices
     * @param  array<int, mixed>  $homepageOptions
     */
    public static function hasPaidAddons(array $sensitivePrices, array $homepageOptions = []): bool
    {
        foreach ($sensitivePrices as $amount) {
            if (is_numeric($amount) && (float) $amount > 0) {
                return true;
            }
        }
        foreach ($homepageOptions as $fee) {
            if (is_numeric($fee) && (float) $fee > 0) {
                return true;
            }
        }

        return false;
    }
}
