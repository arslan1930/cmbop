<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Support\DepositPaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class UsdBankTransferNoteTest extends TestCase
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
            'fx.fake_country' => '',
            'fx.force_display' => '',
            'fx.currencies.USD.fallback' => 1.10,
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);
    }

    public function test_eur_wallet_add_funds_keeps_bank_details_without_usd_note(): void
    {
        $user = $this->advertiser('EUR', 'DE');
        $this->wallet($user, 'EUR');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('Bank Transfer', false)
            ->assertSee('data-method="card"', false)
            ->assertSee('data-method="paypal"', false)
            ->assertSee('data-method="bank"', false)
            ->assertSee('bankIban', false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('international wire to a EUR account', false)
            ->getContent();

        $this->assertStringNotContainsString('we credit', $html);
        $this->assertStringNotContainsString('js-switch-deposit-method', $html);
    }

    public function test_usd_wallet_add_funds_hides_bank_transfer(): void
    {
        $user = $this->advertiser('USD', 'US');
        $this->wallet($user, 'USD');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('data-method="card"', false)
            ->assertSee('data-method="paypal"', false)
            ->assertSee('data-method="wise"', false)
            ->assertDontSee('data-method="bank"', false)
            ->assertDontSee('id="bankIban"', false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('international wire to a EUR account', false)
            ->assertDontSee('includes 0.5% conversion fee', false)
            ->getContent();

        $this->assertStringNotContainsString('data-method="bank"', $html);
        $this->assertStringNotContainsString('js-switch-deposit-method', $html);
    }

    public function test_usd_bank_deposit_is_rejected_server_side(): void
    {
        $user = $this->advertiser('USD', 'US');
        $this->wallet($user, 'USD');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'amount_currency' => 'USD',
                'payment_method' => 'bank',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', DepositPaymentConfig::bankUnavailableMessage());

        $this->assertDatabaseCount('deposit_requests', 0);
    }

    public function test_us_ip_does_not_override_eur_user_bank_details(): void
    {
        config(['fx.fake_country' => 'US']);
        $user = $this->advertiser('EUR', 'DE');
        $user->display_currency = 'EUR';
        $user->save();
        $this->wallet($user, 'EUR');

        $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 40]))
            ->assertOk()
            ->assertSee('data-bank-rail="eur"', false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('Please choose OUR', false)
            ->assertSee('data-method="card"', false)
            ->assertSee('data-method="paypal"', false);
    }

    public function test_leftover_usd_bank_invoice_still_renders_without_wire_guidance(): void
    {
        $user = $this->advertiser('USD', 'US');
        $this->wallet($user, 'USD');

        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => '654321',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk()
            ->assertSee($deposit->reference_code, false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('includes 0.5% conversion fee', false)
            ->assertDontSee('international wire to a EUR account', false);

        $note = $this->pendingDepositNote($deposit, $user);
        $this->assertStringContainsString('IBAN:', $note);
        $this->assertStringContainsString('SEPA payers need only the IBAN.', $note);
        $this->assertStringNotContainsString('includes 0.5% conversion fee', $note);
        $this->assertStringNotContainsString('international wire to a EUR account', $note);
    }

    public function test_eur_invoice_omits_usd_wire_copy(): void
    {
        $user = $this->advertiser('EUR', 'DE');
        $this->wallet($user, 'EUR');

        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => '123456',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk()
            ->assertSee('IBAN:', false)
            ->assertDontSee('data-usd-bank-wire="1"', false)
            ->assertDontSee('international wire to a EUR account', false);

        $note = $this->pendingDepositNote($deposit, $user);
        $this->assertStringContainsString('IBAN:', $note);
        $this->assertStringContainsString('SEPA payers need only the IBAN.', $note);
        $this->assertStringNotContainsString('international wire to a EUR account', $note);
        $this->assertStringNotContainsString('Send €50.00 (≈ $', $note);
    }

    private function advertiser(string $currency, string $country): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'country' => $country,
            'billing_name' => 'Test Advertiser',
            'company_name' => 'Test Co',
            'city' => $country === 'US' ? 'Austin' : 'Berlin',
            'address' => '1 Test Street',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function wallet(User $user, string $currency): Wallet
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

    private function pendingDepositNote(DepositRequest $deposit, User $user): string
    {
        $controller = app(InvoiceController::class);
        $method = new ReflectionMethod($controller, 'pendingDepositNote');

        return (string) $method->invoke($controller, $deposit, $user);
    }
}
