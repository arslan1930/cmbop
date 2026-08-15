<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualWithdrawalMarkPaidLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ManualWithdrawalMarkPaidConfirmLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'billing.withdrawal_mark_paid_link_expire_minutes' => 60 * 24 * 7,
        ]);
        URL::forceRootUrl('http://127.0.0.1:8000');
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function pendingWithdrawal(User $user, float $amount = 90): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'fee' => 0,
            'net_amount' => $amount,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'wise@example.com'],
            'status' => 'pending',
        ]);
    }

    private function relativeSignedUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    public function test_mark_paid_link_is_temporary_signed_public_url(): void
    {
        $withdrawal = $this->pendingWithdrawal($this->makeUser('publisher'));
        $url = ManualWithdrawalMarkPaidLink::url($withdrawal);

        $this->assertStringContainsString('/admin/withdrawals/'.$withdrawal->id.'/mark-paid-confirm', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST));
    }

    public function test_signed_get_shows_confirm_ui_without_settling(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->pendingWithdrawal($publisher, 90);
        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Confirm marked paid', false)
            ->assertSee('€90.00', false)
            ->assertSee('WD-'.$withdrawal->id, false)
            ->assertSee('Confirm marked paid —', false)
            ->assertSee('Wallet snapshot', false)
            ->assertSee('No completed payouts yet', false)
            ->assertSee('href="'.route('admin.withdrawals', [], false).'"', false);

        $this->assertSame('pending', $withdrawal->fresh()->status);
    }

    public function test_confirm_ui_warns_when_same_net_was_recently_paid(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 90,
            'fee' => 0,
            'net_amount' => 90,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'old@example.com'],
            'status' => 'completed',
            'processed_at' => now()->subDays(2),
        ]);

        $withdrawal = $this->pendingWithdrawal($publisher, 90);
        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Possible duplicate payout', false)
            ->assertSee('Recent paid withdrawals', false)
            ->assertSee('€10.00', false)
            ->assertSee('Confirm marked paid —', false);
    }

    public function test_confirm_ui_shows_debited_wallet_balance_not_publisher_preference(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisher->roles()->syncWithoutDetaching([$advertiserRole->id]);

        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $advertiserWallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 77,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $withdrawal = $this->pendingWithdrawal($publisher, 90);
        $withdrawal->forceFill(['wallet_id' => $advertiserWallet->id])->save();

        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('€77.00', false)
            ->assertDontSee('€10.00', false);
    }

    public function test_confirm_ui_skips_duplicate_warning_for_different_net(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'old@example.com'],
            'status' => 'completed',
            'processed_at' => now()->subDay(),
        ]);

        $withdrawal = $this->pendingWithdrawal($publisher, 90);
        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertDontSee('Possible duplicate payout', false)
            ->assertSee('Recent paid withdrawals', false);
    }

    public function test_signed_post_marks_paid_via_service(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->pendingWithdrawal($publisher, 90);
        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->actingAs($admin)
            ->post($url, ['notes' => 'Sent on Wise'])
            ->assertRedirect(route('admin.withdrawals'))
            ->assertSessionHas('success');

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame('Sent on Wise', $withdrawal->fresh()->admin_notes);
    }

    public function test_guest_and_unsigned_are_blocked(): void
    {
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->pendingWithdrawal($publisher);
        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->get($url)->assertRedirect();

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)
            ->get(route('admin.withdrawals.mark-paid-confirm.show', $withdrawal))
            ->assertRedirect(route('admin.withdrawals'))
            ->assertSessionHas('error');
    }

    public function test_already_completed_shows_settled_state(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->pendingWithdrawal($publisher);
        $withdrawal->update(['status' => 'completed', 'processed_at' => now()]);
        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Withdrawal already settled', false)
            ->assertDontSee('Confirm marked paid —', false);
    }
}
