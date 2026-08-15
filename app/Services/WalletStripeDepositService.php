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
 * Idempotent wallet credits from Stripe Checkout Sessions / PaymentIntents.
 */
class WalletStripeDepositService
{
    public function __construct(private WalletLedgerService $ledger) {}

    /**
     * Credit wallet from a succeeded PaymentIntent (saved-card or 3DS return).
     */
    public function creditFromPaymentIntent(
        int $userId,
        string $paymentIntentId,
        float $amountEuros,
        string $referenceCode,
        ?int $completeDepositId = null
    ): float {
        if ($paymentIntentId === '') {
            throw new \RuntimeException('Missing PaymentIntent id for wallet deposit');
        }

        return $this->withStripeDepositLock($paymentIntentId, '', fn () => $this->creditFromPaymentIntentLocked(
            $userId,
            $paymentIntentId,
            $amountEuros,
            $referenceCode,
            $completeDepositId
        ));
    }

    private function creditFromPaymentIntentLocked(
        int $userId,
        string $paymentIntentId,
        float $amountEuros,
        string $referenceCode,
        ?int $completeDepositId = null
    ): float {
        if ($completeDepositId && DepositRequest::query()->whereKey($completeDepositId)->exists()) {
            return $this->completeExistingDeposit(
                $completeDepositId,
                '',
                $paymentIntentId,
                (object) ['amount_total' => StripePaymentService::toCents($amountEuros)],
                $userId
            );
        }

        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use ($userId, $paymentIntentId, $amountEuros, &$referenceCode, &$credited, &$notifyDepositId) {
            $existing = DepositRequest::where('stripe_payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $credited = (float) $existing->amount;

                return;
            }

            if (DepositRequest::where('reference_code', $referenceCode)->exists()) {
                do {
                    $referenceCode = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                } while (DepositRequest::where('reference_code', $referenceCode)->exists());
            }

            try {
                $deposit = DepositRequest::create([
                    'user_id' => $userId,
                    'reference_code' => $referenceCode,
                    'amount' => $amountEuros,
                    'payment_method' => 'card',
                    'status' => 'completed',
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'approved_at' => now(),
                    'paid_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }
                $existing = DepositRequest::where('stripe_payment_intent_id', $paymentIntentId)->first();
                $credited = (float) ($existing?->amount ?? 0);

                return;
            }

            $this->creditAdvertiserWallet($userId, (float) $deposit->amount, $deposit);
            $credited = (float) $deposit->amount;
            $notifyDepositId = $deposit->id;
        });

        $this->notifyDepositCredited($notifyDepositId);

        return $credited;
    }

    /**
     * Credit wallet from a paid Checkout Session (webhook / success URL create-after-pay path).
     */
    public function creditFromCheckoutSession(object $session): float
    {
        $metadata = $this->metaArray($session->metadata ?? null);
        $type = isset($metadata['type']) ? (string) $metadata['type'] : null;
        $depositId = $metadata['deposit_id'] ?? null;
        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : null;
        $referenceCode = $metadata['reference_code'] ?? null;
        $metaAmount = isset($metadata['amount']) ? round((float) $metadata['amount'], 2) : null;

        $sessionId = (string) ($session->id ?? '');
        $paymentIntentId = is_string($session->payment_intent ?? null)
            ? $session->payment_intent
            : (string) ($session->payment_intent->id ?? ($session->payment_intent ?? ''));

        $paymentStatus = $session->payment_status ?? null;
        if ($paymentStatus !== 'paid') {
            throw new \RuntimeException('wallet_deposit session not paid: '.($paymentStatus ?? 'missing'));
        }

        // Never wallet-credit order / feature Checkout Sessions that land on Add Funds success.
        if (! $this->isWalletDepositType($type, $depositId)) {
            Log::warning('WalletStripeDepositService: refusing non-wallet Checkout Session', [
                'session_id' => $sessionId,
                'type' => $type,
            ]);

            return 0.0;
        }

        if ($depositId) {
            return $this->withStripeDepositLock(
                $paymentIntentId,
                $sessionId,
                fn () => $this->completeExistingDeposit(
                    (int) $depositId,
                    $sessionId,
                    $paymentIntentId,
                    $session,
                    $userId
                )
            );
        }

        if (! $userId || $sessionId === '') {
            Log::warning('WalletStripeDepositService: missing user_id or session id', [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return 0.0;
        }

        $stripeAmount = isset($session->amount_total)
            ? StripePaymentService::fromCents((int) $session->amount_total)
            : null;
        $finalAmount = $stripeAmount !== null ? $stripeAmount : ($metaAmount ?? 0.0);
        if ($finalAmount <= 0) {
            throw new \RuntimeException('Invalid deposit amount from Stripe session');
        }

        return $this->withStripeDepositLock(
            $paymentIntentId,
            $sessionId,
            fn () => $this->creditFromCheckoutSessionLocked(
                $session,
                $sessionId,
                $paymentIntentId,
                (int) $userId,
                $finalAmount,
                $referenceCode
            )
        );
    }

    private function creditFromCheckoutSessionLocked(
        object $session,
        string $sessionId,
        string $paymentIntentId,
        int $userId,
        float $finalAmount,
        mixed $referenceCode
    ): float {
        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use ($userId, $session, $sessionId, $paymentIntentId, $finalAmount, $referenceCode, &$credited, &$notifyDepositId) {
            $existing = DepositRequest::where('stripe_session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $credited = (float) $existing->amount;

                return;
            }

            if ($paymentIntentId !== '') {
                $byPi = DepositRequest::where('stripe_payment_intent_id', $paymentIntentId)
                    ->lockForUpdate()
                    ->first();
                if ($byPi) {
                    if (! $byPi->stripe_session_id) {
                        $byPi->update(['stripe_session_id' => $sessionId]);
                    }
                    $credited = (float) $byPi->amount;

                    return;
                }
            }

            $ref = $referenceCode ?: str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            if (DepositRequest::where('reference_code', $ref)->exists()) {
                do {
                    $ref = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                } while (DepositRequest::where('reference_code', $ref)->exists());
            }

            try {
                $deposit = DepositRequest::create([
                    'user_id' => $userId,
                    'reference_code' => $ref,
                    'amount' => $finalAmount,
                    'payment_method' => 'card',
                    'status' => 'completed',
                    'stripe_session_id' => $sessionId,
                    'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
                    'stripe_response' => $this->encodeStripeObject($session),
                    'approved_at' => now(),
                    'paid_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }
                $existing = DepositRequest::where('stripe_session_id', $sessionId)->first()
                    ?: ($paymentIntentId !== ''
                        ? DepositRequest::where('stripe_payment_intent_id', $paymentIntentId)->first()
                        : null);
                $credited = (float) ($existing?->amount ?? 0);

                return;
            }

            $this->creditAdvertiserWallet($userId, (float) $finalAmount, $deposit);
            $credited = (float) $finalAmount;
            $notifyDepositId = $deposit->id;

            Log::info('Deposit created from Stripe session', [
                'deposit_id' => $deposit->id,
                'session_id' => $sessionId,
            ]);
        });

        $this->notifyDepositCredited($notifyDepositId);

        return $credited;
    }

    /**
     * Credit from a PaymentIntent object (webhook path).
     */
    public function creditFromPaymentIntentObject(object $intent): float
    {
        $intentStatus = $intent->status ?? null;
        if ($intentStatus !== 'succeeded') {
            throw new \RuntimeException('wallet_deposit PaymentIntent not succeeded: '.($intentStatus ?? 'missing'));
        }

        $metadata = $this->metaArray($intent->metadata ?? null);
        $type = isset($metadata['type']) ? (string) $metadata['type'] : null;
        if (! $this->isWalletDepositType($type, $metadata['deposit_id'] ?? null)) {
            Log::warning('WalletStripeDepositService: refusing non-wallet PaymentIntent', [
                'payment_intent_id' => $intent->id ?? null,
                'type' => $type,
            ]);

            return 0.0;
        }

        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : 0;
        $referenceCode = (string) ($metadata['reference_code'] ?? str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT));
        $completeDepositId = isset($metadata['deposit_id']) && $metadata['deposit_id'] !== ''
            ? (int) $metadata['deposit_id']
            : null;

        $amountFromStripe = isset($intent->amount_received) && (int) $intent->amount_received > 0
            ? StripePaymentService::fromCents((int) $intent->amount_received)
            : (isset($intent->amount) ? StripePaymentService::fromCents((int) $intent->amount) : null);
        $metaAmount = isset($metadata['amount']) ? round((float) $metadata['amount'], 2) : null;
        $amount = $amountFromStripe !== null ? $amountFromStripe : ($metaAmount ?? 0.0);

        if ($userId <= 0 || $amount <= 0) {
            throw new \RuntimeException('Invalid wallet_deposit PaymentIntent metadata/amount');
        }

        return $this->creditFromPaymentIntent(
            $userId,
            (string) $intent->id,
            $amount,
            $referenceCode,
            $completeDepositId
        );
    }

    protected function completeExistingDeposit(
        int $depositId,
        string $sessionId,
        string $paymentIntentId,
        object $session,
        ?int $expectedUserId = null
    ): float {
        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use ($depositId, $sessionId, $paymentIntentId, $session, $expectedUserId, &$credited, &$notifyDepositId) {
            $lockedDeposit = DepositRequest::where('id', $depositId)->lockForUpdate()->first();
            if (! $lockedDeposit) {
                throw new \RuntimeException('Deposit not found: '.$depositId);
            }

            $sessionUserId = $expectedUserId;
            if (! $sessionUserId) {
                $meta = $this->metaArray($session->metadata ?? null);
                $sessionUserId = isset($meta['user_id']) ? (int) $meta['user_id'] : null;
            }
            if ($sessionUserId && (int) $sessionUserId !== (int) $lockedDeposit->user_id) {
                Log::warning('WalletStripeDepositService: refusing deposit owned by another user', [
                    'deposit_id' => $lockedDeposit->id,
                    'deposit_user_id' => $lockedDeposit->user_id,
                    'session_user_id' => $sessionUserId,
                    'session_id' => $sessionId,
                ]);

                return;
            }

            if ($lockedDeposit->status === 'completed') {
                $credited = (float) $lockedDeposit->amount;

                return;
            }

            // Bank / Wise / crypto invoices are credited by admin, not by a
            // Stripe deposit_id. Attaching a PaymentIntent here would un-reject
            // or settle a manual row the advertiser never paid by card.
            if ($lockedDeposit->isManualPayment() && ! $lockedDeposit->hasStripeSettlement()) {
                Log::warning('WalletStripeDepositService: refusing to complete a manual deposit from Stripe', [
                    'deposit_id' => $lockedDeposit->id,
                    'status' => $lockedDeposit->status,
                    'payment_method' => $lockedDeposit->payment_method,
                    'session_id' => $sessionId,
                ]);

                return;
            }

            if ($paymentIntentId !== '') {
                $already = DepositRequest::query()
                    ->where('stripe_payment_intent_id', $paymentIntentId)
                    ->where('id', '!=', $lockedDeposit->id)
                    ->lockForUpdate()
                    ->first();
                if ($already) {
                    $lockedDeposit->update([
                        'status' => 'completed',
                        'approved_at' => $lockedDeposit->approved_at ?? now(),
                        'paid_at' => $lockedDeposit->paid_at ?? now(),
                        'admin_notes' => trim(implode("\n", array_filter([
                            $lockedDeposit->admin_notes,
                            'Settled via Stripe PaymentIntent on deposit #'.$already->id,
                        ]))),
                    ]);
                    $credited = (float) $already->amount;

                    return;
                }
            }

            $stripeAmount = isset($session->amount_total)
                ? StripePaymentService::fromCents((int) $session->amount_total)
                : null;
            $requested = round((float) $lockedDeposit->amount, 2);
            $creditAmount = $stripeAmount !== null ? $stripeAmount : $requested;
            if ($creditAmount <= 0) {
                throw new \RuntimeException('Invalid deposit amount from Stripe session');
            }
            if ($stripeAmount !== null && abs($stripeAmount - $requested) > 0.01) {
                Log::warning('WalletStripeDepositService: completing deposit at Stripe amount, not request amount', [
                    'deposit_id' => $lockedDeposit->id,
                    'requested' => $requested,
                    'stripe_amount' => $stripeAmount,
                ]);
            }

            $lockedDeposit->update([
                'amount' => $creditAmount,
                'stripe_session_id' => $sessionId !== '' ? $sessionId : $lockedDeposit->stripe_session_id,
                'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : $lockedDeposit->stripe_payment_intent_id,
                'stripe_response' => $this->encodeStripeObject($session),
                'status' => 'completed',
                'approved_at' => now(),
                'paid_at' => now(),
            ]);

            $this->creditAdvertiserWallet(
                (int) $lockedDeposit->user_id,
                $creditAmount,
                $lockedDeposit
            );
            $credited = $creditAmount;
            $notifyDepositId = $lockedDeposit->id;
        });

        $this->notifyDepositCredited($notifyDepositId);

        return $credited;
    }

    protected function notifyDepositCredited(?int $depositId): void
    {
        if (! $depositId) {
            return;
        }

        $deposit = DepositRequest::with('user')->find($depositId);
        if (! $deposit) {
            return;
        }

        // Same email + bell path as admin bank/Wise approval (DepositSettlementNotifier).
        app(DepositSettlementNotifier::class)->notifyApproved($deposit);
    }

    protected function creditAdvertiserWallet(int $userId, float $amount, DepositRequest $deposit): void
    {
        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($userId, $advertiserRoleId);
        $wallet->credit($amount);
        $this->ledger->recordDeposit($wallet, $amount, $deposit, 'card', $deposit->reference_code);
    }

    /**
     * @return array<string, mixed>
     */
    protected function metaArray(mixed $metadata): array
    {
        if ($metadata === null) {
            return [];
        }
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_object($metadata) && method_exists($metadata, 'toArray')) {
            return $metadata->toArray();
        }

        return (array) json_decode(json_encode($metadata), true);
    }

    /**
     * Wallet top-ups only — order_payment / site_feature sessions must never credit the wallet.
     */
    protected function isWalletDepositType(?string $type, mixed $depositId = null): bool
    {
        if ($depositId !== null && $depositId !== '') {
            // Completing an existing DepositRequest row is always a wallet path.
            return $type === null || in_array($type, ['wallet_deposit', 'deposit'], true);
        }

        return in_array((string) $type, ['wallet_deposit', 'deposit'], true);
    }

    protected function encodeStripeObject(object $obj): string
    {
        if (method_exists($obj, 'toArray')) {
            return json_encode($obj->toArray());
        }

        return json_encode($obj);
    }

    private function withStripeDepositLock(string $paymentIntentId, string $sessionId, callable $callback): mixed
    {
        $key = $paymentIntentId !== ''
            ? 'wallet_deposit_pi:'.$paymentIntentId
            : 'wallet_deposit_cs:'.$sessionId;

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
