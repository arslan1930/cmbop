<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Services\Wallet\ManualDepositNotManualException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminDepositsApproveContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'billing.deposit_approve_link_expire_minutes' => 60 * 24 * 7,
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

    private function walletFor(User $user, float $balance = 0, float $bonus = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function depositFor(User $advertiser, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-CTX-'.uniqid(),
            'amount' => 80,
            'payment_method' => 'bank',
            'status' => 'pending',
        ], $overrides));
    }

    private function relativeSignedUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    public function test_show_includes_the_same_wallet_context_as_email_confirm(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser, 50, 10);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-OLD-50',
            'amount' => 50,
            'payment_method' => 'wise',
            'status' => 'completed',
            'approved_at' => now()->subDays(3),
        ]);

        $deposit = $this->depositFor($advertiser, [
            'reference_code' => 'DEP-NEW-80',
            'amount' => 80,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('wallet.can_approve', true)
            ->assertJsonPath('wallet.is_card', false)
            ->assertJsonPath('wallet.current_balance', 50)
            ->assertJsonPath('wallet.bonus_balance', 10)
            ->assertJsonPath('wallet.incoming_amount', 80)
            ->assertJsonPath('wallet.projected_balance', 130)
            ->assertJsonPath('wallet.possible_duplicate', false)
            ->assertJsonPath('wallet.prior_deposits.0.reference_code', 'DEP-OLD-50');
    }

    public function test_show_flags_a_possible_duplicate_same_amount(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser, 80);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-DUP-80',
            'amount' => 80,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now()->subDay(),
        ]);

        $deposit = $this->depositFor($advertiser, ['amount' => 80]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('wallet.possible_duplicate', true)
            ->assertJsonPath('wallet.duplicate_matches.0.reference_code', 'DEP-DUP-80');
    }

    public function test_pending_card_show_cannot_be_approved(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser, 20);
        $deposit = $this->depositFor($advertiser, [
            'payment_method' => 'card',
            'amount' => 40,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('wallet.can_approve', false)
            ->assertJsonPath('wallet.is_card', true)
            ->assertJsonPath('wallet.projected_balance', null)
            ->assertJsonPath('wallet.current_balance', 20);
    }

    public function test_approve_refuses_pending_card_and_does_not_credit(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->walletFor($advertiser, 15);
        $deposit = $this->depositFor($advertiser, [
            'payment_method' => 'card',
            'amount' => 40,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', ManualDepositNotManualException::forDeposit()->getMessage());

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(15.0, (float) $wallet->fresh()->balance);
        Mail::assertNotQueued(DepositApproved::class);
    }

    public function test_index_page_renders_shared_wallet_copy_and_hides_card_approve(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Wallet snapshot', $html);
        $this->assertStringContainsString('Possible duplicate', $html);
        $this->assertStringContainsString('After this approval', $html);
        $this->assertStringContainsString('wallet.can_approve', $html);
        $this->assertStringContainsString('wallet.is_card', $html);
        $this->assertStringContainsString('credit the wallet twice', $html);
        $this->assertStringContainsString('data.wallet', $html);
    }

    public function test_signed_get_for_card_does_not_offer_confirm(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser);
        $deposit = $this->depositFor($advertiser, ['payment_method' => 'card']);
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Card deposit — waits for Stripe', false)
            ->assertSee('credit the wallet twice', false)
            ->assertSee('Wallet snapshot', false)
            ->assertDontSee('Confirm and credit', false);

        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_signed_post_for_card_does_not_credit(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->walletFor($advertiser, 5);
        $deposit = $this->depositFor($advertiser, [
            'payment_method' => 'card',
            'amount' => 40,
        ]);
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->post($url)
            ->assertRedirect(route('admin.deposits'))
            ->assertSessionHas('error');

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(5.0, (float) $wallet->fresh()->balance);
        Mail::assertNotQueued(DepositApproved::class);
    }
}
