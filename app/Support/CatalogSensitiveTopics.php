<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Advertiser catalog sensitive-topic add-ons.
 *
 * Casino is never offered in the buyer catalog (filter, chip, or SQL).
 */
class CatalogSensitiveTopics
{
    /** Buyer filter + closed-row chip. Casino is intentionally omitted. */
    public const FILTER_TYPES = ['crypto', 'trading', 'cbd', 'forex'];

    /**
     * JSON object paths for stored keys (publisher form uses CBD mixed-case).
     *
     * @var list<string>
     */
    public const JSON_PATHS = [
        '$.crypto',
        '$.Crypto',
        '$.CRYPTO',
        '$.trading',
        '$.Trading',
        '$.TRADING',
        '$.cbd',
        '$.CBD',
        '$.Cbd',
        '$.forex',
        '$.Forex',
        '$.FOREX',
    ];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'crypto' => __('messages.catalog_sensitive_crypto'),
            'trading' => __('messages.catalog_sensitive_trading'),
            'cbd' => __('messages.catalog_sensitive_cbd'),
            'forex' => __('messages.catalog_sensitive_forex'),
        ];
    }

    public static function normalizeType(string $type): ?string
    {
        $key = strtolower(trim($type));

        return array_key_exists($key, self::labels()) ? $key : null;
    }

    /**
     * @param  array<string, mixed>  $prices
     * @return array<string, float>
     */
    public static function offered(array $prices): array
    {
        $out = [];
        foreach ($prices as $type => $amount) {
            $key = self::normalizeType((string) $type);
            if ($key === null || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }
            $value = round((float) $amount, 2);
            if (! isset($out[$key]) || $value < $out[$key]) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Closed-row chip. Compact when 1–2 niches; otherwise a from-+€ summary.
     *
     * @param  array<string, mixed>  $prices
     * @return array{label: string, min: float, types: list<string>}|null
     */
    public static function chip(array $prices): ?array
    {
        $offered = self::offered($prices);
        if ($offered === []) {
            return null;
        }

        $min = min($offered);
        $names = [];
        foreach (array_keys($offered) as $key) {
            $names[] = self::labels()[$key];
        }
        $money = format_money($min, ['signed' => true]);
        $label = count($names) <= 2
            ? implode('/', $names).' '.$money
            : __('messages.catalog_sensitive_topics_from', ['price' => $money]);

        return [
            'label' => $label,
            'min' => $min,
            'types' => array_keys($offered),
        ];
    }

    /**
     * @param  Builder<Site>|\Illuminate\Database\Query\Builder  $query
     */
    public static function constrainQuery($query): void
    {
        if (! Site::hasSitesColumn('sensitive_prices')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $paths = self::JSON_PATHS;
        $query->where(function ($q) use ($paths) {
            foreach ($paths as $path) {
                $q->orWhereRaw('CAST(json_extract(sensitive_prices, ?) AS FLOAT) > 0', [$path]);
            }
        });
    }
}
