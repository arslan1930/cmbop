<?php

namespace App\Support;

use App\Services\StripePaymentService;

/**
 * What the platform actually charges for a euro ledger amount.
 * The wallet and orders stay in euros. Stripe and PayPal take this currency and amount.
 */
class PlatformCharge
{
    /**
     * @return array{code: string, stripe: string, amount: float, euros: float}
     */
    public function quote(float $euros): array
    {
        $euros = round($euros, 2);
        $code = strtoupper(app(ViewerCountry::class)->displayCurrency());
        if (! in_array($code, ['EUR', 'USD', 'GBP'], true)) {
            $code = 'EUR';
        }

        $amount = $code === 'EUR'
            ? $euros
            : round(app(MoneyDisplay::class)->fromEuros($euros, $code), 2);

        if ($amount <= 0 && $euros > 0) {
            $code = 'EUR';
            $amount = $euros;
        }

        return [
            'code' => $code,
            'stripe' => strtolower($code),
            'amount' => $amount,
            'euros' => $euros,
        ];
    }

    /**
     * Euro ledger amount from a Stripe session. Card cents are the charged currency, not euros.
     */
    public function eurosFromStripe(object $session, array $metadata = []): ?float
    {
        $currency = strtolower((string) ($session->currency ?? ($metadata['charge_currency'] ?? 'eur')));
        if ($currency !== '' && $currency !== 'eur') {
            $euros = $metadata['eur_amount'] ?? $metadata['expected_amount'] ?? $metadata['amount'] ?? null;
            if ($euros !== null && $euros !== '') {
                return round((float) $euros, 2);
            }

            return null;
        }

        $cents = $session->amount_total ?? $session->amount_received ?? $session->amount ?? null;
        if ($cents === null) {
            return null;
        }

        return StripePaymentService::fromCents((int) $cents);
    }
}
