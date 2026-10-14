<?php

namespace App\Support;

class CartDisplayFx
{
    public const SESSION_KEY = 'cart_fx';

    /**
     * Keep the first cart rate until the cart is empty so $ / £ labels do not drift.
     *
     * @param  list<array<string, mixed>>  $cart
     * @return array{currency: string, rate: float, symbol: string}|null
     */
    public function syncWithCart(array $cart): ?array
    {
        if ($cart === []) {
            $this->forget();

            return null;
        }

        return $this->remember();
    }

    /**
     * @return array{currency: string, rate: float, symbol: string}
     */
    public function remember(): array
    {
        $display = $this->displaySnap();
        $existing = $this->current();
        if ($existing !== null && strtoupper((string) $existing['currency']) === $display['currency']) {
            return $existing;
        }

        session([self::SESSION_KEY => $display]);

        return $display;
    }

    /**
     * @return array{currency: string, rate: float, symbol: string}|null
     */
    public function current(): ?array
    {
        $existing = session(self::SESSION_KEY);
        if (! is_array($existing)) {
            return null;
        }

        $rate = (float) ($existing['rate'] ?? 0);
        $currency = strtoupper((string) ($existing['currency'] ?? ''));
        $symbol = (string) ($existing['symbol'] ?? '');
        if ($rate <= 0 || $currency === '') {
            return null;
        }

        return [
            'currency' => $currency,
            'rate' => $rate,
            'symbol' => $symbol !== '' ? $symbol : app(MoneyDisplay::class)->symbolFor($currency),
        ];
    }

    public function rate(): ?float
    {
        return $this->current()['rate'] ?? null;
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array{currency: string, rate: float, symbol: string}
     */
    public function displaySnap(): array
    {
        $money = app(MoneyDisplay::class);

        return [
            'currency' => $money->currency(),
            'rate' => $money->rate(),
            'symbol' => $money->symbol(),
        ];
    }
}
