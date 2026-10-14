<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Mail\DepositRequestSubmitted;
use App\Models\ActivityLog;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\CartDisplayFx;
use App\Support\DepositFx;
use App\Support\DisplayCurrencyPreference;
use App\Support\MoneyDisplay;
use App\Support\PlatformCharge;
use App\Support\UsdBankWireGuidance;
use App\Support\ViewerCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GeoCurrencyDisplayTest extends TestCase
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
            'fx.currencies.USD.fallback' => 1.10,
            'fx.currencies.GBP.fallback' => 0.85,
            'fx.deposit_lock_days' => 7,
            'fx.deposit_tolerance_percent' => 2,
        ]);
    }

    public function test_gb_viewer_sees_pounds_us_sees_dollars_de_sees_euros(): void
    {
        $this->withCountry('GB');
        $this->assertSame('GBP', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('£10.20', format_money(12));
        $this->assertSame('£10.20', format_money_pay(12));
        $this->assertStringNotContainsString('≈', format_money(12));
        $this->assertStringNotContainsString('about', format_money_pay(12));

        $this->withCountry('US');
        $this->assertSame('USD', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('$13.20', format_money(12));

        $this->withCountry('DE');
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('€12.00', format_money(12));
        $this->assertSame('€12.00', format_money_pay(12));
        $this->assertStringNotContainsString('≈', format_money(12));
    }

    public function test_user_switcher_overrides_geo_and_persists_in_session(): void
    {
        $user = $this->advertiser('DE');
        $this->withCountry('GB');

        $this->actingAs($user)
            ->post(route('display-currency'), ['currency' => 'USD'])
            ->assertRedirect();

        $this->assertSame('USD', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('USD', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('$13.20', format_money(12));
        $this->assertSame('USD', $user->fresh()->display_currency);
    }

    public function test_publisher_gbp_price_converts_once_to_stored_euros(): void
    {
        $this->withCountry('DE');
        $this->assertSame(100.0, app(MoneyDisplay::class)->toEuros(85, 'GBP'));
        $this->assertSame(85.0, app(MoneyDisplay::class)->fromEuros(100, 'GBP'));
        $this->assertSame(40.0, app(MoneyDisplay::class)->toEuros(40, 'EUR'));
    }

    public function test_platform_charge_stays_euros_for_gb_and_us(): void
    {
        foreach (['GB', 'US', 'DE'] as $country) {
            $this->withCountry($country);
            $quote = app(PlatformCharge::class)->quote(40);
            $this->assertSame('EUR', $quote['code'], $country);
            $this->assertSame('eur', $quote['stripe'], $country);
            $this->assertSame(40.0, $quote['amount'], $country);
            $this->assertSame(40.0, $quote['euros'], $country);
        }
    }

    public function test_gb_add_funds_shows_gbp_rails_and_us_hides_bank_transfer(): void
    {
        $gb = $this->advertiser('GB');
        $this->wallet($gb);
        $this->withCountry('GB');

        $this->actingAs($gb)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('data-bank-rail="gbp"', false)
            ->assertSee('data-pay-from="gbp"', false)
            ->assertSee('UK bank (GBP)', false)
            ->assertSee('data-amount="50"', false)
            ->assertSee('data-amount-currency="GBP"', false)
            ->assertSee('£50', false)
            ->assertSee('£100', false)
            ->assertSee('£250', false)
            ->assertSee('£500', false)
            ->assertSee('£1,000', false)
            ->assertSee('id="customAmountPrefix"', false)
            ->assertSee('Minimum £8.50', false)
            ->assertSee('Maximum £42,500.00', false)
            ->assertSee(e('Send £42.50 (we credit €50.00) — rate locked 7 days; amount received may differ slightly (±2%) due to exchange rates/bank fees'), false)
            ->assertSee('23-08-01', false)
            ->assertDontSee('data-bank-rail="eur"', false)
            ->assertDontSee('id="bankIban"', false)
            ->assertDontSee('≈ €', false);

        $us = $this->advertiser('US');
        $this->wallet($us, 'USD');
        $this->withCountry('US');
        $this->flushSession();

        $this->actingAs($us)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('data-method="paypal"', false)
            ->assertSee('data-method="wise"', false)
            ->assertDontSee('data-method="bank"', false)
            ->assertDontSee('id="bankIban"', false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('includes 0.5% conversion fee', false)
            ->assertDontSee('data-bank-rail="gbp"', false);
    }

    public function test_deposit_store_converts_gbp_amount_to_eur_ledger(): void
    {
        $user = $this->advertiser('GB');
        $this->wallet($user);
        $this->withCountry('GB');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'amount_currency' => 'GBP',
                'payment_method' => 'bank',
                'pay_from' => 'gbp',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $deposit = DepositRequest::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame('GBP', $deposit->charge_currency);
        $this->assertEquals(50.0, (float) $deposit->charge_amount);
        $this->assertEquals(0.85, (float) $deposit->fx_rate);
        $this->assertNotNull($deposit->rate_locked_until);
        $this->assertTrue($deposit->rate_locked_until->greaterThan(now()->addDays(6)));
        $this->assertEquals(58.82, (float) $deposit->amount);
    }

    public function test_deposit_store_validates_min_max_against_eur_equivalent(): void
    {
        $user = $this->advertiser('GB');
        $this->wallet($user);
        $this->withCountry('GB');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 8.49,
                'amount_currency' => 'GBP',
                'payment_method' => 'bank',
                'pay_from' => 'gbp',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 8.50,
                'amount_currency' => 'GBP',
                'payment_method' => 'bank',
                'pay_from' => 'gbp',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $deposit = DepositRequest::query()->where('user_id', $user->id)->first();
        $this->assertEquals(10.0, (float) $deposit->amount);
        $this->assertEquals(8.5, (float) $deposit->charge_amount);
    }

    public function test_expired_lock_regenerates_with_fresh_rate(): void
    {
        $user = $this->advertiser('GB');
        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'LOCK001',
            'amount' => 50,
            'charge_currency' => 'GBP',
            'charge_amount' => 40,
            'fx_rate' => 0.80,
            'rate_locked_until' => now()->subDay(),
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $fresh = app(DepositFx::class)->refreshIfExpired($deposit);
        $this->assertEquals(0.85, (float) $fresh->fx_rate);
        $this->assertEquals(42.5, (float) $fresh->charge_amount);
        $this->assertEquals(50.0, (float) $fresh->amount);
        $this->assertTrue($fresh->rate_locked_until->isFuture());
    }

    public function test_invoice_and_deposit_email_use_exact_tolerance_sentence(): void
    {
        $note = app(DepositFx::class)->toleranceNote();
        $this->assertSame(
            'Because of exchange rates and bank fees, the amount we receive may differ slightly (about ±2%). We\'ll credit your wallet with the euro value of what actually arrives.',
            $note
        );

        $user = $this->advertiser('GB');
        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'amount' => 50,
            'charge_currency' => 'GBP',
            'charge_amount' => 42.5,
            'payment_method' => 'bank',
            'reference_code' => '654321',
            'status' => 'pending',
        ]);
        $deposit->setRelation('user', $user);

        $html = (new DepositRequestSubmitted($deposit))->render();
        $this->assertStringContainsString(
            'Send £42.50 (we credit €50.00) — rate locked 7 days; amount received may differ slightly (±2%) due to exchange rates/bank fees',
            $html
        );
        $this->assertSame(1, substr_count($html, '±2%'));
        $this->assertStringNotContainsString($note, $html);
    }

    public function test_cart_and_activity_follow_display_currency_without_secondary_text(): void
    {
        $user = $this->advertiser('GB');
        $wallet = $this->wallet($user);
        $this->withCountry('GB');
        $this->actingAs($user);
        session(['cart' => [['id' => 1, 'price' => 100, 'quantity' => 1]]]);
        app(CartDisplayFx::class)->remember();

        $this->assertSame('£85.00', format_money_pay(100));
        $this->assertSame('GBP', money_js()['currency']);
        $this->assertTrue(money_js()['guide']);

        $this->post(route('display-currency'), ['currency' => 'EUR'])->assertRedirect();
        app()->forgetInstance(ViewerCountry::class);
        app()->forgetInstance(MoneyDisplay::class);
        app()->forgetInstance(DisplayCurrencyPreference::class);

        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('€100.00', format_money(100));
        $this->assertSame('€100.00', format_money_pay(100));
        $this->assertStringNotContainsString('£', format_money_pay(100));
        $this->assertStringNotContainsString('≈', format_money(100));
        $this->assertFalse(money_js()['guide']);
        $this->assertSame('EUR', money_js()['currency']);

        app(WalletLedgerService::class)->recordDeposit($wallet, 100, null, 'bank', 'ACT100');
        $tx = $this->actingAs($user)->getJson(route('advertiser.balance.transactions'));
        $tx->assertOk();
        $this->assertSame('EUR', $tx->json('fx.currency'));
        $this->assertFalse($tx->json('fx.guide'));
        $row = collect($tx->json('transactions'))->firstWhere('reference', 'ACT100');
        $this->assertNotNull($row);
        $this->assertSame('+€100.00', $row['amount_display']);
        $this->assertStringNotContainsString('≈', (string) $row['amount_display']);
        $this->assertStringNotContainsString('£', (string) $row['amount_display']);

        $this->actingAs($user)->post(route('display-currency'), ['currency' => 'GBP']);
        app()->forgetInstance(ViewerCountry::class);
        app()->forgetInstance(MoneyDisplay::class);
        app()->forgetInstance(DisplayCurrencyPreference::class);
        $gbp = $this->actingAs($user)->getJson(route('advertiser.balance.transactions'));
        $gbpRow = collect($gbp->json('transactions'))->firstWhere('reference', 'ACT100');
        $this->assertSame('+£85.00', $gbpRow['amount_display']);
        $this->assertStringNotContainsString('≈', (string) $gbpRow['amount_display']);
    }

    public function test_eur_user_with_us_ip_keeps_eur_and_eur_bank_details(): void
    {
        $user = $this->advertiser('DE');
        $user->display_currency = 'EUR';
        $user->save();
        $this->wallet($user);
        $this->withCountry('US');
        $this->actingAs($user);

        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('€50.00', format_money(50));
        $this->assertFalse(app(UsdBankWireGuidance::class)->applies('EUR', $user));

        $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('data-amount="50"', false)
            ->assertSee('data-amount-currency="EUR"', false)
            ->assertSee('€50', false)
            ->assertSee('id="customAmountPrefix"', false)
            ->assertSee('Minimum €10.00', false)
            ->assertSee('data-bank-rail="eur"', false)
            ->assertSee('bankIban', false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('Please choose OUR', false)
            ->assertDontSee('data-bank-rail="gbp"', false);

        $this->assertSame('EUR', $user->fresh()->display_currency);
    }

    public function test_eu_account_country_ignores_us_ip_even_without_history(): void
    {
        $user = $this->advertiser('DE');
        $this->assertNull($user->display_currency);
        $this->wallet($user);
        $this->withCountry('US');

        $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 40, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('data-bank-rail="eur"', false)
            ->assertSee('bankIban', false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('Please choose OUR', false);

        $this->assertSame('EUR', $user->fresh()->display_currency);
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
    }

    public function test_existing_eur_history_user_with_us_ip_stays_eur(): void
    {
        $user = $this->advertiser('DE');
        $this->assertNull($user->display_currency);
        $this->wallet($user);
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'HIST01',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'completed',
        ]);
        $this->withCountry('US');

        $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('data-bank-rail="eur"', false)
            ->assertDontSee('data-usd-bank-wire="1"', false);

        $this->assertSame('EUR', $user->fresh()->display_currency);
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
    }

    public function test_login_persists_account_country_currency_over_us_ip(): void
    {
        $user = $this->advertiser('DE');
        $this->assertNull($user->display_currency);
        $this->withCountry('US');

        $this->postJson(route('login.post'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('EUR', $user->fresh()->display_currency);
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('EUR', session(DisplayCurrencyPreference::SESSION_KEY));
    }

    public function test_logout_clears_display_currency_so_next_user_does_not_inherit_it(): void
    {
        $gb = $this->advertiser('GB');
        $de = $this->advertiser('DE');
        $this->assertNull($de->display_currency);

        $this->withCountry('GB');
        $this->postJson(route('login.post'), [
            'email' => $gb->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->post(route('display-currency'), ['currency' => 'USD'])->assertRedirect();
        $this->assertSame('USD', $gb->fresh()->display_currency);

        $logout = $this->post(route('logout'));
        $logout->assertRedirect('/');
        $logout->assertCookieExpired(DisplayCurrencyPreference::COOKIE_KEY);
        $this->assertGuest();
        $this->assertNull(session(DisplayCurrencyPreference::SESSION_KEY));
        $this->assertNull(session(DisplayCurrencyPreference::PAY_FROM_KEY));
        $this->assertNull(session(CartDisplayFx::SESSION_KEY));

        $this->withCountry('US');
        $this->postJson(route('login.post'), [
            'email' => $de->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('EUR', $de->fresh()->display_currency);
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('USD', $gb->fresh()->display_currency);
    }

    public function test_admin_can_set_user_display_currency_and_logs_it(): void
    {
        $admin = $this->admin();
        $member = $this->advertiser('DE');
        $wallet = $this->wallet($member);
        $wallet->update(['balance' => 40]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $member))
            ->assertOk()
            ->assertSee('Display currency', false)
            ->assertSee('btn-edit-display-currency', false);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateDisplayCurrency', $member->id), [
                'display_currency' => 'GBP',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('display_currency', 'GBP');

        $this->assertSame('GBP', $member->fresh()->display_currency);
        $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.display_currency_updated',
            'subject_id' => $member->id,
        ]);
        $log = ActivityLog::query()
            ->where('action', 'user.display_currency_updated')
            ->where('subject_id', $member->id)
            ->first();
        $this->assertNotNull($log);
        $props = is_array($log->properties) ? $log->properties : [];
        $this->assertContains($props['from'] ?? null, [null, '', 'EUR']);
        $this->assertSame('GBP', $props['to'] ?? null);
        $this->assertTrue((bool) ($props['ledger_unchanged'] ?? false));
    }

    public function test_admin_converts_received_gbp_with_locked_rate_and_flags_tolerance(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $advertiser = $this->advertiser('GB');
        $wallet = $this->wallet($advertiser);
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'ADM001',
            'amount' => 100,
            'charge_currency' => 'GBP',
            'charge_amount' => 85,
            'fx_rate' => 0.85,
            'rate_locked_until' => now()->addDays(7),
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $fx = app(DepositFx::class);
        $this->assertSame(
            'Expected £85.00 · credits €100.00 (rate 1.1765, locked until '.$deposit->rate_locked_until->format('M d, Y').')',
            $fx->adminExpectedLine($deposit)
        );
        $this->assertTrue($fx->withinTolerance($deposit, 85));
        $this->assertTrue($fx->withinTolerance($deposit, 86.7));
        $this->assertFalse($fx->withinTolerance($deposit, 90));
        $this->assertEquals(100.0, $fx->eurosFromReceived($deposit, 85));
        $this->assertEquals(98.82, $fx->eurosFromReceived($deposit, 84));

        $result = app(ManualDepositApprovalService::class)->approve($deposit, $admin, 'wire matched', 84);
        $this->assertTrue($result['email_sent']);
        $this->assertEquals(98.82, (float) $wallet->fresh()->balance);

        Mail::assertQueued(DepositApproved::class, function (DepositApproved $mail) {
            return str_contains(
                $mail->render(),
                'We received £84.00, credited as €98.82 to your wallet (rate 1 £ = €1.1765).'
            );
        });
    }

    public function test_de_deposit_stays_euros_and_credits_received_amount(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $advertiser = $this->advertiser('DE');
        $wallet = $this->wallet($advertiser);
        $this->withCountry('DE');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 40,
                'payment_method' => 'bank',
                'pay_from' => 'eur',
            ])
            ->assertOk();

        $deposit = DepositRequest::query()->where('user_id', $advertiser->id)->first();
        $this->assertSame('EUR', $deposit->charge_currency);
        $this->assertEquals(40.0, (float) $deposit->charge_amount);
        $this->assertEquals(1.0, (float) $deposit->fx_rate);

        app(ManualDepositApprovalService::class)->approve($deposit, $admin, null, 40);
        $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
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
            'billing_name' => 'Geo Tester',
            'company_name' => 'Geo Co',
            'city' => $country === 'GB' ? 'London' : ($country === 'US' ? 'Austin' : 'Berlin'),
            'address' => '1 Test Street',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
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
