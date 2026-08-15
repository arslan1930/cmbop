<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDepositsFiltersTest extends TestCase
{
    use RefreshDatabase;

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
            'amount' => 25,
            'payment_method' => 'bank',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_status_allowlist_ignores_unknown_and_array_values(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->depositFor($advertiser, ['reference_code' => 'DEP-KEEP-1']);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['status' => 'not-a-status']))
            ->assertOk()
            ->assertSee('DEP-KEEP-1', false);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['status' => ['pending']]))
            ->assertOk()
            ->assertSee('DEP-KEEP-1', false);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['reported_paid' => ['1']]))
            ->assertOk()
            ->assertSee('DEP-KEEP-1', false);
    }

    public function test_reported_paid_filter_only_shows_pending_marked_paid(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->depositFor($advertiser, [
            'reference_code' => 'DEP-PAID-1',
            'user_marked_paid_at' => now(),
        ]);
        $this->depositFor($advertiser, ['reference_code' => 'DEP-WAIT-1']);
        $this->depositFor($advertiser, [
            'reference_code' => 'DEP-DONE-1',
            'status' => 'completed',
            'user_marked_paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['reported_paid' => 1]))
            ->assertOk()
            ->assertSee('DEP-PAID-1', false)
            ->assertDontSee('DEP-WAIT-1', false)
            ->assertDontSee('DEP-DONE-1', false);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['status' => 'reported_paid']))
            ->assertOk()
            ->assertSee('DEP-PAID-1', false)
            ->assertDontSee('DEP-WAIT-1', false);
    }

    public function test_pagination_keeps_status_and_search(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        for ($i = 0; $i < 21; $i++) {
            $this->depositFor($advertiser, [
                'reference_code' => sprintf('DEP-PAGE-%02d', $i),
                'status' => 'pending',
            ]);
        }
        $this->depositFor($advertiser, [
            'reference_code' => 'DEP-OTHER-1',
            'status' => 'completed',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits', ['status' => 'pending', 'search' => 'DEP-PAGE', 'page' => 2]))
            ->assertOk()
            ->assertDontSee('DEP-OTHER-1', false)
            ->getContent();

        $this->assertStringContainsString('status=pending', $html);
        $this->assertStringContainsString('search=DEP-PAGE', $html);
    }

    public function test_kpis_drop_approved_and_link_reported_paid(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->assertSee('User reported paid', false)
            ->assertSee('Rejected', false)
            ->assertSee('Total completed', false)
            ->getContent();

        $this->assertStringContainsString(route('admin.deposits', ['reported_paid' => 1]), $html);
        $this->assertStringContainsString(route('admin.deposits', ['status' => 'rejected']), $html);
        $this->assertStringNotContainsString('>Approved</h6>', $html);
    }
}
