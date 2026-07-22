<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ManualWalletFundingFlowTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_checkout_rejects_wise_bank_crypto_and_points_to_add_funds(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Fund Wallet Blog',
            'site_url' => 'https://fund-wallet.example',
            'domain' => 'fund-wallet.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Fund wallet site description text. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        foreach (['wise', 'bank', 'crypto'] as $method) {
            $response = $this->actingAs($advertiser)
                ->withSession([
                    'cart' => [['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1]],
                ])
                ->postJson(route('advertiser.checkout.process'), [
                    'payment_method' => $method,
                    'reference_code' => 'FW'.strtoupper(substr($method, 0, 3)),
                    'publication_mode' => 'immediate',
                    'content_submissions' => [
                        $site->id => [$sub->id],
                    ],
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'fund_wallet_first');
            $this->assertStringContainsString('add-funds', (string) $response->json('redirect_url'));
            $this->assertSame(0, Order::where('reference_code', 'FW'.strtoupper(substr($method, 0, 3)))->count());
        }
    }

    public function test_checkout_page_shows_fund_wallet_invoice_path(): void
    {
        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Checkout UI Blog',
            'site_url' => 'https://checkout-ui.example',
            'domain' => 'checkout-ui.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Checkout UI site description text. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('Paying by Bank, Wise, or crypto?', false)
            ->assertSee('Add funds &amp; get invoice', false)
            ->assertDontSee('data-method="wise"', false)
            ->assertDontSee('data-method="bank"', false);
    }

    public function test_add_funds_creates_invoice_for_wise_deposit(): void
    {
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);
        $advertiser = $this->advertiser();

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 100,
                'payment_method' => 'wise',
                'reference_code' => 'DEP123',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reference_code', 'DEP123');
        $this->assertStringContainsString('/advertiser/invoice/DEP123', (string) $response->json('invoice_url'));

        $this->assertDatabaseHas('deposit_requests', [
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP123',
            'payment_method' => 'wise',
            'status' => 'pending',
            'amount' => 100,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_PENDING,
            'audience' => InAppNotification::AUDIENCE_ADVERTISER,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'title' => 'Deposit submitted — €100.00',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.invoice', 'DEP123'))
            ->assertOk()
            ->assertSee('DEP123', false)
            ->assertSee('100', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.add-funds', ['amount' => 150, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('Manual funding', false)
            ->assertSee('prefillAmount', false); // ensure page loads with query (JS vars rendered)
    }

    public function test_add_funds_requires_billing_for_bank_invoice(): void
    {
        Mail::fake();
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'bank',
                'reference_code' => 'NOBILL1',
            ])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_billing', true);

        $this->assertSame(0, DepositRequest::where('reference_code', 'NOBILL1')->count());
    }
}
