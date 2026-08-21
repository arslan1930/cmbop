<?php

namespace Tests\Feature;

use App\Models\ContentModerationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminGrowthMenusCrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function admin(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * @param  list<string>  $tables
     */
    private function dropTables(array $tables): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            DB::statement('PRAGMA foreign_keys = OFF');
        } catch (\Throwable) {
        }

        foreach ($tables as $table) {
            try {
                Schema::dropIfExists($table);
            } catch (\Throwable) {
                try {
                    DB::statement('DROP TABLE IF EXISTS "'.$table.'"');
                } catch (\Throwable) {
                }
            }
        }

        try {
            Schema::enableForeignKeyConstraints();
        } catch (\Throwable) {
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function remigrate(array $paths): void
    {
        foreach ($paths as $path) {
            $this->artisan('migrate', [
                '--path' => $path,
                '--force' => true,
            ]);
        }
    }

    public function test_growth_indexes_load(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.blogs.index'))
            ->assertOk()
            ->assertSee('Blogs', false)
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Email Center', false)
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('Updates &amp; Campaigns', false)
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)->get(route('admin.audiences.index'))
            ->assertOk()
            ->assertSee('Audience Inventory', false)
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertSee('Content Moderation', false)
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)->get(route('admin.content-library.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    public function test_blogs_index_survives_missing_tables(): void
    {
        $admin = $this->admin();
        $this->dropTables(['blog_translations', 'blogs']);
        $this->assertFalse(Schema::hasTable('blogs'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.blogs.index', ['q' => 'seo', 'missing_translations' => 1]))
                ->assertOk()
                ->assertSee('Blogs', false)
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->get(route('admin.blogs.show', 1))
                ->assertNotFound();

            $this->actingAs($admin)
                ->get(route('admin.blogs.edit', 1))
                ->assertNotFound();
        } finally {
            $this->remigrate([
                'database/migrations/2024_01_01_000000_create_blogs_table.php',
                'database/migrations/2026_07_27_140000_add_primary_locale_to_blogs_table.php',
                'database/migrations/2026_08_15_083200_add_curated_blog_protection.php',
                'database/migrations/2026_07_29_100000_create_blog_translations_table.php',
            ]);
        }
    }

    public function test_email_center_survives_missing_logs_and_campaigns(): void
    {
        $admin = $this->admin();
        $this->dropTables(['email_campaigns', 'email_logs']);
        $this->assertFalse(Schema::hasTable('email_logs'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.emails.index'))
                ->assertOk()
                ->assertSee('Email Center', false)
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->get(route('admin.emails.log', 1))
                ->assertNotFound();

            $this->actingAs($admin)
                ->from(route('admin.emails.index'))
                ->post(route('admin.emails.retry'), ['log_id' => 1])
                ->assertRedirect(route('admin.emails.index'))
                ->assertSessionHas('error');
        } finally {
            $this->restoreEmailLogsTable();
            $this->remigrate([
                'database/migrations/2026_07_16_170000_create_email_campaigns_table.php',
                'database/migrations/2026_08_21_024800_add_include_unverified_to_email_campaigns_table.php',
            ]);
        }
    }

    public function test_email_settings_flash_when_destination_table_is_missing(): void
    {
        $admin = $this->admin();
        $this->dropTables(['email_notification_settings']);
        $this->assertFalse(Schema::hasTable('email_notification_settings'));

        try {
            $this->actingAs($admin)
                ->from(route('admin.emails.index'))
                ->post(route('admin.emails.settings'), [
                    'enabled' => ['welcome' => '1'],
                ])
                ->assertRedirect(route('admin.emails.index'))
                ->assertSessionHas('error');
        } finally {
            if (! Schema::hasTable('email_notification_settings')) {
                Schema::create('email_notification_settings', function (Blueprint $table) {
                    $table->id();
                    $table->string('type')->unique();
                    $table->boolean('enabled')->default(true);
                    $table->string('subject_override')->nullable();
                    $table->timestamps();
                });
            }
        }
    }

    public function test_campaigns_survive_missing_campaign_tables(): void
    {
        $admin = $this->admin();
        $this->dropTables(['email_campaign_recipients', 'email_campaigns']);
        $this->assertFalse(Schema::hasTable('email_campaigns'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.campaigns.index'))
                ->assertOk()
                ->assertSee('Updates &amp; Campaigns', false)
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->get(route('admin.campaigns.drafts'))
                ->assertOk()
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->get(route('admin.campaigns.index', ['tab' => 'sending']))
                ->assertOk()
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->from(route('admin.campaigns.index'))
                ->post(route('admin.campaigns.send'), [
                    'name' => 'Ghost send',
                    'subject' => 'Ghost send',
                    'body_html' => '<p>Hello</p>',
                    'audience' => 'advertisers',
                ])
                ->assertRedirect(route('admin.campaigns.index'))
                ->assertSessionHas('error');

            $this->actingAs($admin)
                ->from(route('admin.campaigns.index'))
                ->post(route('admin.campaigns.drafts.store'), [
                    'name' => 'Ghost draft',
                    'subject' => 'Ghost draft',
                    'body_html' => '<p>Hello</p>',
                    'audience' => 'advertisers',
                ])
                ->assertRedirect(route('admin.campaigns.index'))
                ->assertSessionHas('error');
        } finally {
            $this->remigrate([
                'database/migrations/2026_07_16_170000_create_email_campaigns_table.php',
                'database/migrations/2026_08_21_024800_add_include_unverified_to_email_campaigns_table.php',
                'database/migrations/2026_08_15_140000_create_email_campaign_recipients_table.php',
            ]);
        }
    }

    public function test_audiences_survive_missing_orders_table(): void
    {
        $admin = $this->admin();
        $this->makeAdvertiser();
        $this->dropTables(['orders']);
        $this->assertFalse(Schema::hasTable('orders'));

        try {
            foreach (['advertisers', 'no_orders', 'paid_orders'] as $tab) {
                $this->actingAs($admin)
                    ->get(route('admin.audiences.index', [
                        'tab' => $tab,
                        'marketing' => 'opted_in',
                    ]))
                    ->assertOk()
                    ->assertSee('Audience Inventory', false)
                    ->assertDontSee('Something went wrong');
            }

            $this->actingAs($admin)
                ->get(route('admin.audiences.export', ['audience' => 'no_orders']))
                ->assertOk();
        } finally {
            $this->remigrate([
                'database/migrations/2026_04_21_070134_create_orders_table.php',
            ]);
        }
    }

    public function test_audiences_survive_missing_sites_table(): void
    {
        $admin = $this->admin();
        $this->dropTables(['sites']);
        $this->assertFalse(Schema::hasTable('sites'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.audiences.index', ['tab' => 'no_sites']))
                ->assertOk()
                ->assertSee('Audience Inventory', false)
                ->assertDontSee('Something went wrong');
        } finally {
            $this->remigrate([
                'database/migrations/2026_04_06_094704_create_sites_table.php',
            ]);
        }
    }

    public function test_audiences_survive_missing_deposit_requests_table(): void
    {
        $admin = $this->admin();
        $this->dropTables(['deposit_requests']);
        $this->assertFalse(Schema::hasTable('deposit_requests'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.audiences.index', ['tab' => 'never_deposited']))
                ->assertOk()
                ->assertSee('Audience Inventory', false)
                ->assertDontSee('Something went wrong');
        } finally {
            $this->remigrate([
                'database/migrations/2026_04_21_115734_create_deposit_requests_table.php',
                'database/migrations/2026_04_22_113004_add_stripe_fields_to_deposit_requests_table.php',
                'database/migrations/2026_07_21_140000_add_user_marked_paid_to_deposit_requests.php',
                'database/migrations/2026_08_14_160000_unique_deposit_stripe_ids.php',
            ]);
        }
    }

    public function test_audiences_marketing_filter_survives_missing_preferences_table(): void
    {
        $admin = $this->admin();
        $this->dropTables(['email_notification_preferences']);
        $this->assertFalse(Schema::hasTable('email_notification_preferences'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.audiences.index', ['marketing' => 'opted_out']))
                ->assertOk()
                ->assertSee('Audience Inventory', false)
                ->assertDontSee('Something went wrong');
        } finally {
            if (! Schema::hasTable('email_notification_preferences')) {
                Schema::create('email_notification_preferences', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                    $table->string('preference_key', 64);
                    $table->boolean('enabled')->default(true);
                    $table->timestamps();
                    $table->unique(['user_id', 'preference_key']);
                });
            }
        }
    }

    public function test_moderation_survives_missing_logs_and_settings(): void
    {
        $admin = $this->admin();
        $this->dropTables(['content_moderation_logs']);
        $this->assertFalse(Schema::hasTable('content_moderation_logs'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.moderation.index'))
                ->assertOk()
                ->assertSee('Content Moderation', false)
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->get(route('admin.moderation.show', 1))
                ->assertNotFound();
        } finally {
            if (! Schema::hasTable('content_moderation_logs')) {
                Schema::create('content_moderation_logs', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('user_id')->nullable();
                    $table->unsignedBigInteger('order_id')->nullable();
                    $table->unsignedBigInteger('order_item_id')->nullable();
                    $table->unsignedBigInteger('content_submission_id')->nullable();
                    $table->string('document_url', 1000);
                    $table->string('document_id')->nullable();
                    $table->string('status', 40);
                    $table->boolean('passed')->default(false);
                    $table->unsignedTinyInteger('max_confidence')->default(0);
                    $table->string('detected_category')->nullable();
                    $table->json('category_scores')->nullable();
                    $table->json('quality_report')->nullable();
                    $table->json('signals')->nullable();
                    $table->string('error_code')->nullable();
                    $table->string('error_message')->nullable();
                    $table->unsignedInteger('word_count')->default(0);
                    $table->string('scan_token', 64)->nullable();
                    $table->boolean('admin_override')->default(false);
                    $table->unsignedBigInteger('overridden_by')->nullable();
                    $table->timestamp('overridden_at')->nullable();
                    $table->text('admin_notes')->nullable();
                    $table->timestamps();
                });
            }
        }

        $this->dropTables(['content_moderation_settings']);
        $this->assertFalse(Schema::hasTable('content_moderation_settings'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.moderation.index'))
                ->assertOk()
                ->assertSee('Content Moderation', false);

            $this->actingAs($admin)
                ->from(route('admin.moderation.index'))
                ->post(route('admin.moderation.settings'), [
                    'confidence_threshold' => 70,
                    'categories' => ['gambling'],
                ])
                ->assertRedirect(route('admin.moderation.index'))
                ->assertSessionHas('error');
        } finally {
            if (! Schema::hasTable('content_moderation_settings')) {
                Schema::create('content_moderation_settings', function (Blueprint $table) {
                    $table->id();
                    $table->string('key')->unique();
                    $table->json('value')->nullable();
                    $table->timestamps();
                });
            }
        }
    }

    public function test_moderation_survives_missing_submissions_table(): void
    {
        $admin = $this->admin();
        $log = ContentModerationLog::create([
            'document_url' => 'upload:99',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'word_count' => 12,
            'scan_token' => 'leftover-scan',
        ]);

        $this->dropTables(['content_submissions']);
        $this->assertFalse(Schema::hasTable('content_submissions'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.moderation.index'))
                ->assertOk()
                ->assertSee('Content Moderation', false)
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->get(route('admin.moderation.show', $log))
                ->assertOk()
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->from(route('admin.moderation.show', $log))
                ->post(route('admin.moderation.override', $log), [
                    'notes' => 'Approve leftover scan.',
                ])
                ->assertRedirect(route('admin.moderation.show', $log))
                ->assertSessionHas('error');
        } finally {
            $this->restoreContentSubmissionsTable();
        }
    }

    public function test_moderation_survives_leftover_dates(): void
    {
        $admin = $this->admin();
        $log = ContentModerationLog::create([
            'document_url' => 'https://example.com/article',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'admin_override' => true,
            'overridden_by' => $admin->id,
            'word_count' => 12,
            'scan_token' => 'leftover-dates',
        ]);

        ContentModerationLog::query()->whereKey($log->id)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'also-not-a-date',
            'overridden_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertSee('Content Moderation', false)
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)
            ->get(route('admin.moderation.show', $log->id))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    public function test_content_library_survives_missing_submissions_and_orders(): void
    {
        $admin = $this->admin();
        $this->dropTables(['content_submissions']);
        $this->assertFalse(Schema::hasTable('content_submissions'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.content-library.index'))
                ->assertOk()
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->get(route('admin.content-library.show', 1))
                ->assertNotFound();
        } finally {
            $this->restoreContentSubmissionsTable();
        }
    }

    public function test_content_library_survives_missing_orders_table(): void
    {
        $admin = $this->admin();
        $this->dropTables(['orders']);
        $this->assertFalse(Schema::hasTable('orders'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.content-library.index'))
                ->assertOk()
                ->assertDontSee('Something went wrong');
        } finally {
            $this->remigrate([
                'database/migrations/2026_04_21_070134_create_orders_table.php',
            ]);
        }
    }

    public function test_promotions_hub_still_loads(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    private function restoreContentSubmissionsTable(): void
    {
        $this->remigrate([
            'database/migrations/2026_07_16_200000_create_content_upload_system.php',
            'database/migrations/2026_07_16_220000_add_country_language_to_content_submissions.php',
            'database/migrations/2026_07_17_220000_add_archived_at_to_content_submissions.php',
            'database/migrations/2026_08_05_120000_add_image_rights_to_content_submissions.php',
        ]);
    }

    private function restoreEmailLogsTable(): void
    {
        $this->remigrate([
            'database/migrations/2026_07_16_150000_create_email_logs_table.php',
        ]);

        if (! Schema::hasTable('email_logs')) {
            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'notification_type')) {
                $table->string('notification_type')->nullable()->index();
            }
            if (! Schema::hasColumn('email_logs', 'dedupe_key')) {
                $table->string('dedupe_key')->nullable()->index();
            }
            if (! Schema::hasColumn('email_logs', 'audience')) {
                $table->string('audience', 32)->nullable();
            }
        });
    }

    private function makeAdvertiser(): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
