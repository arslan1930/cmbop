<?php

namespace App\Support;

/**
 * Query string for Add Funds when the advertiser left checkout short of wallet.
 */
class AddFundsCheckout
{
    /**
     * @return array{from: string, needed: float, amount: int, method?: string}
     */
    public static function query(float $needed, ?string $method = null): array
    {
        $needed = round(max(0, $needed), 2);

        $query = [
            'from' => 'checkout',
            'needed' => $needed,
            'amount' => max(10, (int) ceil($needed)),
        ];

        if (is_string($method) && $method !== '') {
            $query['method'] = $method;
        }

        return $query;
    }
}
