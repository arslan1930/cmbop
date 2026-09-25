<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent wallet credits from a captured PayPal Add Funds order.
 */
class WalletPaypalDepositService
{
    public function __construct(private WalletLedgerService $ledger) {}

    /**
     * Credit from a completed PayPal capture (return URL or webhook).
     *
     * @param  array{id?: string, capture_id?: string, amount?: float, custom?: array{type?: string, user_id?: string, reference_code?: string}, raw?: array<string, mixed>}  $captured
     */
    public function creditFromCapture(array $captured): float
    {
        $custom = is_array($captured['custom'] ?? null) ? $captured['custom'] : [];
        if (($custom['type'] ?? '') !== PaypalCheckoutService::TYPE_WALLET_DEPOSIT) {
            Log::warning('WalletPaypalDepositService: refusing non-wallet capture', [
                'type' => $custom['type'] ?? null,
                'paypal_capture_id' => $captured['capture_id'] ?? null,
            ]);

            return 0.0;
        }

        $userId = (int) ($custom['user_id'] ?? 0);
        $referenceCode = trim((string) ($custom['reference_code'] ?? ''));
        $captureId = trim((string) ($captured['capture_id'] ?? ''));
        $paypalOrderId = trim((string) ($captured['id'] ?? ''));
        $amount = round((float) ($captured['amount'] ?? 0), 2);

        if ($userId <= 0 || $captureId === '' || $amount < 0.01) {
            throw new \RuntimeException('Invalid PayPal wallet deposit capture.');
        }

        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        return $this->withDepositLock($captureId, fn () => $this->creditFromCaptureLocked(
            $userId,
            $captureId,
            $paypalOrderId,
            $amount,
            $referenceCode,
            $captured
        ));
    }

