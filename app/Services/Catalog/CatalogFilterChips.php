<?php

namespace App\Services\Catalog;

/**
 * Active-filter chip labels for the advertiser catalog.
 */
class CatalogFilterChips
{
    public static function priceLabel(mixed $minRaw, mixed $maxRaw): ?string
    {
        $min = is_numeric($minRaw) ? (float) $minRaw : null;
        $max = is_numeric($maxRaw) ? (float) $maxRaw : null;
        if ($min === null && $max === null) {
            return null;
        }

        if ($min !== null && $max !== null) {
            return 'Price '.self::money($min).'–'.self::money($max);
        }
        if ($max !== null) {
            return 'Price ≤ '.self::money($max);
        }

        return 'Price ≥ '.self::money($min);
    }

    /**
     * @param  array<string, string>  $names  code => label
     */
    public static function languageLabel(string $raw, array $names = []): string
    {
        $codes = array_values(array_filter(array_map(
            static fn ($c) => strtolower(trim((string) $c)),
            explode(',', $raw)
        )));
        if ($codes === []) {
            return 'Language';
        }

        $labels = [];
        foreach ($codes as $code) {
            $labels[] = $names[$code] ?? strtoupper($code);
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        return implode(', ', $labels);
    }

    private static function money(float $amount): string
    {
        $decimals = abs($amount - round($amount)) < 0.001 ? 0 : 2;

        return format_money($amount, ['decimals' => $decimals]);
    }
}
