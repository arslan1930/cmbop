<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use App\Support\ProductionRepair;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        StaffCapability::ensureTable();
    }

    public function test_unrestricted_admin_keeps_finance_and_users(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get(route('admin.finance'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Money')
            ->assertSee('Work inbox')
            ->assertSee('Due to pay now');
    }

    public function test_support_only_cannot_open_finance_and_can_open_users(): void
    {
        $admin = $this->userWithRole('admin');
        $this->restrict($admin, [StaffCapability::SUPPORT]);

        $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->getJson(route('admin.deposits'))
            ->assertForbidden()
            ->assertJsonPath('message', 'This area is limited to a different admin capability.');

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.inbox.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.legal.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.categories.index'))->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.analytics'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertDontSee('Order Payments');
        $this->actingAs($admin)
            ->getJson(route('admin.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('>Money<', false)
            ->assertSee('Work inbox')
            ->assertSee('Legal &amp; FAQ', false)
            ->assertSee('Niches')
            ->assertDontSee('Due to pay now');
    }

    public function test_finance_only_can_open_finance_and_cannot_open_inbox(): void
    {
        $admin = $this->userWithRole('admin');
        $this->restrict($admin, [StaffCapability::FINANCE]);

        $this->actingAs($admin)->get(route('admin.finance'))->assertOk();
        $this->actingAs($admin)->get(route('admin.analytics'))->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Order Payments');
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.inbox.index'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)
            ->get(route('admin.legal.index'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)
            ->getJson(route('admin.community.index'))
            ->assertForbidden();
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Money')
            ->assertSee('Analytics')
            ->assertDontSee('Work inbox')
            ->assertDontSee('Legal &amp; FAQ', false)
            ->assertSee('Due to pay now');
    }

    public function test_support_only_cannot_approve_deposits_or_edit_payout(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $this->restrict($admin, [StaffCapability::SUPPORT]);

        $this->actingAs($admin)
            ->post(route('admin.deposits.approve', 1))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->postJson(route('admin.users.updatePayoutProfile', $publisher->id), [
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
            ])
            ->assertForbidden();
    }

    public function test_finance_only_cannot_suspend_or_grant_marketing(): void
    {
        $admin = $this->userWithRole('admin');
        $member = $this->userWithRole('advertiser');
        $this->restrict($admin, [StaffCapability::FINANCE]);

        $this->actingAs($admin)
            ->post(route('admin.users.suspend', $member), [
                'reason' => 'Too many chargebacks on this account',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
            ])
            ->assertForbidden();
    }

    public function test_full_admin_can_set_and_clear_capabilities_on_another_admin(): void
    {
        $full = $this->userWithRole('admin', ['email' => 'full.admin@example.com']);
        $other = $this->userWithRole('admin', ['email' => 'limited.admin@example.com']);

        $this->actingAs($full)
            ->from(route('admin.users.show', $other))
            ->post(route('admin.users.capabilities', $other), [
                'access' => 'limited',
                'capabilities' => ['support'],
            ])
            ->assertRedirect(route('admin.users.show', $other))
            ->assertSessionHas('success');

        $other = $other->fresh();
        $this->assertFalse($other->staffIsUnrestricted());
        $this->assertTrue($other->staffCan(StaffCapability::SUPPORT));
        $this->assertFalse($other->staffCan(StaffCapability::FINANCE));

        $this->actingAs($full)
            ->post(route('admin.users.capabilities', $other), [
                'access' => 'full',
            ])
            ->assertRedirect();

        $this->assertTrue($other->fresh()->staffIsUnrestricted());
    }

    public function test_cannot_change_own_capabilities(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.users.show', $admin))
            ->post(route('admin.users.capabilities', $admin), [
                'access' => 'limited',
                'capabilities' => ['finance'],
            ])
            ->assertRedirect(route('admin.users.show', $admin))
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->staffIsUnrestricted());
    }

    public function test_limited_admin_cannot_change_capabilities(): void
    {
        $full = $this->userWithRole('admin', ['email' => 'full.admin@example.com']);
        $limited = $this->userWithRole('admin', ['email' => 'limited.admin@example.com']);
        $this->restrict($limited, [StaffCapability::SUPPORT]);

        $this->actingAs($limited)
            ->post(route('admin.users.capabilities', $full), [
                'access' => 'limited',
                'capabilities' => ['finance'],
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue($full->fresh()->staffIsUnrestricted());
    }

    public function test_missing_table_is_unrestricted(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('staff_capabilities');
        $this->assertFalse(StaffCapability::tableReady());
        app()->forgetInstance(StaffCapabilityService::class);

        $this->actingAs($admin)->get(route('admin.finance'))->assertOk();
        $this->actingAs($admin)->get(route('admin.inbox.index'))->assertOk();
    }

    public function test_repair_creates_staff_capabilities_table_when_missing(): void
    {
        Schema::dropIfExists('staff_capabilities');
        $this->assertFalse(ProductionRepair::staffCapabilityStorageReady());

        $notes = [];
        app(ProductionRepair::class)->ensureStaffCapabilities($notes);

        $this->assertTrue(Schema::hasTable('staff_capabilities'));
        $this->assertTrue(collect($notes)->contains('staff capabilities table ready'));
    }

    public function test_dashboard_finance_json_requires_finance_capability(): void
    {
        $admin = $this->userWithRole('admin');
        $this->restrict($admin, [StaffCapability::SUPPORT]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.finance'))
            ->assertForbidden();
        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.ops-health'))
            ->assertOk();
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function restrict(User $user, array $capabilities): void
    {
        StaffCapability::ensureTable();
        StaffCapability::query()->where('user_id', $user->id)->delete();
        foreach ($capabilities as $capability) {
            StaffCapability::query()->create([
                'user_id' => $user->id,
                'capability' => $capability,
            ]);
        }
        app()->forgetInstance(StaffCapabilityService::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'password' => 'password',
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
