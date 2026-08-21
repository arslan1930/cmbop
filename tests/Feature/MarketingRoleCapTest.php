<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\UserController;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingRoleCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRoles(array $roleNames, ?string $active = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $ids = [];
        foreach ($roleNames as $name) {
            $ids[$name] = Role::where('name', $name)->value('id');
            $user->roles()->attach($ids[$name]);
        }
        $activeName = $active ?? $roleNames[0];
        $user->active_role_id = $ids[$activeName];
        $user->save();

        return $user->fresh(['roles']);
    }

    public function test_only_admin_can_open_users_page(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');

        $page = $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk();
        $page->assertSee('Marketing seats:', false);
        $page->assertSee('id="marketingSeatsCount"', false);
        $page->assertSee('action-roles', false);
        $page->assertDontSee('can_activate_sites: !!toggle.checked && !!(activateToggle && activateToggle.checked)', false);
        $page->assertDontSee('Can activate websites', false);
        $page->assertDontSee('activateSitesToggle', false);
        $page->assertSee('roleUpdateUrl', false);
        $page->assertSee('__ID__', false);
        $this->assertMatchesRegularExpression(
            '/id="marketingSeatsCount">\s*0\s*<\/strong>\s*\/\s*'.UserController::MAX_MARKETING.'/',
            $page->getContent()
        );

        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $advertiser = $this->userWithRoles(['advertiser'], 'advertiser');

        $this->actingAs($marketer)
            ->get(route('admin.users.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($advertiser)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_only_admin_can_grant_or_revoke_marketing(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $member = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');

        $this->actingAs($marketer)
            ->postJson(route('admin.users.updateRoles', $member->id), ['marketing' => true])
            ->assertForbidden();

        $this->assertFalse($member->fresh()->hasRole('marketing'));
    }

    public function test_admin_can_grant_marketing_up_to_five(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $this->assertSame(5, UserController::MAX_MARKETING);

        for ($i = 0; $i < UserController::MAX_MARKETING; $i++) {
            $member = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');
            $this->actingAs($admin)
                ->postJson(route('admin.users.updateRoles', $member->id), ['marketing' => true])
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('marketing', true)
                ->assertJsonPath('marketing_count', $i + 1)
                ->assertJsonPath('max_marketing', 5);

            $this->assertTrue($member->fresh()->hasRole('marketing'));
            $this->assertSame('marketing', $member->fresh()->activeRole());
        }

        $overflow = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');
        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $overflow->id), ['marketing' => true])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('marketing_count', 5);

        $this->assertFalse($overflow->fresh()->hasRole('marketing'));
    }

    public function test_revoking_frees_a_marketing_seat(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $holders = [];
        for ($i = 0; $i < UserController::MAX_MARKETING; $i++) {
            $member = $this->userWithRoles(['advertiser'], 'advertiser');
            $this->actingAs($admin)
                ->postJson(route('admin.users.updateRoles', $member->id), ['marketing' => true])
                ->assertOk();
            $holders[] = $member;
        }

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $holders[0]->id), ['marketing' => false])
            ->assertOk()
            ->assertJsonPath('marketing', false)
            ->assertJsonPath('marketing_count', 4);

        $replacement = $this->userWithRoles(['advertiser'], 'advertiser');
        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $replacement->id), ['marketing' => true])
            ->assertOk()
            ->assertJsonPath('marketing_count', 5);
    }

    public function test_regranting_existing_marketer_does_not_consume_extra_seat(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $member = $this->userWithRoles(['advertiser', 'marketing'], 'marketing');

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), ['marketing' => true])
            ->assertOk()
            ->assertJsonPath('marketing_count', 1);
    }

    public function test_grant_marketing_succeeds_and_activates_workspace(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $member = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');

        $this->assertTrue(User::ensureCanActivateSitesColumn());

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
                'can_activate_sites' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('marketing', true)
            ->assertJsonPath('active_role', 'marketing')
            ->assertJsonFragment(['message' => 'Marketing access granted. They can review and activate sites (Activate also verifies review-ready listings; the Verify button stays admin-only).']);

        $member->refresh();
        $this->assertTrue($member->hasRole('marketing'));
        $this->assertSame('marketing', $member->activeRole());
        $this->assertTrue($member->canActivateSites());
    }
}
