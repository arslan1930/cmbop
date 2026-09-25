<?php

namespace App\Services;

use App\Support\PlatformCharge;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripePaymentService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Convert a decimal EUR amount to Stripe integer cents.
     * Uses round() to avoid float truncation (e.g. 19.99 * 100 => 1998.999...).
     */
    public static function toCents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Convert Stripe integer cents to a decimal EUR amount.
     */
    public static function fromCents(int|float|string|null $cents): float
    {
        return round(((float) ($cents ?? 0)) / 100, 2);
    }

    /**
     * Success URL used by live order card checkout (CatalogController).
     */
    public static function orderCheckoutSuccessUrl(string $referenceCode): string
    {
        return route('advertiser.checkout.process')
            .'?session_id={CHECKOUT_SESSION_ID}&ref='.urlencode($referenceCode);
    }

    /**
     * Cancel URL used by live order card checkout.
     */
    public static function orderCheckoutCancelUrl(string $referenceCode): string
    {
        return route('advertiser.checkout')
            .'?canceled=1&ref='.urlencode($referenceCode);
    }

    /**
     * Success URL used by live wallet top-up checkout (AddFundsController).
     */
    public static function walletDepositSuccessUrl(float|int|string $amountEuros, string $referenceCode): string
    {
        $amount = number_format((float) $amountEuros, 2, '.', '');

        return route('advertiser.checkout.success')
            .'?session_id={CHECKOUT_SESSION_ID}&amount='.$amount.'&ref='.urlencode($referenceCode);
    }

    /**
     * Cancel URL used by live wallet top-up checkout.
     */
    public static function walletDepositCancelUrl(): string
    {
        return route('advertiser.add-funds');
    }

    /**
     * Create a checkout session for orders.
     * Kept in sync with Advertiser\CatalogController card checkout URLs.
     *
     * @param  array{item_count: int|string, total_amount: float|int|string}  $orderData
     */
    public function createOrderCheckoutSession(array $orderData, string $referenceCode, int|string $userId): Session
    {
        $charge = app(PlatformCharge::class)->quote((float) $orderData['total_amount']);

        return Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $charge['stripe'],
                    'product_data' => [
                        'name' => 'Order Package - '.$orderData['item_count'].' item(s)',
                        'description' => 'Order reference: '.$referenceCode,
                    ],
                    'unit_amount' => self::toCents($charge['amount']),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => self::orderCheckoutSuccessUrl($referenceCode),
            'cancel_url' => self::orderCheckoutCancelUrl($referenceCode),
            'metadata' => [
                'type' => 'order_payment',
                'reference_code' => $referenceCode,
                'user_id' => (string) $userId,
                'order_count' => (string) $orderData['item_count'],
                'expected_amount' => (string) $charge['euros'],
                'eur_amount' => (string) $charge['euros'],
                'charge_currency' => $charge['code'],
                'charge_amount' => (string) $charge['amount'],
            ],
        ]);
    }

    /**
     * Create a checkout session for wallet funding.
     * Kept in sync with Advertiser\AddFundsController card checkout URLs.
     */
    public function createWalletCheckoutSession(
        float|int|string $amount,
        int|string $userId,
        string $referenceCode
    ): Session {
        $charge = app(PlatformCharge::class)->quote(round((float) $amount, 2));
        $amountEuros = $charge['euros'];

        return Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $charge['stripe'],
                    'product_data' => [
                        'name' => 'Add Funds to Wallet',
                        'description' => 'Deposit '.$charge['code'].' '.number_format($charge['amount'], 2).' (wallet €'.number_format($amountEuros, 2).')',
                    ],
                    'unit_amount' => self::toCents($charge['amount']),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => self::walletDepositSuccessUrl($amountEuros, $referenceCode),
            'cancel_url' => self::walletDepositCancelUrl(),
            'metadata' => [
                'type' => 'wallet_deposit',
                'user_id' => (string) $userId,
                'amount' => (string) $amountEuros,
                'eur_amount' => (string) $amountEuros,
                'charge_currency' => $charge['code'],
                'charge_amount' => (string) $charge['amount'],
                'reference_code' => $referenceCode,
            ],
        ]);
    }

    /**
     * Verify and retrieve a checkout session.
     */
    public function verifyCheckoutSession(string $sessionId): Session
    {
        try {
            return Session::retrieve($sessionId);
        } catch (\Throwable $e) {
            throw new \Exception('Invalid Stripe session: '.$e->getMessage());
        }
    }
}
