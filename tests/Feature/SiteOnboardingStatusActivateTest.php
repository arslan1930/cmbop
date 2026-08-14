<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SiteOnboardingStatusActivateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeAwaitingSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Awaiting Blog',
            'site_url' => 'https://awaiting-blog.example',
            'domain' => 'awaiting-blog.example',
            'example_url' => 'https://awaiting-blog.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Please replace this placeholder with a real site description before review.',
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'turnaround_time' => '3days',
        ]);
    }

    public function test_admin_activate_is_blocked_while_awaiting_details(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->makeAwaitingSite($publisher);

        $this->assertTrue($site->awaitsPublisherDetails());

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertTrue($site->awaitsPublisherDetails());
    }

    public function test_marketer_cannot_activate_awaiting_details(): void
    {
        $marketer = $this->userWithRole('marketing');
        $publisher = $this->userWithRole('publisher');
        $site = $this->makeAwaitingSite($publisher);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Publisher has not finished listing details.');

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertTrue($site->awaitsPublisherDetails());
    }
}
