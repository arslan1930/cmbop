<?php

namespace App\Support;

/**
 * Single display-currency entry point. Ledger amounts stay EUR.
 * GBP/USD are viewer labels only — never Stripe/PayPal/wallet units.
 */
class Money
{
    public static function display(mixed $euros, array $options = []): string
    {
        return app(MoneyDisplay::class)->format($euros, $options);
    }

    public static function pay(mixed $euros, array $options = []): string
    {
        return app(MoneyDisplay::class)->formatPay($euros, $options);
    }

    public static function local(mixed $euros, array $options = []): string
    {
        return app(MoneyDisplay::class)->formatLocal($euros, $options);
    }

    /**
     * @return array{currency: string, usd: bool, rate: float, cart_rate: float, symbol: string, guide: bool}
     */
    public static function js(): array
    {
        return money_js();
    }

    /**
     * @return array{label: string, under: string, mid: string, plus: string}
     */
    public static function priceFilterCopy(): array
    {
        return app(MoneyDisplay::class)->priceFilterCopy();
    }

    public static function quickAmountLabel(float $euros): string
    {
        return app(MoneyDisplay::class)->quickAmountLabel($euros);
    }

    public static function symbol(): string
    {
        return app(MoneyDisplay::class)->symbol();
    }

    public static function currency(): string
    {
        return app(MoneyDisplay::class)->currency();
    }
}
