<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\DepositPaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsHardenUxTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    public function test_wise_qr_returns_image_for_valid_amount(): void
    {
        $user = $this->advertiser();

        $response = $this->actingAs($user)
            ->get(route('advertiser.add-funds.wise-qr', ['amount' => 50]));

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        $this->assertGreaterThan(100, strlen($response->getContent()));
        // SVG is the preferred writer (no GD); accept PNG fallback too.
        $mime = (string) $response->headers->get('Content-Type');
        $this->assertTrue(
            str_contains($mime, 'svg') || str_contains($mime, 'png'),
            'Expected SVG or PNG Wise QR, got: '.$mime
        );
    }

    public function test_wise_qr_rejects_below_minimum(): void
    {
        $user = $this->advertiser();

        $this->actingAs($user)
            ->getJson(route('advertiser.add-funds.wise-qr', ['amount' => 5]))
            ->assertStatus(422);
    }

    public function test_wise_qr_requires_auth(): void
    {
        $this->get(route('advertiser.add-funds.wise-qr', ['amount' => 50]))
            ->assertRedirect();
    }

    public function test_crypto_store_requires_billing(): void
    {
        $user = $this->advertiser([
            'billing_name' => null,
            'company_name' => null,
            'address' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'crypto',
                'reference_code' => '123456',
            ])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'requires_billing' => true,
            ]);
    }

    public function test_crypto_store_succeeds_with_billing_when_enabled(): void
    {
        config(['billing.deposit_payment.crypto.enabled' => true]);
        config(['billing.deposit_payment.crypto.networks' => [[
            'key' => 'usdt_trc20',
            'label' => 'USDT (TRC20)',
            'address' => 'TTestAddress123',
        ]]]);

        $user = $this->advertiser();

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'crypto',
                'reference_code' => '654321',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissing(['reference_code' => '654321']);

        $this->assertDatabaseHas('deposit_requests', [
            'user_id' => $user->id,
            'payment_method' => 'crypto',
            'amount' => 50,
        ]);
        $this->assertDatabaseMissing('deposit_requests', [
            'user_id' => $user->id,
            'reference_code' => '654321',
        ]);
    }

    public function test_page_shows_pending_banner_and_same_origin_qr_boot(): void
    {
        $user = $this->advertiser();
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => '999001',
            'amount' => 80,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('pendingInvoicesBanner', $html);
        $this->assertStringContainsString('REF999001', $html);
        $this->assertStringContainsString('cancel-deposit-btn', $html);
        $this->assertStringContainsString('invoiceReadyCancel', $html);
        $this->assertStringContainsString('add-funds/wise-qr', $html);
        // Relative data-qr-base / boot path — absolute APP_URL hosts break Hostinger QR <img>.
        $this->assertMatchesRegularExpression('/data-qr-base="\/advertiser\/add-funds\/wise-qr"/', $html);
        $this->assertMatchesRegularExpression('/wiseQr:\s*"\\\\?\/advertiser\\\\?\/add-funds\\\\?\/wise-qr"/', $html);
        $this->assertStringContainsString('assets/js/add-funds.js', $html);
        $this->assertStringContainsString('assets/css/add-funds.css', $html);
        $this->assertStringContainsString('AddFundsBoot', $html);
        $this->assertStringContainsString('depositFeeNote', $html);
        $this->assertStringNotContainsString('api.qrserver.com', $html);
        $this->assertStringNotContainsString('#9333ea', $html);
        $this->assertStringNotContainsString('PayPal Coming Soon', $html);

        $js = file_get_contents(public_path('assets/js/add-funds.js'));
        $this->assertIsString($js);
        $this->assertStringContainsString('No extra deposit fee', $js);
        $this->assertStringContainsString('SEPA usually 0–2 business days', $js);
        $this->assertStringContainsString('function syncWiseQr', $js);
        $this->assertStringContainsString('wiseQrEndpoint', $js);
        $this->assertStringContainsString('if (!invoiceLocked)', $js);
        $this->assertStringContainsString('invoiceReadyBar', $html);
        $this->assertStringNotContainsString('XXXXXXXX', $html);
        $this->assertStringNotContainsString('api.qrserver.com', $js);
    }

    public function test_deposit_payment_config_hides_empty_crypto_networks(): void
    {
        config([
            'billing.deposit_payment.crypto.enabled' => true,
            'billing.deposit_payment.crypto.networks' => [
                ['key' => 'usdt_trc20', 'label' => 'USDT (TRC20)', 'address' => 'TAlive'],
                ['key' => 'btc', 'label' => 'Bitcoin', 'address' => ''],
            ],
        ]);

        $networks = DepositPaymentConfig::cryptoNetworks();
        $this->assertCount(1, $networks);
        $this->assertSame('usdt_trc20', $networks[0]['key']);
        $this->assertTrue(DepositPaymentConfig::cryptoEnabled());
    }
}
