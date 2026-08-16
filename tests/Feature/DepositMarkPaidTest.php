<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositMarkPaidTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'billing_name' => 'Test Advertiser',
            'company_name' => 'Test Co',
            'address' => '1 Test Street',
            'country' => 'GB',
            'city' => 'London',
        ]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function pendingDeposit(User $user, string $method = 'wise'): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => (string) random_int(100000, 999999),
            'amount' => 50,
            'payment_method' => $method,
            'status' => 'pending',
        ]);
    }

    public function test_advertiser_can_mark_wise_deposit_as_paid_without_changing_status(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'wise');

        $response = $this->actingAs($user)->postJson(
            route('advertiser.add-funds.mark-paid', $deposit),
            ['user_payment_note' => 'WISE-ABC-123']
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'pending');

        $deposit->refresh();
        $this->assertSame('pending', $deposit->status);
        $this->assertNotNull($deposit->user_marked_paid_at);
        $this->assertSame('WISE-ABC-123', $deposit->user_payment_note);
    }

    public function test_mark_paid_is_idempotent_and_keeps_pending(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'bank');
        $deposit->update([
            'user_marked_paid_at' => now()->subMinute(),
            'user_payment_note' => 'first',
        ]);

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.mark-paid', $deposit), [
                'user_payment_note' => 'second',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $deposit->refresh();
        $this->assertSame('pending', $deposit->status);
        $this->assertSame('first', $deposit->user_payment_note);
    }

    public function test_cannot_mark_another_users_deposit(): void
    {
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $deposit = $this->pendingDeposit($owner, 'crypto');

        $this->actingAs($other)
            ->postJson(route('advertiser.add-funds.mark-paid', $deposit))
            ->assertForbidden();
    }

    public function test_add_funds_page_shows_mark_paid_for_pending_deposits(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'wise');

        $page = $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Recent activity', false)
            ->assertSee('Live', false)
            ->assertDontSee('Pending invoice deposits', false)
            ->assertSee('id="walletHistory"', false)
            ->assertSee('id="activityFeed"', false);

        $html = $page->getContent();
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $withdrawJson = json_encode(route('advertiser.balance.withdraw', [], false), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->assertStringContainsString($withdrawJson, $html);
        $this->assertStringContainsString('data-mark-url="'.route('advertiser.add-funds.mark-paid', $deposit, false).'"', $html);
        $this->assertStringNotContainsString('data-mark-url="'.route('advertiser.add-funds.mark-paid', $deposit).'"', $html);
    }

    public function test_pending_deposit_appears_in_activity_feed_with_download(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'wise');

        $feed = $this->actingAs($user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'reference' => $deposit->reference_code,
                'status' => 'pending',
                'is_live_pending' => true,
            ]);

        $row = collect($feed->json('transactions'))->firstWhere('reference', $deposit->reference_code);
        $this->assertSame(
            route('advertiser.add-funds.mark-paid', $deposit, false),
            $row['mark_paid_url'] ?? null
        );

        $this->actingAs($user)
            ->get(route('advertiser.invoice', ['referenceCode' => $deposit->reference_code, 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_invoice_page_shows_mark_paid_button(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'wise');

        $page = $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk()
            ->assertSee('OK, I have made the payment')
            ->assertSee('stays')
            ->assertSee('Pending');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $html = $page->getContent();

        $markPaidJson = json_encode(
            route('advertiser.add-funds.mark-paid', $deposit, false),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
        $this->assertStringContainsString($markPaidJson, $html);
        $this->assertStringNotContainsString(route('advertiser.add-funds.mark-paid', $deposit), $html);
    }

    public function test_deposit_status_json_omits_stripe_payload_and_is_not_cached(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'card');
        $deposit->update([
            'stripe_session_id' => 'cs_test_secret_session',
            'stripe_payment_intent_id' => 'pi_test_secret_intent',
            'stripe_response' => [
                'id' => 'cs_test_secret_session',
                'client_secret' => 'secret_xyz',
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('advertiser.add-funds.status', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('deposit.id', $deposit->id)
            ->assertJsonPath('deposit.reference_code', $deposit->reference_code)
            ->assertJsonPath('deposit.payment_method', 'card')
            ->assertJsonPath('deposit.status', 'pending');

        $this->assertSame('50.00', (string) $response->json('deposit.amount'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $payload = $response->json('deposit');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('stripe_session_id', $payload);
        $this->assertArrayNotHasKey('stripe_payment_intent_id', $payload);
        $this->assertArrayNotHasKey('stripe_response', $payload);
        $this->assertArrayNotHasKey('admin_notes', $payload);

        $body = $response->getContent();
        $this->assertStringNotContainsString('cs_test_secret_session', $body);
        $this->assertStringNotContainsString('pi_test_secret_intent', $body);
        $this->assertStringNotContainsString('secret_xyz', $body);

        $other = $this->advertiser();
        $this->actingAs($other)
            ->getJson(route('advertiser.add-funds.status', $deposit->id))
            ->assertNotFound();
    }
}
