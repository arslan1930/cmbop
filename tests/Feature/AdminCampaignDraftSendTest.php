<?php

namespace Tests\Feature;

use App\Jobs\SendEmailCampaignJob;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminCampaignDraftSendTest extends TestCase
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
            'respect_preferences' => '0',
        ], $overrides);
    }

    public function test_send_clones_a_queued_campaign_and_keeps_the_draft(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $first = $this->makeUser('advertiser');
        $draft = EmailCampaign::create([
            'name' => 'Spring draft',
            'subject' => 'Held for later',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);

        $second = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->draftPayload([
                'draft_id' => $draft->id,
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->draftPayload([
                'draft_id' => $draft->id,
            ]))
            ->assertRedirect(route('admin.campaigns.index'));

        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame(0, $draft->recipients()->count());
        $this->assertSame(1, EmailCampaign::query()->where('status', EmailCampaign::STATUS_DRAFT)->count());

        $queued = EmailCampaign::query()->where('status', EmailCampaign::STATUS_QUEUED)->orderBy('id')->get();
        $this->assertCount(2, $queued);
        $this->assertFalse($queued->contains('id', $draft->id));
        foreach ($queued as $clone) {
            $this->assertEqualsCanonicalizing(
                [$first->id, $second->id],
                $clone->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->all()
            );
        }

        Queue::assertPushed(SendEmailCampaignJob::class, 2);
        $this->assertSame(0, EmailCampaignRecipient::query()->where('email_campaign_id', $draft->id)->count());
    }

    public function test_send_from_queued_draft_id_is_not_found(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');
        $queued = EmailCampaign::create([
            'name' => 'Already queued',
            'subject' => 'Already queued',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_QUEUED,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->draftPayload([
                'draft_id' => $queued->id,
            ]))
            ->assertNotFound();

        $this->assertSame(1, EmailCampaign::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_edit_and_folder_expose_draft_id_for_send(): void
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

        $edit = $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts.edit', $draft))
            ->assertOk()
            ->assertSee('name="draft_id"', false)
            ->assertSee('value="'.$draft->id.'"', false)
            ->getContent();
        $this->assertStringContainsString('campaignSaveDraftBtn', $edit);
        $this->assertStringContainsString("e.submitter.id === 'campaignSaveDraftBtn'", $edit);

        $folder = $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts'))
            ->assertOk()
            ->assertSee(route('admin.campaigns.send'), false)
            ->assertSee('campaign-draft-send-form', false)
            ->assertSee('name="draft_id"', false)
            ->getContent();

        preg_match('/<form[^>]*campaign-draft-send-form[^>]*>.*?<button[^>]*>/s', $folder, $sendButton);
        $this->assertStringNotContainsString('data-slb-confirm', $sendButton[0] ?? '');
    }
}
