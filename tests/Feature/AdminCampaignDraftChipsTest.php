<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCampaignDraftChipsTest extends TestCase
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

    public function test_compose_and_drafts_expose_emailable_chips(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');
        EmailCampaign::create([
            'name' => 'Chip draft',
            'subject' => 'Chip draft subject',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_DRAFT,
            'include_unverified' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaign::create([
            'name' => 'Queued chip',
            'subject' => 'Queued chip',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_QUEUED,
            'created_by' => $admin->id,
        ]);

        $index = $this->actingAs($admin)
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('id="campaignEmailableChip"', false)
            ->assertSee('~— emailable', false)
            ->assertSee('refreshEmailableChip', false)
            ->getContent();
        $this->assertStringContainsString("method: 'POST'", $index);

        $this->actingAs($admin)
            ->get(route('admin.campaigns.drafts'))
            ->assertOk()
            ->assertSee('campaign-draft-count-chip', false)
            ->assertSee('data-audience="advertisers"', false)
            ->assertSee('~— emailable', false)
            ->assertSee('countUrl', false)
            ->assertSee('recipient-count', false);
    }
}
