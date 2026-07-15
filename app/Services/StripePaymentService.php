<?php

namespace App\Services;

use Stripe\Checkout\Session;
use Stripe\Stripe;
use Illuminate\Http\Request;

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
     * Create a checkout session for orders
     */
    public function createOrderCheckoutSession($orderData, $referenceCode, $userId)
    {
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => 'Order Package - ' . $orderData['item_count'] . ' item(s)',
                        'description' => 'Order reference: ' . $referenceCode,
                    ],
                    'unit_amount' => self::toCents($orderData['total_amount']),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('checkout.stripe.success', [
                'session_id' => '{CHECKOUT_SESSION_ID}', 
                'ref' => $referenceCode
            ]),
            'cancel_url' => route('checkout') . '?canceled=true',
            'metadata' => [
                'reference_code' => $referenceCode,
                'user_id' => $userId,
                'type' => 'order',
                'item_count' => $orderData['item_count']
            ],
        ]);

        return $session;
    }

    /**
     * Create a checkout session for wallet funding
     */
    public function createWalletCheckoutSession($amount, $userId, $walletId)
    {
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => 'Wallet Deposit',
                        'description' => 'Add funds to your wallet',
                    ],
                    'unit_amount' => self::toCents($amount),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('wallet.deposit.success', [
                'session_id' => '{CHECKOUT_SESSION_ID}',
                'wallet_id' => $walletId
            ]),
            'cancel_url' => route('wallet.deposit.cancel'),
            'metadata' => [
                'user_id' => $userId,
                'type' => 'wallet_deposit',
                'wallet_id' => $walletId
            ],
        ]);

        return $session;
    }

    /**
     * Verify and retrieve a checkout session
     */
    public function verifyCheckoutSession($sessionId)
    {
        try {
            $session = Session::retrieve($sessionId);
            return $session;
        } catch (\Exception $e) {
            throw new \Exception('Invalid Stripe session: ' . $e->getMessage());
        }
    }
}