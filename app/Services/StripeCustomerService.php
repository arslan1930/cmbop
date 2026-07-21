<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Stripe;

class StripeCustomerService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function configured(): bool
    {
        $secret = (string) config('services.stripe.secret');

        return $secret !== '' && ! str_contains($secret, 'your-');
    }

    /**
     * Ensure users.stripe_* columns exist (Hostinger often skips artisan migrate).
     */
    public function ensureUserStripeColumns(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $needsCustomer = ! Schema::hasColumn('users', 'stripe_customer_id');
        $needsDefaultPm = ! Schema::hasColumn('users', 'stripe_default_payment_method_id');

        if (! $needsCustomer && ! $needsDefaultPm) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($needsCustomer, $needsDefaultPm) {
            if ($needsCustomer) {
                $table->string('stripe_customer_id')->nullable()->unique();
            }
            if ($needsDefaultPm) {
                $table->string('stripe_default_payment_method_id')->nullable();
            }
        });
    }

    /**
     * Ensure the user has a Stripe Customer and return its id.
     */
    public function getOrCreateCustomerId(User $user): string
    {
        $this->ensureUserStripeColumns();

        if ($user->stripe_customer_id) {
            try {
                Customer::retrieve($user->stripe_customer_id);

                return $user->stripe_customer_id;
            } catch (ApiErrorException $e) {
                Log::warning('Stored Stripe customer missing; recreating', [
                    'user_id' => $user->id,
                    'stripe_customer_id' => $user->stripe_customer_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $customer = Customer::create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /**
     * Options to attach a Checkout Session to the customer and offer saving cards.
     *
     * @return array<string, mixed>
     */
    public function checkoutCustomerOptions(User $user, bool $offerSave = true): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $customerId = $this->getOrCreateCustomerId($user);
        } catch (\Throwable $e) {
            // Do not block Stripe Checkout if customer create fails — fall back to guest session.
            Log::warning('Stripe customer options unavailable; continuing without saved-card customer', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $options = [
            'customer' => $customerId,
            'customer_update' => [
                'name' => 'auto',
                'address' => 'auto',
            ],
            'saved_payment_method_options' => [
                'allow_redisplay_filters' => ['always'],
            ],
        ];

        if ($offerSave) {
            $options['saved_payment_method_options']['payment_method_save'] = 'enabled';
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCards(User $user): array
    {
        if (! $user->stripe_customer_id || ! $this->configured()) {
            return [];
        }

        try {
            $methods = PaymentMethod::all([
                'customer' => $user->stripe_customer_id,
                'type' => 'card',
                'limit' => 20,
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('Failed to list Stripe payment methods', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $defaultId = $user->stripe_default_payment_method_id;
        $cards = [];

        foreach ($methods->data as $pm) {
            $card = $pm->card ?? null;
            if (! $card) {
                continue;
            }

            $cards[] = [
                'id' => $pm->id,
                'brand' => (string) ($card->brand ?? 'card'),
                'last4' => (string) ($card->last4 ?? '****'),
                'exp_month' => (int) ($card->exp_month ?? 0),
                'exp_year' => (int) ($card->exp_year ?? 0),
                'is_default' => $defaultId === $pm->id,
            ];
        }

        usort($cards, function (array $a, array $b) {
            if ($a['is_default'] === $b['is_default']) {
                return 0;
            }

            return $a['is_default'] ? -1 : 1;
        });

        return $cards;
    }

    public function setDefaultPaymentMethod(User $user, string $paymentMethodId): void
    {
        $customerId = $this->getOrCreateCustomerId($user);
        $pm = PaymentMethod::retrieve($paymentMethodId);

        if (($pm->customer ?? null) !== $customerId) {
            throw new \RuntimeException('This card does not belong to your account.');
        }

        Customer::update($customerId, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);

        $user->forceFill(['stripe_default_payment_method_id' => $paymentMethodId])->save();
    }

    public function detachPaymentMethod(User $user, string $paymentMethodId): void
    {
        $customerId = $this->getOrCreateCustomerId($user);
        $pm = PaymentMethod::retrieve($paymentMethodId);

        if (($pm->customer ?? null) !== $customerId) {
            throw new \RuntimeException('This card does not belong to your account.');
        }

        $pm->detach();

        if ($user->stripe_default_payment_method_id === $paymentMethodId) {
            $user->forceFill(['stripe_default_payment_method_id' => null])->save();
        }
    }

    /**
     * Stripe Checkout in setup mode — add a card without charging.
     */
    public function createSetupCheckoutSession(User $user, string $successUrl, string $cancelUrl): Session
    {
        $customerId = $this->getOrCreateCustomerId($user);

        return Session::create([
            'mode' => 'setup',
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'type' => 'save_card',
                'user_id' => (string) $user->id,
            ],
        ]);
    }

    /**
     * Charge a saved card (on-session). May return a redirect URL for 3DS.
     *
     * @return array{status: string, payment_intent_id: string, client_secret?: string, redirect_url?: string}
     */
    public function payWithSavedCard(
        User $user,
        string $paymentMethodId,
        int $amountCents,
        array $metadata,
        string $returnUrl,
        string $description
    ): array {
        $customerId = $this->getOrCreateCustomerId($user);
        $pm = PaymentMethod::retrieve($paymentMethodId);

        if (($pm->customer ?? null) !== $customerId) {
            throw new \RuntimeException('This card does not belong to your account.');
        }

        $intent = PaymentIntent::create([
            'amount' => $amountCents,
            'currency' => 'eur',
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'payment_method_types' => ['card'],
            'description' => $description,
            'metadata' => $metadata,
            'confirm' => true,
            'return_url' => $returnUrl,
        ]);

        $result = [
            'status' => (string) $intent->status,
            'payment_intent_id' => (string) $intent->id,
            'client_secret' => (string) $intent->client_secret,
        ];

        if ($intent->status === 'requires_action' && ! empty($intent->next_action->redirect_to_url->url ?? null)) {
            $result['redirect_url'] = $intent->next_action->redirect_to_url->url;
        }

        return $result;
    }

    /**
     * After setup Checkout, mark the new PM as default when none exists.
     */
    public function syncDefaultFromSetupSession(User $user, string $sessionId): void
    {
        $session = Session::retrieve($sessionId, ['expand' => ['setup_intent.payment_method']]);
        if (($session->metadata->user_id ?? null) && (string) $session->metadata->user_id !== (string) $user->id) {
            throw new \RuntimeException('Setup session does not belong to this user.');
        }

        $pmId = null;
        if (is_string($session->setup_intent ?? null)) {
            $setup = SetupIntent::retrieve($session->setup_intent);
            $pmId = is_string($setup->payment_method) ? $setup->payment_method : ($setup->payment_method->id ?? null);
        } elseif (is_object($session->setup_intent ?? null)) {
            $pm = $session->setup_intent->payment_method ?? null;
            $pmId = is_string($pm) ? $pm : ($pm->id ?? null);
        }

        if (! $pmId) {
            return;
        }

        if (! $user->stripe_default_payment_method_id) {
            $this->setDefaultPaymentMethod($user, $pmId);
        }
    }
}
