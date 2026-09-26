<?php

namespace App\Services\Wallet;

use App\Models\Withdrawal;
use App\Support\EmailCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Temporary signed URLs for admin email → confirm-before-mark-paid flow.
 */
class ManualWithdrawalMarkPaidLink
{
    public static function expireMinutes(): int
    {
        $minutes = (int) config('billing.withdrawal_mark_paid_link_expire_minutes', 60 * 24 * 7);

        return max(1, $minutes);
    }

    public static function expiresAt(?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addMinutes(self::expireMinutes());
    }

    public static function previewUrl(): string
    {
        return rtrim(app_public_url(), '/').'/admin/withdrawals/mark-paid-confirm/preview';
    }

    public static function url(Withdrawal|int $withdrawal): string
    {
        $id = $withdrawal instanceof Withdrawal ? (int) $withdrawal->id : (int) $withdrawal;
        if ($id === EmailCatalog::PREVIEW_ID) {
            return self::previewUrl();
        }

        return rtrim(app_public_url(), '/').self::relativeUrl($withdrawal);
    }

    /**
     * Path-only signed confirm URL for the logged-in admin panel.
     */
    public static function relativeUrl(Withdrawal|int $withdrawal): string
    {
        $id = $withdrawal instanceof Withdrawal ? (int) $withdrawal->id : (int) $withdrawal;
        if ($id === EmailCatalog::PREVIEW_ID) {
            return '/admin/withdrawals/mark-paid-confirm/preview';
        }

        return URL::temporarySignedRoute(
            'admin.withdrawals.mark-paid-confirm.show',
            self::expiresAt(),
            ['withdrawal' => $id],
            absolute: false
        );
    }
}
