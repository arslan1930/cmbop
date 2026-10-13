<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Support\UsdBankWireGuidance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsdBankWireGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);
        config([
            'fx.fake_country' => '',
            'fx.force_display' => '',
            'fx.currencies.USD.fallback' => 1.10,
        ]);
    }

    public function test_quote_from_euros_uses_existing_fx_rate(): void
    {
        $quote = app(UsdBankWireGuidance::class)->quoteFromEuros(50);

        $this->assertSame(50.0, $quote['euros']);
        $this->assertEqualsWithDelta(55.0, $quote['usd'], 0.01);
        $this->assertSame('€50.00', $quote['eur_label']);
        $this->assertSame('$55.00', $quote['usd_label']);
        $this->assertSame('Send €50.00 (≈ $55.00 at today\'s rate)', $quote['send_label']);
    }

    public function test_quote_from_usd_uses_money_display_to_euros(): void
    {
        $quote = app(UsdBankWireGuidance::class)->quoteFromUsd(55);

        $this->assertSame(50.0, $quote['euros']);
        $this->assertSame(55.0, $quote['usd']);
        $this->assertSame('Send €50.00 (≈ $55.00 at today\'s rate)', $quote['send_label']);
    }

    public function test_applies_for_usd_wallet_not_eur_wallet(): void
    {
        $guidance = app(UsdBankWireGuidance::class);

        $this->assertTrue($guidance->applies('USD'));
        $this->assertFalse($guidance->applies('EUR'));
        $this->assertFalse($guidance->applies('GBP'));
    }

    public function test_applies_for_us_viewer_with_eur_wallet(): void
    {
        config(['fx.fake_country' => 'US']);

        $this->assertTrue(app(UsdBankWireGuidance::class)->applies('EUR'));
    }

    public function test_instruction_lines_only_for_usd(): void
    {
        $guidance = app(UsdBankWireGuidance::class);

        $this->assertSame([], $guidance->instructionLines(40, 'EUR'));

        $lines = $guidance->instructionLines(40, 'USD');
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('Send €40.00 (≈ $44.00 at today\'s rate)', $lines[0]);
        $this->assertStringContainsString('international wire to a EUR account', $lines[1]);
        $this->assertStringContainsString('OUR', $lines[1]);
        $this->assertStringContainsString('Card or PayPal', $lines[2]);
    }

    public function test_wallet_currency_for_user(): void
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->syncWithoutDetaching([$role->id]);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'USD',
        ]);

        $this->assertSame('USD', app(UsdBankWireGuidance::class)->walletCurrencyFor($user));
    }

    private function fakeFrankfurter(array $rates): void
    {
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['rates' => $rates], 200),
        ]);
    }
}
