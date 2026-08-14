<?php

namespace Tests\Feature;

use App\Http\Controllers\Marketing\PanelController;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
            ->assertSee('<div class="fw-semibold">Seed</div>', false)
            ->assertSee('Seeded 2 draft sites for bulk #9', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.history'), $html);
        $this->assertStringContainsString('role-shell-marketing', $html);
        $this->assertStringContainsString(ActivityLog::query()->first()->created_at->diffForHumans(), $html);
        $this->assertStringContainsString(ActivityLog::query()->first()->created_at->format('d M Y H:i'), $html);
        $this->assertStringNotContainsString('<code class="small text-muted">', $html);
        $this->assertStringNotContainsString('>bulk_request.seeded<', $html);
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

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => ['Mine only'], 'action' => ['site.updated']]))
            ->assertOk()
            ->assertSee('Mine only edit', false)
            ->assertDontSee('Array to string conversion', false);
    }

    public function test_history_includes_activate_and_assign_with_subject_links(): void
    {
        $otherRole = Role::where('name', 'marketing')->firstOrFail();
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $otherRole->id,
        ]);
        $other->roles()->attach($otherRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Activate Link Site',
            'site_url' => 'https://activate-link.example',
            'domain' => 'activate-link.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'History link site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);

        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.activated',
            'description' => 'Activated Activate Link Site',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'subject_label' => 'Activate Link Site',
            'properties' => ['bulk_site_request_id' => $bulk->id],
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.assigned_for_acceptance',
            'description' => 'Assigned Activate Link Site to publisher',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'subject_label' => 'Activate Link Site',
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.deleted_by_marketing',
            'description' => 'Deleted pending leftover',
            'subject_label' => 'Deleted Leftover',
        ]);
        ActivityLog::create([
            'user_id' => $other->id,
            'user_name' => $other->name,
            'user_email' => $other->email,
            'role' => 'marketing',
            'action' => 'site.activated',
            'description' => 'Other marketer activated',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'subject_label' => 'Someone Else Site',
        ]);

        $dashboard = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Activated site', false)
            ->assertSee('Assigned site to publisher', false)
            ->assertSee('Activated Activate Link Site', false)
            ->assertDontSee('Other marketer activated', false)
            ->assertDontSee('>site.activated<', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.sites.edit', $site->id), $dashboard);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $bulk->id), $dashboard);
        $this->assertStringContainsString('Bulk request', $dashboard);

        $history = $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('Activated site', false)
            ->assertSee('Assigned site to publisher', false)
            ->assertSee('Deleted pending leftover', false)
            ->assertDontSee('Other marketer activated', false)
            ->assertDontSee('>site.activated<', false)
            ->getContent();

        $this->assertStringContainsString('value="site.activated"', $history);
        $this->assertStringContainsString('value="site.assigned_for_acceptance"', $history);
        $this->assertStringContainsString(route('marketing.sites.edit', $site->id), $history);
        $this->assertStringContainsString('Deleted Leftover', $history);
        $this->assertStringNotContainsString(
            'href="'.route('marketing.sites.edit', $site->id).'">Deleted Leftover<',
            $history
        );

        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Edited a site that was later removed',
            'subject_type' => Site::class,
            'subject_id' => 999999,
            'subject_label' => 'Gone Site',
            'properties' => ['bulk_site_request_id' => 888888],
        ]);

        $stale = $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('Gone Site', false)
            ->getContent();

        $this->assertStringNotContainsString(route('marketing.sites.edit', 999999), $stale);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', 888888), $stale);
        $this->assertStringContainsString('data-history-removed', $stale);
        $this->assertStringContainsString('Removed', $stale);
    }

    public function test_my_tasks_today_uses_app_timezone_window(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);
        Carbon::setTestNow(Carbon::parse('2026-08-15 00:30:00', 'Europe/Berlin'));

        $today = ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.activated',
            'description' => 'Today in Berlin',
            'subject_label' => 'Berlin Today Site',
        ]);
        $today->forceFill([
            'created_at' => Carbon::parse('2026-08-14 22:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-14 22:30:00', 'UTC'),
        ])->save();

        $yesterday = ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Yesterday in Berlin',
            'subject_label' => 'Berlin Yesterday Site',
        ]);
        $yesterday->forceFill([
            'created_at' => Carbon::parse('2026-08-14 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-14 10:00:00', 'UTC'),
        ])->save();

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame('1', $this->dashboardStat($html, 'my-tasks-today'));
        $this->assertSame('2', $this->dashboardStatTotal($html, 'my-tasks-today'));
        $this->assertStringContainsString('/marketing/history?from=2026-08-15&amp;to=2026-08-15', $html);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['from' => '2026-08-15', 'to' => '2026-08-15']))
            ->assertOk()
            ->assertSee('Today in Berlin', false)
            ->assertDontSee('Yesterday in Berlin', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['from' => 'not-a-date', 'to' => 'also-bad']))
            ->assertOk()
            ->assertSee('Use a valid From date.', false)
            ->assertSee('Use a valid To date.', false)
            ->assertSee('Today in Berlin', false)
            ->assertSee('Yesterday in Berlin', false);
    }

    public function test_history_empty_states_distinguish_filters_from_first_run(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('No marketing tasks recorded yet.', false)
            ->assertSee('Add site for publisher', false)
            ->assertDontSee('No tasks match these filters.', false);

        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Only an edit',
            'subject_label' => 'Edit Only Site',
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['action' => 'site.activated']))
            ->assertOk()
            ->assertSee('No tasks match these filters.', false)
            ->assertSee('Reset filters', false)
            ->assertDontSee('No marketing tasks recorded yet.', false)
            ->assertDontSee('Only an edit', false);
    }

    public function test_history_rejects_inverted_date_range(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Kept when dates are inverted',
            'subject_label' => 'Range Site',
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['from' => '2026-08-16', 'to' => '2026-08-15']))
            ->assertOk()
            ->assertSee('From date must be on or before To date.', false)
            ->assertSee('Kept when dates are inverted', false);
    }

    public function test_history_search_matches_friendly_task_label(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.activated',
            'description' => 'Staff made the listing live',
            'subject_label' => 'Live Target',
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Staff changed niches',
            'subject_label' => 'Edit Target',
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.deactivated',
            'description' => 'Staff deactivated site "Offline Target"',
            'subject_label' => 'Offline Target',
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'Activated']))
            ->assertOk()
            ->assertSee('Live Target', false)
            ->assertSee('Activated site', false)
            ->assertSee('Activated site (1)', false)
            ->assertSee('Deactivated site (0)', false)
            ->assertDontSee('Edit Target', false)
            ->assertDontSee('Staff changed niches', false)
            ->assertDontSee('Offline Target', false)
            ->assertDontSee('Staff deactivated site', false)
            ->assertDontSee('Deactivated site (1)', false);
    }

    public function test_history_search_matches_subtitle_task_stems(): void
    {
        $rows = [
            ['bulk_request.seeded', 'Created 2 draft listings for bulk #9', 'Northwind Drafts'],
            ['site.updated', 'Staff changed niches', 'Niche Change Listing'],
            ['site.activated', 'Staff made the listing live', 'Live Target'],
            ['site.deactivated', 'Staff deactivated site "Offline Target"', 'Offline Target'],
            ['site.assigned_for_acceptance', 'Handed listing to publisher', 'Publisher Handoff'],
            ['site.deleted_by_marketing', 'Removed a pending leftover', 'Pending Leftover'],
        ];

        foreach ($rows as [$action, $description, $label]) {
            ActivityLog::create([
                'user_id' => $this->marketer->id,
                'user_name' => $this->marketer->name,
                'user_email' => $this->marketer->email,
                'role' => 'marketing',
                'action' => $action,
                'description' => $description,
                'subject_label' => $label,
            ]);
        }

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'Seed']))
            ->assertOk()
            ->assertSee('Northwind Drafts', false)
            ->assertSee('Seeded / added sites (1)', false)
            ->assertDontSee('Niche Change Listing', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'edit']))
            ->assertOk()
            ->assertSee('Niche Change Listing', false)
            ->assertSee('Edited site (1)', false)
            ->assertDontSee('Northwind Drafts', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'activate']))
            ->assertOk()
            ->assertSee('Live Target', false)
            ->assertSee('Activated site (1)', false)
            ->assertSee('Deactivated site (0)', false)
            ->assertDontSee('Offline Target', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'assign']))
            ->assertOk()
            ->assertSee('Publisher Handoff', false)
            ->assertDontSee('Pending Leftover', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'delete']))
            ->assertOk()
            ->assertSee('Pending Leftover', false)
            ->assertSee('Deleted pending site (1)', false)
            ->assertDontSee('Publisher Handoff', false);
    }

    public function test_history_rejects_impossible_calendar_dates(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Kept when February 31 is submitted',
            'subject_label' => 'Calendar Site',
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['from' => '2026-02-31', 'to' => '2026-02-31']))
            ->assertOk()
            ->assertSee('Use a valid From date.', false)
            ->assertSee('Use a valid To date.', false)
            ->assertSee('Kept when February 31 is submitted', false);
    }

    public function test_history_out_of_range_page_redirects_to_last_page(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Only row on page one',
            'subject_label' => 'Paged Site',
        ]);

        $this->actingAs($this->marketer)
            ->followingRedirects()
            ->get(route('marketing.history', ['page' => 2]))
            ->assertOk()
            ->assertSee('Showing 1–1 of 1 task', false)
            ->assertSee('Paged Site', false)
            ->assertDontSee('Showing –', false)
            ->assertDontSee('No marketing tasks recorded yet.', false);
    }

    public function test_history_filter_bar_lists_every_task_type_with_counts(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Only an edit for the type list',
            'subject_label' => 'Filter Bar Site',
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('Seed, edit, activate, deactivate, assign, delete, image, metrics, and bulk updates.', false)
            ->assertSee('data-history-filters', false)
            ->assertSee('for="history-q"', false)
            ->assertSee('for="history-action"', false)
            ->assertSee('for="history-from"', false)
            ->assertSee('for="history-to"', false)
            ->assertSee('Apply filters', false)
            ->assertSee('Showing 1–1 of 1 task', false)
            ->assertSee('Edited site (1)', false)
            ->assertSee('Activated site (0)', false)
            ->assertDontSee('seeding, edits, activations, assigns, and bulk updates', false)
            ->getContent();

        foreach (PanelController::TRACKED_ACTIONS as $action) {
            $this->assertStringContainsString('value="'.$action.'"', $html);
        }

        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.activated',
            'description' => 'Staff made the listing live',
            'subject_label' => 'Live Filter Site',
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'Only an edit']))
            ->assertOk()
            ->assertSee('Edited site (1)', false)
            ->assertSee('Activated site (0)', false)
            ->assertSee('Filter Bar Site', false)
            ->assertDontSee('Live Filter Site', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['action' => 'not-a-tracked-action']))
            ->assertOk()
            ->assertSee('Filter Bar Site', false)
            ->assertSee('Live Filter Site', false)
            ->assertDontSee('0 tasks match these filters', false);
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

    private function dashboardStat(string $html, string $stat): string
    {
        preg_match(
            '/data-stat="'.preg_quote($stat, '/').'"[\s\S]{0,400}?data-stat-value="([^"]+)"/',
            $html,
            $m
        );
        $this->assertNotEmpty($m[1] ?? null, "Missing data-stat-value for {$stat}");

        return $m[1];
    }

    private function dashboardStatTotal(string $html, string $stat): string
    {
        preg_match(
            '/data-stat="'.preg_quote($stat, '/').'"[\s\S]{0,400}?data-stat-total="([^"]+)"/',
            $html,
            $m
        );
        $this->assertNotEmpty($m[1] ?? null, "Missing data-stat-total for {$stat}");

        return $m[1];
    }
}
