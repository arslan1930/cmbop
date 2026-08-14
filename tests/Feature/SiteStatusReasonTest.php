<?php

namespace Tests\Feature;

use App\Mail\SiteStatusNotification;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SiteStatusReasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function makeUser(string $roleName, array $attrs = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Reason Test Site',
            'site_url' => 'https://reason-test.example',
            'domain' => 'reason-test.example',
            'example_url' => 'https://reason-test.example/sample',
            'da' => 25,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 90,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Site status reason test description. ', 2),
            'verified' => true,
            'active' => true,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ], $overrides));
    }

    public function test_unverify_requires_reason(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_deactivate_requires_reason(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_delete_requires_reason(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, [
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_archive_requires_reason_and_persists_it(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $reason = 'Publisher asked to take this listing off the catalog.';

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertNull($site->fresh()->archived_at);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('archived', true);

        $site->refresh();
        $this->assertNotNull($site->archived_at);
        $this->assertSame($reason, $site->status_reason);
        $this->assertSame($admin->id, (int) $site->status_reason_by);

        Mail::assertQueued(SiteStatusNotification::class, function (SiteStatusNotification $mail) use ($publisher, $reason) {
            return $mail->hasTo($publisher->email)
                && $mail->action === 'archived'
                && $mail->reason === $reason;
        });
    }

    public function test_unverify_with_reason_notifies_publisher(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $reason = 'Metrics appear inflated and niche does not match submitted category.';

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $site->id), [
                'verified' => 0,
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertFalse((bool) $site->verified);
        $this->assertSame($reason, $site->status_reason);
        $this->assertSame($admin->id, (int) $site->status_reason_by);
        $this->assertNotNull($site->status_reason_at);

        Mail::assertQueued(SiteStatusNotification::class, function (SiteStatusNotification $mail) use ($publisher, $reason) {
            return $mail->hasTo($publisher->email)
                && $mail->action === 'unverified'
                && $mail->reason === $reason;
        });

        $bell = InAppNotification::where('user_id', $publisher->id)
            ->where('audience', InAppNotification::AUDIENCE_PUBLISHER)
            ->latest('id')
            ->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString($reason, (string) $bell->message);
    }

    public function test_deactivate_with_reason_notifies_publisher(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $reason = 'Spam / doorway content detected on the live domain.';

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), [
                'active' => 0,
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertSame($reason, $site->status_reason);

        Mail::assertQueued(SiteStatusNotification::class, function (SiteStatusNotification $mail) use ($reason) {
            return $mail->action === 'deactivated' && $mail->reason === $reason;
        });

        $html = (new SiteStatusNotification($site, 'deactivated', null, $reason))->render();
        $this->assertStringContainsString('Reason:', $html);
        $this->assertStringContainsString($reason, $html);
        $this->assertStringContainsString(
            parse_url(route('publisher.websites'), PHP_URL_PATH),
            $html
        );
        $this->assertStringNotContainsString(url('/login'), $html);
    }

    public function test_verify_and_activate_work_without_reason(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, ['verified' => false, 'active' => false]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->verified);
        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_marketer_can_deactivate_with_reason(): void
    {
        $marketer = $this->makeUser('marketing', ['can_activate_sites' => true]);
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $reason = 'Temporary deactivation pending publisher cleanup of spam links.';

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), [
                'active' => 0,
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($reason, $site->fresh()->status_reason);
        $this->assertSame($marketer->id, (int) $site->fresh()->status_reason_by);
    }

    public function test_policy_pages_include_listing_reason_copy(): void
    {
        $this->get(route('terms-of-services'))
            ->assertOk()
            ->assertSee('Publisher listings', false)
            ->assertSee('When we reject or deactivate a listing', false);

        $this->get(route('refund-policy'))
            ->assertOk()
            ->assertSee('Publisher listing decisions', false)
            ->assertSee('does not by itself create a cash refund', false);
    }

    public function test_staff_ui_prompts_for_reason_on_negative_actions(): void
    {
        $html = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $this->assertStringContainsString('needsReason = !activating', $html);
        $this->assertStringContainsString("needsReason = newStatus === 'unverify'", $html);
        $this->assertStringContainsString("input: needsReason ? 'textarea' : undefined", $html);
        $this->assertStringContainsString('payload.reason', $html);
        $this->assertStringContainsString('Reason for the publisher', $html);
    }
}
