<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CatalogCopyEvent;
use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\CatalogCopyStrikeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminCatalogCopyStrikeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role, array $attrs = []): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ], $attrs));
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function site(User $publisher, string $domain): Site
    {
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
            'description' => 'Catalog activity test.',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function reveal(User $user, Site $site, $at = null): SiteUrlReveal
    {
        $row = SiteUrlReveal::create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_CATALOG,
        ]);

        if ($at) {
            SiteUrlReveal::where('id', $row->id)->update([
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $row->fresh();
    }

    private function paidOrder(User $user, $at = null): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => $at ?? now(),
        ]);

        if ($at) {
            Order::where('id', $order->id)->update([
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $order->fresh();
    }

    public function test_admin_catalog_activity_lists_hide_mode_and_warned_accounts(): void
    {
        $admin = $this->userWithRole('admin');

        $hidden = $this->userWithRole('advertiser', ['email' => 'hidden-copy@example.com']);
        $hidden->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addHours(20),
        ])->save();

        $warned = $this->userWithRole('advertiser', ['email' => 'warned-copy@example.com']);
        $warned->forceFill([
            'catalog_copy_strike_count' => 1,
            'catalog_copy_warned_at' => now()->subMinutes(30),
        ])->save();

        $clean = $this->userWithRole('advertiser', ['email' => 'clean-copy@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee('Copy strikes')
            ->assertSee('Who is in catalog hide mode or on a copy warning. Open-catalog browsing is not logged.')
            ->assertSee('hidden-copy@example.com')
            ->assertSee('warned-copy@example.com')
            ->assertSee('Hide mode')
            ->assertSee('Warned')
            ->assertSee('Lift hide')
            ->assertSee('Reset strikes')
            ->assertDontSee('Hide mode 24h')
            ->assertDontSee('Clear hide mode')
            ->assertDontSee('clean-copy@example.com');
    }

    public function test_catalog_activity_show_survives_leftover_hide_and_exempt_dates(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'leftover-show@example.com']);
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addDay(),
            'catalog_reveal_exempt' => true,
            'catalog_reveal_exempt_until' => now()->addHour(),
        ])->save();
        DB::table('users')->where('id', $advertiser->id)->update([
            'catalog_hide_until' => 'not-a-date',
            'catalog_reveal_exempt_until' => 'also-not-a-date',
            'catalog_copy_warned_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity.show', $advertiser->id))
            ->assertOk()
            ->assertSee('leftover-show@example.com')
            ->assertDontSee('Something went wrong');
    }

    public function test_leftover_hide_until_is_not_listed_as_hide_mode(): void
    {
        $admin = $this->userWithRole('admin');

        $real = $this->userWithRole('advertiser');
        $real->forceFill([
            'email' => 'real-hide@example.com',
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addHours(20),
        ])->save();

        $leftover = $this->userWithRole('advertiser');
        $leftover->forceFill([
            'email' => 'garbage-hide@example.com',
            'catalog_copy_strike_count' => 0,
            'catalog_hide_until' => now()->addDay(),
        ])->save();
        DB::table('users')->where('id', $leftover->id)->update([
            'catalog_hide_until' => 'not-a-date',
        ]);

        $this->assertFalse($leftover->fresh()->inCatalogHideMode());

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee('real-hide@example.com')
            ->assertDontSee('garbage-hide@example.com')
            ->assertDontSee('Something went wrong');
    }

    public function test_admin_can_clear_copy_hide_mode_and_reset_strikes(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addDay(),
            'catalog_reveal_exempt' => true,
            'catalog_reveal_exempt_until' => now()->addHour(),
        ])->save();

        CatalogCopyEvent::create([
            'user_id' => $advertiser->id,
            'site_id' => null,
            'normalized_host' => 'copied.example',
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.catalog-activity.clear-copy-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $advertiser->refresh();
        $this->assertSame(0, (int) $advertiser->catalog_copy_strike_count);
        $this->assertNull($advertiser->catalog_copy_warned_at);
        $this->assertNull($advertiser->catalog_hide_until);
        $this->assertFalse((bool) $advertiser->catalog_reveal_exempt);
        $this->assertNull($advertiser->catalog_reveal_exempt_until);
        $this->assertSame(1, CatalogCopyEvent::where('user_id', $advertiser->id)->count());
        $this->assertSame(
            (int) CatalogCopyEvent::where('user_id', $advertiser->id)->max('id'),
            (int) $advertiser->catalog_copy_after_id
        );
    }

    public function test_lift_hide_heals_leftover_unparseable_until(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'garbage-lift@example.com']);
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 0,
            'catalog_copy_warned_at' => null,
            'catalog_hide_until' => now()->addDay(),
        ])->save();
        DB::table('users')->where('id', $advertiser->id)->update([
            'catalog_hide_until' => 'not-a-date',
        ]);

        $this->assertFalse($advertiser->fresh()->inCatalogHideMode());
        $this->assertNull($advertiser->fresh()->catalog_hide_until);

        $this->actingAs($admin)
            ->from(route('admin.catalog-activity.show', $advertiser->id))
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => ! str_contains((string) $msg, 'already out'));

        $this->assertNull(DB::table('users')->where('id', $advertiser->id)->value('catalog_hide_until'));
    }

    public function test_lift_hide_heals_stale_until_and_logs(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 0,
            'catalog_copy_warned_at' => null,
            'catalog_hide_until' => now()->subHour(),
        ])->save();

        $this->assertFalse($advertiser->inCatalogHideMode());

        $this->actingAs($admin)
            ->from(route('admin.catalog-activity.show', $advertiser->id))
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => ! str_contains((string) $msg, 'already out'));

        $advertiser->refresh();
        $this->assertNull($advertiser->catalog_hide_until);

        $log = ActivityLog::query()->where('action', 'catalog_hide_lifted')->first();
        $this->assertNotNull($log);
        $this->assertTrue((bool) data_get($log->properties, 'healed_stale_until'));
    }

    public function test_lift_hide_true_noop_does_not_log(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 0,
            'catalog_hide_until' => null,
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.catalog-activity.show', $advertiser->id))
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'already out'));

        $this->assertSame(0, ActivityLog::query()->where('action', 'catalog_hide_lifted')->count());
    }

    public function test_clear_copy_hide_heals_stale_until_and_logs(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 0,
            'catalog_copy_warned_at' => null,
            'catalog_hide_until' => now()->subMinutes(30),
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.catalog-activity.show', $advertiser->id))
            ->post(route('admin.catalog-activity.clear-copy-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => ! str_contains((string) $msg, 'already out'));

        $advertiser->refresh();
        $this->assertNull($advertiser->catalog_hide_until);

        $log = ActivityLog::query()->where('action', 'catalog_activity.copy_hide_cleared')->first();
        $this->assertNotNull($log);
        $this->assertTrue((bool) data_get($log->properties, 'healed_stale_until'));
    }

    public function test_non_admin_cannot_lift_hide_or_reset_strikes(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        $this->actingAs($advertiser)
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertStatus(403);

        $this->actingAs($advertiser)
            ->post(route('admin.catalog-activity.reset-strikes', $advertiser->id))
            ->assertStatus(403);

        $this->actingAs($advertiser)
            ->post(route('admin.catalog-activity.clear-copy-hide', $advertiser->id))
            ->assertStatus(403);

        $advertiser->refresh();
        $this->assertTrue($advertiser->inCatalogHideMode());
        $this->assertSame(2, (int) $advertiser->catalog_copy_strike_count);
    }

    public function test_warning_keeps_copy_events_visible_on_the_queue(): void
    {
        config([
            'catalog.copy_strikes.threshold' => 3,
            'catalog.copy_strikes.window_seconds' => 120,
        ]);

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'kept-copies@example.com']);
        $publisher = $this->userWithRole('publisher');
        $guard = app(CatalogCopyStrikeGuard::class);

        for ($i = 1; $i <= 3; $i++) {
            $guard->record($advertiser, $this->site($publisher, "kept-{$i}.example")->id, "kept-{$i}.example");
        }

        $this->assertSame(3, CatalogCopyEvent::where('user_id', $advertiser->id)->count());

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee('kept-copies@example.com')
            ->assertSee('Warned');

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity.show', $advertiser->id))
            ->assertOk()
            ->assertSee('kept-1.example')
            ->assertSee('kept-3.example')
            ->assertSee('Reset strikes')
            ->assertDontSee('Lift hide');
    }

    public function test_search_does_not_pin_a_leftover_user_query(): void
    {
        $admin = $this->userWithRole('admin');
        $hidden = $this->userWithRole('advertiser', ['email' => 'hidden-copy@example.com']);
        $hidden->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addHours(4),
        ])->save();
        $other = $this->userWithRole('advertiser', ['email' => 'other-copy@example.com']);
        $other->forceFill([
            'catalog_copy_strike_count' => 1,
            'catalog_copy_warned_at' => now()->subMinutes(10),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity', [
                'q' => 'other-copy@',
                'user' => $hidden->id,
            ]))
            ->assertOk()
            ->assertSee('other-copy@example.com')
            ->assertDontSee('hidden-copy@example.com');
    }

    public function test_lift_hide_allows_a_new_hide_mode_bell(): void
    {
        config([
            'catalog.copy_strikes.threshold' => 2,
            'catalog.copy_strikes.window_seconds' => 120,
        ]);

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $guard = app(CatalogCopyStrikeGuard::class);

        for ($i = 1; $i <= 2; $i++) {
            $guard->record($advertiser->fresh(), $this->site($publisher, "bell1-{$i}.example")->id, "bell1-{$i}.example");
        }
        for ($i = 1; $i <= 2; $i++) {
            $guard->record($advertiser->fresh(), $this->site($publisher, "bell2-{$i}.example")->id, "bell2-{$i}.example");
        }
        $this->assertTrue($advertiser->fresh()->inCatalogHideMode());
        $this->assertSame(
            1,
            InAppNotification::query()->where('user_id', $admin->id)->where('title', 'Catalog hide mode started')->count()
        );

        $this->actingAs($admin)
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertRedirect();

        $advertiser->refresh();
        $this->assertFalse($advertiser->inCatalogHideMode());

        for ($i = 1; $i <= 2; $i++) {
            $guard->record($advertiser->fresh(), $this->site($publisher, "bell3-{$i}.example")->id, "bell3-{$i}.example");
        }

        $this->assertTrue($advertiser->fresh()->inCatalogHideMode());
        $this->assertSame(
            2,
            InAppNotification::query()->where('user_id', $admin->id)->where('title', 'Catalog hide mode started')->count()
        );
    }

    public function test_search_isolates_one_account(): void
    {
        $admin = $this->userWithRole('admin');
        $hidden = $this->userWithRole('advertiser', ['email' => 'hidden-copy@example.com']);
        $hidden->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addHours(4),
        ])->save();
        $warned = $this->userWithRole('advertiser', ['email' => 'warned-copy@example.com']);
        $warned->forceFill([
            'catalog_copy_strike_count' => 1,
            'catalog_copy_warned_at' => now()->subMinutes(10),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity', ['q' => 'hidden-copy@']))
            ->assertOk()
            ->assertSee('hidden-copy@example.com')
            ->assertDontSee('warned-copy@example.com');
    }

    public function test_user_query_includes_a_clean_account(): void
    {
        $admin = $this->userWithRole('admin');
        $clean = $this->userWithRole('advertiser', ['email' => 'clean-focus@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity', ['user' => $clean->id]))
            ->assertOk()
            ->assertSee('clean-focus@example.com')
            ->assertSee('id="user-'.$clean->id.'"', false);
    }

    public function test_show_page_lists_copy_host_and_reveal_source(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'detail-copy@example.com']);
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'shown.example');

        CatalogCopyEvent::create([
            'user_id' => $advertiser->id,
            'site_id' => null,
            'normalized_host' => 'copied-host.example',
            'created_at' => now(),
        ]);
        $this->reveal($advertiser, $site);

        $html = $this->actingAs($admin)
            ->get(route('admin.catalog-activity.show', $advertiser->id))
            ->assertOk()
            ->assertSee('copied-host.example')
            ->assertSee('shown.example')
            ->assertSee(SiteUrlReveal::SOURCE_CATALOG)
            ->assertSee(route('admin.activity-logs.index', ['user_id' => $advertiser->id]), false)
            ->assertSee(route('admin.sites.edit', $site->id), false)
            ->getContent();

        $this->assertStringNotContainsString('>IP<', $html);
        $this->assertStringNotContainsString('ip_address', $html);
    }

    public function test_windowed_orders_are_not_lifetime_ratio(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'ratio-copy@example.com']);
        $publisher = $this->userWithRole('publisher');

        for ($i = 0; $i < 5; $i++) {
            $this->paidOrder($advertiser, now()->subDays(40));
        }
        $this->reveal($advertiser, $this->site($publisher, 'ratio-a.example'));
        $this->reveal($advertiser, $this->site($publisher, 'ratio-b.example'));

        $html = $this->actingAs($admin)
            ->get(route('admin.catalog-activity', ['days' => 7]))
            ->assertOk()
            ->assertSee('ratio-copy@example.com')
            ->assertSee('5 lifetime')
            ->getContent();

        $this->assertStringNotContainsString('>0.4<', $html);
    }

    public function test_completed_deposit_is_established_not_no_orders(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'deposit-copy@example.com']);
        $publisher = $this->userWithRole('publisher');

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-EST',
            'amount' => 50,
            'payment_method' => 'wise',
            'status' => 'completed',
        ]);

        for ($i = 0; $i < 100; $i++) {
            $this->reveal($advertiser, $this->site($publisher, "dep-{$i}.example"));
        }

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity', ['days' => 7]))
            ->assertOk()
            ->assertSee('deposit-copy@example.com')
            ->assertDontSee('No orders');
    }

    public function test_last_unlock_is_shown(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'last-unlock@example.com']);
        $publisher = $this->userWithRole('publisher');
        $at = now()->subMinutes(12);
        $this->reveal($advertiser, $this->site($publisher, 'last-unlock.example'), $at);

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity', ['days' => 7]))
            ->assertOk()
            ->assertSee('Last unlock')
            ->assertSee($at->timezone(config('app.timezone'))->format('M j, H:i'));
    }

    public function test_queue_filters_survive_details_back(): void
    {
        $admin = $this->userWithRole('admin');
        $hidden = $this->userWithRole('advertiser', ['email' => 'back-copy@example.com']);
        $hidden->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addHours(4),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity', [
                'days' => 30,
                'copy' => 'all',
                'q' => 'back-copy@',
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity.show', $hidden->id))
            ->assertOk()
            ->assertSee(route('admin.catalog-activity', [
                'days' => 30,
                'copy' => 'all',
                'q' => 'back-copy@',
            ]), false);
    }

    public function test_lift_hide_clears_pace_exemption(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'trusted-lift@example.com']);
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addDay(),
            'catalog_reveal_exempt' => true,
            'catalog_reveal_exempt_until' => now()->addHour(),
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.catalog-activity.show', $advertiser->id))
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $advertiser->refresh();
        $this->assertFalse($advertiser->inCatalogHideMode());
        $this->assertFalse((bool) $advertiser->catalog_reveal_exempt);
        $this->assertNull($advertiser->catalog_reveal_exempt_until);
    }

    public function test_lift_hide_clears_stranded_trust_when_hide_already_ended(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'stranded-trust@example.com']);
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 0,
            'catalog_hide_until' => null,
            'catalog_reveal_exempt' => true,
            'catalog_reveal_exempt_until' => now()->addHour(),
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.catalog-activity.show', $advertiser->id))
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => ! str_contains((string) $msg, 'already out'));

        $advertiser->refresh();
        $this->assertFalse((bool) $advertiser->catalog_reveal_exempt);
        $this->assertNull($advertiser->catalog_reveal_exempt_until);
    }

    public function test_show_page_marks_gone_sites_removed(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'gone-site@example.com']);

        CatalogCopyEvent::create([
            'user_id' => $advertiser->id,
            'site_id' => 999999,
            'normalized_host' => 'gone-host.example',
            'created_at' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.catalog-activity.show', $advertiser->id))
            ->assertOk()
            ->assertSee('gone-host.example')
            ->assertSee('Removed', false)
            ->getContent();

        $this->assertStringNotContainsString(route('admin.sites.edit', 999999), $html);
    }

    public function test_index_offers_fourteen_day_unlock_window(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee('14d', false)
            ->assertSee('Unlocks (7d)', false);
    }

    public function test_empty_unlock_table_explains_open_catalog_is_not_logged(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee('No hide-mode unlocks in this period. Everyday catalog browsing does not create rows here.')
            ->assertSee('Hide-mode unlocks (eye / visit / cart)');
    }

    public function test_hide_hours_config_drives_guard_message(): void
    {
        config(['catalog.copy_strikes.hide_hours' => 12]);

        $message = app(CatalogCopyStrikeGuard::class)->hideModeUserMessage();
        $this->assertStringContainsString('12 hours', $message);
        $this->assertStringNotContainsString('24 hours', $message);
    }

    public function test_copy_warning_and_hide_notify_admins_not_marketing(): void
    {
        config([
            'catalog.copy_strikes.threshold' => 3,
            'catalog.copy_strikes.window_seconds' => 120,
        ]);

        $admin = $this->userWithRole('admin');
        $this->userWithRole('marketing');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $guard = app(CatalogCopyStrikeGuard::class);

        $last = null;
        for ($i = 1; $i <= 3; $i++) {
            $last = $guard->record($advertiser, $this->site($publisher, "bell-a-{$i}.example")->id, "bell-a-{$i}.example");
        }
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);

        $this->assertSame(
            1,
            InAppNotification::query()
                ->where('user_id', $admin->id)
                ->where('title', 'Catalog copy warning')
                ->count()
        );
        $this->assertSame(
            0,
            InAppNotification::query()->where('title', 'Catalog copy warning')->where('user_id', '!=', $admin->id)->count()
        );

        for ($i = 1; $i <= 3; $i++) {
            $last = $guard->record($advertiser->fresh(), $this->site($publisher, "bell-b-{$i}.example")->id, "bell-b-{$i}.example");
        }
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);

        $this->assertSame(
            1,
            InAppNotification::query()
                ->where('user_id', $admin->id)
                ->where('title', 'Catalog hide mode started')
                ->count()
        );
    }
}
