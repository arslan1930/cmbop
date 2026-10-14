<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
use App\Services\CartPricingService;
use App\Support\DepositFx;
use App\Support\ListingMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function buyerSiteBufferMatrix(): array
    {
        return [
            ['GBP', 'GBP', false],
            ['GBP', 'EUR', true],
            ['EUR', 'GBP', true],
            ['EUR', 'EUR', false],
            ['USD', 'GBP', true],
            ['USD', 'EUR', true],
        ];
    }

    #[DataProvider('buyerSiteBufferMatrix')]
    public function test_fx_buffer_follows_buyer_vs_listing_currency(string $buyerCurrency, string $listingCurrency, bool $expectBuffer): void
    {
        $listingPrice = $listingCurrency === 'GBP' ? 85.0 : 100.0;
        $site = $this->makeSite([
            'listing_currency' => $listingCurrency,
            'listing_price' => $listingPrice,
            'price' => 100,
        ]);
        $buyer = $this->makeAdvertiser($buyerCurrency);

        $pricing = app(CartPricingService::class)->priceForAdvertiser(
            $site,
            null,
            1,
            null,
            true,
            $buyerCurrency
        );

        $this->assertSame($listingCurrency, $pricing['publisher_currency']);
        $this->assertEquals($listingPrice, $pricing['publisher_amount']);
        $this->assertEquals(100.0, $pricing['publisher_price']);
        $this->assertEquals($expectBuffer ? 0.5 : 0.0, $pricing['fx_buffer_percent']);
        if ($listingCurrency === 'GBP') {
            $this->assertEqualsWithDelta(0.85, $pricing['fx_rate'], 0.001);
        } else {
            $this->assertNull($pricing['fx_rate']);
        }

        $expectedBase = $expectBuffer ? 113.57 : 113.0;
        $this->assertEquals($expectedBase, $pricing['base']);
        $this->assertEquals($expectedBase, $pricing['total']);
        $this->assertEquals($expectedBase, $pricing['advertiser_eur_amount']);

        $this->actingAs($buyer);
        $catalog = $site->fresh()->catalogPricesForViewer($buyer);
        $this->assertEquals($expectedBase, $catalog['list']);

        $checkout = app(CartPricingService::class)->buildCheckoutItems([
            ['id' => $site->id, 'quantity' => 1, 'homepage_days' => 'none'],
        ], $buyer->id);
        $this->assertEquals($expectedBase, $checkout['total']);
        $this->assertEquals($expectedBase, $checkout['items'][0]['price']);

        $expanded = app(CartPricingService::class)->expandCart([
            ['id' => $site->id, 'quantity' => 1, 'homepage_days' => 'none'],
        ], $buyer->id);
        $this->assertEquals($expectedBase, $expanded[0]['price']);
        $this->assertEquals($expectBuffer ? 0.5 : 0.0, $expanded[0]['fx_buffer_percent']);

        $snapshot = OrderItem::fxSnapshotFromPricing($pricing);
        $this->assertEquals($expectBuffer ? 0.5 : 0.0, $snapshot['fx_buffer_percent']);
    }

    public function test_gbp_listing_applies_buffer_for_default_eur_buyer(): void
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
        $this->assertEquals(113.57, $pricing['base']);
    }

    public function test_eur_listing_does_not_apply_buffer_for_eur_buyer(): void
    {
        $site = $this->makeSite([
            'listing_currency' => 'EUR',
            'listing_price' => 100,
            'price' => 100,
        ]);

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);

        $this->assertSame('EUR', $pricing['publisher_currency']);
        $this->assertEquals(100.0, $pricing['publisher_amount']);
        $this->assertEquals(0.0, $pricing['fx_buffer_percent']);
        $this->assertNull($pricing['fx_rate']);
        $this->assertSame(113.0, $pricing['total']);
    }

    public function test_refresh_eur_prices_stores_unbuffered_catalog_euros(): void
    {
        $site = $this->makeSite([
            'listing_currency' => 'GBP',
            'listing_price' => 85,
            'price' => 999,
            'listing_sensitive_prices' => ['crypto' => 8.5],
            'sensitive_prices' => ['crypto' => 9.99],
            'listing_homepage_prices' => ['7' => 17],
            'homepage_placement_prices' => ['7' => 19.99],
        ]);

        $site->refreshCatalogEuros();
        $this->assertEquals(100.0, (float) $site->price);
        $this->assertEquals(10.0, (float) ($site->sensitive_prices['crypto'] ?? 0));
        $this->assertEquals(20.0, (float) ($site->homepage_placement_prices['7'] ?? 0));

        $site->price = 999;
        $site->save();

        Artisan::call('sites:refresh-eur-prices');
        $site->refresh();
        $this->assertEquals(100.0, (float) $site->price);
        $this->assertEquals(10.0, (float) ($site->sensitive_prices['crypto'] ?? 0));
        $this->assertEquals(20.0, (float) ($site->homepage_placement_prices['7'] ?? 0));
    }

    public function test_catalog_price_filter_uses_buyer_facing_buffered_totals(): void
    {
        $eurSite = $this->makeSite([
            'site_name' => 'EUR filter listing',
            'site_url' => 'https://eur-filter.example',
            'domain' => 'eur-filter.example',
            'listing_currency' => 'EUR',
            'listing_price' => 100,
            'price' => 100,
        ]);
        $gbpSite = $this->makeSite([
            'site_name' => 'GBP filter listing',
            'site_url' => 'https://gbp-filter.example',
            'domain' => 'gbp-filter.example',
            'listing_currency' => 'GBP',
            'listing_price' => 85,
            'price' => 100,
        ]);

        $gbpBuyer = $this->makeAdvertiser('GBP');
        $eurBuyer = $this->makeAdvertiser('EUR');

        $gbpPage = $this->actingAs($gbpBuyer)
            ->get(route('advertiser.catalog', ['price_max' => 113.20]))
            ->assertOk();
        $gbpIds = collect($gbpPage->viewData('sites')->items())->pluck('id');
        $this->assertTrue($gbpIds->contains($gbpSite->id), 'GBP buyer should see the GBP listing (no buffer).');
        $this->assertFalse($gbpIds->contains($eurSite->id), 'GBP buyer should not see the EUR listing (buffered above max).');

        $eurPage = $this->actingAs($eurBuyer)
            ->get(route('advertiser.catalog', ['price_max' => 113.20]))
            ->assertOk();
        $eurIds = collect($eurPage->viewData('sites')->items())->pluck('id');
        $this->assertTrue($eurIds->contains($eurSite->id), 'EUR buyer should see the EUR listing (no buffer).');
        $this->assertFalse($eurIds->contains($gbpSite->id), 'EUR buyer should not see the GBP listing (buffered above max).');

        $this->assertTrue($eurSite->isCatalogVisible());
        $this->assertTrue($gbpSite->isCatalogVisible());
    }

    public function test_add_site_currency_defaults_to_gbp_for_gb_publishers(): void
    {
        $listing = app(ListingMoney::class);
        $this->assertSame('GBP', $listing->defaultListingCurrencyForPublisher($this->publisher));

        $gbpAccount = $this->publisher->replicate();
        $gbpAccount->country = 'de';
        $gbpAccount->display_currency = 'GBP';
        $this->assertSame('GBP', $listing->defaultListingCurrencyForPublisher($gbpAccount));

        $dePublisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $this->publisherRoleId,
            'country' => 'de',
            'display_currency' => 'EUR',
        ]);
        $this->assertSame('EUR', $listing->defaultListingCurrencyForPublisher($dePublisher));

        $usPublisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $this->publisherRoleId,
            'country' => 'us',
            'display_currency' => 'USD',
        ]);
        $this->assertSame('EUR', $listing->defaultListingCurrencyForPublisher($usPublisher));

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/<option value="GBP"[^>]*selected/i',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="EUR"[^>]*selected/i',
            $html
        );

        $dePublisher->roles()->attach($this->publisherRoleId);
        $deHtml = $this->actingAs($dePublisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/<option value="EUR"[^>]*selected/i',
            $deHtml
        );
    }

    public function test_deposit_fx_send_label_has_no_usd_conversion_fee_text(): void
    {
        $label = app(DepositFx::class)->sendLabel('USD', 108.0, 100.0);
        $this->assertStringNotContainsString('conversion fee', $label);
        $this->assertStringContainsString('we credit €100.00', $label);
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

    private function makeAdvertiser(string $currency): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'display_currency' => $currency,
            'country' => match ($currency) {
                'GBP' => 'gb',
                'USD' => 'us',
                default => 'de',
            },
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSite(array $overrides = []): Site
    {
        $stamp = str_replace('.', '', uniqid('', true));

        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'GBP listing '.$stamp,
            'site_url' => 'https://gbp-listing-'.$stamp.'.example',
            'domain' => 'gbp-listing-'.$stamp.'.example',
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
