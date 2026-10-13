<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Support\DepositFx;
use App\Support\DisplayCurrencyPreference;
use App\Support\MoneyDisplay;
use App\Support\PlatformCharge;
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
        $this->assertSame('£10.20 (≈ €12.00)', format_money(12));

        $this->withCountry('US');
        $this->assertSame('USD', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('$13.20 (≈ €12.00)', format_money(12));

        $this->withCountry('DE');
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('€12.00', format_money(12));
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
        $this->assertSame('$13.20 (≈ €12.00)', format_money(12));
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

    public function test_gb_add_funds_shows_only_gbp_rails_and_us_keeps_usd_wire_note(): void
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
            ->assertSee(e('Amount to send: £42.50 (≈ €50.00 at today\'s rate, locked for 7 days)'), false)
            ->assertSee('23-08-01', false)
            ->assertDontSee('data-bank-rail="eur"', false)
            ->assertDontSee('id="bankIban"', false);

        $us = $this->advertiser('US');
        $this->wallet($us, 'USD');
        $this->withCountry('US');

        $this->actingAs($us)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('data-bank-rail="eur"', false)
            ->assertSee('bankIban', false)
            ->assertSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('data-bank-rail="gbp"', false);
    }

    public function test_deposit_store_locks_gbp_charge_fields(): void
    {
        $user = $this->advertiser('GB');
        $this->wallet($user);
        $this->withCountry('GB');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'bank',
                'pay_from' => 'gbp',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $deposit = DepositRequest::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame('GBP', $deposit->charge_currency);
        $this->assertEquals(42.5, (float) $deposit->charge_amount);
        $this->assertEquals(0.85, (float) $deposit->fx_rate);
        $this->assertNotNull($deposit->rate_locked_until);
        $this->assertTrue($deposit->rate_locked_until->greaterThan(now()->addDays(6)));
        $this->assertEquals(50.0, (float) $deposit->amount);
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

        $html = (new \App\Mail\DepositRequestSubmitted($deposit))->render();
        $this->assertStringContainsString($note, $html);
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