    /**
     * @param  array<string, mixed>  $captured
     */
    private function creditFromCaptureLocked(
        int $userId,
        string $captureId,
        string $paypalOrderId,
        float $amount,
        string $referenceCode,
        array $captured
    ): float {
        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use (
            $userId,
            $captureId,
            $paypalOrderId,
            $amount,
            &$referenceCode,
            $captured,
            &$credited,
            &$notifyDepositId
        ) {
            $existing = DepositRequest::query()
                ->where('paypal_capture_id', $captureId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $credited = (float) $existing->amount;

                return;
            }

            if ($paypalOrderId !== '') {
                $byOrder = DepositRequest::query()
                    ->where('paypal_order_id', $paypalOrderId)
                    ->lockForUpdate()
                    ->first();
                if ($byOrder) {
                    if (! $byOrder->paypal_capture_id) {
                        $byOrder->update(['paypal_capture_id' => $captureId]);
                    }
                    $credited = (float) $byOrder->amount;

                    return;
                }
            }

            $ref = $referenceCode !== ''
                ? $referenceCode
                : str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            if (DepositRequest::where('reference_code', $ref)->exists()) {
                do {
                    $ref = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                } while (DepositRequest::where('reference_code', $ref)->exists());
            }

            try {
                $deposit = DepositRequest::create([
                    'user_id' => $userId,
                    'reference_code' => $ref,
                    'amount' => $amount,
                    'charge_currency' => $captured['currency'] ?? null,
                    'charge_amount' => $captured['charge_amount'] ?? $amount,
                    'payment_method' => 'paypal',
                    'status' => 'completed',
                    'paypal_order_id' => $paypalOrderId !== '' ? $paypalOrderId : null,
                    'paypal_capture_id' => $captureId,
                    'paypal_response' => $captured['raw'] ?? $captured,
                    'approved_at' => now(),
                    'paid_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }
                $existing = DepositRequest::query()->where('paypal_capture_id', $captureId)->first()
                    ?: ($paypalOrderId !== ''
                        ? DepositRequest::query()->where('paypal_order_id', $paypalOrderId)->first()
                        : null);
                $credited = (float) ($existing?->amount ?? 0);

                return;
            }

            $this->creditAdvertiserWallet($userId, (float) $deposit->amount, $deposit);
            $credited = (float) $deposit->amount;
            $notifyDepositId = $deposit->id;

            Log::info('Deposit created from PayPal capture', [
                'deposit_id' => $deposit->id,
                'paypal_capture_id' => $captureId,
            ]);
        });

        $this->notifyDepositCredited($notifyDepositId);

        return $credited;
    }

    /**
     * Refund a credited PayPal Add Funds capture from the admin panel, then
     * reverse the wallet credit. HTTP stays outside the wallet transaction.
     *
     * @return array{already_refunded: bool, deposit: DepositRequest}
     */
    public function refundCapture(DepositRequest $deposit): array
    {
        $deposit->refresh();

        if (strtolower((string) $deposit->payment_method) !== 'paypal') {
            throw new \RuntimeException('Only PayPal deposits can be refunded here.');
        }

        if ($deposit->status === 'refunded') {
            return [
                'already_refunded' => true,
                'deposit' => $deposit,
            ];
        }

        if (! $deposit->isPaypalRefundable()) {
            throw new \RuntimeException(
                filled($deposit->paypal_capture_id)
                    ? 'This PayPal deposit has not been credited yet.'
                    : 'This PayPal deposit has no capture id, so it cannot be refunded here.'
            );
        }

        $paypal = app(PaypalCheckoutService::class);
        if (! $paypal->configured()) {
            throw new \RuntimeException('PayPal is not configured. Refund the capture in the PayPal dashboard.');
        }

        $captureId = trim((string) $deposit->paypal_capture_id);
        $amount = round((float) $deposit->amount, 2);
        $refundCurrency = strtoupper((string) ($deposit->charge_currency ?: 'EUR'));
        $refundAmount = $refundCurrency === 'EUR'
            ? $amount
            : round((float) ($deposit->charge_amount ?: $amount), 2);
        $refunded = $paypal->refundCapture($captureId, $refundAmount, 'deposit-refund-'.$captureId, $refundCurrency);
        $refundId = trim((string) ($refunded['id'] ?? ''));
        if ($refundId === '') {
            throw new \RuntimeException('PayPal refund did not return an id. The wallet was not changed.');
        }

        try {
            $this->reverseFromRefund(
                $captureId,
                $refundId,
                trim((string) $deposit->paypal_order_id),
                $amount
            );
        } catch (\Throwable $e) {
            Log::error('PayPal deposit capture refunded but wallet reverse failed', [
                'deposit_id' => $deposit->id,
                'paypal_capture_id' => $captureId,
                'paypal_refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'PayPal refunded the capture, but the wallet debit failed. Contact support before retrying.',
                0,
                $e
            );
        }

        $fresh = $deposit->fresh(['user']);
        if (! $fresh) {
            throw new \RuntimeException('PayPal refund succeeded but the deposit row could not be reloaded.');
        }

        return [
            'already_refunded' => false,
            'deposit' => $fresh,
        ];
    }

    /**
     * Reverse a completed PayPal Add Funds credit after the capture is refunded.
     * Debits available cash; leftover becomes advertiser wallet debt.
     *
     * @return float Amount actually debited from the wallet
     */
    public function reverseFromRefund(string $captureId, string $refundId, string $paypalOrderId = '', float $amount = 0.0): float
    {
        $captureId = trim($captureId);
        $refundId = trim($refundId);
        $paypalOrderId = trim($paypalOrderId);
        if ($captureId === '' && $paypalOrderId === '') {
            return 0.0;
        }

        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $lockKey = $captureId !== '' ? $captureId : $paypalOrderId;

        return $this->withDepositLock('rf:'.$lockKey, fn () => $this->reverseFromRefundLocked(
            $captureId,
            $refundId,
            $paypalOrderId,
            round($amount, 2)
        ));
    }

    private function reverseFromRefundLocked(
        string $captureId,
        string $refundId,
        string $paypalOrderId,
        float $amount
    ): float {
        $debited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use ($captureId, $refundId, $paypalOrderId, $amount, &$debited, &$notifyDepositId) {
            $deposit = DepositRequest::query()
                ->where(function ($query) use ($captureId, $paypalOrderId) {
                    if ($captureId !== '') {
                        $query->orWhere('paypal_capture_id', $captureId);
                    }
                    if ($paypalOrderId !== '') {
                        $query->orWhere('paypal_order_id', $paypalOrderId);
                    }
                })
                ->lockForUpdate()
                ->first();
            if (! $deposit) {
                return;
            }

            if ($deposit->status === 'refunded') {
                return;
            }

            if (! in_array($deposit->status, ['completed', 'approved'], true)) {
                Log::info('PayPal deposit refund ignored for non-credited row', [
                    'deposit_id' => $deposit->id,
                    'status' => $deposit->status,
                ]);

                return;
            }

            $target = $amount >= 0.01 ? min($amount, round((float) $deposit->amount, 2)) : round((float) $deposit->amount, 2);
            if ($target < 0.01) {
                return;
            }

            $wallet = $this->lockAdvertiserWallet((int) $deposit->user_id);
            $available = round((float) $wallet->balance, 2);
            $take = round(min($available, $target), 2);
            $shortfall = round(max(0, $target - $take), 2);

            if ($take > 0) {
                $wallet->debit($take);
                $this->ledger->recordAdjustment(
                    $wallet,
                    $take,
                    'debit',
                    $deposit,
                    $deposit->reference_code,
                    'PayPal deposit refunded'
                );
                $debited = $take;
            }

            if ($shortfall > 0) {
                $wallet->increaseDebt($shortfall);
            }

            $response = is_array($deposit->paypal_response) ? $deposit->paypal_response : [];
            $response['refund'] = [
                'id' => $refundId,
                'amount' => $target,
                'debited' => $take,
                'debt_created' => $shortfall,
                'reversed_at' => now()->toIso8601String(),
            ];

            $deposit->update(DepositRequest::attributesThatExist([
                'status' => 'refunded',
                'paypal_response' => $response,
                'admin_notes' => trim((string) ($deposit->admin_notes ?? '')) !== ''
                    ? $deposit->admin_notes
                    : 'PayPal capture refunded; wallet credit reversed.',
                'rejected_at' => now(),
            ]));

            $notifyDepositId = $deposit->id;

            Log::info('PayPal wallet deposit reversed after capture refund', [
                'deposit_id' => $deposit->id,
                'paypal_capture_id' => $captureId !== '' ? $captureId : $deposit->paypal_capture_id,
                'paypal_refund_id' => $refundId,
                'debited' => $take,
                'debt_created' => $shortfall,
            ]);
        });

        $this->notifyDepositRefunded($notifyDepositId);

        return $debited;
    }

    private function lockAdvertiserWallet(int $userId): Wallet
    {
        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        return Wallet::lockOrCreateForRole($userId, $advertiserRoleId);
    }

    private function notifyDepositRefunded(?int $depositId): void
    {
        if (! $depositId) {
            return;
        }

        $deposit = DepositRequest::with('user')->find($depositId);
        if (! $deposit) {
            return;
        }

        app(DepositSettlementNotifier::class)->notifyRefunded($deposit);
    }

    private function notifyDepositCredited(?int $depositId): void
    {
        if (! $depositId) {
            return;
        }

        $deposit = DepositRequest::with('user')->find($depositId);
        if (! $deposit) {
            return;
        }

        app(DepositSettlementNotifier::class)->notifyApproved($deposit);
    }

    private function creditAdvertiserWallet(int $userId, float $amount, DepositRequest $deposit): void
    {
        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($userId, $advertiserRoleId);
        $wallet->credit($amount);
        $this->ledger->recordDeposit($wallet, $amount, $deposit, 'paypal', $deposit->reference_code);
    }

    private function withDepositLock(string $captureId, callable $callback): mixed
    {
        $key = 'wallet_deposit_pp:'.$captureId;

        try {
            return Cache::lock($key, 20)->block(15, $callback);
        } catch (\BadMethodCallException) {
            return $callback();
        }
    }

    private function isUniqueConstraintFailure(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = strtolower($e->getMessage());

        return $sqlState === '23000'
            || $sqlState === '23505'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
