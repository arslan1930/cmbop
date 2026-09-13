<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminActivateQualityWarningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function publisher(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'publisher'],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function site(User $publisher, array $extra = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Activate Quality Site',
            'site_url' => 'https://activate-quality.test',
            'domain' => 'activate-quality.test',
            'example_url' => 'https://activate-quality.test/sample',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Below quality bar fixture',
            'verified' => true,
            'active' => false,
            'onboarding_status' => null,
        ], $extra));
    }

    public function test_activating_below_quality_bar_still_succeeds_with_warning(): void
    {
        $site = $this->site($this->publisher());
        $this->assertFalse($site->hasGoodMetrics());

        $this->actingAs($this->admin())
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true)
            ->assertJsonPath('below_quality_bar', true)
            ->assertJsonFragment(['warning' => 'Activated below the quality bar (DA ≥ 30, DR ≥ 30, traffic ≥ 10,000). Listing is live; consider updating metrics before promoting it.']);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_activating_quality_site_has_no_quality_warning(): void
    {
        $site = $this->site($this->publisher(), [
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
        ]);
        $this->assertTrue($site->hasGoodMetrics());

        $this->actingAs($this->admin())
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('below_quality_bar', false)
            ->assertJsonPath('warning', null);
    }
}
