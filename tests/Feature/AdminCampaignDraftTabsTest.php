<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCampaignDraftTabsTest extends TestCase
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

    private function makeCampaign(User $admin, string $status, string $subject): EmailCampaign
    {
        return EmailCampaign::create([
            'name' => $subject,
            'subject' => $subject,
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => $status,
            'created_by' => $admin->id,
        ]);
    }

    public function test_compose_shows_tabs_and_edit_form(): void
    {
        $admin = $this->makeUser('admin');
        $draft = $this->makeCampaign($admin, EmailCampaign::STATUS_DRAFT, 'Tab draft subject');

        $index = $this->actingAs($admin)
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('Compose', false)
            ->assertSee(route('admin.campaigns.drafts'), false)
            ->assertSee('tab=sending', false)
            ->assertSee('tab=sent', false)
            ->assertSee('id="campaignForm"', false)
            ->assertSee('Compose campaign', false)
            ->assertDontSee('Edit draft', false)
            ->getContent();
        $this->assertStringNotContainsString('Tab draft subject', $index);

        $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts.edit', $draft))
            ->assertOk()
            ->assertSee('Edit draft', false)
            ->assertSee('Tab draft subject', false)
            ->assertSee('id="campaignForm"', false)
            ->assertSee(route('admin.campaigns.drafts.update', $draft), false)
            ->assertSee('>Compose</a>', false);
    }

    public function test_sending_and_sent_tabs_filter_recent_list(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeCampaign($admin, EmailCampaign::STATUS_DRAFT, 'Only in drafts');
        $queued = $this->makeCampaign($admin, EmailCampaign::STATUS_QUEUED, 'Queued blast subject');
        $sending = $this->makeCampaign($admin, EmailCampaign::STATUS_SENDING, 'Sending blast subject');
        $sent = $this->makeCampaign($admin, EmailCampaign::STATUS_SENT, 'Sent blast subject');
        $failed = $this->makeCampaign($admin, EmailCampaign::STATUS_FAILED, 'Failed blast subject');

        $sendingHtml = $this->actingAs($admin)
            ->get(route('admin.campaigns.index', ['tab' => 'sending']))
            ->assertOk()
            ->assertSee('Queued blast subject', false)
            ->assertSee('Sending blast subject', false)
            ->assertDontSee('id="campaignForm"', false)
            ->assertDontSee('No campaigns are sending.', false)
            ->getContent();
        $this->assertStringNotContainsString('Sent blast subject', $sendingHtml);
        $this->assertStringNotContainsString('Failed blast subject', $sendingHtml);
        $this->assertStringNotContainsString('Only in drafts', $sendingHtml);
        $this->assertStringContainsString(route('admin.campaigns.show', $queued), $sendingHtml);
        $this->assertStringContainsString(route('admin.campaigns.show', $sending), $sendingHtml);

        $sentHtml = $this->actingAs($admin)
            ->get(route('admin.campaigns.index', ['tab' => 'sent']))
            ->assertOk()
            ->assertSee('Sent blast subject', false)
            ->assertSee('Failed blast subject', false)
            ->assertDontSee('id="campaignForm"', false)
            ->getContent();
        $this->assertStringNotContainsString('Queued blast subject', $sentHtml);
        $this->assertStringNotContainsString('Sending blast subject', $sentHtml);
        $this->assertStringNotContainsString('Only in drafts', $sentHtml);
        $this->assertStringContainsString(route('admin.campaigns.show', $sent), $sentHtml);
        $this->assertStringContainsString(route('admin.campaigns.show', $failed), $sentHtml);
    }

    public function test_drafts_page_marks_drafts_tab_active(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeCampaign($admin, EmailCampaign::STATUS_DRAFT, 'Folder draft subject');

        $html = $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts'))
            ->assertOk()
            ->assertSee('Folder draft subject', false)
            ->assertSee('nav-link active', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/nav-link[^>]*active[^>]*>\s*Drafts/s',
            $html
        );
    }
}
