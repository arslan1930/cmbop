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

        $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Recent activity', false)
            ->assertSee('Live', false)
            ->assertDontSee('Pending invoice deposits', false)
            ->assertSee('id="walletHistory"', false)
            ->assertSee('id="activityFeed"', false);
    }

    public function test_pending_deposit_appears_in_activity_feed_with_download(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'wise');

        $this->actingAs($user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'reference' => $deposit->reference_code,
                'status' => 'pending',
                'is_live_pending' => true,
            ]);

        $this->actingAs($user)
            ->get(route('advertiser.invoice', ['referenceCode' => $deposit->reference_code, 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_invoice_page_shows_mark_paid_button(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'wise');

        $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk()
            ->assertSee('OK, I have made the payment')
            ->assertSee('stays')
            ->assertSee('Pending');
    }
}
