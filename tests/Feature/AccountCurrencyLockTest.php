<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Support\AccountCurrency;
use App\Support\DisplayCurrencyPreference;
use App\Support\MoneyDisplay;
use App\Support\ViewerCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountCurrencyLockTest extends TestCase
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

    public function test_guest_can_still_switch_display_currency(): void
    {
        $this->withCountry('DE');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('slb-display-currency', false)
            ->assertDontSee('slb-currency-label', false);

        $this->post(route('display-currency'), ['currency' => 'USD'])->assertRedirect();

        $this->assertSame('USD', app(DisplayCurrencyPreference::class)->preferred());
        $this->assertSame('USD', session(DisplayCurrencyPreference::SESSION_KEY));
    }

    public function test_logged_in_header_shows_plain_currency_and_switch_is_ignored(): void
    {
        $user = $this->advertiser('GB');
        $user->display_currency = 'GBP';
        $user->save();
        $this->wallet($user);
        $this->withCountry('US');

        $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('slb-currency-label', false)
            ->assertSee('£ GBP', false)
            ->assertDontSee('id="slb-display-currency"', false);

        $switcher = view('partials.currency-switcher')->render();
        $this->assertStringContainsString('slb-currency-label', $switcher);
        $this->assertStringNotContainsString('cannot be changed', $switcher);
        $this->assertStringNotContainsString('contact support', $switcher);
        $this->assertStringNotContainsString('slb-display-currency', $switcher);

        $this->actingAs($user)
            ->post(route('display-currency'), ['currency' => 'USD'])
            ->assertRedirect();

        $this->assertSame('GBP', $user->fresh()->display_currency);
        $this->assertSame('GBP', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('GBP', app(DisplayCurrencyPreference::class)->preferred());
    }

    public function test_existing_user_saves_resolved_currency_on_first_login(): void
    {
        $user = $this->advertiser('DE');
        $this->assertNull($user->display_currency);
        $this->withCountry('US');

        $this->postJson(route('login.post'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('EUR', $user->fresh()->display_currency);
    }

    public function test_gbp_add_funds_shows_only_gbp_wise_bank(): void
    {
        $user = $this->advertiser('GB');
        $user->display_currency = 'GBP';
        $user->save();
        $this->wallet($user);
        $this->withCountry('GB');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bank-rail="gbp"', $html);
        $this->assertStringContainsString('23-08-01', $html);
        $this->assertStringContainsString('32932194', $html);
        $this->assertStringContainsString('Teqno Ltd (partners SEOLinkBuildings)', $html);
        $this->assertStringNotContainsString('data-bank-rail="eur"', $html);
        $this->assertStringNotContainsString('id="bankIban"', $html);
        $this->assertStringNotContainsString('BE40 9059 9538 0863', $html);
        $this->assertStringNotContainsString('I\'m paying from:', $html);
        $this->assertStringNotContainsString('UK bank (GBP)', $html);
        $this->assertStringNotContainsString('Other (EUR)', $html);
        $this->assertStringContainsString('id="summaryAmount"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="summaryAmount"[^>]*>€/', $html);
        $this->assertMatchesRegularExpression('/id="summaryAmount"[^>]*>£0\.00/', $html);
        $this->assertMatchesRegularExpression('/id="summaryTotal"[^>]*>£0\.00/', $html);
    }

    public function test_usd_add_funds_payment_summary_uses_dollars(): void
    {
        $user = $this->advertiser('US');
        $user->display_currency = 'USD';
        $user->save();
        $this->wallet($user);
        $this->withCountry('US');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('$50', $html);
        $this->assertStringContainsString('id="customAmountPrefix"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="summaryAmount"[^>]*>€/', $html);
        $this->assertMatchesRegularExpression('/id="summaryAmount"[^>]*>\$0\.00/', $html);
        $this->assertMatchesRegularExpression('/id="summaryTotal"[^>]*>\$0\.00/', $html);
        $this->assertMatchesRegularExpression('/id="afMobileTotal"[^>]*>\$0\.00/', $html);
        $this->assertStringContainsString('displaySymbol: "$"', $html);
        $this->assertStringContainsString('displayCurrency: "USD"', $html);
    }

    public function test_eur_add_funds_shows_only_eur_wise_bank(): void
    {
        $user = $this->advertiser('DE');
        $user->display_currency = 'EUR';
        $user->save();
        $this->wallet($user);
        $this->withCountry('DE');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bank-rail="eur"', $html);
        $this->assertStringContainsString('id="bankIban"', $html);
        $this->assertStringContainsString('Teqno Ltd (partners SEOLinkBuildings)', $html);
        $this->assertStringContainsString('BE40 9059 9538 0863', $html);
        $this->assertStringContainsString('TRWIBEB1XXX', $html);
        $this->assertStringContainsString('Swift/BIC: use when sending from outside SEPA', $html);
        $this->assertStringContainsString('SEPA payers need only the IBAN.', $html);
        $this->assertStringNotContainsString('data-bank-rail="gbp"', $html);
        $this->assertStringNotContainsString('id="bankUkSort"', $html);
        $this->assertStringNotContainsString('23-08-01', $html);
        $this->assertStringNotContainsString('32932194', $html);
        $this->assertStringNotContainsString('I\'m paying from:', $html);
    }

    public function test_mismatched_currency_bank_deposit_is_rejected(): void
    {
        $user = $this->advertiser('GB');
        $user->display_currency = 'GBP';
        $user->save();
        $this->wallet($user);
        $this->withCountry('GB');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'amount_currency' => 'EUR',
                'payment_method' => 'bank',
                'pay_from' => 'eur',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', AccountCurrency::bankMismatchMessage('GBP'));

        $this->assertDatabaseCount('deposit_requests', 0);
    }

    public function test_bank_deposit_stores_currency_bank_and_generates_reference(): void
    {
        $user = $this->advertiser('GB');
        $user->display_currency = 'GBP';
        $user->save();
        $this->wallet($user);
        $this->withCountry('GB');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'amount_currency' => 'GBP',
                'payment_method' => 'bank',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $deposit = DepositRequest::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame('GBP', $deposit->charge_currency);
        $this->assertSame(AccountCurrency::GBP_WISE, $deposit->bank_account);
        $this->assertSame('SLB-'.$deposit->id.'-GBP', $deposit->payment_reference);
        $this->assertSame('SLB-'.$deposit->id.'-GBP', $deposit->paymentReferenceLabel());

        $invoice = $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('SLB-'.$deposit->id.'-GBP', $invoice);
        $this->assertStringContainsString('Put this reference in the payment reference', $invoice);
        $this->assertStringContainsString('Teqno Ltd (partners SEOLinkBuildings)', $invoice);
        $this->assertStringContainsString('23-08-01', $invoice);
        $this->assertStringNotContainsString('GB30 TRWI', $invoice);
        $this->assertStringNotContainsString('BE40 9059 9538 0863', $invoice);
    }

    public function test_admin_currency_change_requires_reason_and_is_logged(): void
    {
        $admin = $this->admin();
        $member = $this->advertiser('DE');
        $member->display_currency = 'EUR';
        $member->save();
        $wallet = $this->wallet($member);
        $wallet->update(['balance' => 25]);
        Order::create([
            'user_id' => $member->id,
            'order_number' => 'ORD-LOCK-1',
            'reference_code' => 'ORDLOCK1',
            'status' => 'pending',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'total_amount' => 10,
            'subtotal' => 10,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $member))
            ->assertOk()
            ->assertSee('This account has a non-zero wallet balance and open orders.', false);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateDisplayCurrency', $member->id), [
                'display_currency' => 'GBP',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateDisplayCurrency', $member->id), [
                'display_currency' => 'GBP',
                'reason' => 'Support ticket 4421',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('display_currency', 'GBP');

        $this->assertSame('GBP', $member->fresh()->display_currency);
        $log = ActivityLog::query()
            ->where('action', 'user.display_currency_updated')
            ->where('subject_id', $member->id)
            ->first();
        $this->assertNotNull($log);
        $props = is_array($log->properties) ? $log->properties : [];
        $this->assertSame('EUR', $props['from'] ?? null);
        $this->assertSame('GBP', $props['to'] ?? null);
        $this->assertSame('Support ticket 4421', $props['reason'] ?? null);
    }

    public function test_admin_deposit_screens_show_tracking_and_received_bank_warning(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser('GB');
        $advertiser->display_currency = 'GBP';
        $advertiser->save();
        $this->wallet($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'TRK001',
            'amount' => 40,
            'charge_currency' => 'GBP',
            'charge_amount' => 34,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);
        $deposit->assignPaymentTracking(AccountCurrency::GBP_WISE, 'GBP');

        $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->assertSee('SLB-'.$deposit->id.'-GBP', false)
            ->assertSee('GBP Wise', false);

        $show = $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->json();
        $this->assertSame('gbp_wise', $show['deposit']['bank_account'] ?? null);
        $this->assertSame('SLB-'.$deposit->id.'-GBP', $show['deposit']['payment_reference'] ?? null);
        $this->assertSame('GBP Wise', $show['approve_context']['bank_label'] ?? null);

        $html = $this->actingAs($admin)
            ->get(ManualDepositApproveLink::relativeUrl($deposit))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('SLB-'.$deposit->id.'-GBP', $html);
        $this->assertStringContainsString('GBP Wise', $html);
        $this->assertStringContainsString('Account the money arrived in', $html);
        $this->assertStringContainsString('This does not match the bank shown to the advertiser.', $html);
    }

    public function test_payment_reference_helper_format(): void
    {
        $this->assertSame('SLB-12-GBP', AccountCurrency::paymentReference(12, 'GBP'));
        $this->assertSame('SLB-3-EUR', AccountCurrency::paymentReference(3, 'EUR'));
        $this->assertSame(AccountCurrency::GBP_WISE, AccountCurrency::bankAccountForCurrency('GBP'));
        $this->assertSame(AccountCurrency::EUR_WISE, AccountCurrency::bankAccountForCurrency('EUR'));
        $this->assertNull(AccountCurrency::bankAccountForCurrency('USD'));
    }

    public function test_open_withdrawal_is_included_in_admin_warning(): void
    {
        $member = $this->advertiser('DE');
        $wallet = $this->wallet($member);
        Withdrawal::create([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
            'amount' => 10,
            'fee' => 0,
            'net_amount' => 10,
            'currency' => 'EUR',
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $risk = AccountCurrency::changeRisk($member);
        $this->assertTrue($risk['has_open_withdrawals']);
        $this->assertTrue($risk['warning']);
        $this->assertStringContainsString('open withdrawals', $risk['message']);
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
            'billing_name' => 'Lock Tester',
            'company_name' => 'Lock Co',
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
