<?php

namespace Tests\Feature;

use App\Mail\PayoutProfileUpdatedBySupport;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublisherWithdrawPayoutLockTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 100,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_withdraw_page_shows_double_check_guidance(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee('Double-check your payout details')
            ->assertSee('details_confirmed', false)
            ->assertSee('account_number_confirm', false);
    }

    public function test_first_withdraw_requires_confirm_and_locks_profile(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payout_locked', true);

        $publisher->refresh();
        $this->assertTrue($publisher->payoutProfileLocked());
        $this->assertSame('pay@example.com', $publisher->payout_paypal_email);
        $this->assertSame('paypal', $publisher->payout_preferred_method);
    }

    public function test_mismatched_confirm_is_rejected(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 10,
                'payment_method' => 'wise',
                'wise_email' => 'a@example.com',
                'wise_email_confirm' => 'b@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse($publisher->fresh()->payoutProfileLocked());
    }

    public function test_locked_profile_cannot_switch_method(): void
    {
        $publisher = $this->publisher();
        $publisher->forceFill([
            'payout_paypal_email' => 'locked@example.com',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 10,
                'payment_method' => 'wise',
                'wise_email' => 'new@example.com',
                'wise_email_confirm' => 'new@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('locked@example.com', $publisher->fresh()->payout_paypal_email);
    }

    public function test_locked_withdraw_uses_saved_paypal(): void
    {
        $publisher = $this->publisher();
        $publisher->forceFill([
            'payout_paypal_email' => 'locked@example.com',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 15,
                'payment_method' => 'paypal',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $publisher->id,
            'payment_method' => 'paypal',
            'amount' => 15,
        ]);
    }

    public function test_admin_can_update_payout_and_emails_publisher(): void
    {
        Mail::fake();

        $publisher = $this->publisher();
        $publisher->forceFill([
            'payout_paypal_email' => 'old@example.com',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.users.updatePayoutProfile', $publisher->id), [
                'payment_method' => 'paypal',
                'paypal_email' => 'new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('new@example.com', $publisher->fresh()->payout_paypal_email);
        Mail::assertQueued(PayoutProfileUpdatedBySupport::class);
    }
}
