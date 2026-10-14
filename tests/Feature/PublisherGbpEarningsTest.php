<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
use App\Services\CartPricingService;
use App\Support\ListingMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublisherGbpEarningsTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private int $publisherRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['rates' => ['USD' => 1.10, 'GBP' => 0.85]], 200),
        ]);
        config([
            'fx.fake_country' => '',
            'fx.force_display' => '',
            'fx.currencies.GBP.fallback' => 0.85,
            'fx.currencies.USD.fallback' => 1.10,
            'fx.conversion_buffer_percent' => 0.5,
        ]);

        $this->publisherRoleId = (int) Role::firstOrCreate(['name' => 'publisher'])->id;
        Role::firstOrCreate(['name' => 'advertiser']);
        Role::firstOrCreate(['name' => 'admin']);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $this->publisherRoleId,
            'country' => 'uk',
        ]);
        $this->publisher->roles()->attach($this->publisherRoleId);
    }

    public function test_gbp_listing_applies_buffer_only_to_advertiser_euros(): void
    {
        $site = $this->makeSite([
            'listing_currency' => 'GBP',
            'listing_price' => 85,
            'price' => 100,
        ]);

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);

        $this->assertSame('GBP', $pricing['publisher_currency']);
        $this->assertEquals(85.0, $pricing['publisher_amount']);
        $this->assertEquals(100.0, $pricing['publisher_price']);
        $this->assertEquals(0.5, $pricing['fx_buffer_percent']);
        $this->assertEqualsWithDelta(0.85, $pricing['fx_rate'], 0.001);
        $this->assertGreaterThan($pricing['publisher_price'], $pricing['advertiser_eur_amount']);
        $this->assertEqualsWithDelta(100.50, $pricing['base'] / (1 + 13 / 100), 0.05);
    }

    public function test_eur_listing_does_not_apply_buffer(): void
    {
        $site = $this->makeSite([
            'listing_currency' => 'EUR',
            'listing_price' => 100,
            'price' => 100,
        ]);

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);

        $this->assertSame('EUR', $pricing['publisher_currency']);
        $this->assertEquals(100.0, $pricing['publisher_amount']);
        $this->assertNull($pricing['fx_buffer_percent']);
        $this->assertNull($pricing['fx_rate']);
        $this->assertSame(113.0, $pricing['total']);
    }

    public function test_existing_uk_site_stays_eur_until_publisher_confirms_gbp(): void
    {
        $site = $this->makeSite([
            'country' => 'uk',
            'listing_currency' => 'EUR',
            'listing_price' => 100,
            'price' => 100,
            'listing_gbp_prompt_dismissed_at' => null,
        ]);

        $this->assertTrue($site->needsGbpListingPrompt());
        $this->assertSame('EUR', $site->listingCurrency());

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.keep-eur-listing', $site->id))
            ->assertRedirect();

        $site->refresh();
        $this->assertFalse($site->needsGbpListingPrompt());
        $this->assertSame('EUR', $site->listingCurrency());

        $site->listing_gbp_prompt_dismissed_at = null;
        $site->save();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.confirm-gbp-listing', $site->id), [
                'listing_price' => 85,
            ])
            ->assertRedirect();

        $site->refresh();
        $this->assertSame('GBP', $site->listingCurrency());
        $this->assertEquals(85.0, $site->listingBaseAmount());
        $this->assertFalse($site->needsGbpListingPrompt());
    }

    public function test_publisher_wallets_are_per_currency(): void
    {
        $eur = Wallet::lockOrCreateForRole($this->publisher->id, $this->publisherRoleId, 'EUR');
        $gbp = Wallet::lockOrCreateForRole($this->publisher->id, $this->publisherRoleId, 'GBP');

        $this->assertNotSame($eur->id, $gbp->id);
        $this->assertSame('EUR', $eur->currency);
        $this->assertSame('GBP', $gbp->currency);
        $this->assertCount(2, Wallet::forPublisherAll($this->publisher->id));
    }

    public function test_min_withdrawal_is_twenty_per_currency(): void
    {
        $listing = app(ListingMoney::class);
        $this->assertEquals(20.0, $listing->minWithdrawal('EUR'));
        $this->assertEquals(20.0, $listing->minWithdrawal('GBP'));
        $this->assertEquals(20.0, $listing->minWithdrawal('USD'));
    }

    public function test_finance_hub_separates_eur_held_from_gbp_owed(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiserRole->id,
            'balance' => 200,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $this->publisher->id,
            'role_id' => $this->publisherRoleId,
            'balance' => 40,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'GBP',
        ]);
        Withdrawal::create([
            'user_id' => $this->publisher->id,
            'amount' => 10,
            'fee' => 0,
            'net_amount' => 10,
            'status' => 'pending',
            'currency' => 'GBP',
            'payment_method' => 'bank',
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(200.0, $overview['listing_fx']['eur_held']);
        $this->assertEquals(40.0, $overview['listing_fx']['gbp_owed_wallets']);
        $this->assertEquals(10.0, $overview['listing_fx']['gbp_owed_withdrawals']);
        $this->assertEquals(50.0, $overview['listing_fx']['gbp_owed']);

        $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('EUR held vs GBP owed', false)
            ->assertSee('£40.00', false)
            ->assertSee('£10.00', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'GBP listing',
            'site_url' => 'https://gbp-listing.example',
            'domain' => 'gbp-listing.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 8000,
            'country' => 'uk',
            'language' => 'en',
            'countries' => ['uk'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'listing_currency' => 'EUR',
            'listing_price' => 100,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'GBP earnings listing.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }
}
