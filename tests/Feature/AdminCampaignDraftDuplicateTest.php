<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCampaignDraftDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeCampaign(User $admin, string $status, array $overrides = []): EmailCampaign
    {
        return EmailCampaign::create(array_merge([
            'name' => 'Spring update',
            'subject' => 'Platform update',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'selected',
            'selected_user_ids' => [99],
            'cta_label' => 'Open catalog',
            'cta_url' => 'https://example.com/catalog',
            'status' => $status,
            'respect_preferences' => false,
            'include_unverified' => true,
            'recipients_count' => $status === EmailCampaign::STATUS_DRAFT ? 0 : 1,
            'sent_count' => $status === EmailCampaign::STATUS_SENT ? 1 : 0,
            'created_by' => $admin->id,
        ], $overrides));
    }

    public function test_duplicate_from_draft_and_sent_creates_a_new_draft(): void
    {
        $admin = $this->makeUser('admin');
        $draft = $this->makeCampaign($admin, EmailCampaign::STATUS_DRAFT);
        $sent = $this->makeCampaign($admin, EmailCampaign::STATUS_SENT, [
            'name' => 'Sent blast',
            'subject' => 'Sent blast subject',
        ]);
        $advertiser = $this->makeUser('advertiser');
        EmailCampaignRecipient::create([
            'email_campaign_id' => $sent->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_DELIVERED,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.duplicate', $draft))
            ->assertRedirect();

        $fromDraft = EmailCampaign::query()->where('name', 'Spring update copy')->first();
        $this->assertNotNull($fromDraft);
        $this->assertTrue($fromDraft->isEditableDraft());
        $this->assertNotSame($draft->id, $fromDraft->id);
        $this->assertSame('Platform update', $fromDraft->subject);
        $this->assertSame('selected', $fromDraft->audience);
        $this->assertEquals([99], array_map('intval', $fromDraft->selected_user_ids ?? []));
        $this->assertTrue((bool) $fromDraft->include_unverified);
        $this->assertSame(0, $fromDraft->recipients()->count());
        $this->assertTrue($draft->fresh()->isDraft());

        $this->actingAs($admin)
            ->from(route('admin.campaigns.show', $sent))
            ->post(route('admin.campaigns.duplicate', $sent))
            ->assertRedirect();

        $fromSent = EmailCampaign::query()->where('name', 'Sent blast copy')->first();
        $this->assertNotNull($fromSent);
        $this->assertTrue($fromSent->isEditableDraft());
        $this->assertSame(0, $fromSent->recipients()->count());
        $this->assertSame(1, $sent->fresh()->recipients()->count());
        $this->assertGreaterThanOrEqual(2, ActivityLog::query()->where('action', 'campaign.duplicated')->count());
    }

    public function test_duplicate_rejects_queued_and_sending(): void
    {
        $admin = $this->makeUser('admin');
        $queued = $this->makeCampaign($admin, EmailCampaign::STATUS_QUEUED);
        $sending = $this->makeCampaign($admin, EmailCampaign::STATUS_SENDING);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.duplicate', $queued))
            ->assertNotFound();
        $this->actingAs($admin)
            ->post(route('admin.campaigns.duplicate', $sending))
            ->assertNotFound();

        $this->assertSame(2, EmailCampaign::query()->count());
    }

    public function test_marketing_cannot_duplicate(): void
    {
        $admin = $this->makeUser('admin');
        $draft = $this->makeCampaign($admin, EmailCampaign::STATUS_DRAFT);

        $this->actingAs($this->makeUser('marketing'))
            ->post(route('admin.campaigns.duplicate', $draft))
            ->assertRedirect(route('marketing.dashboard'));

        $this->assertSame(1, EmailCampaign::query()->count());
    }

    public function test_duplicate_route_is_throttled_and_linked(): void
    {
        $admin = $this->makeUser('admin');
        $draft = $this->makeCampaign($admin, EmailCampaign::STATUS_DRAFT);
        $sent = $this->makeCampaign($admin, EmailCampaign::STATUS_SENT, [
            'name' => 'Sent blast',
            'subject' => 'Sent blast subject',
        ]);

        $route = collect(app('router')->getRoutes())
            ->first(fn ($candidate) => $candidate->getName() === 'admin.campaigns.duplicate');
        $this->assertNotNull($route);
        $this->assertContains('throttle:20,1', $route->gatherMiddleware());

        $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts'))
            ->assertOk()
            ->assertSee(route('admin.campaigns.duplicate', $draft), false);

        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $sent))
            ->assertOk()
            ->assertSee(route('admin.campaigns.duplicate', $sent), false)
            ->assertDontSee(route('admin.campaigns.send'), false);
    }
}
