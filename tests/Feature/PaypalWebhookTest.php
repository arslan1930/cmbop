<?php

namespace Tests\Feature;

use App\Mail\DepositRefunded;
use App\Mail\PaypalExternalPaymentNotice;
use App\Mail\PaypalPaymentNotCompleted;
use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\PaypalWebhookLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\PaypalCheckoutService;
use App\Support\UserMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class PaypalWebhookTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $paypalHttp = [];

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'PayPal Webhook Site',
            'site_url' => 'https://paypal-hook.example',
            'domain' => 'paypal-hook.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100.00,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Webhook test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function enablePaypal(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.webhook_id' => 'WH-TEST-1',
            'services.paypal.base_url' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $capture
     */
    private function fakePaypal(string $orderId, array $capture = []): void
    {
        $this->paypalHttp['order_id'] = $orderId;
        $this->paypalHttp['capture'] = array_replace($this->paypalHttp['capture'] ?? [], $capture);
        $this->paypalHttp['verify'] = $this->paypalHttp['verify'] ?? 'SUCCESS';
        if (($this->paypalHttp['registered'] ?? false) === true) {
            return;
        }

        $this->paypalHttp['registered'] = true;
        Http::fake(function ($request) {
            $url = $request->url();
            $orderId = (string) ($this->paypalHttp['order_id'] ?? '');
            $capture = is_array($this->paypalHttp['capture'] ?? null) ? $this->paypalHttp['capture'] : [];
            $userId = (string) ($capture['user_id'] ?? '0');
            $ref = (string) ($capture['reference_code'] ?? 'PP-HOOK');
            $amount = (string) ($capture['amount'] ?? '');
            if ($amount === '') {
                $package = $ref !== '' ? app(OrderPaymentService::class)->getPendingCheckout($ref) : null;
                $amount = is_array($package)
                    ? number_format((float) ($package['amount_due'] ?? 0), 2, '.', '')
                    : '113.00';
            }

            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }

            if (str_contains($url, '/v1/notifications/verify-webhook-signature')) {
                return Http::response([
                    'verification_status' => $this->paypalHttp['verify'] ?? 'SUCCESS',
                ], 200);
            }

            if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return Http::response([
                    'id' => $orderId,
                    'status' => 'CREATED',
                    'links' => [
                        ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$orderId],
                    ],
                ], 201);
            }

            $completed = [
                'id' => $orderId,
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'custom_id' => PaypalCheckoutService::customId(
                        PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                        $userId,
                        $ref
                    ),
                    'payments' => [
                        'captures' => [[
                            'id' => (string) ($capture['id'] ?? 'CAP-'.$orderId),
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'EUR', 'value' => $amount],
                        ]],
                    ],
                ]],
            ];

            if (str_contains($url, '/v2/checkout/orders/'.$orderId.'/capture')
                || str_contains($url, '/v2/checkout/orders/'.$orderId)) {
                return Http::response($completed, 201);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postWebhook(array $event): TestResponse
    {
        return $this->call(
            'POST',
            '/api/paypal/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
                'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-1',
                'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
                'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
                'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-08-18T12:00:00Z',
            ],
            json_encode($event, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function captureCompletedEvent(User $advertiser, string $ref, string $orderId, string $eventId, string $amount): array
    {
        return [
            'id' => $eventId,
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-'.$orderId,
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => $amount],
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    $ref
                ),
                'supplementary_data' => [
                    'related_ids' => ['order_id' => $orderId],
                ],
            ],
        ];
    }

    public function test_webhook_rejects_when_not_configured(): void
    {
        $this->postWebhook(['id' => 'WH-OFF', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'])
            ->assertStatus(503)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_unavailable'));
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-BAD');
        $this->paypalHttp['verify'] = 'FAILURE';

        $this->postWebhook([
            'id' => 'WH-BAD',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        ])->assertStatus(400)->assertJsonPath('error', 'Invalid signature');
    }

    public function test_webhook_log_stores_redacted_payload_only(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-REDACT');

        $this->postWebhook([
            'id' => 'WH-REDACT-1',
            'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'create_time' => '2026-01-01T00:00:00Z',
            'resource' => [
                'id' => 'CAP-DENIED',
                'payer' => ['email_address' => 'buyer@example.com'],
                'amount' => ['currency_code' => 'EUR', 'value' => '99.00'],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $log = PaypalWebhookLog::query()->where('event_id', 'WH-REDACT-1')->first();
        $this->assertNotNull($log);
        $stored = $log->payload;
        $this->assertIsArray($stored);
        $this->assertSame('WH-REDACT-1', $stored['id'] ?? null);
        $this->assertSame('PAYMENT.CAPTURE.DENIED', $stored['event_type'] ?? null);
        $this->assertSame('CAP-DENIED', $stored['resource_id'] ?? null);
        $this->assertArrayNotHasKey('resource', $stored);
        $this->assertStringNotContainsString('buyer@example.com', json_encode($stored));
        $this->assertStringNotContainsString('99.00', json_encode($stored));
    }

    public function test_capture_completed_settles_paid_orders_without_return_url(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $this->fakePaypal('PO-HOOK', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-HOOK',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP-HOOK',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $package = app(OrderPaymentService::class)->getPendingCheckout('PP-HOOK');
        $this->assertIsArray($package);
        $amount = number_format((float) $package['amount_due'], 2, '.', '');

        $this->postWebhook($this->captureCompletedEvent(
            $advertiser,
            'PP-HOOK',
            'PO-HOOK',
            'WH-SETTLE-1',
            $amount
        ))->assertOk()->assertJsonPath('status', 'success');

        $order = Order::query()->where('reference_code', 'PP-HOOK')->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paypal', $order->payment_method);
        $this->assertSame('CAP-PO-HOOK', $order->paypal_capture_id);
        $this->assertTrue((bool) PaypalWebhookLog::query()->where('event_id', 'WH-SETTLE-1')->value('processed'));
    }

    public function test_approved_order_webhook_captures_then_settles(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $this->fakePaypal('PO-APPR', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-APPR',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP-APPR',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $this->postWebhook([
            'id' => 'WH-APPR-1',
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'PO-APPR',
                'status' => 'APPROVED',
                'purchase_units' => [[
                    'custom_id' => PaypalCheckoutService::customId(
                        PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                        $advertiser->id,
                        'PP-APPR'
                    ),
                ]],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(1, Order::query()->where('reference_code', 'PP-APPR')->where('payment_status', 'paid')->count());
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $this->fakePaypal('PO-DUP', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-WDUP',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP-WDUP',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $package = app(OrderPaymentService::class)->getPendingCheckout('PP-WDUP');
        $amount = number_format((float) $package['amount_due'], 2, '.', '');
        $event = $this->captureCompletedEvent($advertiser, 'PP-WDUP', 'PO-DUP', 'WH-DUP-1', $amount);

        $this->postWebhook($event)->assertOk()->assertJsonPath('status', 'success');
        $this->postWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, Order::query()->where('reference_code', 'PP-WDUP')->count());
    }

    public function test_later_capture_event_does_not_renotify_after_settle(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $this->fakePaypal('PO-ONCE', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-ONCE',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP-ONCE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $package = app(OrderPaymentService::class)->getPendingCheckout('PP-ONCE');
        $amount = number_format((float) $package['amount_due'], 2, '.', '');

        $this->postWebhook($this->captureCompletedEvent(
            $advertiser,
            'PP-ONCE',
            'PO-ONCE',
            'WH-ONCE-1',
            $amount
        ))->assertOk();

        $createdAfterFirst = InAppNotification::query()
            ->where('type', InAppNotificationService::TYPE_ORDER_CREATED)
            ->count();
        $paidAfterFirst = InAppNotification::query()
            ->where('type', InAppNotificationService::TYPE_PAYMENT_RECEIVED)
            ->count();
        $this->assertGreaterThan(0, $createdAfterFirst);
        $this->assertGreaterThan(0, $paidAfterFirst);

        $this->postWebhook($this->captureCompletedEvent(
            $advertiser,
            'PP-ONCE',
            'PO-ONCE',
            'WH-ONCE-2',
            $amount
        ))->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(1, Order::query()->where('reference_code', 'PP-ONCE')->count());
        $this->assertSame(
            $createdAfterFirst,
            InAppNotification::query()->where('type', InAppNotificationService::TYPE_ORDER_CREATED)->count()
        );
        $this->assertSame(
            $paidAfterFirst,
            InAppNotification::query()->where('type', InAppNotificationService::TYPE_PAYMENT_RECEIVED)->count()
        );
    }

    public function test_failed_webhook_can_be_retried(): void
    {
        Mail::fake();
        $this->enablePaypal();
        $this->fakePaypal('PO-RETRY');

        $event = [
            'id' => 'WH-RETRY-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-PO-RETRY',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '10.00'],
                'custom_id' => 'not_an_order',
            ],
        ];

        $this->postWebhook($event)->assertStatus(500);
        $log = PaypalWebhookLog::query()->where('event_id', 'WH-RETRY-1')->first();
        $this->assertNotNull($log);
        $this->assertFalse((bool) $log->processed);

        $advertiser = $this->advertiser();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $event['resource']['custom_id'] = PaypalCheckoutService::customId(
            PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
            $advertiser->id,
            '654321'
        );
        $this->postWebhook($event)->assertOk()->assertJsonPath('status', 'success');
        $this->assertTrue((bool) PaypalWebhookLog::query()->where('event_id', 'WH-RETRY-1')->value('processed'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, DepositRequest::query()->where('paypal_capture_id', 'CAP-PO-RETRY')->count());
    }

    public function test_wallet_deposit_webhook_credits_advertiser_wallet(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DEP');
        Mail::fake();

        $advertiser = $this->advertiser();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 3,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->postWebhook([
            'id' => 'WH-DEP-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-DEP-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
                    $advertiser->id,
                    '777777'
                ),
                'supplementary_data' => [
                    'related_ids' => ['order_id' => 'PO-DEP'],
                ],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $deposit = DepositRequest::query()->where('paypal_capture_id', 'CAP-DEP-1')->first();
        $this->assertNotNull($deposit);
        $this->assertSame('paypal', $deposit->payment_method);
        $this->assertSame('completed', $deposit->status);
        $this->assertEqualsWithDelta(25.0, (float) $deposit->amount, 0.01);
        $this->assertEqualsWithDelta(28.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_refunded_webhook_marks_order_refunded_without_wallet_credit(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-RF');
        Mail::fake();

        $advertiser = $this->advertiser();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 5,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'PP-RF', 20);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '880001',
            'reference_code' => 'PP-RF',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paypal_order_id' => 'PO-RF',
            'paypal_capture_id' => 'CAP-PO-RF',
            'paid_at' => now(),
        ]);

        $this->postWebhook([
            'id' => 'WH-RF-1',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'RF-PO-RF',
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-RF'
                ),
                'supplementary_data' => [
                    'related_ids' => [
                        'capture_id' => 'CAP-PO-RF',
                        'order_id' => 'PO-RF',
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $fresh = $order->fresh();
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('RF-PO-RF', $fresh->paypal_refund_id);
        $wallet->refresh();
        $this->assertEqualsWithDelta(5.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_refunded_deposit_webhook_reverses_wallet_credit(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DEP-RF');
        Mail::fake();

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 40,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => '888001',
            'amount' => 25,
            'payment_method' => 'paypal',
            'status' => 'completed',
            'paypal_order_id' => 'PO-DEP-RF',
            'paypal_capture_id' => 'CAP-DEP-RF',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $payload = [
            'id' => 'WH-DEP-RF-1',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'RF-DEP-RF',
                'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
                    $advertiser->id,
                    '888001'
                ),
                'supplementary_data' => [
                    'related_ids' => [
                        'capture_id' => 'CAP-DEP-RF',
                        'order_id' => 'PO-DEP-RF',
                    ],
                ],
            ],
        ];

        $this->postWebhook($payload)->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('refunded', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(15.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->fresh()->debt_balance, 0.01);

        Mail::assertQueued(DepositRefunded::class, function (DepositRefunded $mail) use ($advertiser, $deposit) {
            return (int) $mail->deposit->id === (int) $deposit->id
                && (int) $mail->deposit->user_id === (int) $advertiser->id
                && $mail->notificationType === 'deposit_refunded'
                && $mail->dedupeKey === 'deposit_refunded:'.$deposit->id;
        });
        Mail::assertQueued(DepositRefunded::class, 1);

        $html = (new DepositRefunded($deposit->fresh(['user'])))->render();
        $this->assertStringContainsString('PayPal deposit refunded', $html);
        $this->assertStringContainsString('removed from your wallet', $html);

        $this->assertTrue(InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('title', 'PayPal deposit refunded — €25.00')
            ->exists());
        $this->assertTrue(InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->where('title', 'PayPal deposit refunded')
            ->exists());

        $this->postWebhook($payload)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertEqualsWithDelta(15.0, (float) $wallet->fresh()->balance, 0.01);
        Mail::assertQueued(DepositRefunded::class, 1);
    }

    public function test_refunded_deposit_webhook_records_debt_when_wallet_spent(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DEP-SHORT');
        Mail::fake();

        $advertiser = $this->advertiser();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 8,
            'reserved_balance' => 12,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => '888002',
            'amount' => 25,
            'payment_method' => 'paypal',
            'status' => 'completed',
            'paypal_order_id' => 'PO-DEP-SHORT',
            'paypal_capture_id' => 'CAP-DEP-SHORT',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $this->postWebhook([
            'id' => 'WH-DEP-SHORT-1',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'RF-DEP-SHORT',
                'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
                    $advertiser->id,
                    '888002'
                ),
                'supplementary_data' => [
                    'related_ids' => [
                        'capture_id' => 'CAP-DEP-SHORT',
                        'order_id' => 'PO-DEP-SHORT',
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(12.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(17.0, (float) $wallet->debt_balance, 0.01);

        Mail::assertQueued(DepositRefunded::class, 1);
        $refunded = DepositRequest::query()->where('paypal_capture_id', 'CAP-DEP-SHORT')->first();
        $html = (new DepositRefunded($refunded->fresh(['user'])))->render();
        $this->assertStringContainsString('€17.00', $html);
        $this->assertStringContainsString('outstanding wallet debt', $html);
    }

    public function test_refunded_webhook_does_not_cancel_a_completed_order(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DONE');
        Mail::fake();

        $this->admin();
        $advertiser = $this->advertiser();
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '880002',
            'reference_code' => 'PP-DONE',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paypal_order_id' => 'PO-DONE',
            'paypal_capture_id' => 'CAP-PO-DONE',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
        ]);

        $this->postWebhook([
            'id' => 'WH-DONE-1',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'RF-PO-DONE',
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-DONE'
                ),
                'supplementary_data' => [
                    'related_ids' => [
                        'capture_id' => 'CAP-PO-DONE',
                        'order_id' => 'PO-DONE',
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('RF-PO-DONE', $fresh->paypal_refund_id);
        Mail::assertQueued(PaypalExternalPaymentNotice::class, function (PaypalExternalPaymentNotice $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->kind === PaypalExternalPaymentNotice::KIND_COMPLETED_REFUND
                && $mail->audience === PaypalExternalPaymentNotice::AUDIENCE_ADVERTISER;
        });
        $this->assertTrue(InAppNotification::query()
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->where('title', 'PayPal refunded completed order #880002')
            ->exists());
    }

    public function test_denied_webhook_emails_advertiser_when_checkout_is_unpaid(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DENY');
        Mail::fake();

        $advertiser = $this->advertiser();

        $this->postWebhook([
            'id' => 'WH-DENY-1',
            'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => [
                'id' => 'CAP-PO-DENY',
                'status' => 'DENIED',
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-DENY'
                ),
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        Mail::assertQueued(PaypalPaymentNotCompleted::class, function (PaypalPaymentNotCompleted $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->reason === PaypalPaymentNotCompleted::REASON_DENIED
                && $mail->referenceCode === 'PP-DENY';
        });
        Mail::assertQueued(PaypalPaymentNotCompleted::class, 1);
        $this->assertSame(0, Order::query()->where('reference_code', 'PP-DENY')->count());
    }

    public function test_denied_webhook_is_silent_after_paid_checkout(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DENY-PAID');
        Mail::fake();

        $advertiser = $this->advertiser();
        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-DENY-PAID',
            'reference_code' => 'PP-DENY-PAID',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paypal_order_id' => 'PO-DENY-PAID',
            'paypal_capture_id' => 'CAP-PO-DENY-PAID',
            'paid_at' => now(),
        ]);

        $this->postWebhook([
            'id' => 'WH-DENY-PAID-1',
            'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => [
                'id' => 'CAP-PO-DENY-PAID',
                'status' => 'DENIED',
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-DENY-PAID'
                ),
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        Mail::assertNotQueued(PaypalPaymentNotCompleted::class);
    }

    public function test_pending_webhook_emails_advertiser_once(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-PEND');
        Mail::fake();

        $advertiser = $this->advertiser();
        $event = [
            'id' => 'WH-PEND-1',
            'event_type' => 'PAYMENT.CAPTURE.PENDING',
            'resource' => [
                'id' => 'CAP-PO-PEND',
                'status' => 'PENDING',
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-PEND'
                ),
            ],
        ];

        $this->postWebhook($event)->assertOk()->assertJsonPath('status', 'success');
        $this->postWebhook(array_merge($event, ['id' => 'WH-PEND-2']))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Mail::assertQueued(PaypalPaymentNotCompleted::class, function (PaypalPaymentNotCompleted $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->reason === PaypalPaymentNotCompleted::REASON_PENDING
                && $mail->referenceCode === 'PP-PEND';
        });
        Mail::assertQueued(PaypalPaymentNotCompleted::class, 1);
    }

    public function test_partial_refund_keeps_sibling_orders_paid(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-PART');
        Mail::fake();

        $advertiser = $this->advertiser();
        $a = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '880010',
            'reference_code' => 'PP-PART',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paypal_order_id' => 'PO-PART',
            'paypal_capture_id' => 'CAP-PO-PART',
            'paid_at' => now(),
        ]);
        $b = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '880011',
            'reference_code' => 'PP-PART',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paypal_order_id' => 'PO-PART',
            'paypal_capture_id' => null,
            'paid_at' => now(),
        ]);

        $this->postWebhook([
            'id' => 'WH-PART-1',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'RF-PO-PART',
                'amount' => ['currency_code' => 'EUR', 'value' => '40.00'],
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-PART'
                ),
                'supplementary_data' => [
                    'related_ids' => [
                        'capture_id' => 'CAP-PO-PART',
                        'order_id' => 'PO-PART',
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('paid', $a->fresh()->payment_status);
        $this->assertSame('pending', $a->fresh()->status);
        $this->assertSame('paid', $b->fresh()->payment_status);
        $this->assertSame('pending', $b->fresh()->status);
        Mail::assertQueued(PaypalExternalPaymentNotice::class, function (PaypalExternalPaymentNotice $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->kind === PaypalExternalPaymentNotice::KIND_PARTIAL_REFUND;
        });
    }

    public function test_reversed_webhook_notifies_without_changing_completed_order(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-REV');
        Mail::fake();

        $advertiser = $this->advertiser();
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '880012',
            'reference_code' => 'PP-REV',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paypal_order_id' => 'PO-REV',
            'paypal_capture_id' => 'CAP-PO-REV',
            'paid_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        $this->postWebhook([
            'id' => 'WH-REV-1',
            'event_type' => 'PAYMENT.CAPTURE.REVERSED',
            'resource' => [
                'id' => 'RV-PO-REV',
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-REV'
                ),
                'supplementary_data' => [
                    'related_ids' => [
                        'capture_id' => 'CAP-PO-REV',
                        'order_id' => 'PO-REV',
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('completed', $order->fresh()->status);
        Mail::assertQueued(PaypalExternalPaymentNotice::class, function (PaypalExternalPaymentNotice $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->kind === PaypalExternalPaymentNotice::KIND_REVERSED;
        });
    }

    public function test_dispute_created_webhook_notifies_without_changing_order(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DSP');
        Mail::fake();

        $advertiser = $this->advertiser();
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '880013',
            'reference_code' => 'PP-DSP',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paypal_order_id' => 'PO-DSP',
            'paypal_capture_id' => 'CAP-PO-DSP',
            'paid_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        $this->postWebhook([
            'id' => 'WH-DSP-1',
            'event_type' => 'CUSTOMER.DISPUTE.CREATED',
            'resource' => [
                'dispute_id' => 'PP-D-1',
                'custom_id' => PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    $advertiser->id,
                    'PP-DSP'
                ),
                'disputed_transactions' => [[
                    'seller_transaction_id' => 'CAP-PO-DSP',
                    'invoice_number' => 'PP-DSP',
                ]],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNull($order->fresh()->paypal_refund_id);
        Mail::assertQueued(PaypalExternalPaymentNotice::class, function (PaypalExternalPaymentNotice $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->kind === PaypalExternalPaymentNotice::KIND_DISPUTE_CREATED;
        });
    }
}
