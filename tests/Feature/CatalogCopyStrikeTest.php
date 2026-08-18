<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CatalogCopyEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogCopyStrikeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogCopyStrikeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        config([
            'catalog.copy_strikes.threshold' => 5,
            'catalog.copy_strikes.window_seconds' => 120,
            'catalog.copy_strikes.hide_hours' => 24,
        ]);
    }

    private function advertiser(array $attrs = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function site(string $domain): Site
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Copy strike test listing.',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_ignores_non_domain_text(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record($user, null, 'just some words');

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_IGNORED, $result['status']);
        $this->assertSame(0, CatalogCopyEvent::count());
    }

    public function test_ignores_first_party_visit_copy_instead_of_listing_fallback(): void
    {
        $user = $this->advertiser();
        $site = $this->site('visit-copy.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        config(['app.url' => 'http://localhost']);

        $relative = $guard->record($user, $site->id, '/advertiser/go/'.$site->id.'?sample=1');
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_IGNORED, $relative['status']);

        $absolute = $guard->record($user->fresh(), $site->id, 'http://localhost/advertiser/go/'.$site->id.'?sample=1');
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_IGNORED, $absolute['status']);

        config(['app.url' => 'https://seolinkbuildings.com']);
        $prod = $guard->record(
            $user->fresh(),
            $site->id,
            'https://seolinkbuildings.com/advertiser/go/'.$site->id.'?sample=1'
        );
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_IGNORED, $prod['status']);

        $this->assertSame(0, CatalogCopyEvent::count());
    }

    public function test_publisher_url_containing_go_path_still_records(): void
    {
        $user = $this->advertiser();
        $site = $this->site('publisher-go.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record($user, $site->id, 'https://publisher-go.example/go/99');

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        $this->assertSame(1, CatalogCopyEvent::count());
    }

    public function test_records_distinct_domain_copies_without_strike_below_threshold(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        for ($i = 1; $i <= 4; $i++) {
            $site = $this->site("copy-{$i}.example");
            $result = $guard->record($user, $site->id, 'https://copy-'.$i.'.example');
            $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        }

        $this->assertSame(0, (int) $user->fresh()->catalog_copy_strike_count);
        $this->assertSame(4, CatalogCopyEvent::where('user_id', $user->id)->count());
    }

    public function test_first_threshold_crossing_warns_and_keeps_visibility(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        $last = null;
        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("warn-{$i}.example");
            $last = $guard->record($user, $site->id, 'warn-'.$i.'.example');
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);
        $user = $user->fresh();
        $this->assertSame(1, (int) $user->catalog_copy_strike_count);
        $this->assertNotNull($user->catalog_copy_warned_at);
        $this->assertNull($user->catalog_hide_until);
        $this->assertFalse($user->inCatalogHideMode());
        $this->assertSame(1, ActivityLog::query()->where('action', 'catalog_copy_warned')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'catalog_hide_applied')->count());
    }

    public function test_second_threshold_after_warning_sets_hide_mode_24h(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        // Wave 1 → warning. Events stay for admin forensics; strike 2
        // counts only newer ids so the same burst cannot restage.
        $last = null;
        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("warn-then-hide-a-{$i}.example");
            $last = $guard->record($user, $site->id, 'https://warn-then-hide-a-'.$i.'.example');
        }
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);
        $this->assertSame(5, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertGreaterThan(0, (int) $user->fresh()->catalog_copy_after_id);

        // Wave 2 in the same second → hide mode.
        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("warn-then-hide-b-{$i}.example");
            $last = $guard->record($user->fresh(), $site->id, 'https://warn-then-hide-b-'.$i.'.example/path');
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $user = $user->fresh();
        $this->assertSame(2, (int) $user->catalog_copy_strike_count);
        $this->assertTrue($user->inCatalogHideMode());
        $this->assertNotNull($user->catalog_hide_until);
        $this->assertTrue($user->catalog_hide_until->greaterThan(now()->addHours(23)));
        $this->assertTrue($user->catalog_hide_until->lessThanOrEqualTo(now()->addHours(24)->addMinute()));
        $this->assertStringContainsString('24 hours', $last['message']);
        $this->assertSame(10, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'catalog_copy_warned')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'catalog_hide_applied')->count());
    }

    public function test_second_wave_of_the_same_sites_still_reaches_hide_mode(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);
        $sites = [];
        for ($i = 1; $i <= 5; $i++) {
            $sites[] = $this->site("repeat-wave-{$i}.example");
        }

        $last = null;
        foreach ($sites as $site) {
            $last = $guard->record($user, $site->id, $site->domain);
        }
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);
        $afterWarning = (int) $user->fresh()->catalog_copy_after_id;
        $this->assertGreaterThan(0, $afterWarning);

        foreach ($sites as $site) {
            $last = $guard->record($user->fresh(), $site->id, $site->domain);
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $user = $user->fresh();
        $this->assertTrue($user->inCatalogHideMode());
        $this->assertSame(10, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertGreaterThan($afterWarning, (int) $user->catalog_copy_after_id);
    }

    public function test_lift_hide_requires_a_fresh_wave_not_one_leftover_copy(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);
        $sites = [];
        for ($i = 1; $i <= 5; $i++) {
            $sites[] = $this->site("lift-restage-{$i}.example");
        }

        foreach ($sites as $site) {
            $guard->record($user->fresh(), $site->id, $site->domain);
        }
        foreach ($sites as $site) {
            $guard->record($user->fresh(), $site->id, $site->domain);
        }
        $this->assertTrue($user->fresh()->inCatalogHideMode());

        $this->actingAs($this->admin())
            ->post(route('admin.catalog-activity.lift-hide', $user->id))
            ->assertRedirect();

        $user = $user->fresh();
        $this->assertFalse($user->inCatalogHideMode());
        $this->assertSame(2, (int) $user->catalog_copy_strike_count);

        $one = $guard->record($user->fresh(), $sites[0]->id, $sites[0]->domain);
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $one['status']);
        $this->assertFalse($user->fresh()->inCatalogHideMode());

        $last = $one;
        for ($i = 1; $i <= 4; $i++) {
            $site = $this->site("lift-fresh-{$i}.example");
            $last = $guard->record($user->fresh(), $site->id, $site->domain);
        }
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $this->assertTrue($user->fresh()->inCatalogHideMode());
    }

    public function test_reset_strikes_does_not_restage_the_same_burst(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);
        $sites = [];
        for ($i = 1; $i <= 5; $i++) {
            $sites[] = $this->site("reset-restage-{$i}.example");
        }

        $last = null;
        foreach ($sites as $site) {
            $last = $guard->record($user->fresh(), $site->id, $site->domain);
        }
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);

        $this->actingAs($this->admin())
            ->post(route('admin.catalog-activity.reset-strikes', $user->id))
            ->assertRedirect();

        $user = $user->fresh();
        $this->assertSame(0, (int) $user->catalog_copy_strike_count);
        $this->assertGreaterThan(0, (int) $user->catalog_copy_after_id);

        $one = $guard->record($user->fresh(), $sites[0]->id, $sites[0]->domain);
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $one['status']);
        $this->assertSame(0, (int) $user->fresh()->catalog_copy_strike_count);
    }

    public function test_hide_mode_message_uses_configured_hours(): void
    {
        config(['catalog.copy_strikes.hide_hours' => 12, 'catalog.copy_strikes.threshold' => 2]);

        $user = $this->advertiser(['catalog_copy_strike_count' => 1]);
        $guard = app(CatalogCopyStrikeGuard::class);
        $last = null;
        for ($i = 1; $i <= 2; $i++) {
            $last = $guard->record($user->fresh(), $this->site("cfg-hide-{$i}.example")->id, "cfg-hide-{$i}.example");
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $this->assertStringContainsString('12 hours', $last['message']);
        $this->assertStringNotContainsString('24 hours', $last['message']);
    }

    public function test_warning_and_hide_can_fire_in_the_same_second(): void
    {
        config(['catalog.copy_strikes.threshold' => 3]);

        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);
        $last = null;

        for ($i = 1; $i <= 6; $i++) {
            $site = $this->site("same-sec-{$i}.example");
            $last = $guard->record($user->fresh(), $site->id, 'same-sec-'.$i.'.example');
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $this->assertTrue($user->fresh()->inCatalogHideMode());
    }

    public function test_same_site_copy_dedupes_inside_window(): void
    {
        $user = $this->advertiser();
        $site = $this->site('once.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $guard->record($user, $site->id, 'https://once.example');
        $guard->record($user, $site->id, 'https://once.example');
        $guard->record($user, $site->id, 'once.example');

        $this->assertSame(1, CatalogCopyEvent::where('user_id', $user->id)->count());
    }

    public function test_copy_track_endpoint_accepts_a_multi_url_dump(): void
    {
        $user = $this->advertiser();
        $dump = implode("\n", array_map(
            fn (int $i): string => 'https://api-dump-'.$i.'.example',
            range(1, 5)
        ));

        $this->actingAs($user)
            ->postJson(route('advertiser.catalog.copy-track'), ['text' => $dump])
            ->assertOk()
            ->assertJsonPath('status', CatalogCopyStrikeGuard::STATUS_WARNING);

        $this->assertSame(5, CatalogCopyEvent::where('user_id', $user->id)->count());
    }

    public function test_copy_track_endpoint_applies_warning(): void
    {
        $user = $this->advertiser();

        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("api-{$i}.example");
            $response = $this->actingAs($user)->postJson(route('advertiser.catalog.copy-track'), [
                'text' => 'https://api-'.$i.'.example',
                'site_id' => $site->id,
            ]);
            $response->assertOk()->assertJsonPath('success', true);
        }

        $response->assertJsonPath('status', CatalogCopyStrikeGuard::STATUS_WARNING);
        $this->assertSame(1, (int) $user->fresh()->catalog_copy_strike_count);
    }

    public function test_sample_article_url_counts_as_the_listing_domain(): void
    {
        $user = $this->advertiser();
        $site = $this->site('sample-host.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record($user, $site->id, 'https://sample-host.example/blog/guest-post?ref=1');

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        $event = CatalogCopyEvent::where('user_id', $user->id)->first();
        $this->assertNotNull($event);
        $this->assertSame($site->id, (int) $event->site_id);
        $this->assertSame('sample-host.example', $event->normalized_host);
    }

    public function test_normalize_host_keeps_subdomain_strips_path(): void
    {
        $guard = app(CatalogCopyStrikeGuard::class);

        $this->assertSame('news.site.com', $guard->normalizeHost('https://news.site.com/blog/post?x=1'));
        $this->assertSame('example.com', $guard->normalizeHost('www.example.com'));
        $this->assertSame('', $guard->normalizeHost('not a host'));
        $this->assertSame('', $guard->normalizeHost("https://one.example\nhttps://two.example"));
    }

    public function test_trailing_newline_still_counts_as_one_host(): void
    {
        $user = $this->advertiser();
        $site = $this->site('cell-newline.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record($user, $site->id, "https://cell-newline.example\n");

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        $this->assertSame(1, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertSame('cell-newline.example', CatalogCopyEvent::first()->normalized_host);
    }

    public function test_multi_url_dump_counts_each_host(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);
        $dump = implode("\n", [
            'https://dump-1.example',
            'https://dump-2.example',
            'https://dump-3.example',
            'https://dump-4.example',
            'https://dump-5.example',
        ]);

        $result = $guard->record($user, null, $dump);

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $result['status']);
        $this->assertSame(5, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertSame(1, (int) $user->fresh()->catalog_copy_strike_count);
    }

    public function test_comma_separated_dump_counts_each_host(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);
        $dump = implode(',', [
            'https://csv-1.example',
            'https://csv-2.example',
            'https://csv-3.example',
            'https://csv-4.example',
            'https://csv-5.example',
        ]);

        $result = $guard->record($user, null, $dump);

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $result['status']);
        $this->assertSame(5, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertSame(1, (int) $user->fresh()->catalog_copy_strike_count);
    }

    public function test_whole_row_text_with_one_url_counts_once(): void
    {
        $user = $this->advertiser();
        $site = $this->site('row-copy.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record(
            $user,
            $site->id,
            "Row Copy Brand\nhttps://row-copy.example\nDR 45  DA 40  €150"
        );

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        $this->assertSame(1, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertSame($site->id, (int) CatalogCopyEvent::first()->site_id);
    }

    public function test_unparseable_copy_event_dates_do_not_count_toward_threshold(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        for ($i = 1; $i <= 4; $i++) {
            $event = CatalogCopyEvent::create([
                'user_id' => $user->id,
                'site_id' => $this->site("garbage-copy-{$i}.example")->id,
                'normalized_host' => "garbage-copy-{$i}.example",
                'created_at' => now(),
            ]);
            DB::table('catalog_copy_events')->where('id', $event->id)->update([
                'created_at' => 'not-a-date',
            ]);
        }

        $site = $this->site('real-copy.example');
        $result = $guard->record($user, $site->id, 'https://real-copy.example');

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        $this->assertSame(0, (int) $user->fresh()->catalog_copy_strike_count);
        $this->assertSame(1, $result['distinct_in_window']);
    }

    public function test_copy_tracking_pauses_while_hide_mode_is_active(): void
    {
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);
        $site = $this->site('already-hidden.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record($user, $site->id, 'https://already-hidden.example');

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_IGNORED, $result['status']);
        $this->assertSame(0, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertTrue($user->fresh()->inCatalogHideMode());
    }

    public function test_copy_tracking_resumes_after_hide_expires(): void
    {
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->subMinute(),
        ]);
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record(
            $user,
            $this->site('after-expiry.example')->id,
            'https://after-expiry.example'
        );

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        $this->assertFalse($result['in_hide_mode']);
        $this->assertSame(1, CatalogCopyEvent::where('user_id', $user->id)->count());
    }

    public function test_reused_site_id_with_rotating_hosts_still_reaches_the_threshold(): void
    {
        $user = $this->advertiser();
        $pinned = $this->site('pinned-row.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $last = null;
        for ($i = 1; $i <= 5; $i++) {
            $last = $guard->record($user->fresh(), $pinned->id, 'rotate-'.$i.'.example');
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);
        $this->assertSame(5, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertSame(0, CatalogCopyEvent::where('user_id', $user->id)->whereNotNull('site_id')->count());
        $this->assertSame(1, (int) $user->fresh()->catalog_copy_strike_count);
    }
}
