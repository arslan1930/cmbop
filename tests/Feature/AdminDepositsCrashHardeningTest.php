<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminDepositsCrashHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
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

    private function depositFor(User $advertiser, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-'.uniqid(),
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_index_view_survives_a_missing_user(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);
        $deposit->setRelation('user', null);

        $this->actingAs($admin)->withViewErrors([]);

        $html = view('admin.deposits', [
            'deposits' => new LengthAwarePaginator(collect([$deposit]), 1, 20),
            'stats' => [
                'pending' => 1,
                'user_reported_paid' => 0,
                'approved' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total_amount' => 0,
            ],
            'invoiceLinks' => collect(),
        ])->render();

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString($deposit->reference_code, $html);
        $this->assertStringContainsString('deposit.user || {}', $html);
    }

    public function test_array_admin_notes_do_not_approve_or_reject(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $approve = $this->depositFor($advertiser, ['reference_code' => 'DEP-NOTE-A']);
        $reject = $this->depositFor($advertiser, ['reference_code' => 'DEP-NOTE-R']);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $approve->id), [
                'admin_notes' => ['injected'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $reject->id), [
                'admin_notes' => ['injected'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->assertSame('pending', $approve->fresh()->status);
        $this->assertSame('pending', $reject->fresh()->status);
    }

    public function test_oversized_admin_notes_are_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => str_repeat('x', 1001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_index_uses_named_deposit_action_routes(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();

        $showPath = route('admin.deposits.show', $deposit->id, false);
        $this->assertStringContainsString('data-show-url="'.$showPath.'"', $html);
        $this->assertStringNotContainsString('data-show-url="'.route('admin.deposits.show', $deposit->id).'"', $html);
        $this->assertStringContainsString('readJsonResponse', $html);
        $this->assertStringNotContainsString("fetch('/admin/deposits/'", $html);
        $this->assertStringNotContainsString('fetch(`/admin/deposits/${id}/approve`', $html);
        $this->assertStringNotContainsString('fetch(`/admin/deposits/${id}/reject`', $html);

        $approveJson = json_encode(route('admin.deposits.approve', ['id' => '__ID__'], false), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $rejectJson = json_encode(route('admin.deposits.reject', ['id' => '__ID__'], false), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->assertStringContainsString($approveJson, $html);
        $this->assertStringContainsString($rejectJson, $html);

        $advertiser->forceFill([
            'payout_paypal_email' => 'hidden-pay@example.com',
            'payout_bank_account' => 'DE89370400440532013000',
        ])->save();

        $show = $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deposit.user.email', $advertiser->email);

        $this->assertStringContainsString('no-store', (string) $show->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('payout_paypal_email', $show->json('deposit.user') ?? []);
        $this->assertArrayNotHasKey('payout_bank_account', $show->json('deposit.user') ?? []);
        $this->assertStringNotContainsString('hidden-pay@example.com', $show->getContent());
        $this->assertStringNotContainsString('DE89370400440532013000', $show->getContent());
    }
}
