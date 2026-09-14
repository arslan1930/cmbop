<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\StripeCustomerService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class PaymentFlowLeftoverErrorTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_payment_leftover';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config([
            'services.paypal.client_id' => 'leftover-paypal-client',
            'services.paypal.secret' => 'leftover-paypal-secret',
            'services.stripe.webhook_secret' => $this->webhookSecret,
        ]);
    }

    private function advertiser(): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_paypal_return_and_cancel_survive_dropped_orders_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-LEFT',
                'token' => 'PO-LEFT',
            ]))
            ->assertRedirect(route('advertiser.checkout'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.cancel', ['ref' => 'PP-LEFT']))
            ->assertRedirect();
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_orders_pay_again_cancel_survives_dropped_orders_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->get(route('advertiser.orders', ['retry' => 'canceled', 'ref' => 'CARD-LEFT']))
            ->assertOk()
            ->assertDontSee('SQLSTATE');
    }

    public function test_invoice_survives_dropped_deposit_and_order_tables(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('deposit_requests');
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->get(route('advertiser.invoice', 'REF-LEFT'))
            ->assertRedirect();
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_stripe_webhook_survives_dropped_log_table(): void
    {
        Schema::dropIfExists('stripe_webhook_logs');

        $event = [
            'id' => 'evt_leftover_log',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_leftover',
                    'object' => 'checkout.session',
                    'metadata' => [
                        'type' => 'wallet_deposit',
                        'user_id' => '1',
                    ],
                ],
            ],
        ];
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
            ],
            $payload
        )
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_mark_paid_survives_missing_user_marked_paid_at_column(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'MARK503',
            'amount' => 40,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);

        if (! Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
            $this->markTestSkipped('deposit_requests.user_marked_paid_at is already absent');
        }

        try {
            Schema::table('deposit_requests', function (Blueprint $table) {
                $table->dropColumn('user_marked_paid_at');
            });
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop user_marked_paid_at on this driver');
        }

        if (Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
            $this->markTestSkipped('user_marked_paid_at is still present after drop');
        }

        try {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.add-funds.mark-paid', $deposit), [
                    'user_payment_note' => 'WISE-LEFT',
                ])
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');
        } finally {
            if (! Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
                Schema::table('deposit_requests', function (Blueprint $table) {
                    $table->timestamp('user_marked_paid_at')->nullable();
                });
            }
        }
    }

    public function test_mark_paid_survives_dropped_deposit_requests_table(): void
    {
        $advertiser = $this->advertiser();

        try {
            Schema::dropIfExists('deposit_requests');
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.add-funds.mark-paid', 1))
                ->assertNotFound()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Invoice not found.')
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE')
                ->assertDontSee('App\\Models')
                ->assertDontSee('DepositRequest');
        } finally {
            $this->restoreDepositRequestsTable();
        }
    }

    public function test_cancel_survives_dropped_deposit_requests_table(): void
    {
        $advertiser = $this->advertiser();

        try {
            Schema::dropIfExists('deposit_requests');
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.add-funds.cancel', 1))
                ->assertNotFound()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Invoice not found.')
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE')
                ->assertDontSee('App\\Models')
                ->assertDontSee('DepositRequest');
        } finally {
            $this->restoreDepositRequestsTable();
        }
    }

    public function test_orders_ajax_survives_dropped_orders_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.statistics'))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', 1))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_payment_methods_index_survives_leftover_card_lookup(): void
    {
        $advertiser = $this->advertiser();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('listCards')->andThrow(new QueryException(
                'sqlite',
                'select * from users',
                [],
                new \PDOException('SQLSTATE[42S22]: Unknown column "stripe_customer_id"')
            ));
        });

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.payment-methods.index'))
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('configured', false)
            ->assertJsonPath('cards', [])
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_checkout_process_survives_dropped_orders_table(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $this->fundAdvertiserWallet($advertiser, 500);
        [$site, $submission] = $this->readyCheckoutCart($advertiser);

        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'WALLET-LEFT',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_checkout_process_survives_dropped_wallets_table(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        [$site, $submission] = $this->readyCheckoutCart($advertiser);

        Schema::dropIfExists('wallets');

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'WALLET-NOWALLET',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_scheduled_orders_survives_dropped_orders_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders'))
            ->assertRedirect(route('advertiser.orders'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_dashboard_survives_dropped_orders_and_wallets_tables(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');
        Schema::dropIfExists('wallets');

        $response = $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'));

        $this->assertContains($response->status(), [200, 302]);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        if ($response->isRedirect()) {
            $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
        }
    }

    public function test_reports_survive_dropped_order_and_deposit_tables(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');
        Schema::dropIfExists('deposit_requests');

        try {
            $this->actingAs($advertiser)
                ->get(route('advertiser.reports'))
                ->assertRedirect(route('advertiser.dashboard'));
            $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));

            $this->actingAs($advertiser)
                ->getJson(route('advertiser.reports.statistics'))
                ->assertStatus(500)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');

            $this->actingAs($advertiser)
                ->getJson(route('advertiser.reports.orders'))
                ->assertStatus(500)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');

            $this->actingAs($advertiser)
                ->getJson(route('advertiser.reports.funds'))
                ->assertStatus(500)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');
        } finally {
            $this->restoreDepositRequestsTable();
        }
    }

    /**
     * @return array{0: Site, 1: ContentSubmission}
     */
    private function readyCheckoutCart(User $advertiser): array
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leftover Checkout Site',
            'site_url' => 'https://leftover-checkout.example',
            'domain' => 'leftover-checkout.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80.00,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Leftover checkout probe',
            'verified' => true,
            'active' => true,
        ]);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        return [$site, $submission];
    }

    private function restoreDepositRequestsTable(): void
    {
        if (Schema::hasTable('deposit_requests')) {
            return;
        }

        foreach ([
            'database/migrations/2026_04_21_115734_create_deposit_requests_table.php',
            'database/migrations/2026_04_22_113004_add_stripe_fields_to_deposit_requests_table.php',
            'database/migrations/2026_07_21_140000_add_user_marked_paid_to_deposit_requests.php',
            'database/migrations/2026_08_14_160000_unique_deposit_stripe_ids.php',
            'database/migrations/2026_08_18_160000_add_paypal_columns_to_deposit_requests.php',
        ] as $path) {
            $this->artisan('migrate', [
                '--path' => $path,
                '--force' => true,
            ]);
        }
    }
}
