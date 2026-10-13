<?php

namespace App\Support;

/**
 * Helpers for advertiser Add Funds deposit payment rails (bank / Wise / crypto).
 */
class DepositPaymentConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function depositPayment(): array
    {
        return (array) config('billing.deposit_payment', []);
    }

    public static function wisePayUrl(): string
    {
        return rtrim((string) (self::depositPayment()['wise_pay_url'] ?? 'https://wise.com/pay/business/teqnoltd'), '?&');
    }

    public static function wisePayLink(float $amount): string
    {
        $amount = max(0, round($amount, 2));

        return self::wisePayUrl().'?amount='.$amount.'&currency=EUR';
    }

    public static function depositMin(): float
    {
        $min = (float) config('billing.deposit_min', 10);

        return $min > 0 ? $min : 10.0;
    }

    public static function depositMax(): float
    {
        $min = self::depositMin();
        $max = (float) config('billing.deposit_max', 50000);
        if ($max < $min) {
            $max = 50000.0;
        }

        return $max;
    }

    public static function amountRule(): string
    {
        return 'required|numeric|min:'.self::depositMin().'|max:'.self::depositMax();
    }

    public static function cryptoEnabled(): bool
    {
        $crypto = (array) (self::depositPayment()['crypto'] ?? []);

        return ! empty($crypto['enabled']) && self::cryptoNetworks() !== [];
    }

    public static function cryptoNote(): string
    {
        $crypto = (array) (self::depositPayment()['crypto'] ?? []);

        return (string) ($crypto['note'] ?? '');
    }

    /**
     * @return list<array{key: string, label: string, address: string}>
     */
    public static function cryptoNetworks(): array
    {
        $crypto = (array) (self::depositPayment()['crypto'] ?? []);
        $networks = [];

        foreach ((array) ($crypto['networks'] ?? []) as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '') {
                continue;
            }
            $networks[] = [
                'key' => (string) ($row['key'] ?? md5($address)),
                'label' => (string) ($row['label'] ?? 'Crypto'),
                'address' => $address,
            ];
        }

        return $networks;
    }
}
