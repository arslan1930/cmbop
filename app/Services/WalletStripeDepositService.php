<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
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
        string $referenceCode
    ): float {
        $credited = 0.0;

        DB::transaction(function () use ($userId, $paymentIntentId, $amountEuros, &$referenceCode, &$credited) {
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

            $this->creditAdvertiserWallet($userId, (float) $deposit->amount, $deposit);
            $credited = (float) $deposit->amount;
        });

        return $credited;
    }

    /**
     * Credit wallet from a paid Checkout Session (webhook / success URL create-after-pay path).
     */
    public function creditFromCheckoutSession(object $session): float
    {
        $metadata = $this->metaArray($session->metadata ?? null);
        $depositId = $metadata['deposit_id'] ?? null;
        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : null;
        $referenceCode = $metadata['reference_code'] ?? null;
        $metaAmount = isset($metadata['amount']) ? round((float) $metadata['amount'], 2) : null;

        $sessionId = (string) ($session->id ?? '');
        $paymentIntentId = is_string($session->payment_intent ?? null)
            ? $session->payment_intent
            : (string) ($session->payment_intent->id ?? ($session->payment_intent ?? ''));

        if ($depositId) {
            return $this->completeExistingDeposit((int) $depositId, $sessionId, $paymentIntentId, $session);
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

        $credited = 0.0;

        DB::transaction(function () use ($userId, $session, $sessionId, $paymentIntentId, $finalAmount, $referenceCode, &$credited) {
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

            $this->creditAdvertiserWallet($userId, (float) $finalAmount, $deposit);
            $credited = (float) $finalAmount;

            Log::info('Deposit created from Stripe session', [
                'deposit_id' => $deposit->id,
                'session_id' => $sessionId,
            ]);
        });

        return $credited;
    }

    /**
     * Credit from a PaymentIntent object (webhook path).
     */
    public function creditFromPaymentIntentObject(object $intent): float
    {
        $metadata = $this->metaArray($intent->metadata ?? null);
        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : 0;
        $referenceCode = (string) ($metadata['reference_code'] ?? str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT));

        $amountFromStripe = isset($intent->amount_received) && (int) $intent->amount_received > 0
            ? StripePaymentService::fromCents((int) $intent->amount_received)
            : (isset($intent->amount) ? StripePaymentService::fromCents((int) $intent->amount) : null);
        $metaAmount = isset($metadata['amount']) ? round((float) $metadata['amount'], 2) : null;
        $amount = $amountFromStripe !== null ? $amountFromStripe : ($metaAmount ?? 0.0);

        if ($userId <= 0 || $amount <= 0) {
            throw new \RuntimeException('Invalid wallet_deposit PaymentIntent metadata/amount');
        }

        return $this->creditFromPaymentIntent($userId, (string) $intent->id, $amount, $referenceCode);
    }

    protected function completeExistingDeposit(
        int $depositId,
        string $sessionId,
        string $paymentIntentId,
        object $session
    ): float {
        $credited = 0.0;

        DB::transaction(function () use ($depositId, $sessionId, $paymentIntentId, $session, &$credited) {
            $lockedDeposit = DepositRequest::where('id', $depositId)->lockForUpdate()->first();
            if (! $lockedDeposit) {
                throw new \RuntimeException('Deposit not found: '.$depositId);
            }

            if ($lockedDeposit->status === 'completed') {
                $credited = (float) $lockedDeposit->amount;

                return;
            }

            $lockedDeposit->update([
                'stripe_session_id' => $sessionId !== '' ? $sessionId : $lockedDeposit->stripe_session_id,
                'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : $lockedDeposit->stripe_payment_intent_id,
                'stripe_response' => $this->encodeStripeObject($session),
                'status' => 'completed',
                'approved_at' => now(),
                'paid_at' => now(),
            ]);

            $this->creditAdvertiserWallet(
                (int) $lockedDeposit->user_id,
                (float) $lockedDeposit->amount,
                $lockedDeposit
            );
            $credited = (float) $lockedDeposit->amount;
        });

        return $credited;
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

    protected function encodeStripeObject(object $obj): string
    {
        if (method_exists($obj, 'toArray')) {
            return json_encode($obj->toArray());
        }

        return json_encode($obj);
    }
}
