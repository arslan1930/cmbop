<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPanelHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $role = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->marketer->roles()->attach($role->id);
    }

    public function test_marketing_dashboard_is_dedicated_workspace_with_history(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'bulk_request.seeded',
            'description' => 'Seeded 2 draft sites for bulk #9',
            'subject_label' => 'Bulk request #9',
            'properties' => ['bulk_site_request_id' => 9],
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Marketing workspace', false)
            ->assertSee('Your recent tasks', false)
            ->assertSee('My task history', false)
            ->assertSee('Seeded / added sites', false)
            ->assertSee('Seeded 2 draft sites for bulk #9', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.history'), $html);
        $this->assertStringContainsString('role-shell-marketing', $html);
    }

    public function test_marketing_history_lists_only_this_marketers_tasks(): void
    {
        $otherRole = Role::where('name', 'marketing')->firstOrFail();
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $otherRole->id,
        ]);
        $other->roles()->attach($otherRole->id);

        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Mine only edit',
            'subject_label' => 'Mine Site',
        ]);
        ActivityLog::create([
            'user_id' => $other->id,
            'user_name' => $other->name,
            'user_email' => $other->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Someone else edit',
            'subject_label' => 'Other Site',
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'admin',
            'action' => 'site.updated',
            'description' => 'Admin role should be hidden',
            'subject_label' => 'Hidden',
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('My task history', false)
            ->assertSee('Mine only edit', false)
            ->assertSee('Edited site', false)
            ->assertDontSee('Someone else edit', false)
            ->assertDontSee('Admin role should be hidden', false)
            ->assertDontSee('<code class="small text-muted">', false)
            ->getContent();

        $this->assertStringContainsString('role-shell-marketing', $html);
    }

    public function test_sites_page_uses_marketing_layout_for_marketers(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role-shell-marketing', $html);
        $this->assertStringContainsString('Marketing workspace', $html);
        $this->assertStringContainsString(route('marketing.history'), $html);
        $this->assertStringNotContainsString(route('marketing.site-enrichment.index'), $html);
        $this->assertStringNotContainsString('Enrichment &amp; scan failures', $html);
    }
}
