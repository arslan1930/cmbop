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
    /** @var array<int, true> */
    private array $completingDepositIds = [];

    public function __construct(private WalletLedgerService $ledger) {}

    /**
     * Credit wallet from a succeeded PaymentIntent (saved-card or 3DS return).
     */
    public function creditFromPaymentIntent(
        int $userId,
        string $paymentIntentId,
        float $amountEuros,
        string $referenceCode,
        ?int $completeDepositId = null,
        bool $allowNewCardIfUnsettled = true,
        string $sessionReference = ''
    ): float {
        if ($paymentIntentId === '') {
            throw new \RuntimeException('Missing PaymentIntent id for wallet deposit');
        }

        if ($amountEuros <= 0) {
            throw new \RuntimeException('Invalid deposit amount from PaymentIntent');
        }

        return $this->withStripeDepositLock(
            $paymentIntentId,
            '',
            fn () => $this->creditFromPaymentIntentLocked(
                $userId,
                $paymentIntentId,
                $amountEuros,
                $referenceCode,
                $completeDepositId,
                $allowNewCardIfUnsettled,
                $sessionReference
            ),
            $sessionReference
        );
    }

    private function creditFromPaymentIntentLocked(
        int $userId,
        string $paymentIntentId,
        float $amountEuros,
        string $referenceCode,
        ?int $completeDepositId = null,
        bool $allowNewCardIfUnsettled = true,
        string $sessionReference = ''
    ): float {
        $session = $this->paymentIntentAmountSession($userId, $amountEuros, $sessionReference);

        if ($completeDepositId) {
            $credited = $this->completeExistingDeposit(
                $completeDepositId,
                '',
                $paymentIntentId,
                $session,
                $userId
            );
            if ($credited > 0) {
                return $credited;
            }

            if (! $allowNewCardIfUnsettled) {
                Log::warning('WalletStripeDepositService: deposit_id did not settle; not creating a card row', [
                    'payment_intent_id' => $paymentIntentId,
                    'deposit_id' => $completeDepositId,
                    'user_id' => $userId,
                ]);

                return 0.0;
            }

            // Explicit wallet top-up whose named row was a manual invoice,
            // missing, or belonged to someone else. Credit a new card row
            // so the charge is not swallowed.
            Log::info('WalletStripeDepositService: deposit_id did not settle; crediting a new card row', [
                'payment_intent_id' => $paymentIntentId,
                'deposit_id' => $completeDepositId,
                'user_id' => $userId,
            ]);
        }

        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use ($userId, $paymentIntentId, $amountEuros, $session, $sessionReference, &$referenceCode, &$credited, &$notifyDepositId) {
            $existing = DepositRequest::where('stripe_payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();
            $resolved = $this->resolveExistingStripeRow($existing, '', $paymentIntentId, $session, $userId);
            if ($resolved !== null) {
                $credited = $resolved;

                return;
            }

            $orphaned = $this->findCompletedCardForLatePaymentIntent($userId, $amountEuros, $sessionReference);
            if ($orphaned) {
                $this->attachMissingStripeIds($orphaned, '', $paymentIntentId);
                $credited = (float) $orphaned->amount;

                return;
            }

            $deposit = $this->createCompletedCardRow(
                $userId,
                $amountEuros,
                $referenceCode,
                '',
                $paymentIntentId,
                $this->encodeStripeObject($session),
                $session,
                $credited
            );
            if (! $deposit) {
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
        $type = $type === '' ? null : $type;
        $depositId = $metadata['deposit_id'] ?? null;
        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : null;
        $referenceCode = $metadata['reference_code'] ?? null;
        $metaAmount = isset($metadata['amount']) ? round((float) $metadata['amount'], 2) : null;

        $sessionId = (string) ($session->id ?? '');
        $sessionReference = trim((string) ($metadata['session_reference'] ?? ''));
        $paymentIntentId = is_string($session->payment_intent ?? null)
            ? $session->payment_intent
            : (string) ($session->payment_intent->id ?? ($session->payment_intent ?? ''));

        $paymentStatus = $session->payment_status ?? null;
        if ($paymentStatus !== 'paid') {
            throw new \RuntimeException('wallet_deposit session not paid: '.($paymentStatus ?? 'missing'));
        }

        // Never wallet-credit order / feature Checkout Sessions that land on Add Funds success.
        if (! $this->isWalletDepositType($type, $depositId, $sessionReference)) {
            Log::warning('WalletStripeDepositService: refusing non-wallet Checkout Session', [
                'session_id' => $sessionId,
                'type' => $type,
            ]);

            return 0.0;
        }

        $stripeAmount = isset($session->amount_total)
            ? StripePaymentService::fromCents((int) $session->amount_total)
            : null;
        $finalAmount = $stripeAmount !== null ? $stripeAmount : ($metaAmount ?? 0.0);

        return $this->withStripeDepositLock(
            $paymentIntentId,
            $sessionId,
            function () use ($depositId, $sessionId, $paymentIntentId, $session, $userId, $finalAmount, $referenceCode, $type, $sessionReference) {
                if ($depositId) {
                    $credited = $this->completeExistingDeposit(
                        (int) $depositId,
                        $sessionId,
                        $paymentIntentId,
                        $session,
                        $userId
                    );
                    if ($credited > 0) {
                        return $credited;
                    }

                    if (! $this->mayCreateFallbackCardRow($type, $sessionReference)) {
                        Log::warning('WalletStripeDepositService: deposit_id did not settle and session is not an explicit wallet top-up', [
                            'session_id' => $sessionId,
                            'deposit_id' => $depositId,
                            'type' => $type,
                        ]);

                        return 0.0;
                    }

                    Log::info('WalletStripeDepositService: deposit_id did not settle; crediting a new card row', [
                        'session_id' => $sessionId,
                        'deposit_id' => $depositId,
                        'user_id' => $userId,
                    ]);
                }

                if (! $userId || $sessionId === '') {
                    Log::warning('WalletStripeDepositService: missing user_id or session id', [
                        'session_id' => $sessionId,
                        'user_id' => $userId,
                    ]);

                    return 0.0;
                }

                if ($finalAmount <= 0) {
                    throw new \RuntimeException('Invalid deposit amount from Stripe session');
                }

                return $this->creditFromCheckoutSessionLocked(
                    $session,
                    $sessionId,
                    $paymentIntentId,
                    (int) $userId,
                    $finalAmount,
                    $referenceCode,
                    $sessionReference
                );
            },
            $sessionReference
        );
    }

    private function creditFromCheckoutSessionLocked(
        object $session,
        string $sessionId,
        string $paymentIntentId,
        int $userId,
        float $finalAmount,
        mixed $referenceCode,
        string $sessionReference = ''
    ): float {
        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use ($userId, $session, $sessionId, $paymentIntentId, $finalAmount, $referenceCode, $sessionReference, &$credited, &$notifyDepositId) {
            $existing = DepositRequest::where('stripe_session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            $resolved = $this->resolveExistingStripeRow($existing, $sessionId, $paymentIntentId, $session, $userId);
            if ($resolved !== null) {
                $credited = $resolved;

                return;
            }

            if ($paymentIntentId !== '') {
                $byPi = DepositRequest::where('stripe_payment_intent_id', $paymentIntentId)
                    ->lockForUpdate()
                    ->first();
                $resolved = $this->resolveExistingStripeRow($byPi, $sessionId, $paymentIntentId, $session, $userId);
                if ($resolved !== null) {
                    if ($resolved > 0) {
                        $byPi->refresh();
                        $this->attachMissingStripeIds($byPi, $sessionId, $paymentIntentId);
                    }
                    $credited = $resolved;

                    return;
                }
            }

            // payment_intent.succeeded often lands first. The later
            // checkout.session.completed can still have an empty
            // payment_intent — attach that session instead of minting
            // a second card for the same session_reference.
            $orphaned = $this->findCompletedCardForLateCheckoutSession($userId, $finalAmount, $sessionReference);
            if ($orphaned) {
                $this->attachMissingStripeIds($orphaned, $sessionId, $paymentIntentId);
                $credited = (float) $orphaned->amount;

                return;
            }

            $ref = $referenceCode ?: str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $deposit = $this->createCompletedCardRow(
                $userId,
                $finalAmount,
                $ref,
                $sessionId,
                $paymentIntentId,
                $this->encodeStripeObject($session),
                $session,
                $credited
            );
            if (! $deposit) {
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
        $type = $type === '' ? null : $type;
        $sessionReference = trim((string) ($metadata['session_reference'] ?? ''));
        if (! $this->isWalletDepositType($type, $metadata['deposit_id'] ?? null, $sessionReference)) {
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
            $completeDepositId,
            $this->mayCreateFallbackCardRow($type, $sessionReference),
            $sessionReference
        );
    }

    protected function completeExistingDeposit(
        int $depositId,
        string $sessionId,
        string $paymentIntentId,
        object $session,
        ?int $expectedUserId = null
    ): float {
        if (isset($this->completingDepositIds[$depositId])) {
            return 0.0;
        }

        $this->completingDepositIds[$depositId] = true;
        try {
            return $this->completeExistingDepositOnce(
                $depositId,
                $sessionId,
                $paymentIntentId,
                $session,
                $expectedUserId
            );
        } finally {
            unset($this->completingDepositIds[$depositId]);
        }
    }

    private function completeExistingDepositOnce(
        int $depositId,
        string $sessionId,
        string $paymentIntentId,
        object $session,
        ?int $expectedUserId
    ): float {
        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use ($depositId, $sessionId, $paymentIntentId, $session, $expectedUserId, &$credited, &$notifyDepositId) {
            $lockedDeposit = DepositRequest::where('id', $depositId)->lockForUpdate()->first();
            if (! $lockedDeposit) {
                Log::warning('WalletStripeDepositService: deposit_id not found', [
                    'deposit_id' => $depositId,
                    'session_id' => $sessionId,
                ]);

                return;
            }

            $sessionUserId = $expectedUserId;
            if (! $sessionUserId) {
                $meta = $this->metaArray($session->metadata ?? null);
                $sessionUserId = isset($meta['user_id']) ? (int) $meta['user_id'] : null;
            }
            if (! $sessionUserId) {
                Log::warning('WalletStripeDepositService: refusing to complete deposit_id without user_id', [
                    'deposit_id' => $lockedDeposit->id,
                    'session_id' => $sessionId,
                ]);

                return;
            }
            if ((int) $sessionUserId !== (int) $lockedDeposit->user_id) {
                // Leftover Stripe ids on someone else's unpaid row — or a
                // completed bank/Wise/crypto invoice — must not block the
                // payer from getting their own card credit. Never detach
                // ids from a completed card settlement.
                if ($lockedDeposit->status !== 'completed' || $lockedDeposit->isManualPayment()) {
                    $this->detachLeftoverStripeIds($lockedDeposit, $sessionId, $paymentIntentId);
                }
                Log::warning('WalletStripeDepositService: refusing deposit owned by another user', [
                    'deposit_id' => $lockedDeposit->id,
                    'deposit_user_id' => $lockedDeposit->user_id,
                    'session_user_id' => $sessionUserId,
                    'session_id' => $sessionId,
                ]);

                return;
            }

            if ($lockedDeposit->status === 'completed') {
                // Admin-approved bank/Wise/crypto is not a Stripe card
                // settlement. Leftover ids must not swallow a real charge.
                if ($lockedDeposit->isManualPayment()) {
                    $this->detachLeftoverStripeIds($lockedDeposit, $sessionId, $paymentIntentId);
                    Log::info('WalletStripeDepositService: completed manual deposit_id is not a Stripe settlement', [
                        'deposit_id' => $lockedDeposit->id,
                        'session_id' => $sessionId,
                        'payment_intent_id' => $paymentIntentId,
                    ]);

                    return;
                }

                // Only treat this as the same Stripe charge when the ids match.
                // A different PaymentIntent must not swallow a new card payment.
                if ($this->depositMatchesStripePayment($lockedDeposit, $sessionId, $paymentIntentId)) {
                    $this->attachMissingStripeIds($lockedDeposit, $sessionId, $paymentIntentId);
                    $credited = (float) $lockedDeposit->amount;

                    return;
                }

                // Session webhook can complete this card before Stripe has a
                // PaymentIntent. A later PI webhook names the same deposit_id
                // with no session id — that is the same charge, not a new one.
                if ($this->isLatePaymentIntentForSessionOnlyCard(
                    $lockedDeposit,
                    $sessionId,
                    $paymentIntentId,
                    $session
                )) {
                    $this->attachMissingStripeIds($lockedDeposit, $sessionId, $paymentIntentId);
                    $credited = (float) $lockedDeposit->amount;

                    return;
                }

                Log::info('WalletStripeDepositService: completed deposit_id is a different settlement', [
                    'deposit_id' => $lockedDeposit->id,
                    'session_id' => $sessionId,
                    'payment_intent_id' => $paymentIntentId,
                ]);

                return;
            }

            // Bank / Wise / crypto invoices are credited by admin, not by a
            // Stripe deposit_id — even when a leftover session / PaymentIntent
            // id is sitting on the row. Attaching this charge would un-reject
            // or settle a manual invoice the advertiser never paid by card.
            if ($lockedDeposit->isManualPayment()) {
                $this->detachLeftoverStripeIds($lockedDeposit, $sessionId, $paymentIntentId);
                Log::warning('WalletStripeDepositService: refusing to complete a manual deposit from Stripe', [
                    'deposit_id' => $lockedDeposit->id,
                    'status' => $lockedDeposit->status,
                    'payment_method' => $lockedDeposit->payment_method,
                    'session_id' => $sessionId,
                ]);

                return;
            }

            if (! in_array($lockedDeposit->status, ['pending', 'rejected'], true)) {
                Log::info('WalletStripeDepositService: refusing to settle deposit in status '.$lockedDeposit->status, [
                    'deposit_id' => $lockedDeposit->id,
                    'session_id' => $sessionId,
                ]);

                return;
            }

            $already = $this->findOtherRowWithStripeIds(
                (int) $lockedDeposit->id,
                $sessionId,
                $paymentIntentId
            );
            if ($already) {
                $alreadyCredited = $this->creditIfAlreadySettledByStripeRow(
                    $already,
                    $sessionId,
                    $paymentIntentId
                );
                if ($alreadyCredited !== null) {
                    $ownerId = $expectedUserId ?? $sessionUserId;
                    if ($ownerId && (int) $already->user_id !== (int) $ownerId) {
                        $credited = 0.0;

                        return;
                    }
                    if ($alreadyCredited > 0) {
                        $this->attachMissingStripeIds($already, $sessionId, $paymentIntentId);
                    }
                    // Completed card already holds this charge. Do not mark
                    // this invoice completed — and do not recurse into
                    // completeExistingDeposit on the other row.
                    $credited = $alreadyCredited;

                    return;
                }

                // Unpaid card or manual leftover: free the ids so this row
                // can take the charge. Settling the other pending card here
                // recursed when session and PI were split across two rows.
                $this->detachLeftoverStripeIds($already, $sessionId, $paymentIntentId);
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

            $settle = [
                'amount' => $creditAmount,
                'stripe_session_id' => $sessionId !== '' ? $sessionId : $lockedDeposit->stripe_session_id,
                'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : $lockedDeposit->stripe_payment_intent_id,
                'stripe_response' => $this->encodeStripeObject($session),
                'status' => 'completed',
                'approved_at' => now(),
                'paid_at' => now(),
            ];

            try {
                $lockedDeposit->update($settle);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }

                $conflict = $this->findOtherRowWithStripeIds(
                    (int) $lockedDeposit->id,
                    $sessionId,
                    $paymentIntentId
                );
                $resolved = $conflict
                    ? $this->creditIfAlreadySettledByStripeRow(
                        $conflict,
                        $sessionId,
                        $paymentIntentId
                    )
                    : null;
                if ($resolved !== null) {
                    $ownerId = $expectedUserId ?? $sessionUserId;
                    if ($ownerId && $conflict && (int) $conflict->user_id !== (int) $ownerId) {
                        $credited = 0.0;

                        return;
                    }
                    if ($resolved > 0 && $conflict) {
                        $this->attachMissingStripeIds($conflict, $sessionId, $paymentIntentId);
                    }
                    $credited = $resolved;

                    return;
                }
                if ($conflict) {
                    $this->detachLeftoverStripeIds($conflict, $sessionId, $paymentIntentId);
                }

                // Leftover ids were detached from a manual / unpaid row — retry.
                $lockedDeposit->update($settle);
            }

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

    /**
     * @param-out float $credited
     */
    private function createCompletedCardRow(
        int $userId,
        float $amountEuros,
        string $referenceCode,
        string $sessionId,
        string $paymentIntentId,
        ?string $stripeResponse,
        object $session,
        float &$credited
    ): ?DepositRequest {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ref = $referenceCode;
            if ($attempt > 0 || $ref === '' || DepositRequest::where('reference_code', $ref)->exists()) {
                do {
                    $ref = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                } while (DepositRequest::where('reference_code', $ref)->exists());
                $referenceCode = $ref;
            }

            try {
                return DepositRequest::create([
                    'user_id' => $userId,
                    'reference_code' => $ref,
                    'amount' => $amountEuros,
                    'payment_method' => 'card',
                    'status' => 'completed',
                    'stripe_session_id' => $sessionId !== '' ? $sessionId : null,
                    'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
                    'stripe_response' => $stripeResponse,
                    'approved_at' => now(),
                    'paid_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }

                $existing = $this->findOtherRowWithStripeIds(0, $sessionId, $paymentIntentId);
                $resolved = $this->resolveExistingStripeRow(
                    $existing,
                    $sessionId,
                    $paymentIntentId,
                    $session,
                    $userId
                );
                if ($resolved !== null) {
                    $credited = $resolved;

                    return null;
                }

                if ($attempt === 1) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Failed to create card deposit row');
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

        try {
            // Same email + bell path as admin bank/Wise approval (DepositSettlementNotifier).
            app(DepositSettlementNotifier::class)->notifyApproved($deposit);
        } catch (\Throwable $e) {
            Log::error('Failed to notify Stripe deposit credit: '.$e->getMessage(), [
                'deposit_id' => $depositId,
            ]);
        }
    }

    /**
     * Resolve a row that already carries this Checkout / PaymentIntent id.
     * Completed rows are idempotent. Unpaid cards are settled now. Leftover
     * ids on a manual invoice are detached so a new card row can use them.
     */
    private function resolveExistingStripeRow(
        ?DepositRequest $found,
        string $sessionId,
        string $paymentIntentId,
        object $session,
        ?int $expectedUserId
    ): ?float {
        if (! $found) {
            return null;
        }

        $alreadyCredited = $this->creditIfAlreadySettledByStripeRow($found, $sessionId, $paymentIntentId);
        if ($alreadyCredited !== null) {
            if ($expectedUserId && (int) $found->user_id !== (int) $expectedUserId) {
                Log::warning('WalletStripeDepositService: Stripe id already settled on another user', [
                    'deposit_id' => $found->id,
                    'deposit_user_id' => $found->user_id,
                    'session_user_id' => $expectedUserId,
                    'session_id' => $sessionId,
                    'payment_intent_id' => $paymentIntentId,
                ]);

                // Charge is already recorded — do not create a second row,
                // and do not tell this payer they were credited.
                return 0.0;
            }

            if ($alreadyCredited > 0) {
                $this->attachMissingStripeIds($found, $sessionId, $paymentIntentId);
            }

            return $alreadyCredited;
        }

        if ($found->isManualPayment()) {
            return null;
        }

        $settled = $this->completeExistingDeposit(
            (int) $found->id,
            $sessionId,
            $paymentIntentId,
            $session,
            $expectedUserId
        );

        return $settled > 0 ? $settled : null;
    }

    /**
     * Only a completed row is a settlement. Pending / rejected cards still
     * need a wallet credit. Pending / rejected bank invoices with leftover
     * Stripe ids are not settlements — detach those ids.
     */
    private function creditIfAlreadySettledByStripeRow(
        DepositRequest $existing,
        string $sessionId,
        string $paymentIntentId
    ): ?float {
        if ($existing->status === 'completed') {
            if ($existing->isManualPayment()) {
                $this->detachLeftoverStripeIds($existing, $sessionId, $paymentIntentId);

                return null;
            }

            return (float) $existing->amount;
        }

        if ($existing->isManualPayment()) {
            $this->detachLeftoverStripeIds($existing, $sessionId, $paymentIntentId);
        }

        return null;
    }

    /**
     * Persist a PaymentIntent / session id onto a completed card that was
     * recorded before that id was known, so a later webhook cannot mint a
     * second row for the same charge.
     */
    private function attachMissingStripeIds(
        DepositRequest $deposit,
        string $sessionId,
        string $paymentIntentId
    ): void {
        if ($deposit->status !== 'completed' || $deposit->isManualPayment()) {
            return;
        }

        $updates = [];
        if ($sessionId !== '' && ! filled($deposit->stripe_session_id)
            && ! DepositRequest::query()
                ->where('stripe_session_id', $sessionId)
                ->where('id', '!=', $deposit->id)
                ->exists()) {
            $updates['stripe_session_id'] = $sessionId;
        }
        if ($paymentIntentId !== '' && ! filled($deposit->stripe_payment_intent_id)
            && ! DepositRequest::query()
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->where('id', '!=', $deposit->id)
                ->exists()) {
            $updates['stripe_payment_intent_id'] = $paymentIntentId;
        }
        if ($updates === []) {
            return;
        }

        try {
            $deposit->update($updates);
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintFailure($e)) {
                throw $e;
            }
        }
    }

    /**
     * A Checkout Session can credit a card row before Stripe has attached the
     * PaymentIntent. Only reuse that row when the per-checkout session_reference
     * matches — the client REF is reused on Add Funds and would swallow a
     * second same-amount top-up.
     */
    private function findCompletedCardForLatePaymentIntent(
        int $userId,
        float $amountEuros,
        string $sessionReference
    ): ?DepositRequest {
        if ($sessionReference === '') {
            return null;
        }

        $candidates = DepositRequest::query()
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->whereIn('payment_method', DepositRequest::CARD_METHODS)
            ->whereNotNull('stripe_session_id')
            ->where('stripe_session_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('stripe_payment_intent_id')
                    ->orWhere('stripe_payment_intent_id', '');
            })
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $row) {
            if (abs((float) $row->amount - $amountEuros) > 0.01) {
                continue;
            }
            $stored = $this->sessionReferenceFromDeposit($row);
            if ($stored !== '' && hash_equals($stored, $sessionReference)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * payment_intent.succeeded can credit a card before checkout.session.completed
     * arrives — and that session payload may still lack a PaymentIntent id.
     * Reuse the PI-only row when session_reference matches.
     */
    private function findCompletedCardForLateCheckoutSession(
        int $userId,
        float $amountEuros,
        string $sessionReference
    ): ?DepositRequest {
        if ($sessionReference === '') {
            return null;
        }

        $candidates = DepositRequest::query()
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->whereIn('payment_method', DepositRequest::CARD_METHODS)
            ->whereNotNull('stripe_payment_intent_id')
            ->where('stripe_payment_intent_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('stripe_session_id')
                    ->orWhere('stripe_session_id', '');
            })
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $row) {
            if (abs((float) $row->amount - $amountEuros) > 0.01) {
                continue;
            }
            $stored = $this->sessionReferenceFromDeposit($row);
            if ($stored !== '' && hash_equals($stored, $sessionReference)) {
                return $row;
            }
        }

        return null;
    }

    private function findOtherRowWithStripeIds(
        int $exceptId,
        string $sessionId,
        string $paymentIntentId
    ): ?DepositRequest {
        $rows = [];

        if ($sessionId !== '') {
            $bySession = DepositRequest::query()
                ->where('stripe_session_id', $sessionId)
                ->where('id', '!=', $exceptId)
                ->lockForUpdate()
                ->first();
            if ($bySession) {
                $rows[] = $bySession;
            }
        }

        if ($paymentIntentId !== '') {
            $byPi = DepositRequest::query()
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->where('id', '!=', $exceptId)
                ->lockForUpdate()
                ->first();
            if ($byPi) {
                $alreadyListed = false;
                foreach ($rows as $row) {
                    if ((int) $row->id === (int) $byPi->id) {
                        $alreadyListed = true;
                        break;
                    }
                }
                if (! $alreadyListed) {
                    $rows[] = $byPi;
                }
            }
        }

        foreach ($rows as $row) {
            if ($row->status === 'completed' && ! $row->isManualPayment()) {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * A Checkout Session may credit a card before the PaymentIntent id exists.
     * The follow-up PI webhook often has deposit_id and no session id — treat
     * that as the same charge when the named row is a session-only card at
     * the same amount.
     */
    private function isLatePaymentIntentForSessionOnlyCard(
        DepositRequest $deposit,
        string $sessionId,
        string $paymentIntentId,
        object $session
    ): bool {
        if ($sessionId !== '' || $paymentIntentId === '') {
            return false;
        }
        if ($deposit->status !== 'completed' || $deposit->isManualPayment()) {
            return false;
        }
        if (! filled($deposit->stripe_session_id) || filled($deposit->stripe_payment_intent_id)) {
            return false;
        }

        $stripeAmount = isset($session->amount_total)
            ? StripePaymentService::fromCents((int) $session->amount_total)
            : null;
        if ($stripeAmount !== null && abs($stripeAmount - (float) $deposit->amount) > 0.01) {
            return false;
        }

        $incomingReference = $this->sessionReferenceFromStripeObject($session);
        $storedReference = $this->sessionReferenceFromDeposit($deposit);
        if ($incomingReference !== '' && $storedReference !== ''
            && ! hash_equals($incomingReference, $storedReference)) {
            return false;
        }

        return ! DepositRequest::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->where('id', '!=', $deposit->id)
            ->exists();
    }

    private function sessionReferenceFromDeposit(DepositRequest $deposit): string
    {
        $response = $deposit->stripe_response;
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            $response = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($response)) {
            return '';
        }

        $meta = $response['metadata'] ?? [];
        if (is_object($meta)) {
            $meta = $this->metaArray($meta);
        }
        if (! is_array($meta)) {
            return '';
        }

        return trim((string) ($meta['session_reference'] ?? ''));
    }

    private function detachLeftoverStripeIds(
        DepositRequest $deposit,
        string $sessionId,
        string $paymentIntentId
    ): void {
        // Completed cards are the Stripe settlement — freeing those ids
        // would let a retry mint a second credit. Completed bank/Wise/crypto
        // leftover ids are not a card settlement.
        if ($deposit->status === 'completed' && ! $deposit->isManualPayment()) {
            return;
        }

        $updates = [];
        if ($sessionId !== '' && filled($deposit->stripe_session_id)
            && hash_equals((string) $deposit->stripe_session_id, $sessionId)) {
            $updates['stripe_session_id'] = null;
        }
        if ($paymentIntentId !== '' && filled($deposit->stripe_payment_intent_id)
            && hash_equals((string) $deposit->stripe_payment_intent_id, $paymentIntentId)) {
            $updates['stripe_payment_intent_id'] = null;
        }
        if ($updates !== []) {
            $deposit->update($updates);
        }
    }

    protected function depositMatchesStripePayment(
        DepositRequest $deposit,
        string $sessionId,
        string $paymentIntentId
    ): bool {
        if ($paymentIntentId !== '' && filled($deposit->stripe_payment_intent_id)
            && hash_equals((string) $deposit->stripe_payment_intent_id, $paymentIntentId)) {
            return true;
        }

        return $sessionId !== '' && filled($deposit->stripe_session_id)
            && hash_equals((string) $deposit->stripe_session_id, $sessionId);
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
    protected function isWalletDepositType(?string $type, mixed $depositId = null, string $sessionReference = ''): bool
    {
        if ($this->isExplicitWalletDepositType($type)) {
            return true;
        }

        // Typed as something else (order / feature): never a wallet top-up.
        if ($type !== null && $type !== '') {
            return false;
        }

        if (self::isAddFundsSessionReference($sessionReference)) {
            return true;
        }

        if ($depositId !== null && $depositId !== '') {
            // Completing an existing DepositRequest row is a wallet path when
            // Stripe did not tag the payment as something else.
            return true;
        }

        return false;
    }

    protected function isExplicitWalletDepositType(?string $type): bool
    {
        return in_array((string) $type, ['wallet_deposit', 'deposit'], true);
    }

    /**
     * Add Funds Checkout writes session_reference as deposit_{uniqid} on both
     * the session and the PaymentIntent. That prefix is unique to wallet
     * top-ups — catalog orders never set it.
     */
    public static function isAddFundsSessionReference(mixed $sessionReference): bool
    {
        $value = trim((string) $sessionReference);

        return $value !== '' && str_starts_with($value, 'deposit_');
    }

    protected function mayCreateFallbackCardRow(?string $type, string $sessionReference = ''): bool
    {
        return $this->isExplicitWalletDepositType($type)
            || self::isAddFundsSessionReference($sessionReference);
    }

    /**
     * @return object{amount_total: int, metadata: array<string, string>}
     */
    private function paymentIntentAmountSession(int $userId, float $amountEuros, string $sessionReference): object
    {
        return (object) [
            'amount_total' => StripePaymentService::toCents($amountEuros),
            'metadata' => [
                'user_id' => (string) $userId,
                'session_reference' => $sessionReference,
            ],
        ];
    }

    private function sessionReferenceFromStripeObject(object $session): string
    {
        $metadata = $this->metaArray($session->metadata ?? null);

        return trim((string) ($metadata['session_reference'] ?? ''));
    }

    protected function encodeStripeObject(object $obj): string
    {
        if (method_exists($obj, 'toArray')) {
            return json_encode($obj->toArray());
        }

        return json_encode($obj);
    }

    private function withStripeDepositLock(
        string $paymentIntentId,
        string $sessionId,
        callable $callback,
        string $sessionReference = ''
    ): mixed {
        $key = $sessionReference !== ''
            ? 'wallet_deposit_sref:'.$sessionReference
            : ($paymentIntentId !== ''
                ? 'wallet_deposit_pi:'.$paymentIntentId
                : 'wallet_deposit_cs:'.$sessionId);

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
