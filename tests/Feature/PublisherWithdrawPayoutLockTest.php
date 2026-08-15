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
                'amount' => 20,
                'payment_method' => 'wise',
                'wise_email' => 'a@example.com',
                'wise_email_confirm' => 'b@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse($publisher->fresh()->payoutProfileLocked());
    }

    public function test_locked_profile_can_switch_to_another_saved_method(): void
    {
        $publisher = $this->publisher();
        $publisher->forceFill([
            'payout_paypal_email' => 'locked@example.com',
            'payout_bank_name' => 'Demo Bank',
            'payout_bank_holder_name' => 'Jane Publisher',
            'payout_bank_account' => 'DE89370400440532013000',
            'payout_bank_swift' => 'COBADEFFXXX',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'payment_method' => 'bank',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $publisher->refresh();
        $this->assertSame('bank', $publisher->payout_preferred_method);
        $this->assertSame('locked@example.com', $publisher->payout_paypal_email);
        $this->assertSame('DE89370400440532013000', $publisher->payout_bank_account);

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $publisher->id,
            'payment_method' => 'bank',
            'amount' => 20,
        ]);
    }

    public function test_locked_profile_cannot_select_method_without_saved_details(): void
    {
        $publisher = $this->publisher();
        $publisher->forceFill([
            'payout_paypal_email' => 'locked@example.com',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $response = $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'payment_method' => 'wise',
                'wise_email' => 'new@example.com',
                'wise_email_confirm' => 'new@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('locked', strtolower((string) $response->json('message')));
        $this->assertSame('locked@example.com', $publisher->fresh()->payout_paypal_email);
        $this->assertNull($publisher->fresh()->payout_wise_email);
        $this->assertSame('paypal', $publisher->fresh()->payout_preferred_method);
    }

    public function test_locked_withdraw_page_lists_only_provided_methods(): void
    {
        $publisher = $this->publisher();
        $publisher->forceFill([
            'payout_paypal_email' => 'locked@example.com',
            'payout_wise_email' => 'wise@example.com',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $html = $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee('Choose a saved payout method', false)
            ->assertSee('value="paypal"', false)
            ->assertSee('value="wise"', false)
            ->getContent();

        $this->assertStringNotContainsString('value="bank"', $html);
        $this->assertStringNotContainsString('value="crypto"', $html);
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
                'amount' => 20,
                'payment_method' => 'paypal',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $publisher->id,
            'payment_method' => 'paypal',
            'amount' => 20,
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

        $update = $this->actingAs($admin)
            ->postJson(route('admin.users.updatePayoutProfile', $publisher->id), [
                'payment_method' => 'paypal',
                'paypal_email' => 'new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payout_profile.paypal_email', 'new@example.com');
        $this->assertStringContainsString('no-store', (string) $update->headers->get('Cache-Control'));

        $this->assertSame('new@example.com', $publisher->fresh()->payout_paypal_email);
        Mail::assertQueued(PayoutProfileUpdatedBySupport::class);
    }

    public function test_admin_users_page_does_not_store_cache_payout_dest(): void
    {
        $publisher = $this->publisher();
        $publisher->forceFill([
            'payout_paypal_email' => 'hidden-pay@example.com',
            'payout_bank_account' => 'DE89370400440532013000',
            'payout_crypto_trx_wallet' => 'TXhiddenwallet',
            'payout_preferred_method' => 'paypal',
        ])->save();

        $page = $this->actingAs($this->admin())
            ->get(route('admin.users.index', ['user' => $publisher->id]))
            ->assertOk()
            ->assertSee('data-paypal="hidden-pay@example.com"', false)
            ->assertSee('data-account="DE89370400440532013000"', false)
            ->assertSee('data-wallet="TXhiddenwallet"', false);

        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
    }
}
