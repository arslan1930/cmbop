<?php

namespace Tests\Feature;

use App\Mail\AdminBulkDiscountNotification;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminBulkDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
            'name' => 'Ada Admin',
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
            'name' => 'Pat Publisher',
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Pack Site',
            'site_url' => 'https://pack-site.example',
            'domain' => 'pack-site.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => '3',
            'description' => 'Admin pack discount listing',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_admin_can_set_and_remove_all_three_tiers(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount', $site->id), [
                'percent' => 10,
                'percent_5' => 15,
                'percent_10' => 25,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('bulk_discount', true)
            ->assertJsonPath('bulk_discount_label', '3 for −10% · 5 for −15% · 10 for −25%')
            ->assertJsonPath('bulk_discount_percent', 10)
            ->assertJsonPath('bulk_discount_percent_5', 15)
            ->assertJsonPath('bulk_discount_percent_10', 25);

        $site->refresh();
        $this->assertTrue($site->joinsBulkDiscount());
        $this->assertSame(10.0, (float) $site->bulk_discount_percent);
        $this->assertSame(15.0, (float) $site->bulk_discount_percent_5);
        $this->assertSame(25.0, (float) $site->bulk_discount_percent_10);

        $log = ActivityLog::query()->where('action', 'site.bulk_discount_admin_set')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, (int) $log->user_id);
        $this->assertSame($site->id, (int) $log->subject_id);
        $this->assertNull($log->properties['from'] ?? null);
        $this->assertSame(10.0, (float) $log->properties['to']);
        $this->assertNull($log->properties['from_percent_5'] ?? null);
        $this->assertSame(15.0, (float) $log->properties['to_percent_5']);
        $this->assertNull($log->properties['from_percent_10'] ?? null);
        $this->assertSame(25.0, (float) $log->properties['to_percent_10']);
        $this->assertStringContainsString('3 for −10% · 5 for −15% · 10 for −25%', (string) $log->description);

        Mail::assertQueued(AdminBulkDiscountNotification::class, function (AdminBulkDiscountNotification $mail) use ($site) {
            return $mail->hasTo($this->publisher->email)
                && $mail->site->is($site)
                && $mail->change === 'set'
                && ($mail->toTiers[3] ?? null) === 10.0
                && ($mail->toTiers[5] ?? null) === 15.0
                && ($mail->toTiers[10] ?? null) === 25.0
                && ! str_contains(strtolower($mail->build()->subject), 'markup')
                && ! str_contains(strtolower($mail->render()), 'markup');
        });

        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount', $site->id), [
                'percent' => 12,
                'percent_5' => 18,
                'percent_10' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('bulk_discount_label', '3 for −12% · 5 for −18% · 10 for −30%');

        $updated = ActivityLog::query()->where('action', 'site.bulk_discount_admin_updated')->first();
        $this->assertNotNull($updated);
        $this->assertSame(10.0, (float) $updated->properties['from']);
        $this->assertSame(12.0, (float) $updated->properties['to']);
        $this->assertSame(15.0, (float) $updated->properties['from_percent_5']);
        $this->assertSame(18.0, (float) $updated->properties['to_percent_5']);
        $this->assertSame(25.0, (float) $updated->properties['from_percent_10']);
        $this->assertSame(30.0, (float) $updated->properties['to_percent_10']);

        Mail::assertQueued(AdminBulkDiscountNotification::class, fn (AdminBulkDiscountNotification $mail) => $mail->change === 'updated');

        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount', $site->id), ['remove' => true])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('bulk_discount', false);

        $this->assertFalse($site->fresh()->joinsBulkDiscount());
        $this->assertNull($site->fresh()->bulk_discount_percent);
        $this->assertNull($site->fresh()->bulk_discount_percent_5);
        $this->assertNull($site->fresh()->bulk_discount_percent_10);

        $removed = ActivityLog::query()->where('action', 'site.bulk_discount_admin_removed')->first();
        $this->assertNotNull($removed);
        $this->assertSame(12.0, (float) $removed->properties['from']);
        $this->assertNull($removed->properties['to'] ?? null);
        $this->assertSame(18.0, (float) $removed->properties['from_percent_5']);
        $this->assertNull($removed->properties['to_percent_5'] ?? null);
        $this->assertSame(30.0, (float) $removed->properties['from_percent_10']);
        $this->assertNull($removed->properties['to_percent_10'] ?? null);

        Mail::assertQueued(AdminBulkDiscountNotification::class, fn (AdminBulkDiscountNotification $mail) => $mail->change === 'removed');
    }

    public function test_admin_bulk_set_and_remove_selected_sites(): void
    {
        $one = $this->site();
        $two = $this->site([
            'site_name' => 'Pack Site Two',
            'site_url' => 'https://pack-site-two.example',
            'domain' => 'pack-site-two.example',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount.bulk'), [
                'ids' => [$one->id, $two->id],
                'percent' => 10,
                'percent_5' => 20,
                'percent_10' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated', [$one->id, $two->id]);

        $this->assertSame('3 for −10% · 5 for −20% · 10 for −30%', $one->fresh()->bulkDiscountLabel());
        $this->assertSame('3 for −10% · 5 for −20% · 10 for −30%', $two->fresh()->bulkDiscountLabel());
        $this->assertSame(2, ActivityLog::query()->where('action', 'site.bulk_discount_admin_set')->count());
        Mail::assertQueued(AdminBulkDiscountNotification::class, 2);

        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount.bulk'), [
                'ids' => [$one->id, $two->id],
                'remove' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($one->fresh()->joinsBulkDiscount());
        $this->assertFalse($two->fresh()->joinsBulkDiscount());
        $this->assertSame(2, ActivityLog::query()->where('action', 'site.bulk_discount_admin_removed')->count());
        Mail::assertQueued(AdminBulkDiscountNotification::class, 2);
    }

    public function test_admin_clamps_out_of_range_percents(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount', $site->id), [
                'percent' => 5,
                'percent_5' => 90,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame(10.0, (float) $site->bulk_discount_percent);
        $this->assertSame(80.0, (float) $site->bulk_discount_percent_5);
        $this->assertNull($site->bulk_discount_percent_10);
        $this->assertSame('3 for −10% · 5 for −80%', $site->bulkDiscountLabel());
    }

    public function test_admin_rejects_non_monotonic_and_empty_tiers(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount', $site->id), [
                'percent' => 20,
                'percent_5' => 15,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('percent_5');

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount', $site->id), [
                'percent' => 20,
                'percent_10' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('percent_10');

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk-discount', $site->id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('percent');

        $this->assertFalse($site->fresh()->joinsBulkDiscount());
        Mail::assertNothingQueued();
    }

    public function test_marketing_and_publisher_cannot_set_pack_discount(): void
    {
        $site = $this->site();

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.bulk-discount', $site->id), [
                'percent' => 10,
            ])
            ->assertStatus(403);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.bulk-discount.bulk'), [
                'ids' => [$site->id],
                'percent' => 10,
            ])
            ->assertStatus(403);

        $this->actingAs($this->publisher)
            ->postJson(route('admin.sites.bulk-discount', $site->id), [
                'percent' => 10,
            ])
            ->assertStatus(403);

        $this->assertFalse($site->fresh()->joinsBulkDiscount());
        Mail::assertNothingQueued();
        $this->assertSame(0, ActivityLog::query()->where('action', 'like', 'site.bulk_discount_admin_%')->count());
    }

    public function test_admin_pack_discount_mail_is_wired_to_system_updates(): void
    {
        $this->assertSame('system_updates', config('email_notifications.types.admin_bulk_discount.preference'));
        $this->assertSame(AdminBulkDiscountNotification::class, config('email_notifications.types.admin_bulk_discount.mailable'));
        $this->assertTrue((bool) config('email_notifications.types.admin_bulk_discount.default_enabled'));
    }
}
