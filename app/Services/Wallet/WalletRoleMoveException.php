<?php

namespace App\Services\Wallet;

use RuntimeException;

/**
 * Domain failure when moving publisher withdrawable cash to the advertiser wallet.
 */
class WalletRoleMoveException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $userMessage,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($userMessage);
    }

    public static function advertiserRoleRequired(): self
    {
        return new self(
            'advertiser_role_required',
            'You need an advertiser account to move earnings for spending.',
            403
        );
    }

    public static function walletDebt(): self
    {
        return new self(
            'wallet_debt',
            'Outstanding clawback debt must be cleared before you can move earnings.',
            422
        );
    }

    public static function insufficientWithdrawable(): self
    {
        return new self(
            'insufficient_withdrawable',
            'Not enough withdrawable earnings for this amount. Bonus credit cannot be moved.',
            422
        );
    }

    public static function invalidAmount(): self
    {
        return new self(
            'invalid_amount',
            'Enter an amount of at least €0.01.',
            422
        );
    }

    public static function rolesNotConfigured(): self
    {
        return new self(
            'roles_not_configured',
            'Could not move earnings to your advertiser wallet. Please try again.',
            500
        );
    }
}
