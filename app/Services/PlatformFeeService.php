<?php

namespace App\Services;

class PlatformFeeService
{
    /**
     * @var list<array{min: float, max: ?float, percent: float}>
     */
    private array $tiers;

    private float $legacyMarkupRate;

    /**
     * @param  list<array{min?: float|int, max?: float|int|null, percent?: float|int}>|null  $tiers
     */
    public function __construct(?array $tiers = null, ?float $legacyMarkupRate = null)
    {
        $configured = $tiers ?? $this->configValue('pricing.fee_tiers');
        $this->tiers = $this->normalizeTiers(is_array($configured) ? $configured : self::defaultTiers());
        $legacy = $legacyMarkupRate ?? $this->configValue('pricing.legacy_markup_rate');
        $this->legacyMarkupRate = (float) ($legacy ?: 1.15);
    }

    private function configValue(string $key): mixed
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            if (! is_object($app) || ! method_exists($app, 'bound') || ! $app->bound('config')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return config($key);
    }

    /**
     * @return list<array{min: float, max: ?float, percent: float}>
     */
    public static function defaultTiers(): array
    {
        return [
            ['min' => 0.0, 'max' => 99.99, 'percent' => 15.0],
            ['min' => 100.0, 'max' => 299.99, 'percent' => 13.0],
            ['min' => 300.0, 'max' => 999.99, 'percent' => 12.0],
            ['min' => 1000.0, 'max' => null, 'percent' => 10.0],
        ];
    }

    public function feePercentForBase(float $base): float
    {
        $base = max(0, $base);

        foreach ($this->tiers as $tier) {
            if ($base < $tier['min']) {
                continue;
            }
            if ($tier['max'] !== null && $base > $tier['max']) {
                continue;
            }

            return $tier['percent'];
        }

        return (float) end($this->tiers)['percent'];
    }

    public function feeAmountForBase(float $base): float
    {
        $base = round(max(0, $base), 2);

        return round($base * ($this->feePercentForBase($base) / 100), 2);
    }

    public function advertiserBase(float $base): float
    {
        $base = round(max(0, $base), 2);

        return round($base + $this->feeAmountForBase($base), 2);
    }

    public function markupMultiplierForBase(float $base): float
    {
        return 1 + ($this->feePercentForBase($base) / 100);
    }

    public function legacyMarkupRate(): float
    {
        return $this->legacyMarkupRate;
    }

    /**
     * SQL expression that converts publisher `price` column to advertiser-facing base.
     * Column name defaults to `price` (sites.price).
     */
    public function advertiserBaseSqlExpression(string $column = 'price'): string
    {
        $cases = [];
        foreach ($this->tiers as $tier) {
            $rate = 1 + ($tier['percent'] / 100);
            $min = $tier['min'];
            $max = $tier['max'];
            if ($max === null) {
                $cases[] = "WHEN {$column} >= {$min} THEN ROUND({$column} * {$rate}, 2)";
            } else {
                $cases[] = "WHEN {$column} >= {$min} AND {$column} <= {$max} THEN ROUND({$column} * {$rate}, 2)";
            }
        }

        $fallbackRate = 1 + ((float) end($this->tiers)['percent'] / 100);

        return 'CASE '.implode(' ', $cases)." ELSE ROUND({$column} * {$fallbackRate}, 2) END";
    }

    /**
     * @param  list<array{min?: float|int, max?: float|int|null, percent?: float|int}>  $tiers
     * @return list<array{min: float, max: ?float, percent: float}>
     */
    private function normalizeTiers(array $tiers): array
    {
        $normalized = [];
        foreach ($tiers as $tier) {
            $normalized[] = [
                'min' => (float) ($tier['min'] ?? 0),
                'max' => array_key_exists('max', $tier) && $tier['max'] !== null
                    ? (float) $tier['max']
                    : null,
                'percent' => (float) ($tier['percent'] ?? 15),
            ];
        }

        return $normalized !== [] ? $normalized : self::defaultTiers();
    }
}
