<?php

namespace App\Services\Wallet;

use App\Mail\WithdrawalStatusUpdated;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\ActivityLogger;
use App\Services\Billing\WithdrawalPayoutStatementService;
use App\Services\InAppNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Shared admin settlement for withdrawals (panel + email mark-paid confirm).
 * Wallet was already debited on request; mark-paid confirms external payout,
 * reject refunds the gross amount.
 */
class ManualWithdrawalSettlementService
{
    /**
     * @return array{
     *     withdrawal: Withdrawal,
     *     old_status: string,
     *     new_status: string,
     *     unchanged: bool,
     *     message: string,
     *     has_statement?: bool
     * }
     *
     * @throws ManualWithdrawalInvalidTransitionException
     * @throws RuntimeException
     */
    public function transition(
        Withdrawal|int $withdrawal,
        string $newStatus,
        ?User $actor = null,
        ?string $notes = null,
        bool $quiet = false,
    ): array {
        $allowed = ['pending', 'processing', 'completed', 'cancelled'];
        if (! in_array($newStatus, $allowed, true)) {
            throw ManualWithdrawalInvalidTransitionException::messageFor('Invalid withdrawal status.');
        }

        $withdrawalId = $withdrawal instanceof Withdrawal ? (int) $withdrawal->id : (int) $withdrawal;
        if ($withdrawalId <= 0) {
            throw new RuntimeException('Withdrawal not found');
        }

        $result = DB::transaction(function () use ($withdrawalId, $newStatus, $notes) {
            $locked = Withdrawal::query()
                ->with('user')
                ->whereKey($withdrawalId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new RuntimeException('Withdrawal not found');
            }

            $oldStatus = (string) $locked->status;

            if ($oldStatus === $newStatus) {
                return [
                    'withdrawal' => $locked->fresh(['user:id,name,email']),
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'unchanged' => true,
                    'message' => 'Status unchanged',
                ];
            }

            if ($newStatus === 'cancelled') {
                if ($oldStatus === 'completed') {
                    throw ManualWithdrawalInvalidTransitionException::messageFor(
                        'Cannot cancel a completed withdrawal. Funds were already paid out.'
                    );
                }

                if (! in_array($oldStatus, ['pending', 'processing'], true)) {
                    throw ManualWithdrawalInvalidTransitionException::messageFor(
                        'Withdrawal cannot be cancelled from status: '.$oldStatus
                    );
                }

                $this->refundWallet($locked);
            }

            if ($newStatus === 'completed' && ! in_array($oldStatus, ['pending', 'processing'], true)) {
                throw ManualWithdrawalInvalidTransitionException::messageFor(
                    'Only pending or processing withdrawals can be marked paid.'
                );
            }

            if ($newStatus === 'processing' && $oldStatus !== 'pending') {
                throw ManualWithdrawalInvalidTransitionException::messageFor(
                    'Only pending withdrawals can move to processing.'
                );
            }

            if ($newStatus === 'pending' && in_array($oldStatus, ['completed', 'cancelled'], true)) {
                throw ManualWithdrawalInvalidTransitionException::messageFor(
                    'Cannot reopen a '.$oldStatus.' withdrawal to pending.'
                );
            }

            $locked->status = $newStatus;

            if ($notes !== null && $notes !== '') {
                $locked->admin_notes = $notes;
            }

            if ($newStatus === 'cancelled') {
                $locked->cancelled_by = Withdrawal::CANCELLED_BY_ADMIN;
                $locked->cancelled_at = now();
            }

            if ($newStatus === 'completed') {
                $locked->processed_at = now();
            }

            $locked->save();

            return [
                'withdrawal' => $locked->fresh(['user:id,name,email']),
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'unchanged' => false,
                'message' => 'Withdrawal status updated successfully',
            ];
        });

        // Retry even when status is already completed: the first issue() can
        // fail after the wallet row is paid, and issue() is idempotent.
        if ($result['new_status'] === 'completed') {
            $statement = $this->issuePayoutStatement($result['withdrawal']);
            $result['has_statement'] = $statement !== null;
            if ($statement === null) {
                $result['message'] = $result['unchanged']
                    ? 'Status unchanged — payout statement is still missing'
                    : 'Marked paid, but the payout statement could not be created. Open history and choose Create statement.';
            } elseif ($result['unchanged']) {
                $result['message'] = 'Payout statement is ready';
            }
        }

        if (! $result['unchanged']) {
            $this->notifyStatusChange(
                $result['withdrawal'],
                $result['old_status'],
                $result['new_status'],
                $notes
            );
        }

        if (! $quiet && ! $result['unchanged']) {
            $actorName = $actor?->name ?: 'System';
            Log::info('Withdrawal status updated', [
                'withdrawal_id' => $result['withdrawal']->id,
                'old_status' => $result['old_status'],
                'new_status' => $result['new_status'],
                'admin_id' => $actor?->id,
                'notes' => $notes,
            ]);

            ActivityLogger::log(
                'withdrawal.status_updated',
                $actorName.' set withdrawal #'.$result['withdrawal']->id.' to '.$result['new_status'],
                $result['withdrawal'],
                [
                    'from' => $result['old_status'],
                    'to' => $result['new_status'],
                    'amount' => $result['withdrawal']->amount,
                    'actor_id' => $actor?->id,
                ],
                'Withdrawal #'.$result['withdrawal']->id
            );
        }

        return $result;
    }

