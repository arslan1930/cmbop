<?php

namespace App\Support;

/**
 * Publisher withdrawal rails and the USD conversion fee.
 *
 * USD wallets: PayPal, Wise, and USD bank transfer only — each charged the
 * conversion fee. EUR / GBP keep every method and no conversion fee.
 */
class WithdrawalPayoutConfig
{
    /** @var list<string> */
    public const ALL_METHODS = ['bank', 'paypal', 'wise', 'crypto'];

    /** @var list<string> */
    public const USD_METHODS = ['bank', 'paypal', 'wise'];

    public static function isUsd(?string $currency): bool
    {
        return strtoupper(trim((string) $currency)) === 'USD';
    }

    /**
     * @return list<string>
     */
    public static function allowedMethods(?string $currency): array
    {
        return self::isUsd($currency) ? self::USD_METHODS : self::ALL_METHODS;
    }

    public static function allowsMethod(string $method, ?string $currency): bool
    {
        return in_array($method, self::allowedMethods($currency), true);
    }

    public static function conversionFeePercent(?string $currency): float
    {
        if (! self::isUsd($currency)) {
            return 0.0;
        }

        return max(0, (float) config('billing.withdrawal_usd_conversion_fee_percent', 0.5));
    }

    public static function conversionFee(float $amount, ?string $currency): float
    {
        $percent = self::conversionFeePercent($currency);
        if ($percent <= 0) {
            return 0.0;
        }

        return round(($amount * $percent) / 100, 2);
    }

    public static function methodUnavailableMessage(): string
    {
        return 'USD withdrawals can use PayPal, Wise, or USD bank transfer only.';
    }
}
