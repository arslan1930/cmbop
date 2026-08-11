<?php

namespace App\Services\Wallet;

use App\Models\DepositRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Temporary signed URLs for admin email → confirm-before-credit approve flow.
 *
 * HMAC is signed against the path only (absolute: false) so www/apex or
 * APP_URL host drift cannot invalidate the link; the public origin is prefixed
 * the same way as email verification CTAs.
 */
class ManualDepositApproveLink
{
    public static function expireMinutes(): int
    {
        $minutes = (int) config('billing.deposit_approve_link_expire_minutes', 60 * 24 * 7);

        return max(1, $minutes);
    }

    public static function expiresAt(?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addMinutes(self::expireMinutes());
    }

    /**
     * Absolute public URL for the signed confirm page (email CTA).
     */
    public static function url(DepositRequest|int $deposit): string
    {
        $depositId = $deposit instanceof DepositRequest ? (int) $deposit->id : (int) $deposit;

        $relative = URL::temporarySignedRoute(
            'admin.deposits.approve-confirm.show',
            self::expiresAt(),
            ['deposit' => $depositId],
            absolute: false
        );

        return rtrim(app_public_url(), '/').$relative;
    }
}
