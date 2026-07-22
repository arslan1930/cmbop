<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $secret = $this->secret();
        if ($secret !== '') {
            Stripe::setApiKey($secret);
        }
    }

    public function configured(): bool
    {
        $secret = $this->secret();

        return $secret !== ''
            && str_starts_with($secret, 'sk_')
            && ! str_contains($secret, 'your-');
    }

    private function secret(): string
    {
        return trim((string) config('services.stripe.secret', ''));
    }

    /**
     * Whether the users table can store a Stripe Customer id.
     */
    public function usersTableReady(): bool
    {
        try {
            return Schema::hasTable('users') && Schema::hasColumn('users', 'stripe_customer_id');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ensure users.stripe_* columns exist (Hostinger often skips artisan migrate).
     * Never throws — Checkout must keep working even when ALTER is denied.
     */
    public function ensureUserStripeColumns(): void
    {
        try {
            if (! Schema::hasTable('users')) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe column check failed', ['error' => $e->getMessage()]);

            return;
        }

        $this->addUsersColumnIfMissing('stripe_customer_id', 'varchar(255) NULL');
        $this->addUsersColumnIfMissing('stripe_default_payment_method_id', 'varchar(255) NULL');

        // Unique index is optional — ignore failures (duplicates / no privilege).
        try {
            if (Schema::hasColumn('users', 'stripe_customer_id')) {
                DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `users_stripe_customer_id_unique` (`stripe_customer_id`)');
            }
        } catch (\Throwable) {
            // Duplicate key name / no privilege — fine.
        }
    }

    private function addUsersColumnIfMissing(string $column, string $definition): void
    {
        try {
            if (Schema::hasColumn('users', $column)) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe hasColumn failed', ['column' => $column, 'error' => $e->getMessage()]);

            return;
        }

        try {
            DB::statement("ALTER TABLE `users` ADD COLUMN `{$column}` {$definition}");
            Log::info('Added missing users.'.$column.' for Stripe');
        } catch (\Throwable $e) {
            try {
                if (Schema::hasColumn('users', $column)) {
                    return;
                }
            } catch (\Throwable) {
                // ignore
            }
            Log::warning('Could not add users.'.$column.' for Stripe', [
                'error' => $e->getMessage(),
                'hint' => 'Run database/sql/fix_users_stripe_customer_columns.sql in phpMyAdmin',
            ]);
        }
    }

    /**
     * Ensure the user has a Stripe Customer and return its id.
     *
     * Persisting to users.stripe_customer_id is best-effort. If the Hostinger
     * schema is missing the column, Checkout can still use the Customer id.
     */
    public function getOrCreateCustomerId(User $user): string
    {
        $this->ensureUserStripeColumns();
        $canPersist = $this->usersTableReady();

        $existing = $canPersist ? trim((string) ($user->stripe_customer_id ?? '')) : '';
        if ($existing !== '') {
            try {
                Customer::retrieve($existing);

                return $existing;
            } catch (ApiErrorException $e) {
                Log::warning('Stored Stripe customer missing; recreating', [
                    'user_id' => $user->id,
                    'stripe_customer_id' => $existing,
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

        if ($canPersist) {
            try {
                $user->forceFill(['stripe_customer_id' => $customer->id])->save();
            } catch (\Throwable $e) {
                Log::warning('Could not persist users.stripe_customer_id; using Customer for this checkout only', [
                    'user_id' => $user->id,
                    'stripe_customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::warning('users.stripe_customer_id missing; Checkout will use ephemeral Stripe Customer', [
                'user_id' => $user->id,
                'hint' => 'Run database/sql/fix_users_stripe_customer_columns.sql',
            ]);
        }

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
            ],
            'billing_address_collection' => 'auto',
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
     * Create a Checkout Session, retrying as a guest session if customer options fail.
     *
     * @param  array<string, mixed>  $basePayload
     */
    public function createCheckoutSession(array $basePayload, ?User $user = null, bool $offerSave = true): Session
    {
        if (! $this->configured()) {
            throw new \RuntimeException(
                'Stripe is not configured. Set STRIPE_SECRET (sk_...) and STRIPE_KEY (pk_...) in .env, then run: php artisan config:clear'
            );
        }

        Stripe::setApiKey($this->secret());

        $guestPayload = $basePayload;
        if ($user && empty($guestPayload['customer']) && empty($guestPayload['customer_email'])) {
            $guestPayload['customer_email'] = $user->email;
        }

        if (! $user) {
            return Session::create($guestPayload);
        }

        $withCustomer = array_merge($basePayload, $this->checkoutCustomerOptions($user, $offerSave));

        if (! isset($withCustomer['customer'])) {
            return Session::create($guestPayload);
        }

        try {
            return Session::create($withCustomer);
        } catch (\Throwable $e) {
            Log::warning('Stripe Checkout with customer failed; retrying guest session', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return Session::create($guestPayload);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCards(User $user): array
    {
        if (! $this->configured() || ! $this->usersTableReady()) {
            return [];
        }

        $customerId = trim((string) ($user->stripe_customer_id ?? ''));
        if ($customerId === '') {
            return [];
        }

        try {
            $methods = PaymentMethod::all([
                'customer' => $customerId,
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

        $defaultId = $user->stripe_default_payment_method_id ?? null;
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

        if ($this->usersTableReady()) {
            $user->forceFill(['stripe_default_payment_method_id' => $paymentMethodId])->save();
        }
    }

    public function detachPaymentMethod(User $user, string $paymentMethodId): void
    {
        $customerId = $this->getOrCreateCustomerId($user);
        $pm = PaymentMethod::retrieve($paymentMethodId);

        if (($pm->customer ?? null) !== $customerId) {
            throw new \RuntimeException('This card does not belong to your account.');
        }

        $pm->detach();

        if ($this->usersTableReady() && ($user->stripe_default_payment_method_id ?? null) === $paymentMethodId) {
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

        if ($this->usersTableReady() && ! $user->stripe_default_payment_method_id) {
            $this->setDefaultPaymentMethod($user, $pmId);
        }
    }
}
