<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingActivateSitesPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function userWithRoles(array $roleNames, ?string $active = null, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['email_verified_at' => now()], $attrs));
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

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ready Site',
            'site_url' => 'https://ready-site.example',
            'domain' => 'ready-site.example',
            'example_url' => 'https://ready-site.example/sample',
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Ready for admin activation description. ', 2),
            'verified' => true,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ], $overrides));
    }

    public function test_admin_can_grant_and_revoke_marketing_without_activate_toggle(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $member = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
                'can_activate_sites' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('marketing', true)
            ->assertJsonPath('can_activate_sites', true);

        $member->refresh();
        $this->assertTrue($member->hasRole('marketing'));
        $this->assertTrue((bool) $member->can_activate_sites);
        $this->assertTrue($member->fresh()->canActivateSites());

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => false,
                'can_activate_sites' => true,
            ])
            ->assertOk()
            ->assertJsonPath('marketing', false)
            ->assertJsonPath('can_activate_sites', false);

        $this->assertFalse($member->fresh()->hasRole('marketing'));
        $this->assertFalse((bool) $member->fresh()->can_activate_sites);
    }

    public function test_marketer_can_activate_without_legacy_grant_flag(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => false]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher);

        $this->assertTrue($marketer->canActivateSites());

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_marketer_with_permission_can_activate_ready_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher);

        $this->assertTrue($marketer->canActivateSites());

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_marketer_with_permission_can_activate_completed_bulk_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Bulk Ready',
            'site_url' => 'https://bulk-ready.example',
            'domain' => 'bulk-ready.example',
            // bulk_site_request_id left null — FK not needed; status mirrors post-publisher completion.
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_marketer_cannot_activate_awaiting_details_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Incomplete Draft',
            'site_url' => 'https://incomplete-draft.example',
            'domain' => 'incomplete-draft.example',
            'description' => 'Please replace this placeholder with a real site description (at least 50 characters) before submitting for review.',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'turnaround_time' => '3days',
        ]);

        $this->assertTrue($site->awaitsPublisherDetails());

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Publisher details are still incomplete. The listing cannot be activated yet.');

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertTrue($site->awaitsPublisherDetails());
    }

    public function test_marketer_cannot_activate_details_complete_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Publisher Reviewing Site',
            'site_url' => 'https://publisher-reviewing.example',
            'domain' => 'publisher-reviewing.example',
            'onboarding_status' => Site::ONBOARDING_DETAILS_COMPLETE,
        ]);

        $this->assertTrue($site->hasDetailsComplete());

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Publisher is still reviewing this listing.');

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertTrue($site->hasDetailsComplete());
    }

    public function test_marketer_cannot_activate_archived_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Archived Ready Site',
            'site_url' => 'https://archived-ready-activate.example',
            'domain' => 'archived-ready-activate.example',
            'archived_at' => now(),
        ]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_marketer_cannot_activate_site_without_marketplace_country(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'No Country Site',
            'site_url' => 'https://no-country.example',
            'domain' => 'no-country.example',
            'country' => '',
            'countries' => [],
        ]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('missing_market', true);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_marketer_cannot_activate_site_below_quality_bar(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Thin Metrics Site',
            'site_url' => 'https://thin-metrics.example',
            'domain' => 'thin-metrics.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 500,
        ]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('below_quality_bar', true);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_admin_cannot_activate_awaiting_details_site(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertTrue($site->awaitsPublisherDetails());
    }

    public function test_marketer_with_permission_can_deactivate(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, ['active' => true]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), [
                'active' => 0,
                'reason' => 'Deactivated for quality review after policy concerns.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', false);

        $this->assertFalse((bool) $site->fresh()->active);
        $this->assertSame(
            'Deactivated for quality review after policy concerns.',
            $site->fresh()->status_reason
        );
    }

    public function test_marketer_can_deactivate_again_after_activate_with_reason(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => false]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher);
        $reason = 'Deactivated after activate for spam / thin content review.';

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true);

        $this->assertTrue((bool) $site->fresh()->active);
        $this->assertNull($site->fresh()->onboarding_status);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), [
                'active' => 0,
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', false)
            ->assertJsonPath('reason', $reason);

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertSame($reason, $site->status_reason);
        $this->assertSame($marketer->id, (int) $site->status_reason_by);

        // Manage menu must still offer Deactivate after activate (and Activate after deactivate).
        $html = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $this->assertStringContainsString('const isActive = Number(site.active) === 1', $html);
        $this->assertStringContainsString('marketingActivateBlocked', $html);
        $this->assertStringContainsString('data-status="0"', $html);
        $this->assertStringContainsString('Deactivate', $html);
        $this->assertStringContainsString('Reason for the publisher', $html);
    }

    public function test_marketer_deactivate_without_reason_returns_422(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, ['active' => true]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_users_page_does_not_expose_activate_permission_toggle(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('data-can-activate-sites', false)
            ->assertDontSee('Can activate websites', false)
            ->assertDontSee('Activate sites', false)
            ->assertSee('Marketing team member', false);
    }
}
