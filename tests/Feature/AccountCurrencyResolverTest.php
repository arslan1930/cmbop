<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\AccountCurrency;
use App\Support\DisplayCurrencyPreference;
use App\Support\MoneyDisplay;
use App\Support\ViewerCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountCurrencyResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['rates' => ['USD' => 1.10, 'GBP' => 0.85]], 200),
        ]);
        config([
            'fx.force_display' => '',
            'fx.geo_display' => true,
            'fx.fake_country' => 'US',
        ]);
    }

    public function test_guest_still_uses_legacy_session_and_cookie(): void
    {
        $this->post(route('display-currency'), ['currency' => 'USD'])->assertRedirect();

        $this->assertSame('USD', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('USD', session(DisplayCurrencyPreference::SESSION_KEY));
        $this->assertSame('USD', app(ViewerCountry::class)->displayCurrency());
    }

    public function test_auth_user_ignores_legacy_session_and_cookie(): void
    {
        $user = $this->advertiser('GB');
        $this->assertNull($user->display_currency);

        $this->actingAs($user)
            ->withSession([DisplayCurrencyPreference::SESSION_KEY => 'USD'])
            ->withUnencryptedCookie(DisplayCurrencyPreference::COOKIE_KEY, 'GBP')
            ->get(route('advertiser.dashboard'))
            ->assertOk();

        $this->assertSame('EUR', $user->fresh()->display_currency);
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('EUR', AccountCurrency::forUser($user->fresh()));
    }

    public function test_deposit_history_persists_eur_before_country_cookie_or_ip(): void
    {
        $user = $this->advertiser('GB');
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'EURHIST1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'completed',
        ]);
        $this->actingAs($user)->withSession([
            DisplayCurrencyPreference::SESSION_KEY => 'USD',
        ]);
        $this->withCountry('US');

        $this->assertTrue(app(DisplayCurrencyPreference::class)->hasEuroLedgerHistory($user));
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', $user->fresh()->display_currency);
    }

    public function test_wallet_transaction_history_persists_eur(): void
    {
        $user = $this->advertiser('US');
        $wallet = $this->wallet($user);
        WalletTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'direction' => 'credit',
            'amount' => 25,
            'bonus_amount' => 0,
            'balance_after' => 25,
            'bonus_balance_after' => 0,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Ledger deposit',
            'reference' => 'TX-EUR-1',
        ]);
        $this->actingAs($user)->withSession([
            DisplayCurrencyPreference::SESSION_KEY => 'GBP',
        ]);
        $this->withCountry('GB');

        $this->assertTrue(app(DisplayCurrencyPreference::class)->hasEuroLedgerHistory($user));
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', $user->fresh()->display_currency);
    }

    public function test_order_history_counts_as_euro_history_and_persists_eur(): void
    {
        $user = $this->advertiser('US');
        Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-EUR-1',
            'reference_code' => 'ORDEUR1',
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'total_amount' => 20,
            'subtotal' => 20,
        ]);
        $this->actingAs($user)->withSession([
            DisplayCurrencyPreference::SESSION_KEY => 'USD',
        ]);
        $this->withCountry('US');

        $this->assertTrue(app(DisplayCurrencyPreference::class)->hasEuroLedgerHistory($user));
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', $user->fresh()->display_currency);
    }

    public function test_order_item_as_publisher_counts_as_euro_history(): void
    {
        $publisher = $this->advertiser('GB');
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher->roles()->syncWithoutDetaching([$publisherRole->id]);

        $buyer = $this->advertiser('DE');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'History Site',
            'site_url' => 'https://history-site.example',
            'domain' => 'history-site.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 100,
            'country' => 'uk',
            'language' => 'en',
            'countries' => ['uk'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test',
            'verified' => true,
            'active' => true,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'ORD-PUB-1',
            'reference_code' => 'ORDPUB1',
            'status' => 'pending',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'total_amount' => 40,
            'subtotal' => 40,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 40,
            'additional_price' => 0,
        ]);

        $this->actingAs($publisher)->withSession([
            DisplayCurrencyPreference::SESSION_KEY => 'GBP',
        ]);
        $this->withCountry('GB');

        $this->assertTrue(app(DisplayCurrencyPreference::class)->hasEuroLedgerHistory($publisher));
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', $publisher->fresh()->display_currency);
    }

    public function test_no_history_defaults_to_eur_and_ignores_country_and_ip(): void
    {
        $user = $this->advertiser('GB');
        $this->assertNull($user->display_currency);
        $this->actingAs($user);
        $this->withCountry('US');

        $this->assertFalse(app(DisplayCurrencyPreference::class)->hasEuroLedgerHistory($user));
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', $user->fresh()->display_currency);
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('EUR', AccountCurrency::forUser($user->fresh()));
    }

    public function test_login_persists_eur_for_existing_google_style_account_without_country_step(): void
    {
        $user = $this->advertiser('US');
        $this->assertNull($user->display_currency);
        $this->withCountry('US');
        $this->withSession([DisplayCurrencyPreference::SESSION_KEY => 'USD']);

        $this->postJson(route('login.post'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('EUR', $user->fresh()->display_currency);
        $this->assertSame('EUR', session(DisplayCurrencyPreference::SESSION_KEY));
    }

    public function test_profile_billing_save_does_not_change_account_currency(): void
    {
        $user = $this->advertiser('DE');
        $user->display_currency = 'EUR';
        $user->billing_name = 'Resolver Tester';
        $user->company_name = 'Resolver Co';
        $user->city = 'Berlin';
        $user->address = '1 Test Street';
        $user->save();

        $this->actingAs($user)
            ->from(route('profile'))
            ->post(route('profile.billing'), [
                'billing_name' => 'Resolver Tester',
                'company_name' => 'Resolver Co',
                'country' => 'US',
                'state' => 'TX',
                'city' => 'Austin',
                'address' => '1 Test Street',
                'postal_code' => '78701',
            ])
            ->assertRedirect(route('profile'));

        $fresh = $user->fresh();
        $this->assertSame('us', $fresh->country);
        $this->assertSame('EUR', $fresh->display_currency);

        $this->withCountry('US');
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', AccountCurrency::forUser($fresh));
    }

    public function test_profile_billing_save_does_not_assign_currency_from_country_when_unset(): void
    {
        $user = $this->advertiser('DE');
        $this->assertNull($user->display_currency);

        $this->actingAs($user)
            ->from(route('profile'))
            ->post(route('profile.billing'), [
                'billing_name' => 'Resolver Tester',
                'country' => 'GB',
                'city' => 'London',
                'address' => '1 Test Street',
            ])
            ->assertRedirect(route('profile'));

        $fresh = $user->fresh();
        $this->assertSame('uk', $fresh->country);
        $this->assertNull($fresh->display_currency);

        $this->withCountry('GB');
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('EUR', $user->fresh()->display_currency);
        $this->assertSame('EUR', AccountCurrency::forUser($user->fresh()));
    }

    public function test_add_funds_billing_save_does_not_change_saved_gbp(): void
    {
        $user = $this->advertiser('GB');
        $user->display_currency = 'GBP';
        $user->save();
        $this->wallet($user);

        $this->actingAs($user)
            ->postJson(route('advertiser.save-billing-info'), [
                'billing_name' => 'Lock Tester',
                'company_name' => 'Lock Co',
                'country' => 'US',
                'city' => 'Austin',
                'address' => '1 Main St',
                'postal_code' => '78701',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $user->fresh();
        $this->assertSame('us', $fresh->country);
        $this->assertSame('GBP', $fresh->display_currency);
        $this->assertSame('GBP', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('GBP', AccountCurrency::forUser($fresh));
    }

    public function test_default_eur_copy_does_not_say_cannot_be_changed(): void
    {
        $user = $this->advertiser('DE');
        $this->wallet($user);
        $this->actingAs($user);

        $switcher = view('partials.currency-switcher')->render();
        $this->assertStringNotContainsString('cannot be changed', strtolower($switcher));
        $this->assertStringNotContainsString('contact support', strtolower($switcher));

        $funds = $this->actingAs($user)->get(route('advertiser.add-funds'))->assertOk()->getContent();
        $this->assertStringNotContainsString('account currency cannot be changed', strtolower($funds));
        $this->assertStringNotContainsString('currency cannot be changed', strtolower($funds));
        $this->assertSame('EUR', $user->fresh()->display_currency);
    }

    public function test_reset_sql_previews_and_skips_gbp_usd_wallets_and_deposits(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/reset_existing_users_currency_eur.sql'));

        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('UPDATE users', $sql);
        $this->assertStringContainsString("SET u.display_currency = 'EUR'", $sql);
        $this->assertStringContainsString('wallet_transactions', $sql);
        $this->assertStringContainsString('deposit_requests', $sql);
        $this->assertStringContainsString('order_items', $sql);
        $this->assertStringContainsString('Manual review', $sql);
        $this->assertStringContainsString("IN ('GBP', 'USD')", $sql);
        $this->assertStringContainsString('charge_currency', $sql);
        $this->assertStringContainsString('reserved_balance', $sql);
    }

    public function test_saved_display_currency_is_not_overwritten(): void
    {
        $user = $this->advertiser('DE');
        $user->display_currency = 'USD';
        $user->save();
        $this->actingAs($user);
        $this->withCountry('DE');

        $this->assertSame('USD', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('USD', $user->fresh()->display_currency);
    }

    private function withCountry(string $country): void
    {
        config(['fx.fake_country' => $country, 'fx.force_display' => '']);
        app()->forgetInstance(ViewerCountry::class);
        app()->forgetInstance(MoneyDisplay::class);
        app()->forgetInstance(DisplayCurrencyPreference::class);
    }

    private function advertiser(string $country): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'country' => $country,
            'billing_name' => 'Resolver Tester',
            'company_name' => 'Resolver Co',
            'city' => $country === 'GB' ? 'London' : ($country === 'US' ? 'Austin' : 'Berlin'),
            'address' => '1 Test Street',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function wallet(User $user, string $currency = 'EUR'): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Role::firstOrCreate(['name' => 'advertiser'])->id,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => $currency,
        ]);
    }
}
