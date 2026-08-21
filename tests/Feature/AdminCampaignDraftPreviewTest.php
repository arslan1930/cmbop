<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCampaignDraftPreviewTest extends TestCase
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

    public function test_draft_folder_posts_saved_fields_to_preview(): void
    {
        $admin = $this->makeUser('admin');
        $draft = EmailCampaign::create([
            'name' => 'Preview draft',
            'subject' => 'Preview later subject',
            'body_html' => '<p>Saved preview body.</p>',
            'cta_label' => 'Open catalog',
            'cta_url' => 'https://example.com/catalog',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts'))
            ->assertOk()
            ->assertSee(route('admin.campaigns.preview'), false)
            ->assertSee('name="subject"', false)
            ->assertSee('Preview later subject', false)
            ->assertSee('Saved preview body.', false)
            ->assertSee('https://example.com/catalog', false)
            ->assertSee('id="draftPreviewFrame"', false)
            ->assertSee('sandbox', false)
            ->assertSee('campaign-draft-preview-form', false)
            ->getContent();

        $this->assertStringContainsString('name="body_html"', $html);
        $this->assertStringContainsString('name="cta_label"', $html);
        $this->assertStringContainsString('name="cta_url"', $html);

        $preview = $this->actingAs($admin)
            ->post(route('admin.campaigns.preview'), [
                'subject' => $draft->subject,
                'body_html' => $draft->body_html,
                'cta_label' => $draft->cta_label,
                'cta_url' => $draft->cta_url,
            ])
            ->assertOk()
            ->assertSee('Saved preview body.', false)
            ->assertSee('Open catalog', false)
            ->getContent();

        $this->assertStringNotContainsString((string) $admin->email, $preview);
    }

    public function test_marketing_cannot_preview_from_drafts(): void
    {
        $this->actingAs($this->makeUser('marketing'))
            ->post(route('admin.campaigns.preview'), [
                'subject' => 'Nope',
                'body_html' => '<p>Nope</p>',
            ])
            ->assertRedirect(route('marketing.dashboard'));
    }
}
