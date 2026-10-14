<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Support\DepositFx;
use App\Support\DepositPaymentConfig;
use App\Support\DisplayCurrencyPreference;
use App\Support\MoneyDisplay;
use App\Support\ViewerCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DepositBankPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['rates' => ['USD' => 1.118, 'GBP' => 0.85]], 200),
        ]);
        config([
            'fx.force_display' => '',
            'fx.geo_display' => true,
            'fx.currencies.USD.fallback' => 1.118,
            'fx.currencies.GBP.fallback' => 0.85,
            'fx.deposit_lock_days' => 7,
            'fx.deposit_tolerance_percent' => 2,
            'billing.deposit_payment.beneficiary' => 'Teqno LTD',
            'billing.deposit_payment.registration_no' => '16607074',
        ]);
    }

    public function test_paying_from_eur_shows_euro_send_amount_not_display_currency(): void
    {
        $user = $this->advertiser('DE');
        $this->wallet($user);
        $this->withCountry('DE');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 89.38, 'method' => 'bank', 'pay_from' => 'eur']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Send €89.38', $html);
        $this->assertStringNotContainsString('Send $', $html);
        $this->assertStringNotContainsString('we credit', $html);
        $this->assertStringContainsString('Rate locked for 7 days.', $html);
        $this->assertStringNotContainsString('Amount received may differ slightly', $html);
        $this->assertStringNotContainsString('±2%', $html);
        $this->assertStringContainsString('Paying in another currency? Read our currency guide', $html);
        $this->assertStringContainsString('/help/currencies', $html);
        $this->assertSame(
            'Send €89.38',
            app(DepositFx::class)->sendLabel('EUR', 89.38, 89.38)
        );
    }

    public function test_paying_from_gbp_shows_gbp_send_amount(): void
    {
        $user = $this->advertiser('GB');
        $this->wallet($user);
        $this->withCountry('GB');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank', 'pay_from' => 'gbp']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Send £42.50', $html);
        $this->assertStringNotContainsString('Send $', $html);
        $this->assertStringNotContainsString('we credit', $html);
        $this->assertSame(
            'Send £42.50',
            app(DepositFx::class)->sendLabel('GBP', 42.5, 50)
        );
    }

    public function test_pay_from_toggle_swaps_in_place_without_navigation(): void
    {
        $user = $this->advertiser('DE');
        $this->wallet($user);
        $this->withCountry('DE');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank', 'pay_from' => 'eur']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-pay-from-switch="1"', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('data-pay-from="gbp"', $html);
        $this->assertStringContainsString('data-pay-from="eur"', $html);
        $this->assertStringNotContainsString('pay_from=gbp', $html);
        $this->assertStringNotContainsString('pay_from=eur', $html);
        $this->assertStringContainsString('data-bank-rail="gbp"', $html);
        $this->assertStringContainsString('data-bank-rail="eur"', $html);
        $this->assertMatchesRegularExpression('/data-bank-rail="gbp"[^>]*\bhidden\b/', $html);
        $this->assertStringContainsString('window.__slbPayFromBound', $html);
        $this->assertStringContainsString('event.preventDefault()', $html);

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.pay-from'), ['pay_from' => 'gbp'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'pay_from' => 'gbp',
            ]);

        $this->assertSame('gbp', app(DisplayCurrencyPreference::class)->payFrom());
    }

    public function test_teqno_registration_number_on_panel_and_deposit_invoice(): void
    {
        $this->assertSame('17392540', DepositPaymentConfig::beneficiaryRegistrationNo());
        $this->assertSame(
            DepositPaymentConfig::TEQNO_REGISTRATION_NO,
            DepositPaymentConfig::beneficiaryRegistrationNo([
                'beneficiary' => 'Teqno LTD',
                'registration_no' => DepositPaymentConfig::TOPURLZ_REGISTRATION_NO,
            ])
        );
        $this->assertSame(
            DepositPaymentConfig::TOPURLZ_REGISTRATION_NO,
            DepositPaymentConfig::beneficiaryRegistrationNo([
                'beneficiary' => 'Topurlz Ltd',
                'registration_no' => DepositPaymentConfig::TOPURLZ_REGISTRATION_NO,
            ])
        );

        $user = $this->advertiser('DE');
        $this->wallet($user);
        $this->withCountry('DE');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 40, 'method' => 'bank']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('17392540', $html);
        $this->assertStringNotContainsString('>16607074<', $html);
        $this->assertStringNotContainsString('Phone no:', $html);
        $this->assertStringNotContainsString('+447445152374', $html);
        $this->assertSame(
            1,
            substr_count($html, 'Use this EUR account when sending euros, including from outside the UK')
        );

        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'REG001',
            'amount' => 40,
            'charge_currency' => 'EUR',
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $invoice = $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Teqno LTD', $invoice);
        $this->assertStringContainsString('17392540', $invoice);
        $this->assertStringNotContainsString('16607074', $invoice);
        $this->assertStringNotContainsString('Phone no:', $invoice);
        $this->assertStringNotContainsString('+447445152374', $invoice);
        $this->assertSame(
            1,
            substr_count($invoice, 'Use this EUR account when sending euros, including from outside the UK')
        );
    }

    public function test_currency_guide_placeholder_redirects_to_faq_anchor(): void
    {
        $this->get('/help/currencies')->assertRedirect('/faq#currency');
    }

    public function test_eur_iban_is_configurable_without_changing_default_value(): void
    {
        $pay = DepositPaymentConfig::depositPayment();
        $this->assertSame('GB30 TRWI 2308 0132 9321 94', $pay['eur_iban']);
        $this->assertSame('TRWIGB2LXXX', $pay['eur_bic']);

        config([
            'billing.deposit_payment.eur_iban' => 'BE12 TEQN 0000 0000 0000',
            'billing.deposit_payment.eur_bic' => 'TEQNEUR2XXX',
        ]);

        $updated = DepositPaymentConfig::depositPayment();
        $this->assertSame('BE12 TEQN 0000 0000 0000', $updated['eur_iban']);
        $this->assertSame('TEQNEUR2XXX', $updated['eur_bic']);
        $this->assertSame('GB30 TRWI 2308 0132 9321 94', $updated['iban']);
    }

    private function withCountry(string $country): void
    {
        config(['fx.fake_country' => $country, 'fx.force_display' => '']);
        app()->forgetInstance(ViewerCountry::class);
        app()->forgetInstance(MoneyDisplay::class);
        app()->forgetInstance(DisplayCurrencyPreference::class);
        app()->forgetInstance(DepositFx::class);
    }

    private function advertiser(string $country): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'country' => $country,
            'billing_name' => 'Bank Panel Tester',
            'company_name' => 'Panel Co',
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
