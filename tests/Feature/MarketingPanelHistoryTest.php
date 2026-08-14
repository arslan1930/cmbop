<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            'description' => 'Staff took the listing offline',
            'subject_label' => 'Offline Target',
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'Activated']))
            ->assertOk()
            ->assertSee('Live Target', false)
            ->assertSee('Activated site', false)
            ->assertDontSee('Edit Target', false)
            ->assertDontSee('Staff changed niches', false)
            ->assertDontSee('Offline Target', false)
            ->assertDontSee('Staff took the listing offline', false);
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

    public function test_history_shows_done_and_seed_as_distinct_tasks(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'bulk_request.done',
            'description' => 'Marketer Casey marked Done and added 2 draft site(s) to publisher panel on bulk request #11',
            'subject_label' => 'Bulk request #11',
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'bulk_request.seeded',
            'description' => 'Marketer Casey seeded 1 draft site(s) to publisher panel on bulk request #12',
            'subject_label' => 'Bulk request #12',
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('<div class="fw-semibold">Done</div>', false)
            ->assertSee('<div class="fw-semibold">Seed</div>', false)
            ->assertSee('Bulk request #11', false)
            ->assertSee('Bulk request #12', false)
            ->assertDontSee('Seeded / added sites', false)
            ->assertDontSee('>bulk_request.done<', false)
            ->assertDontSee('>bulk_request.seeded<', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'Done']))
            ->assertOk()
            ->assertSee('Bulk request #11', false)
            ->assertDontSee('Bulk request #12', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => 'Seed']))
            ->assertOk()
            ->assertSee('Bulk request #12', false)
            ->assertDontSee('Bulk request #11', false);
    }

    public function test_history_array_filters_do_not_500(): void
    {
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'bulk_request.done',
            'description' => 'Done kept when filters are junk',
            'subject_label' => 'Bulk request #99',
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.history', [
                'q' => ['Done'],
                'action' => ['bulk_request.done'],
                'from' => ['2026-08-01'],
                'to' => ['2026-08-31'],
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('TypeError', $html);
        $this->assertStringNotContainsString('Array to string conversion', $html);
        $this->assertStringContainsString('Done kept when filters are junk', $html);
        $this->assertStringNotContainsString('Reset filters', $html);
    }

    public function test_history_shows_publisher_reason_changes_and_removed_without_n_plus_one(): void
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'name' => 'Publisher Pat',
            'email' => 'pat-publisher@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'History Extra Site',
            'site_url' => 'https://history-extra.example',
            'domain' => 'history-extra.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'History extra site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);

        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.updated',
            'description' => 'Edited History Extra Site',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'subject_label' => 'History Extra Site',
            'properties' => [
                'publisher_id' => $publisher->id,
                'changes' => [
                    'da' => ['from' => 10, 'to' => 20],
                    'country' => ['from' => 'de', 'to' => 'us'],
                    'category' => ['from' => 'Pending', 'to' => 'News'],
                ],
            ],
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.deactivated',
            'description' => 'Deactivated History Extra Site',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'subject_label' => 'History Extra Site',
            'properties' => [
                'publisher_id' => $publisher->id,
                'reason' => 'Metrics fell below the quality bar.',
            ],
        ]);
        ActivityLog::create([
            'user_id' => $this->marketer->id,
            'user_name' => $this->marketer->name,
            'user_email' => $this->marketer->email,
            'role' => 'marketing',
            'action' => 'site.deleted_by_marketing',
            'description' => 'Deleted pending leftover extra',
            'subject_label' => 'Deleted Extra',
            'properties' => [
                'publisher_id' => $publisher->id,
                'reason' => 'Duplicate draft from the same domain.',
            ],
        ]);

        for ($i = 0; $i < 4; $i++) {
            ActivityLog::create([
                'user_id' => $this->marketer->id,
                'user_name' => $this->marketer->name,
                'user_email' => $this->marketer->email,
                'role' => 'marketing',
                'action' => 'site.updated',
                'description' => 'Repeat edit '.$i,
                'subject_type' => Site::class,
                'subject_id' => $site->id,
                'subject_label' => 'History Extra Site',
                'properties' => [
                    'publisher_id' => $publisher->id,
                    'changes' => ['dr' => ['from' => 10 + $i, 'to' => 11 + $i]],
                ],
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('Publisher', false)
            ->assertSee('Publisher Pat', false)
            ->assertSee('Reason: Metrics fell below the quality bar.', false)
            ->assertSee('Changed: DA, Country, Niches', false)
            ->assertSee('Changed: DR', false)
            ->assertSee('Deleted Extra', false)
            ->assertSee('data-history-removed', false)
            ->getContent();

        $this->assertStringContainsString('Reason: Duplicate draft from the same domain.', $html);
        $this->assertSame(1, substr_count($html, 'data-history-removed'));

        $existsLookups = array_values(array_filter($queries, function (string $sql) {
            return (bool) preg_match('/exists\s*\(\s*select\s+\*\s+from\s+["`]?(sites|bulk_site_requests)["`]/i', $sql)
                || (bool) preg_match('/from\s+["`]?(sites|bulk_site_requests)["`]?\s+where\s+(?:["`]?(?:sites|bulk_site_requests)["`]?\.)?["`]?id["`]?\s*=\s*\?/i', $sql);
        }));

        $this->assertSame([], $existsLookups, implode("\n", $existsLookups));
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
