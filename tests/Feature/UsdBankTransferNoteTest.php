<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
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

        $this->assertStringNotContainsString('Send €50.00', $html);
        $this->assertStringNotContainsString('js-switch-deposit-method', $html);
    }

    public function test_usd_wallet_add_funds_shows_eur_send_amount_and_wire_note(): void
    {
        $user = $this->advertiser('USD', 'US');
        $this->wallet($user, 'USD');

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 50, 'method' => 'bank']))
            ->assertOk()
            ->assertSee('Bank Transfer', false)
            ->assertSee('data-method="card"', false)
            ->assertSee('data-method="paypal"', false)
            ->assertSee('data-method="bank"', false)
            ->assertSee('bankIban', false)
            ->assertSee('data-usd-bank-wire="1"', false)
            ->assertSee(e('Send €50.00 (≈ $55.00 at today\'s rate)'), false)
            ->assertSee('This is an international wire to a EUR account.', false)
            ->assertSee('Please choose OUR', false)
            ->assertSee('Want to avoid wire fees? Pay instantly by Card or PayPal instead.', false)
            ->assertSee('js-switch-deposit-method', false)
            ->assertSee('data-method="card"', false)
            ->getContent();

        $this->assertStringContainsString('usdBankWire:', $html);
        $this->assertMatchesRegularExpression('/data-method="card"/', $html);
        $this->assertSame(2, substr_count($html, 'js-switch-deposit-method'));
    }

    public function test_us_ip_eur_wallet_still_gets_the_usd_wire_note(): void
    {
        config(['fx.fake_country' => 'US']);
        $user = $this->advertiser('EUR', 'DE');
        $this->wallet($user, 'EUR');

        $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['amount' => 40]))
            ->assertOk()
            ->assertSee('data-usd-bank-wire="1"', false)
            ->assertSee(e('Send €40.00 (≈ $44.00 at today\'s rate)'), false)
            ->assertSee('data-method="card"', false)
            ->assertSee('data-method="paypal"', false);
    }

    public function test_usd_invoice_html_and_pending_note_include_wire_copy(): void
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
            ->assertSee('data-usd-bank-wire="1"', false)
            ->assertSee(e('Send €50.00 (≈ $55.00 at today\'s rate)'), false)
            ->assertSee('international wire to a EUR account', false)
            ->assertSee(route('advertiser.add-funds', ['method' => 'card'], false), false)
            ->assertSee(route('advertiser.add-funds', ['method' => 'paypal'], false), false)
            ->assertSee($deposit->reference_code, false);

        $note = $this->pendingDepositNote($deposit, $user);
        $this->assertStringContainsString('IBAN (from outside the UK):', $note);
        $this->assertStringContainsString('Send €50.00 (≈ $55.00 at today\'s rate)', $note);
        $this->assertStringContainsString('international wire to a EUR account', $note);
        $this->assertStringContainsString('Card or PayPal', $note);
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
        $this->assertStringContainsString('IBAN (from outside the UK):', $note);
        $this->assertStringNotContainsString('international wire to a EUR account', $note);
        $this->assertStringNotContainsString('Send €50.00', $note);
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
