<?php

namespace Tests\Feature;

use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\PaypalPaymentNotCompleted;
use App\Mail\SiteOwnerOrderNotification;
use App\Mail\UnfulfilledCheckoutCredited;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OrderPaymentService;
use App\Services\PaypalCheckoutService;
use App\Support\UserMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutPaypalProcessTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

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

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $domain = 'paypal-test.example'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'PayPal Test Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
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
            'description' => 'Test site for PayPal checkout',
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
     * Http::fake() merges callbacks and keeps the first match. Later calls
     * only update this state so capture can use the real package amount.
     *
     * @var array<string, mixed>
     */
    private array $paypalHttp = [];

    /**
     * @param  array<string, mixed>  $capture
     */
    private function fakePaypalCheckout(string $orderId, array $capture = []): void
    {
        $this->paypalHttp['order_id'] = $orderId;
        $this->paypalHttp['capture'] = array_replace($this->paypalHttp['capture'] ?? [], $capture);
        if (($this->paypalHttp['registered'] ?? false) === true) {
            return;
        }

        $this->paypalHttp['registered'] = true;
        $this->paypalHttp['capture_calls'] = 0;

        Http::fake(function ($request) {
            $orderId = (string) ($this->paypalHttp['order_id'] ?? '');
            $capture = is_array($this->paypalHttp['capture'] ?? null) ? $this->paypalHttp['capture'] : [];
            $captureId = (string) ($capture['id'] ?? 'CAP-'.$orderId);
            $status = (string) ($capture['status'] ?? 'COMPLETED');
            $userId = (string) ($capture['user_id'] ?? '0');
            $ref = (string) ($capture['reference_code'] ?? 'PP42');
            $alreadyCaptured = (bool) ($capture['already_captured'] ?? false);
            $amount = (string) ($capture['amount'] ?? '');
            if ($amount === '') {
                $package = $ref !== '' ? app(OrderPaymentService::class)->getPendingCheckout($ref) : null;
                $amount = is_array($package)
                    ? number_format((float) ($package['amount_due'] ?? 0), 2, '.', '')
                    : '100.00';
                if ((float) $amount < 0.01) {
                    $amount = '100.00';
                }
            }

            $url = $request->url();

            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
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
                            'id' => $captureId,
                            'status' => $status,
                            'amount' => ['currency_code' => 'EUR', 'value' => $amount],
                        ]],
                    ],
                ]],
            ];

            if (str_contains($url, '/v2/checkout/orders/'.$orderId.'/capture')) {
                $this->paypalHttp['capture_calls'] = (int) ($this->paypalHttp['capture_calls'] ?? 0) + 1;
                if ($alreadyCaptured && $this->paypalHttp['capture_calls'] > 1) {
                    return Http::response([
                        'name' => 'UNPROCESSABLE_ENTITY',
                        'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']],
                    ], 422);
                }

                return Http::response($completed, 201);
            }

            if (str_contains($url, '/v2/checkout/orders/'.$orderId)) {
                return Http::response($completed, 200);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });
    }

    private function fakePaypalCreateOrder(string $orderId = 'PO-CHECKOUT-1'): void
    {
        $this->fakePaypalCheckout($orderId);
    }

    public function test_checkout_page_shows_paypal_tile_disabled_when_not_configured(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('data-method="paypal"', false)
            ->assertSee('data-paypal-disabled="1"', false)
            ->assertSee('PayPal is not configured', false);
    }

    public function test_checkout_page_enables_paypal_tile_when_configured(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('data-method="paypal"', false)
            ->assertDontSee('data-paypal-disabled="1"', false)
            ->assertDontSee('id="paypalNotConfiguredAlert"', false)
            ->assertSee('Secure PayPal checkout', false);
    }

    public function test_process_order_rejects_paypal_when_not_configured(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

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
                'reference_code' => 'PP-OFF',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::where('reference_code', 'PP-OFF')->count());
    }

    public function test_process_order_paypal_oauth_401_is_not_generic_order_error(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
        ]);

        $this->postPaypalCheckout('PP-401')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', UserMessages::get('payment.paypal_auth'))
            ->assertJsonMissing(['message' => 'We could not process your order. Please try again.']);

        $this->assertSame(0, Order::where('reference_code', 'PP-401')->count());
    }

    public function test_process_order_paypal_connection_error_is_not_generic_order_error(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 7: Failed to connect to api-m.sandbox.paypal.com port 443: Connection refused'
            );
        });

        $this->postPaypalCheckout('PP-CONN')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', UserMessages::get('payment.paypal_unavailable'))
            ->assertJsonMissing(['message' => 'We could not process your order. Please try again.'])
            ->assertJsonMissing(['message' => 'Failed to start PayPal checkout. Please try again.']);
    }

    public function test_process_order_paypal_uses_live_host_when_sandbox_oauth_is_rejected(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Http::fake(function ($request) {
            $url = $request->url();
            if ($url === 'https://api-m.sandbox.paypal.com/v1/oauth2/token') {
                return Http::response(['error' => 'invalid_client'], 401);
            }
            if ($url === 'https://api-m.paypal.com/v1/oauth2/token') {
                return Http::response([
                    'access_token' => 'tok_live',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }
            if (str_starts_with($url, 'https://api-m.paypal.com/v2/checkout/orders')) {
                return Http::response([
                    'id' => 'PO-LIVE-FALLBACK',
                    'status' => 'CREATED',
                    'links' => [
                        ['rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=PO-LIVE-FALLBACK'],
                    ],
                ], 201);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });

        $this->postPaypalCheckout('PP-LIVE', [], 'https://checkout.example')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('paypal_order_id', 'PO-LIVE-FALLBACK')
            ->assertJsonPath('checkout_url', 'https://www.paypal.com/checkoutnow?token=PO-LIVE-FALLBACK');

        Http::assertSent(function ($request) {
            if (! str_starts_with($request->url(), 'https://api-m.paypal.com/v2/checkout/orders')) {
                return false;
            }
            $body = $request->data();

            return str_starts_with((string) ($body['application_context']['return_url'] ?? ''), 'https://checkout.example/');
        });
        $this->assertSame(0, Order::where('reference_code', 'PP-LIVE')->count());
    }

    /**
     * @param  array<string, string>  $server
     * @return TestResponse
     */
    private function postPaypalCheckout(string $reference, array $server = [], ?string $origin = null)
    {
        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher(), 'paypal-'.$reference.'.example');
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $pending = $this->actingAs($advertiser);
        $processUrl = route('advertiser.checkout.process');
        if (is_string($origin) && $origin !== '') {
            $host = (string) parse_url($origin, PHP_URL_HOST);
            $https = parse_url($origin, PHP_URL_SCHEME) === 'https';
            $server = array_merge([
                'HTTP_HOST' => $host,
                'SERVER_NAME' => $host,
                'HTTPS' => $https ? 'on' : 'off',
                'SERVER_PORT' => $https ? '443' : '80',
            ], $server);
            $processUrl = rtrim($origin, '/').route('advertiser.checkout.process', [], false);
        }
        if ($server !== []) {
            $pending = $pending->withServerVariables($server);
        }

        return $pending
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'price' => 100,
                ]],
            ])
            ->postJson($processUrl, [
                'payment_method' => 'paypal',
                'reference_code' => $reference,
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ]);
    }

    public function test_process_order_paypal_creates_approve_url_without_order_rows(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        $this->fakePaypalCreateOrder('PO-CHECKOUT-42');

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'price' => 9999,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP42',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'requires_payment' => true,
                'reference_code' => 'PP42',
                'paypal_order_id' => 'PO-CHECKOUT-42',
            ])
            ->assertJsonPath('checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=PO-CHECKOUT-42');

        $this->assertSame(0, Order::where('reference_code', 'PP42')->count());
        $package = app(OrderPaymentService::class)->getPendingCheckout('PP42');
        $this->assertIsArray($package);
        $this->assertSame('paypal', $package['payment_method'] ?? null);
        $this->assertSame('PO-CHECKOUT-42', $package['paypal_order_id'] ?? null);
        $this->assertSame('PP42', session('pending_paypal_reference'));
        $this->assertNotNull(Cache::get('pending_card_checkout:PP42'));

        Http::assertSent(function ($request) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return false;
            }

            $body = $request->data();

            return ($body['intent'] ?? null) === 'CAPTURE'
                && ($body['purchase_units'][0]['invoice_id'] ?? null) === 'PP42'
                && ($body['purchase_units'][0]['custom_id'] ?? null) === PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    auth()->id(),
                    'PP42'
                )
                && str_contains((string) ($body['application_context']['return_url'] ?? ''), '/advertiser/checkout/paypal/return')
                && str_contains((string) ($body['application_context']['cancel_url'] ?? ''), '/advertiser/checkout/paypal/cancel');
        });
    }

    public function test_process_order_paypal_is_not_fund_wallet_first(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        $this->fakePaypalCreateOrder();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

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
                'reference_code' => 'PP-RAIL',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk()
            ->assertJsonMissing(['code' => 'fund_wallet_first']);
    }

    public function test_paypal_cancel_route_returns_to_checkout(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->get(route('advertiser.checkout.paypal.cancel', ['ref' => 'PP-CAN']))
            ->assertRedirect(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'PP-CAN']));

        Mail::assertNothingQueued();
    }

    public function test_paypal_cancel_after_pending_checkout_emails_advertiser(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $this->fakePaypalCheckout('PO-CAN-MAIL', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-CAN-MAIL',
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
                'reference_code' => 'PP-CAN-MAIL',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.cancel', ['ref' => 'PP-CAN-MAIL']))
            ->assertRedirect(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'PP-CAN-MAIL']));

        Mail::assertQueued(PaypalPaymentNotCompleted::class, function (PaypalPaymentNotCompleted $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->kind === PaypalPaymentNotCompleted::KIND_CHECKOUT
                && $mail->reason === PaypalPaymentNotCompleted::REASON_CANCELLED
                && $mail->referenceCode === 'PP-CAN-MAIL';
        });
        Mail::assertQueued(PaypalPaymentNotCompleted::class, 1);
        Mail::assertNotQueued(PaymentSuccessfulInvoiceMail::class);
        $this->assertSame(0, Order::where('reference_code', 'PP-CAN-MAIL')->count());
    }

    public function test_paypal_return_settles_paid_orders(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $this->fakePaypalCheckout('PO-OK', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-OK',
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
                'reference_code' => 'PP-OK',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-OK',
                'token' => 'PO-OK',
            ]))
            ->assertRedirect(route('advertiser.orders'))
            ->assertSessionHas('success');

        $orders = Order::where('reference_code', 'PP-OK')->where('payment_method', 'paypal')->get();
        $this->assertCount(1, $orders);
        $this->assertSame('paid', $orders[0]->payment_status);
        $this->assertSame('CAP-PO-OK', $orders[0]->paypal_capture_id);
        $this->assertTrue($orders[0]->paidViaPaypal());
        $this->assertNull(app(OrderPaymentService::class)->getPendingCheckout('PP-OK'));
        Mail::assertQueued(PaymentSuccessfulInvoiceMail::class, 1);
        Mail::assertQueued(SiteOwnerOrderNotification::class, 1);
    }

    public function test_paypal_return_is_idempotent_on_duplicate(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ]);

        $this->fakePaypalCheckout('PO-DUP', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-DUP',
        ]);
        $this->postJson(route('advertiser.checkout.process'), [
            'payment_method' => 'paypal',
            'reference_code' => 'PP-DUP',
            'publication_mode' => 'immediate',
            'content_submissions' => [
                $site->id => [$sub->id],
            ],
        ])->assertOk();

        $this->fakePaypalCheckout('PO-DUP', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-DUP',
            'already_captured' => true,
        ]);

        $first = $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', ['ref' => 'PP-DUP', 'token' => 'PO-DUP']));
        $first->assertRedirect(route('advertiser.orders'));

        $second = $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', ['ref' => 'PP-DUP', 'token' => 'PO-DUP']));
        $second->assertRedirect(route('advertiser.orders'));

        $this->assertSame(1, Order::where('reference_code', 'PP-DUP')->where('payment_method', 'paypal')->count());
    }

    public function test_paypal_return_fails_when_capture_is_declined(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->fakePaypalCheckout('PO-FAIL', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-FAIL',
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
                'reference_code' => 'PP-FAIL',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $this->fakePaypalCheckout('PO-FAIL', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-FAIL',
            'status' => 'DECLINED',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-FAIL',
                'token' => 'PO-FAIL',
            ]))
            ->assertRedirect(route('advertiser.checkout'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::where('reference_code', 'PP-FAIL')->count());
        Mail::assertQueued(PaypalPaymentNotCompleted::class, function (PaypalPaymentNotCompleted $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && $mail->reason === PaypalPaymentNotCompleted::REASON_DECLINED
                && $mail->referenceCode === 'PP-FAIL';
        });
        Mail::assertQueued(PaypalPaymentNotCompleted::class, 1);
        Mail::assertNotQueued(PaymentSuccessfulInvoiceMail::class);
    }

    public function test_paypal_return_rejects_missing_token(): void
    {
        $this->enablePaypal();
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', ['ref' => 'PP-NONE']))
            ->assertRedirect(route('advertiser.checkout'))
            ->assertSessionHas('error', 'Invalid PayPal return.');
    }

    public function test_paypal_cancel_after_paid_goes_to_orders(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->fakePaypalCheckout('PO-PAID', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-PAID',
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
                'reference_code' => 'PP-PAID',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', ['ref' => 'PP-PAID', 'token' => 'PO-PAID']))
            ->assertRedirect(route('advertiser.orders'));

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.cancel', ['ref' => 'PP-PAID']))
            ->assertRedirect(route('advertiser.orders'))
            ->assertSessionHas('success');
        $this->assertSame(1, Order::where('reference_code', 'PP-PAID')->where('payment_status', 'paid')->count());
        Mail::assertNotQueued(PaypalPaymentNotCompleted::class);
    }

    public function test_paypal_return_rejects_mismatched_token(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->fakePaypalCheckout('PO-MATCH', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-TOKEN',
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
                'reference_code' => 'PP-TOKEN',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-TOKEN',
                'token' => 'PO-OTHER',
            ]))
            ->assertRedirect(route('advertiser.checkout'))
            ->assertSessionHas('error', 'PayPal order does not match this checkout.');

        $this->assertSame(0, Order::where('reference_code', 'PP-TOKEN')->count());
        $this->assertNotNull(app(OrderPaymentService::class)->getPendingCheckout('PP-TOKEN'));
    }

    public function test_paypal_return_credits_wallet_when_capture_amount_mismatches(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->fakePaypalCheckout('PO-AMT', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-AMT',
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
                'reference_code' => 'PP-AMT',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk();

        $this->fakePaypalCheckout('PO-AMT', ['amount' => '50.00']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-AMT',
                'token' => 'PO-AMT',
            ]))
            ->assertRedirect(route('advertiser.checkout'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::where('reference_code', 'PP-AMT')->count());
        $wallet = Wallet::query()
            ->where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertNotNull($wallet);
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);

        Mail::assertQueued(UnfulfilledCheckoutCredited::class, function (UnfulfilledCheckoutCredited $mail) use ($advertiser) {
            return (int) $mail->user->id === (int) $advertiser->id
                && abs($mail->amount - 50.0) < 0.01
                && $mail->paymentMethod === 'paypal'
                && $mail->notificationType === 'unfulfilled_checkout_credited';
        });
        Mail::assertQueued(UnfulfilledCheckoutCredited::class, 1);
        Mail::assertNotQueued(PaymentSuccessfulInvoiceMail::class);

        $this->assertTrue(InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('title', 'Wallet topped up — €50.00')
            ->where('meta->reason', 'unfulfilled_checkout')
            ->exists());

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-AMT',
                'token' => 'PO-AMT',
            ]))
            ->assertRedirect(route('advertiser.checkout'));

        Mail::assertQueued(UnfulfilledCheckoutCredited::class, 1);
        $this->assertEqualsWithDelta(50.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertSame(1, InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('meta->reason', 'unfulfilled_checkout')
            ->count());
    }

    public function test_paypal_return_stores_capture_id_on_first_line_only(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'paypal-a.example');
        $siteB = $this->activeSite($publisher, 'paypal-b.example');
        $subA = $this->createApprovedSubmission($advertiser, $siteA->id);
        $subB = $this->createApprovedSubmission($advertiser, $siteB->id, 1);

        $this->fakePaypalCheckout('PO-MULTI', [
            'user_id' => (string) $advertiser->id,
            'reference_code' => 'PP-MULTI',
        ]);
        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    [
                        'id' => $siteA->id,
                        'name' => $siteA->site_name,
                        'quantity' => 1,
                        'content_submission_id' => $subA->id,
                    ],
                    [
                        'id' => $siteB->id,
                        'name' => $siteB->site_name,
                        'quantity' => 1,
                        'content_submission_id' => $subB->id,
                    ],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP-MULTI',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $siteA->id => [$subA->id],
                    $siteB->id => [$subB->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-MULTI',
                'token' => 'PO-MULTI',
            ]))
            ->assertRedirect(route('advertiser.orders'));

        $orders = Order::query()
            ->where('reference_code', 'PP-MULTI')
            ->where('payment_method', 'paypal')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $orders);
        $this->assertSame('CAP-PO-MULTI', $orders[0]->paypal_capture_id);
        $this->assertNull($orders[1]->paypal_capture_id);
        $this->assertSame('PO-MULTI', $orders[0]->paypal_order_id);
        $this->assertSame('PO-MULTI', $orders[1]->paypal_order_id);
        $this->assertTrue($orders[0]->paidViaPaypal());
        $this->assertTrue($orders[1]->paidViaPaypal());
    }
}
