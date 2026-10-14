<?php

use App\Support\CartDisplayFx;
use App\Support\ListingMoney;
use App\Support\Money;
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
        return Money::display($euros, $options);
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
        return Money::pay($euros, $options);
    }
}

if (! function_exists('format_money_local')) {
    /**
     * Local amount only (no ≈ € ledger suffix).
     *
     * @param  array{signed?: bool, decimals?: int}  $options
     */
    function format_money_local(mixed $euros, array $options = []): string
    {
        return Money::local($euros, $options);
    }
}

if (! function_exists('format_money_amount')) {
    /**
     * Format an amount already in $currency. Does not convert from EUR.
     *
     * @param  array{signed?: bool, decimals?: int}  $options
     */
    function format_money_amount(mixed $amount, ?string $currency = 'EUR', array $options = []): string
    {
        return app(ListingMoney::class)->formatAmount($amount, $currency, $options);
    }
}

if (! function_exists('format_eur_admin')) {
    /**
     * Admin ledger euros: −€40.00 (minus before the symbol), never €-40.00.
     */
    function format_eur_admin(mixed $euros): string
    {
        $value = round((float) $euros, 2);
        $abs = number_format(abs($value), 2);

        return ($value < 0 ? MoneyDisplay::MINUS : '').'€'.$abs;
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
