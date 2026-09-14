<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\DepositPaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsPhase4PolishTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    public function test_page_shows_max_copy_usdt_icon_and_closed_billing_modal(): void
    {
        config([
            'billing.deposit_payment.crypto.enabled' => true,
            'billing.deposit_payment.crypto.networks' => [[
                'key' => 'usdt_trc20',
                'label' => 'USDT (TRC20)',
                'address' => 'TTestAddressPhase4',
            ]],
        ]);
        $this->assertTrue(DepositPaymentConfig::cryptoEnabled());

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('€10–€100,000', $html);
        $this->assertStringContainsString('max="100000"', $html);
        $this->assertStringContainsString('payments/usdt.svg', $html);
        $this->assertStringNotContainsString('fa-bitcoin', $html);
        $this->assertStringContainsString('TTestAddressPhase4', $html);
        $this->assertStringContainsString('data-copy="TTestAddressPhase4"', $html);
        $this->assertStringContainsString('Copy address', $html);
        $this->assertStringContainsString('afCopyStatus', $html);
        $this->assertStringContainsString('billingInfoModalLabel', $html);
        $this->assertStringContainsString('for="company_name"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringNotContainsString('id="walletChart"', $html);
        $this->assertStringNotContainsString('new Chart(', $html);
        $this->assertMatchesRegularExpression(
            '/id="billingInfoModal"[\s\S]+?<\/div>\s*<script>\s*window\.AddFundsBoot/s',
            $html
        );
    }
}