    public function markProcessing(Withdrawal|int $withdrawal, ?User $actor = null, ?string $notes = null, bool $quiet = false): array
    {
        return $this->transition($withdrawal, 'processing', $actor, $notes, $quiet);
    }

    public function markPaid(Withdrawal|int $withdrawal, ?User $actor = null, ?string $notes = null, bool $quiet = false): array
    {
        return $this->transition($withdrawal, 'completed', $actor, $notes, $quiet);
    }

    public function reject(Withdrawal|int $withdrawal, ?User $actor = null, ?string $notes = null, bool $quiet = false): array
    {
        return $this->transition($withdrawal, 'cancelled', $actor, $notes, $quiet);
    }

    protected function refundWallet(Withdrawal $withdrawal): void
    {
        $wallet = $withdrawal->resolveDebitedWallet(lockForUpdate: true);
        if (! $wallet) {
            throw ManualWithdrawalUnknownWalletException::forWithdrawal((int) $withdrawal->id);
        }

        $wallet->credit((float) $withdrawal->amount);
        try {
            app(WalletLedgerService::class)->recordAdjustment(
                $wallet,
                (float) $withdrawal->amount,
                'credit',
                $withdrawal,
                'WD-'.$withdrawal->id.'-refund',
                'Withdrawal rejected — funds returned to wallet',
                [
                    'withdrawal_id' => $withdrawal->id,
                    'reason' => 'withdrawal_rejected',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to record withdrawal reject ledger credit', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function issuePayoutStatement(Withdrawal $withdrawal): ?Invoice
    {
        try {
            return app(WithdrawalPayoutStatementService::class)->issue($withdrawal->fresh(['user']));
        } catch (\Throwable $e) {
            Log::warning('Failed to issue withdrawal payout statement', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function notifyStatusChange(
        Withdrawal $withdrawal,
        string $oldStatus,
        string $newStatus,
        ?string $notes,
    ): void {
        try {
            $user = $withdrawal->user;
            if ($user?->email && $newStatus !== 'pending') {
                Mail::to($user->email)->send(
                    new WithdrawalStatusUpdated($withdrawal, $oldStatus, $newStatus, $notes)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send withdrawal status update email: '.$e->getMessage());
        }

        try {
            $notifications = app(InAppNotificationService::class);
            if ($newStatus === 'completed') {
                $notifications->notifyWithdrawalPaid($withdrawal);
            } elseif ($newStatus === 'cancelled') {
                $notifications->notifyWithdrawalRejected($withdrawal);
            } elseif ($newStatus === 'processing') {
                $notifications->notifyWithdrawalProcessing($withdrawal);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send withdrawal status bell: '.$e->getMessage());
        }
    }
}
