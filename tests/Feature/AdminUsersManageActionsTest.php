<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersManageActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function adminUser(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Admin Operator',
            'email' => 'admin.operator@example.com',
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_users_page_keeps_manage_menu_unclipped_and_columns_readable(): void
    {
        $advertiser = Role::where('name', 'advertiser')->firstOrFail();
        $member = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
            'name' => 'Readable Name Here',
            'email' => 'readable.user@example.com',
            'phone' => '+32123456789',
            'country' => 'BE',
        ]);
        $member->roles()->attach($advertiser->id);

        $page = $this->actingAs($this->adminUser())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management', false)
            ->assertSee('admin-manage-dropdown', false)
            ->assertSee('admin-manage-dropdown.js', false)
            ->assertSee('admin-col-email', false)
            ->assertSee('admin-role-badges', false)
            ->assertSee('readable.user@example.com', false)
            ->assertSee('Readable Name Here', false)
            ->assertSee('Manage', false)
            ->assertSee('action-view', false)
            ->assertSee('action-roles', false)
            ->assertSee('admin-components.css', false)
            ->assertSee('function escapeHtml', false)
            ->assertSee('escapeHtml(name)', false)
            ->assertSee('admins stay in Admin', false);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $html = $page->getContent();

        // Table chrome lives in admin-components.css (Tier 3). Keep Manage menus
        // unclipped and avoid a table-wide center alignment that fights column classes.
        $css = (string) file_get_contents(public_path('assets/css/admin-components.css'));
        $this->assertDoesNotMatchRegularExpression(
            '/\.modern-table\s*\{[^}]*overflow:\s*hidden/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.modern-table\s*\{[^}]*text-align:\s*center/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.modern-table\s*\{[^}]*overflow:\s*visible/s',
            $css
        );
    }

    public function test_users_index_deep_link_loads_that_user(): void
    {
        $admin = $this->adminUser();
        $advertiser = Role::where('name', 'advertiser')->firstOrFail();
        $target = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
            'name' => 'Deep Link Target',
            'email' => 'deep-link-target@example.com',
        ]);
        $target->roles()->attach($advertiser->id);
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
            'name' => 'Someone Else',
            'email' => 'someone-else@example.com',
        ]);
        $other->roles()->attach($advertiser->id);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['user' => $target->id]))
            ->assertOk()
            ->assertSee('Deep Link Target')
            ->assertSee('id="user-'.$target->id.'"', false)
            ->assertDontSee('Someone Else')
            ->assertSee('All users', false)
            ->assertSee(route('admin.users.index'), false);
    }

    public function test_users_page_escapes_html_in_display_names(): void
    {
        $advertiser = Role::where('name', 'advertiser')->firstOrFail();
        $member = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
            'name' => 'Alice <img src=x onerror=alert(1)>',
            'email' => 'alice.xss@example.com',
        ]);
        $member->roles()->attach($advertiser->id);

        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('Alice &lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('function escapeHtml(str)', $html);
        $this->assertStringContainsString('escapeHtml(name)', $html);
    }
}
