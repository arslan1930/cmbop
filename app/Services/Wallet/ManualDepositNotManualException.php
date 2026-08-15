<?php

namespace App\Services\Wallet;

use RuntimeException;

/**
 * Thrown when admin tries to credit a Stripe/card deposit from the manual path.
 */
class ManualDepositNotManualException extends RuntimeException
{
    public static function forDeposit(): self
    {
        return new self(
            'Card deposits settle through Stripe. Approving here would credit the wallet twice.'
        );
    }
}
