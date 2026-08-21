<?php

namespace Tests\Feature;

use App\Jobs\SendEmailCampaignJob;
use App\Models\ActivityLog;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminCampaignDraftsTest extends TestCase
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

    private function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Spring draft',
            'subject' => 'Held for later',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'respect_preferences' => '1',
        ], $overrides);
    }

    public function test_guest_is_redirected_from_drafts(): void
    {
        $this->get(route('admin.campaigns.drafts'))
            ->assertRedirect(route('login'));
    }

    public function test_advertiser_cannot_access_drafts(): void
    {
        $this->actingAs($this->makeUser('advertiser'))
            ->get(route('admin.campaigns.drafts'))
            ->assertForbidden();
    }

    public function test_marketing_is_redirected_from_drafts(): void
    {
        $this->actingAs($this->makeUser('marketing'))
            ->get(route('admin.campaigns.drafts'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_admin_can_save_and_reopen_a_draft(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->from(route('admin.campaigns.index'))
            ->post(route('admin.campaigns.drafts.store'), $this->draftPayload([
                'include_unverified' => '1',
                'body_html' => '<p>Hello partners.</p><script>alert(1)</script>',
            ]))
            ->assertRedirect();

        $draft = EmailCampaign::query()->latest('id')->first();
        $this->assertNotNull($draft);
        $this->assertTrue($draft->isDraft());
        $this->assertTrue($draft->isEditableDraft());
        $this->assertTrue((bool) $draft->include_unverified);
        $this->assertSame(0, (int) $draft->recipients_count);
        $this->assertSame(0, $draft->recipients()->count());
        $this->assertSame(0, EmailCampaignRecipient::query()->count());
        $this->assertStringContainsString('Hello partners.', (string) $draft->body_html);
        $this->assertStringNotContainsString('<script>', (string) $draft->body_html);
        $this->assertSame($admin->id, (int) $draft->created_by);
        $this->assertSame(1, ActivityLog::query()->where('action', 'campaign.draft_saved')->count());

        Queue::assertNothingPushed();
        $this->assertSame(0, EmailCampaign::recoverStalled());
        Queue::assertNothingPushed();

        $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts.edit', $draft))
            ->assertOk()
            ->assertSee('Held for later', false)
            ->assertSee('Hello partners.', false)
            ->assertSee('id="campaignSaveDraftBtn"', false)
            ->assertSee(route('admin.campaigns.drafts.update', $draft), false)
            ->assertSee('formaction', false);

        $html = $this->actingAs($admin)
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee(route('admin.campaigns.drafts'), false)
            ->getContent();
        $this->assertStringNotContainsString('Held for later', $html);
        $this->assertStringContainsString("e.submitter.id === 'campaignSaveDraftBtn'", $html);

        $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts'))
            ->assertOk()
            ->assertSee('Spring draft', false)
            ->assertSee('Held for later', false)
            ->assertSee(route('admin.campaigns.drafts.edit', $draft), false);
    }

    public function test_blank_and_javascript_cta_are_rejected(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->from(route('admin.campaigns.index'))
            ->post(route('admin.campaigns.drafts.store'), $this->draftPayload([
                'body_html' => '   ',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHasErrors('body_html');

        $this->actingAs($admin)
            ->from(route('admin.campaigns.index'))
            ->post(route('admin.campaigns.drafts.store'), $this->draftPayload([
                'cta_url' => 'javascript:alert(1)',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHasErrors('cta_url');

        $this->assertSame(0, EmailCampaign::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_update_rewrites_the_same_draft(): void
    {
        $admin = $this->makeUser('admin');
        $draft = EmailCampaign::create([
            'name' => 'Spring draft',
            'subject' => 'Held for later',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'respect_preferences' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.campaigns.drafts.edit', $draft))
            ->post(route('admin.campaigns.drafts.update', $draft), $this->draftPayload([
                'name' => 'Spring draft v2',
                'subject' => 'Updated later',
                'body_html' => '<p>Updated body.</p>',
                'respect_preferences' => '0',
                'include_unverified' => '1',
            ]))
            ->assertRedirect(route('admin.campaigns.drafts.edit', $draft));

        $this->assertSame(1, EmailCampaign::query()->count());
        $fresh = $draft->fresh();
        $this->assertSame('Spring draft v2', $fresh->name);
        $this->assertSame('Updated later', $fresh->subject);
        $this->assertStringContainsString('Updated body.', (string) $fresh->body_html);
        $this->assertFalse((bool) $fresh->respect_preferences);
        $this->assertTrue((bool) $fresh->include_unverified);
        $this->assertTrue($fresh->isDraft());
        $this->assertSame(0, $fresh->recipients()->count());
    }

    public function test_selected_draft_keeps_marketplace_ids_and_drops_staff(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.drafts.store'), $this->draftPayload([
                'audience' => 'selected',
                'user_ids' => [$admin->id, $advertiser->id],
            ]))
            ->assertRedirect();

        $draft = EmailCampaign::query()->latest('id')->first();
        $this->assertEquals([$advertiser->id], array_map('intval', $draft->selected_user_ids ?? []));
        $this->assertSame(0, $draft->recipients()->count());
    }

    public function test_show_redirects_drafts_to_edit(): void
    {
        $admin = $this->makeUser('admin');
        $draft = EmailCampaign::create([
            'name' => 'Spring draft',
            'subject' => 'Held for later',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $draft))
            ->assertRedirect(route('admin.campaigns.drafts.edit', $draft));
    }

    public function test_delete_draft_works_and_queued_or_sent_are_not_found(): void
    {
        $admin = $this->makeUser('admin');
        $draft = EmailCampaign::create([
            'name' => 'Spring draft',
            'subject' => 'Held for later',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $queued = EmailCampaign::create([
            'name' => 'Queued update',
            'subject' => 'Queued now',
            'body_html' => '<p>Now.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_QUEUED,
            'created_by' => $admin->id,
        ]);
        $sent = EmailCampaign::create([
            'name' => 'Sent update',
            'subject' => 'Already sent',
            'body_html' => '<p>Done.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_SENT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.campaigns.drafts.destroy', $queued))
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete(route('admin.campaigns.drafts.destroy', $sent))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts.edit', $queued))
            ->assertNotFound();

        $this->actingAs($admin)
            ->from(route('admin.campaigns.drafts'))
            ->delete(route('admin.campaigns.drafts.destroy', $draft))
            ->assertRedirect(route('admin.campaigns.drafts'));

        $this->assertNull(EmailCampaign::query()->find($draft->id));
        $this->assertNotNull($queued->fresh());
        $this->assertNotNull($sent->fresh());
        $this->assertSame(1, ActivityLog::query()->where('action', 'campaign.draft_deleted')->count());
    }

    public function test_marketing_cannot_save_or_delete_drafts(): void
    {
        $admin = $this->makeUser('admin');
        $draft = EmailCampaign::create([
            'name' => 'Spring draft',
            'subject' => 'Held for later',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $marketer = $this->makeUser('marketing');
        $this->actingAs($marketer)
            ->post(route('admin.campaigns.drafts.store'), $this->draftPayload())
            ->assertRedirect(route('marketing.dashboard'));
        $this->actingAs($marketer)
            ->delete(route('admin.campaigns.drafts.destroy', $draft))
            ->assertRedirect(route('marketing.dashboard'));

        $this->assertNotNull($draft->fresh());
        $this->assertSame(1, EmailCampaign::query()->where('status', EmailCampaign::STATUS_DRAFT)->count());
    }

    public function test_send_does_not_convert_an_existing_draft(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');
        $draft = EmailCampaign::create([
            'name' => 'Spring draft',
            'subject' => 'Held for later',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->draftPayload([
                'name' => 'Live blast',
                'subject' => 'Sending now',
                'respect_preferences' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'));

        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame(0, $draft->recipients()->count());
        $queued = EmailCampaign::query()->where('status', EmailCampaign::STATUS_QUEUED)->first();
        $this->assertNotNull($queued);
        $this->assertNotSame($draft->id, $queued->id);
        Queue::assertPushed(SendEmailCampaignJob::class);
    }

    public function test_draft_routes_are_throttled(): void
    {
        $routes = collect(app('router')->getRoutes());

        foreach (['admin.campaigns.drafts.store', 'admin.campaigns.drafts.update', 'admin.campaigns.drafts.destroy'] as $name) {
            $route = $routes->first(fn ($candidate) => $candidate->getName() === $name);
            $this->assertNotNull($route, $name);
            $this->assertContains('throttle:20,1', $route->gatherMiddleware(), $name);
        }
    }
}
