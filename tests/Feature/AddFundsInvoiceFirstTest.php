<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsInvoiceFirstTest extends TestCase
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

    public function test_store_generates_server_ref_and_ignores_client_code(): void
    {
        $user = $this->advertiser();

        $response = $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'wise',
                'reference_code' => '111111',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $serverRef = (string) $response->json('reference_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $serverRef);
        $this->assertNotSame('111111', $serverRef);
        $this->assertNotEmpty($response->json('invoice_url'));
        $this->assertNotEmpty($response->json('mark_paid_url'));
        $this->assertNotEmpty($response->json('cancel_url'));
        $this->assertNotEmpty($response->json('deposit_id'));

        $this->assertDatabaseHas('deposit_requests', [
            'user_id' => $user->id,
            'reference_code' => $serverRef,
            'amount' => 50,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('deposit_requests', [
            'reference_code' => '111111',
        ]);
    }

    public function test_store_succeeds_without_client_reference_code(): void
    {
        $user = $this->advertiser();

        $response = $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 40,
                'payment_method' => 'bank',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $response->json('reference_code'));
        $this->assertSame(1, DepositRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_fresh_page_has_no_live_wise_qr_or_copyable_placeholder_ref(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('XXXXXXXX', $html);
        $this->assertStringContainsString('data-invoice-ready="0"', $html);
        $this->assertStringContainsString('invoiceReadyBar', $html);
        $this->assertStringContainsString('Your transfer reference is created with the invoice', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/id="wiseQRCode"[^>]+src="/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="referenceCode"[^>]*data-placeholder="true"[^>]*>—</',
            $html
        );
    }

    public function test_generate_unique_reference_skips_existing_codes(): void
    {
        DepositRequest::create([
            'user_id' => $this->advertiser()->id,
            'reference_code' => '000001',
            'amount' => 10,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = DepositRequest::generateUniqueReferenceCode();
        }

        $this->assertNotContains('000001', $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
    }
}
