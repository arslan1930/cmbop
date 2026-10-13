<?php

namespace App\Services;

use App\Mail\DepositApproved;
use App\Mail\DepositRefunded;
use App\Models\DepositRequest;
use App\Services\Billing\DepositReceiptService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared post-settlement fan-out for wallet top-ups (admin bank/Wise approve,
 * Stripe card credit, and PayPal Add Funds). Keeps email + bell parity across
 * instant and invoice money paths.
 */
class DepositSettlementNotifier
{
    /**
     * @return array{email_sent: bool}
     */
    public function notifyApproved(
        DepositRequest $deposit,
        ?float $receivedCharge = null,
        ?float $creditedEuros = null,
    ): array {
        $deposit->loadMissing('user');

        $emailSent = $this->sendApprovedEmail($deposit, $receivedCharge, $creditedEuros);
        $this->sendApprovedBell($deposit);

        return ['email_sent' => $emailSent];
    }

    protected function sendApprovedEmail(
        DepositRequest $deposit,
        ?float $receivedCharge = null,
        ?float $creditedEuros = null,
    ): bool {
        try {
            $user = $deposit->user;
            if (! $user?->email) {
                Log::warning('Cannot send deposit approved email — user has no email', [
                    'deposit_id' => $deposit->id,
                    'user_id' => $deposit->user_id,
                ]);

                return false;
            }

            Mail::to($user->email)->send(new DepositApproved($deposit, $receivedCharge, $creditedEuros));

            // Bump receipt email counters so admin resend / audit stay accurate.
            try {
                $receipt = app(DepositReceiptService::class)->find($deposit);
                if ($receipt) {
                    $receipt->update([
                        'emailed_at' => now(),
                        'email_count' => ((int) $receipt->email_count) + 1,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to bump deposit receipt emailed_at: '.$e->getMessage(), [
                    'deposit_id' => $deposit->id,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send deposit approved email: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);

            return false;
        }
    }

    protected function sendApprovedBell(DepositRequest $deposit): void
    {
        try {
            app(InAppNotificationService::class)->notifyDepositApproved($deposit);
        } catch (\Throwable $e) {
            Log::warning('Failed to send deposit approved bell: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);
        }
    }

    /**
     * @return array{email_sent: bool}
     */
    public function notifyRefunded(DepositRequest $deposit): array
    {
        $deposit->loadMissing('user');

        $emailSent = $this->sendRefundedEmail($deposit);
        $this->sendRefundedBells($deposit);

        return ['email_sent' => $emailSent];
    }

    protected function sendRefundedEmail(DepositRequest $deposit): bool
    {
        try {
            $user = $deposit->user;
            if (! $user?->email) {
                Log::warning('Cannot send deposit refunded email — user has no email', [
                    'deposit_id' => $deposit->id,
                    'user_id' => $deposit->user_id,
                ]);

                return false;
            }

            Mail::to($user->email)->send(new DepositRefunded($deposit));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send deposit refunded email: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);

            return false;
        }
    }

    protected function sendRefundedBells(DepositRequest $deposit): void
    {
        $notifications = app(InAppNotificationService::class);

        try {
            $notifications->notifyDepositRefunded($deposit);
        } catch (\Throwable $e) {
            Log::warning('Failed to send deposit refunded bell: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);
        }

        try {
            $notifications->notifyAdminsDepositRefunded($deposit);
        } catch (\Throwable $e) {
            Log::warning('Failed to send admin deposit refunded bell: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);
        }
    }
}
