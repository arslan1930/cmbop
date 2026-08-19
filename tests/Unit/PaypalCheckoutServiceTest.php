<?php

namespace Tests\Unit;

use App\Services\PaypalCheckoutService;
use App\Support\UserMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PaypalCheckoutServiceTest extends TestCase
{
    private PaypalCheckoutService $paypal;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->enablePaypal();
        $this->paypal = new PaypalCheckoutService;
    }

    private function enablePaypal(array $overrides = []): void
    {
        config([
            'services.paypal.enabled' => $overrides['enabled'] ?? true,
            'services.paypal.mode' => $overrides['mode'] ?? 'sandbox',
            'services.paypal.client_id' => $overrides['client_id'] ?? 'paypal-client-test',
            'services.paypal.secret' => $overrides['secret'] ?? 'paypal-secret-test',
            'services.paypal.webhook_id' => $overrides['webhook_id'] ?? 'WH-TEST-1',
            'services.paypal.base_url' => $overrides['base_url'] ?? null,
        ]);
    }

    private function fakePaypal(array $handlers = []): void
    {
        Http::fake(function ($request) use ($handlers) {
            $url = $request->url();

            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }

            foreach ($handlers as $needle => $response) {
                if (str_contains($url, $needle)) {
                    return $response;
                }
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });
    }

    public function test_configured_requires_kill_switch_and_credentials(): void
    {
        $this->enablePaypal(['enabled' => false]);
        $this->assertFalse((new PaypalCheckoutService)->configured());

        $this->enablePaypal(['enabled' => 'off']);
        $this->assertFalse((new PaypalCheckoutService)->configured());

        $this->enablePaypal(['secret' => '']);
        $this->assertFalse((new PaypalCheckoutService)->configured());

        $this->enablePaypal(['client_id' => 'your-client-id']);
        $this->assertFalse((new PaypalCheckoutService)->configured());

        $this->enablePaypal(['enabled' => null]);
        $this->assertTrue((new PaypalCheckoutService)->configured());

        $this->enablePaypal();
        $this->assertTrue((new PaypalCheckoutService)->configured());
    }

    public function test_base_url_follows_mode_not_live_keys_on_sandbox(): void
    {
        $this->enablePaypal(['mode' => 'sandbox']);
        $this->assertSame('https://api-m.sandbox.paypal.com', (new PaypalCheckoutService)->baseUrl());

        $this->enablePaypal(['mode' => 'live']);
        $this->assertSame('https://api-m.paypal.com', (new PaypalCheckoutService)->baseUrl());

        $this->enablePaypal(['mode' => 'unexpected']);
        $this->assertSame('https://api-m.sandbox.paypal.com', (new PaypalCheckoutService)->baseUrl());
    }

    public function test_create_order_posts_capture_intent_and_returns_approve_url(): void
    {
        $this->fakePaypal([
            '/v2/checkout/orders' => Http::response([
                'id' => 'PO-1',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PO-1'],
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PO-1'],
                ],
            ], 201),
        ]);

        $created = $this->paypal->createOrder(19.99, [
            'type' => PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
            'user_id' => 7,
            'reference_code' => 'REF-PP-1',
        ], 'https://app.test/paypal/return', 'https://app.test/paypal/cancel');

        $this->assertSame('PO-1', $created['id']);
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=PO-1', $created['approve_url']);
        $this->assertSame('19.99', $created['amount']);

        Http::assertSent(function ($request) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return false;
            }

            $body = $request->data();

            return ($body['intent'] ?? null) === 'CAPTURE'
                && ($body['purchase_units'][0]['amount']['currency_code'] ?? null) === 'EUR'
                && ($body['purchase_units'][0]['amount']['value'] ?? null) === '19.99'
                && ($body['purchase_units'][0]['invoice_id'] ?? null) === 'REF-PP-1'
                && ($body['purchase_units'][0]['custom_id'] ?? null) === 'order_checkout:7:REF-PP-1'
                && ($body['application_context']['user_action'] ?? null) === 'PAY_NOW'
                && ($body['application_context']['shipping_preference'] ?? null) === 'NO_SHIPPING'
                && str_contains($request->url(), 'api-m.sandbox.paypal.com');
        });
    }

    public function test_create_order_fails_closed_when_disabled(): void
    {
        $this->enablePaypal(['enabled' => false]);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PayPal is not configured.');

        (new PaypalCheckoutService)->createOrder(10, [
            'user_id' => 1,
            'reference_code' => 'REF',
        ], 'https://app.test/ok', 'https://app.test/no');
    }

    public function test_create_order_requires_user_and_reference(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('user_id and reference_code');

        $this->paypal->createOrder(10, [
            'user_id' => '',
            'reference_code' => 'REF',
        ], 'https://app.test/ok', 'https://app.test/no');
    }

    public function test_capture_order_reads_amount_from_paypal_not_client(): void
    {
        $this->fakePaypal([
            '/v2/checkout/orders/PO-99/capture' => Http::response([
                'id' => 'PO-99',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'custom_id' => 'order_checkout:4:REF-CAP',
                    'amount' => ['currency_code' => 'EUR', 'value' => '10.00'],
                    'payments' => [
                        'captures' => [[
                            'id' => 'CAP-99',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'EUR', 'value' => '42.50'],
                        ]],
                    ],
                ]],
            ], 201),
        ]);

        $captured = $this->paypal->captureOrder('PO-99');

        $this->assertSame('CAP-99', $captured['capture_id']);
        $this->assertSame(42.5, $captured['amount']);
        $this->assertSame('4', $captured['custom']['user_id']);
        $this->assertSame('REF-CAP', $captured['custom']['reference_code']);
        $this->assertSame('order_checkout', $captured['custom']['type']);
    }

    public function test_capture_order_rejects_incomplete_and_missing_user(): void
    {
        $this->fakePaypal([
            '/v2/checkout/orders/PO-DECLINED/capture' => Http::response([
                'id' => 'PO-DECLINED',
                'status' => 'PAYER_ACTION_REQUIRED',
                'purchase_units' => [[
                    'custom_id' => 'order_checkout:1:REF',
                    'payments' => [
                        'captures' => [[
                            'id' => 'CAP-X',
                            'status' => 'DECLINED',
                            'amount' => ['currency_code' => 'EUR', 'value' => '10.00'],
                        ]],
                    ],
                ]],
            ], 201),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not completed');
        $this->paypal->captureOrder('PO-DECLINED');
    }

    public function test_capture_order_rejects_missing_user_id_in_custom_id(): void
    {
        $this->fakePaypal([
            '/v2/checkout/orders/PO-NOUSER/capture' => Http::response([
                'id' => 'PO-NOUSER',
                'purchase_units' => [[
                    'custom_id' => 'order_checkout',
                    'payments' => [
                        'captures' => [[
                            'id' => 'CAP-NOUSER',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'EUR', 'value' => '10.00'],
                        ]],
                    ],
                ]],
            ], 201),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing user_id');
        $this->paypal->captureOrder('PO-NOUSER');
    }

    public function test_capture_order_reuses_existing_capture_when_already_captured(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }

            if (str_contains($url, '/v2/checkout/orders/PO-DUP/capture')) {
                return Http::response([
                    'name' => 'UNPROCESSABLE_ENTITY',
                    'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']],
                ], 422);
            }

            if (str_contains($url, '/v2/checkout/orders/PO-DUP')) {
                return Http::response([
                    'id' => 'PO-DUP',
                    'status' => 'COMPLETED',
                    'purchase_units' => [[
                        'custom_id' => 'order_checkout:8:REF-DUP',
                        'payments' => [
                            'captures' => [[
                                'id' => 'CAP-DUP',
                                'status' => 'COMPLETED',
                                'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
                            ]],
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });

        $captured = $this->paypal->captureOrder('PO-DUP');
        $this->assertSame('CAP-DUP', $captured['capture_id']);
        $this->assertSame(25.0, $captured['amount']);
        $this->assertSame('8', $captured['custom']['user_id']);
    }

    public function test_refund_capture_posts_euro_amount(): void
    {
        $this->fakePaypal([
            '/v2/payments/captures/CAP-1/refund' => Http::response([
                'id' => 'RF-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '10.00'],
            ], 201),
        ]);

        $refund = $this->paypal->refundCapture('CAP-1', 10);

        $this->assertSame('RF-1', $refund['id']);
        $this->assertSame(10.0, $refund['amount']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/payments/captures/CAP-1/refund')) {
                return false;
            }

            $body = $request->data();

            return ($body['amount']['currency_code'] ?? null) === 'EUR'
                && ($body['amount']['value'] ?? null) === '10.00';
        });
    }

    public function test_refund_capture_treats_already_refunded_as_success(): void
    {
        $this->fakePaypal([
            '/v2/payments/captures/CAP-DONE/refund' => Http::response([
                'name' => 'UNPROCESSABLE_ENTITY',
                'details' => [['issue' => 'CAPTURE_FULLY_REFUNDED']],
            ], 422),
        ]);

        $refund = $this->paypal->refundCapture('CAP-DONE', 10);

        $this->assertSame('already-CAP-DONE', $refund['id']);
        $this->assertSame(10.0, $refund['amount']);
    }

    public function test_verify_webhook_success(): void
    {
        $this->fakePaypal([
            '/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $request = Request::create('/api/paypal/webhook', 'POST', [], [], [], [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-1',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-08-18T12:00:00Z',
        ], json_encode([
            'id' => 'WH-EVT-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        ], JSON_THROW_ON_ERROR));

        $ok = $this->paypal->verifyWebhook($request);
        $this->assertTrue($ok['verified']);
        $this->assertSame('WH-EVT-1', $ok['event']['id']);
    }

    public function test_verify_webhook_failure_status_is_not_verified(): void
    {
        $this->fakePaypal([
            '/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $request = Request::create('/api/paypal/webhook', 'POST', [], [], [], [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-1',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-08-18T12:00:00Z',
        ], json_encode([
            'id' => 'WH-EVT-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        ], JSON_THROW_ON_ERROR));

        $bad = $this->paypal->verifyWebhook($request);
        $this->assertFalse($bad['verified']);
        $this->assertSame('FAILURE', $bad['verification_status']);
    }

    public function test_verify_webhook_rejects_non_paypal_cert_host(): void
    {
        Http::fake();

        $request = Request::create('/api/paypal/webhook', 'POST', [], [], [], [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://evil.example/cert.pem',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-08-18T12:00:00Z',
        ], '{"id":"WH-EVT-2"}');

        $result = $this->paypal->verifyWebhook($request);
        $this->assertFalse($result['verified']);
        $this->assertSame('invalid_cert_url', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_oauth_uses_live_host_when_mode_is_live(): void
    {
        $this->enablePaypal(['mode' => 'live']);
        Cache::flush();
        $this->fakePaypal();

        (new PaypalCheckoutService)->accessToken();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://api-m.paypal.com/v1/oauth2/token')
                && $request->hasHeader('Authorization');
        });
    }

    public function test_oauth_strips_wrapped_quotes_from_credentials(): void
    {
        $this->enablePaypal([
            'client_id' => '"paypal-client-test"',
            'secret' => "'paypal-secret-test'",
        ]);
        Cache::flush();
        $this->fakePaypal();

        $this->assertTrue((new PaypalCheckoutService)->configured());
        (new PaypalCheckoutService)->accessToken();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v1/oauth2/token')) {
                return false;
            }
            $auth = (string) $request->header('Authorization')[0];
            $expected = 'Basic '.base64_encode('paypal-client-test:paypal-secret-test');

            return $auth === $expected;
        });
    }

    public function test_oauth_401_explains_sandbox_versus_live_keys(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Client Authentication failed',
            ], 401),
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
        ]);

        try {
            (new PaypalCheckoutService)->accessToken();
            $this->fail('Expected a PayPal OAuth exception.');
        } catch (RuntimeException $e) {
            $this->assertSame(UserMessages::get('payment.paypal_auth'), $e->getMessage());
        }
    }

    public function test_oauth_401_when_keys_work_on_live_tells_you_to_switch_mode(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_live',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        try {
            (new PaypalCheckoutService)->accessToken();
            $this->fail('Expected a PayPal OAuth exception.');
        } catch (RuntimeException $e) {
            $this->assertSame(UserMessages::get('payment.paypal_auth_live_keys'), $e->getMessage());
        }
    }

    public function test_oauth_strips_interior_whitespace_from_credentials(): void
    {
        $this->enablePaypal([
            'client_id' => "paypal-\u{00A0}client-test",
            'secret' => 'paypal secret test',
        ]);
        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        (new PaypalCheckoutService)->accessToken();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v1/oauth2/token')) {
                return false;
            }
            $auth = (string) $request->header('Authorization')[0];
            $expected = 'Basic '.base64_encode('paypal-client-test:paypalsecrettest');

            return $auth === $expected;
        });
    }

    public function test_connection_snapshot_does_not_include_the_secret(): void
    {
        $snap = (new PaypalCheckoutService)->connectionSnapshot();

        $this->assertSame('sandbox', $snap['mode']);
        $this->assertSame('https://api-m.sandbox.paypal.com', $snap['host']);
        $this->assertTrue($snap['configured']);
        $this->assertTrue($snap['client_id_set']);
        $this->assertTrue($snap['secret_set']);
        $this->assertSame(strlen('paypal-secret-test'), $snap['secret_length']);
        $this->assertStringStartsWith('paypal', $snap['client_id_hint']);
        $this->assertStringNotContainsString('paypal-secret-test', json_encode($snap));
    }

    public function test_oauth_cache_does_not_reuse_token_after_secret_rotation(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::sequence()
                ->push(['access_token' => 'tok_test', 'expires_in' => 300, 'token_type' => 'Bearer'], 200)
                ->push(['access_token' => 'tok_rotated', 'expires_in' => 300, 'token_type' => 'Bearer'], 200),
        ]);

        $first = (new PaypalCheckoutService)->accessToken();
        $this->assertSame('tok_test', $first);
        $this->assertSame('tok_test', (new PaypalCheckoutService)->accessToken());
        Http::assertSentCount(1);

        $this->enablePaypal(['secret' => 'paypal-secret-rotated']);
        $this->assertSame('tok_rotated', (new PaypalCheckoutService)->accessToken());
        Http::assertSentCount(2);
    }

    public function test_format_euros_and_custom_id_helpers(): void
    {
        $this->assertSame('19.99', PaypalCheckoutService::formatEuros(19.99));
        $this->assertSame('10.00', PaypalCheckoutService::formatEuros(10));
        $this->assertSame(
            'order_checkout:9:REF-A',
            PaypalCheckoutService::customId('order_checkout', 9, 'REF-A')
        );
        $this->assertSame(
            ['type' => 'wallet_deposit', 'user_id' => '3', 'reference_code' => 'DEP-1'],
            PaypalCheckoutService::parseCustomId('wallet_deposit:3:DEP-1')
        );
    }

    public function test_capture_from_webhook_event_reads_capture_resource(): void
    {
        $captured = $this->paypal->captureFromWebhookEvent([
            'id' => 'WH-CAP-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-WH-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '42.50'],
                'custom_id' => 'order_checkout:4:PP-WH',
                'supplementary_data' => [
                    'related_ids' => ['order_id' => 'PO-WH-1'],
                ],
            ],
        ]);

        $this->assertSame('PO-WH-1', $captured['id']);
        $this->assertSame('CAP-WH-1', $captured['capture_id']);
        $this->assertSame(42.5, $captured['amount']);
        $this->assertSame('4', $captured['custom']['user_id']);
        $this->assertSame('PP-WH', $captured['custom']['reference_code']);
    }

    public function test_refund_from_webhook_event_reads_related_ids(): void
    {
        $refunded = $this->paypal->refundFromWebhookEvent([
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'RF-WH-1',
                'custom_id' => 'order_checkout:4:PP-WH',
                'supplementary_data' => [
                    'related_ids' => [
                        'capture_id' => 'CAP-WH-1',
                        'order_id' => 'PO-WH-1',
                    ],
                ],
            ],
        ]);

        $this->assertSame('RF-WH-1', $refunded['refund_id']);
        $this->assertSame('CAP-WH-1', $refunded['capture_id']);
        $this->assertSame('PO-WH-1', $refunded['paypal_order_id']);
    }
}
