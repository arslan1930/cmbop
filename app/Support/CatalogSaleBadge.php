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

        return rtrim(rtrim(number_format($percent, 1), '0'), '.').'%';
    }
}
