<?php

namespace Tests\Feature;

use App\Mail\BulkSiteRequestCancelled;
use App\Mail\SiteStatusNotification;
use App\Models\BulkSiteRequest;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Approvals were already announced; refusals were not. A publisher whose
 * submission was deleted, or whose bulk batch was cancelled, saw their work
 * disappear with no message — which reads as the platform losing it rather than
 * rejecting it, and turns a decision into a support ticket.
 */
class AdminRejectionNotifiesPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Rejected Site',
            'site_url' => 'https://rejected.example',
            'domain' => 'rejected.example',
            'da' => 20, 'dr' => 20, 'traffic' => 500,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 40,
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'Test site', 'verified' => false, 'active' => false,
        ]);
    }

    public function test_deleting_a_submission_tells_the_publisher_why(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => 'Traffic could not be verified against the analytics screenshot.',
            ])
            ->assertOk();

        Mail::assertQueued(SiteStatusNotification::class, function (SiteStatusNotification $mail) use ($publisher) {
            return $mail->hasTo($publisher->email)
                && $mail->action === 'removed'
                && $mail->reason === 'Traffic could not be verified against the analytics screenshot.';
        });

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'audience' => InAppNotification::AUDIENCE_PUBLISHER,
        ]);

        $bell = InAppNotification::where('user_id', $publisher->id)->latest('id')->first();
        $this->assertStringContainsString('Traffic could not be verified', (string) $bell->message);
    }

    public function test_the_site_is_still_deleted(): void
    {
        $admin = $this->userWithRole('admin');
        $site = $this->site($this->userWithRole('publisher'));

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => 'Traffic could not be verified against the analytics screenshot.',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_delete_without_a_reason_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');
        $site = $this->site($this->userWithRole('publisher'));

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        Mail::assertNotQueued(SiteStatusNotification::class);
    }

    public function test_delete_with_a_short_reason_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');
        $site = $this->site($this->userWithRole('publisher'));

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => 'too short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_cancelling_a_bulk_request_tells_the_publisher(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 12,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.bulk-site-requests.cancel', $bulk->id), [
                'reason' => 'Duplicate of an earlier batch.',
            ])
            ->assertRedirect();

        Mail::assertQueued(BulkSiteRequestCancelled::class, fn ($mail) => $mail->hasTo($publisher->email));

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'audience' => InAppNotification::AUDIENCE_PUBLISHER,
        ]);

        $bell = InAppNotification::where('user_id', $publisher->id)->latest('id')->first();
        $this->assertStringContainsString('Duplicate of an earlier batch', (string) $bell->message);
        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
    }

    public function test_approving_a_site_still_notifies_as_before(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

        Mail::assertQueued(SiteStatusNotification::class, fn ($mail) => $mail->hasTo($publisher->email));
    }
}
