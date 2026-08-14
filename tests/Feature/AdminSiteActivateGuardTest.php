<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSiteActivateGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Activate Guard Site',
            'site_url' => 'https://activate-guard.example',
            'domain' => 'activate-guard.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Activate guard listing description. ', 3),
            'verified' => true,
            'active' => false,
        ], $overrides));
    }

    public function test_activate_requires_verified(): void
    {
        $site = $this->site(['verified' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Verify this site before activating it.');

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_activate_requires_marketplace_country(): void
    {
        $site = $this->site([
            'country' => '',
            'countries' => [],
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_activate_rejects_archived_site(): void
    {
        $site = $this->site([
            'archived_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This site is archived and cannot be activated.');

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_activate_rejects_awaiting_details(): void
    {
        $site = $this->site([
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
        $this->assertTrue($site->fresh()->awaitsPublisherDetails());
    }

    public function test_ready_verified_site_can_be_activated(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_staff_list_flags_blocked_activate(): void
    {
        $blocked = $this->site([
            'site_name' => 'Blocked Activate',
            'site_url' => 'https://blocked-activate.example',
            'domain' => 'blocked-activate.example',
            'verified' => false,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('sites.0.id', $blocked->id)
            ->assertJsonPath('sites.0.can_activate', false)
            ->assertJsonPath('sites.0.activate_block_reason', 'Verify this site before activating it.');

        $blade = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $this->assertStringContainsString('activate_block_reason', $blade);
        $this->assertStringContainsString('Cannot activate', $blade);
    }
}
