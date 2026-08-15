<?php

namespace App\Services\Wallet;

use App\Models\DepositRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\DepositSettlementNotifier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Credits a pending manual deposit (Wise / bank / crypto) into the advertiser
 * wallet. Shared by the admin panel approve action and the signed email
 * approve-confirm flow so both paths stay lock/ledger/notify identical.
 */
class ManualDepositApprovalService
{
    public function __construct(
        private WalletLedgerService $ledger,
        private DepositSettlementNotifier $notifier,
    ) {}

    /**
     * @return array{
     *     deposit: DepositRequest,
     *     email_sent: bool,
     *     message: string
     * }
     *
     * @throws ManualDepositAlreadyProcessedException
     * @throws RuntimeException
     */
    public function approve(
        DepositRequest|int $deposit,
        ?User $actor = null,
        ?string $adminNotes = null,
    ): array {
        $depositId = $deposit instanceof DepositRequest ? (int) $deposit->id : (int) $deposit;

        if ($depositId <= 0) {
            throw new RuntimeException('Deposit request not found');
        }

        $completed = DB::transaction(function () use ($depositId, $adminNotes) {
            $locked = DepositRequest::query()
                ->whereKey($depositId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new RuntimeException('Deposit request not found');
            }

            if ($locked->status !== 'pending') {
                throw ManualDepositAlreadyProcessedException::forDeposit((int) $locked->id);
            }

            if (! $locked->canManuallyApprove()) {
                throw ManualDepositNotManualException::forDeposit();
            }

            $locked->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes,
                'approved_at' => now(),
            ]);

            $advertiserRoleId = Wallet::advertiserRoleId();
            if (! $advertiserRoleId) {
                throw new RuntimeException('Advertiser role not configured');
            }

            $wallet = Wallet::lockOrCreateForRole($locked->user_id, $advertiserRoleId);
            $amount = (float) $locked->amount;
            $wallet->credit($amount);
            $this->ledger->recordDeposit(
                $wallet,
                $amount,
                $locked,
                $locked->payment_method,
                $locked->reference_code
            );

            $locked->update(['status' => 'completed']);

            return $locked->fresh(['user']);
        });

        $notified = $this->notifier->notifyApproved($completed);
        $emailSent = (bool) ($notified['email_sent'] ?? false);

        $actorName = $actor?->name ?: 'System';
        ActivityLogger::log(
            'deposit.approved',
            $actorName.' approved deposit #'.$completed->id.' (€'.number_format((float) $completed->amount, 2).')',
            $completed,
            [
                'amount' => $completed->amount,
                'user_id' => $completed->user_id,
                'actor_id' => $actor?->id,
            ],
            'Deposit #'.$completed->id
        );

        $message = 'Deposit approved and funds added to user wallet.';
        $message .= $emailSent
            ? ' Email notification sent to user.'
            : ' Email could not be sent.';

        return [
            'deposit' => $completed,
            'email_sent' => $emailSent,
            'message' => $message,
        ];
    }
}
