<?php

use App\Support\CartDisplayFx;
use App\Support\MoneyDisplay;

if (! function_exists('format_money')) {
    /**
     * Display an EUR ledger amount as €12.00, live USD for US/CA/AU, or
     * live GBP for UK viewers.
     *
     * @param  array{signed?: bool}  $options
     */
    function format_money(mixed $euros, array $options = []): string
    {
        return app(MoneyDisplay::class)->format($euros, $options);
    }
}

if (! function_exists('format_money_pay')) {
    /**
     * Cart / checkout: € ledger first, optional locked $ / £ guide.
     *
     * @param  array{signed?: bool, rate?: float}  $options
     */
    function format_money_pay(mixed $euros, array $options = []): string
    {
        return app(MoneyDisplay::class)->formatPay($euros, $options);
    }
}

if (! function_exists('listing_price_symbol')) {
    function listing_price_symbol(?string $country = null): string
    {
        $money = app(MoneyDisplay::class);

        return $money->symbolFor($money->currencyForCountry($country));
    }
}

if (! function_exists('money_js')) {
    /**
     * @return array{currency: string, usd: bool, rate: float, cart_rate: float, symbol: string, guide: bool}
     */
    function money_js(): array
    {
        try {
            $cart = session('cart', []);
            app(CartDisplayFx::class)->syncWithCart(is_array($cart) ? array_values($cart) : []);

            return app(MoneyDisplay::class)->jsPayload();
        } catch (Throwable) {
            return [
                'currency' => 'EUR',
                'usd' => false,
                'rate' => 1.0,
                'cart_rate' => 1.0,
                'symbol' => '€',
                'guide' => false,
            ];
        }
    }
}
