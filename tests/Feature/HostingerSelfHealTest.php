<?php

namespace Tests\Feature;

use App\Http\Middleware\HealHostingerProduction;
use App\Support\DotEnvWriter;
use App\Support\HostingerMediaPath;
use App\Support\ProductionReadiness;
use App\Support\ProductionRepair;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Live Hostinger cannot be SSH’d from this agent. These tests lock the
 * self-heal path: migrate --force, APP_URL, MEDIA_PATH, scheduler/queue.
 */
class HostingerSelfHealTest extends TestCase
{
    use RefreshDatabase;

    public function test_heal_middleware_is_on_the_http_kernel(): void
    {
        $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

        $this->assertContains(HealHostingerProduction::class, $middleware);
    }

    public function test_suggests_persistent_media_outside_public_html(): void
    {
        $this->assertSame(
            '/home/u123/persistent/media',
            HostingerMediaPath::suggest('/home/u123/domains/x.com/public_html')
        );
        $this->assertSame(
            '/home/u123/persistent/media',
            HostingerMediaPath::suggest('/home/u123/public_html')
        );
        $this->assertSame(
            '/var/www/site/persistent/media',
            HostingerMediaPath::suggest('/var/www/site/public_html/app')
        );
        $this->assertNull(HostingerMediaPath::suggest('/workspace'));
        $this->assertNull(HostingerMediaPath::suggest('/Users/me/code'));
    }

    public function test_looks_like_hostinger_only_for_home_public_html(): void
    {
        $this->assertTrue(HostingerMediaPath::looksLikeHostinger('/home/u123/domains/x.com/public_html'));
        $this->assertFalse(HostingerMediaPath::looksLikeHostinger('/home/ubuntu/workspace'));
        $this->assertFalse(HostingerMediaPath::looksLikeHostinger('/workspace'));
        $this->assertFalse(HostingerMediaPath::looksLikeHostinger(base_path()));
    }

    public function test_ensure_creates_preferred_dir_and_leaves_public_html(): void
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cmbop-hh-'.uniqid('', true);
        $bad = $base.DIRECTORY_SEPARATOR.'public_html'.DIRECTORY_SEPARATOR.'storage';
        $good = $base.DIRECTORY_SEPARATOR.'persistent'.DIRECTORY_SEPARATOR.'media';

        $this->assertTrue(mkdir($bad, 0755, true));

        try {
            config(['filesystems.media_path' => $bad]);
            $path = HostingerMediaPath::ensure($good);

            $this->assertSame($good, $path);
            $this->assertDirectoryExists($good);
            $this->assertDirectoryIsWritable($good);

            HostingerMediaPath::applyRuntime($path);
            $this->assertSame($good, config('filesystems.media_path'));
            $this->assertSame($good, config('filesystems.disks.public.root'));
        } finally {
            $this->removeDir($base);
        }
    }

    public function test_dot_env_writer_updates_a_temp_file_only(): void
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cmbop-env-'.uniqid('', true);
        file_put_contents($file, "APP_URL=http://localhost\nMEDIA_PATH=\n");

        try {
            $this->assertTrue(DotEnvWriter::set('MEDIA_PATH', '/home/u123/persistent/media', $file));
            $this->assertTrue(DotEnvWriter::set('APP_URL', 'https://seolinkbuildings.example', $file));
            $this->assertFalse(DotEnvWriter::set('not-a-key', 'x', $file));

            $contents = (string) file_get_contents($file);
            $this->assertStringContainsString('MEDIA_PATH=/home/u123/persistent/media', $contents);
            $this->assertStringContainsString('APP_URL=https://seolinkbuildings.example', $contents);
            $this->assertStringNotContainsString('not-a-key', $contents);
        } finally {
            @unlink($file);
        }
    }

    public function test_repair_sets_runtime_app_url_and_media_without_writing_env(): void
    {
        $this->seed(RolesTableSeeder::class);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cmbop-repair-media-'.uniqid('', true);
        $this->assertTrue(mkdir($dir, 0755, true));
        $envBefore = is_file(base_path('.env')) ? (string) file_get_contents(base_path('.env')) : null;

        try {
            $this->forceProduction();
            config([
                'app.url' => 'http://127.0.0.1:8000',
                'app.public_url' => 'https://seolinkbuildings.example',
                'filesystems.media_path' => $dir,
                'services.paypal.mode' => 'sandbox',
                'services.paypal.allow_sandbox' => false,
            ]);

            $notes = app(ProductionRepair::class)->run(true);

            $this->assertSame('https://seolinkbuildings.example', config('app.url'));
            $this->assertSame('live', config('services.paypal.mode'));
            $this->assertTrue(collect($notes)->contains(fn (string $note) => str_contains($note, 'PAYPAL_MODE runtime set to live')));
            $this->assertSame($dir, config('filesystems.media_path'));
            $this->assertSame($dir, config('filesystems.disks.public.root'));
            $this->assertTrue(collect($notes)->contains(fn (string $note) => str_contains($note, 'migrate --force')));
            $this->assertTrue(collect($notes)->contains(fn (string $note) => str_contains($note, 'PUBLIC_APP_URL')));
            $this->assertTrue(collect($notes)->contains(fn (string $note) => str_contains($note, 'MEDIA_PATH using')));
            $this->assertFalse(collect($notes)->contains(fn (string $note) => str_contains($note, 'APP_URL written')));

            if ($envBefore !== null) {
                $this->assertSame($envBefore, (string) file_get_contents(base_path('.env')));
            }
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_scheduler_is_ok_when_web_heal_is_on(): void
    {
        $this->seed(RolesTableSeeder::class);
        $this->forceProduction();
        config([
            'app.web_heal' => true,
            'app.cron_secret' => '',
        ]);

        $scheduler = collect(app(ProductionReadiness::class)->checks())->firstWhere('id', 'scheduler');
        $this->assertNotNull($scheduler);
        $this->assertSame(ProductionReadiness::SEVERITY_OK, $scheduler['severity']);
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL', $scheduler['detail']);
    }

    public function test_scheduler_warns_in_production_when_web_heal_is_off(): void
    {
        $this->seed(RolesTableSeeder::class);
        $this->forceProduction();
        config([
            'app.web_heal' => false,
            'app.cron_secret' => '',
        ]);

        $scheduler = collect(app(ProductionReadiness::class)->checks())->firstWhere('id', 'scheduler');
        $this->assertNotNull($scheduler);
        $this->assertSame(ProductionReadiness::SEVERITY_WARN, $scheduler['severity']);
    }

    public function test_heal_is_not_remembered_when_migrate_failed(): void
    {
        $this->assertFalse(ProductionRepair::migrateCompleted([
            'migrate failed: SQLSTATE[42000]: 1059 Identifier name is too long',
            'roles seeded (advertiser, publisher, admin, marketing)',
        ]));
        $this->assertFalse(ProductionRepair::migrateCompleted([
            'migrate --force exited 1',
        ]));
        $this->assertFalse(ProductionRepair::migrateCompleted([
            'roles seeded (advertiser, publisher, admin, marketing)',
        ]));
        $this->assertTrue(ProductionRepair::migrateCompleted([
            'migrate --force completed',
            'roles seeded (advertiser, publisher, admin, marketing)',
        ]));
    }

    public function test_force_production_still_counts_as_an_automated_test(): void
    {
        $this->assertTrue(ProductionRepair::runningAutomatedTest());

        $this->forceProduction();
        config(['app.web_heal' => true]);

        $this->assertTrue(ProductionRepair::runningAutomatedTest());
        $this->assertFalse(app()->runningUnitTests());
        $this->assertTrue(ProductionRepair::promotionsStorageReady());
    }

    public function test_promotions_storage_ready_is_false_when_welcome_settings_missing(): void
    {
        $this->assertTrue(ProductionRepair::promotionsStorageReady());

        Schema::dropIfExists('welcome_bonus_settings');

        $this->assertFalse(ProductionRepair::promotionsStorageReady());
        $this->assertFalse(ProductionRepair::welcomeBonusStorageReady());
    }

    public function test_welcome_bonus_tables_can_be_created_when_batch_migrate_did_not(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');
        Schema::dropIfExists('welcome_bonus_settings');
        DB::table('migrations')->whereIn('migration', [
            '2026_08_14_180000_create_welcome_bonus_settings_table',
            '2026_08_14_180100_create_welcome_bonus_claims_table',
            '2026_08_15_103800_keep_welcome_bonus_claims_after_user_delete',
            '2026_08_15_110800_unique_welcome_bonus_claim_place',
            '2026_08_15_112000_unique_welcome_bonus_settings_key',
        ])->delete();
        $this->assertFalse(ProductionRepair::welcomeBonusStorageReady());

        $notes = [];
        app(ProductionRepair::class)->ensureWelcomeBonusMigrations($notes);

        $this->assertTrue(Schema::hasTable('welcome_bonus_settings'));
        $this->assertTrue(Schema::hasTable('welcome_bonus_claims'));
        $this->assertTrue(ProductionRepair::welcomeBonusStorageReady());
        $this->assertTrue(collect($notes)->contains('welcome bonus tables ready'));
    }

    public function test_welcome_bonus_tables_are_created_when_migrate_rows_already_exist(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');
        Schema::dropIfExists('welcome_bonus_settings');
        $this->assertFalse(ProductionRepair::welcomeBonusStorageReady());

        $recorded = DB::table('migrations')->whereIn('migration', [
            '2026_08_14_180000_create_welcome_bonus_settings_table',
            '2026_08_14_180100_create_welcome_bonus_claims_table',
        ])->count();
        $this->assertSame(2, $recorded);

        $notes = [];
        app(ProductionRepair::class)->ensureWelcomeBonusMigrations($notes);

        $this->assertTrue(Schema::hasTable('welcome_bonus_settings'));
        $this->assertTrue(Schema::hasTable('welcome_bonus_claims'));
        $this->assertTrue(ProductionRepair::welcomeBonusStorageReady());
        $this->assertTrue(collect($notes)->contains('welcome bonus tables ready'));
    }

    public function test_incomplete_heal_always_sets_retry_even_when_promotions_storage_is_missing(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');
        $this->assertFalse(ProductionRepair::promotionsStorageReady());

        $this->app->instance(ProductionRepair::class, new class extends ProductionRepair
        {
            public function run(bool $persistEnv = true): array
            {
                return ['migrate failed: later FK'];
            }
        });

        $middleware = $this->app->make(HealHostingerProduction::class);
        $healOnce = new \ReflectionMethod($middleware, 'healOnce');

        $healOnce->invoke($middleware);

        $this->assertTrue((bool) cache()->get(HealHostingerProduction::HEAL_RETRY));
        $this->assertFalse((bool) cache()->get(HealHostingerProduction::HEAL_FLAG));
    }

    public function test_heal_skips_while_retry_is_cached(): void
    {
        cache()->put(HealHostingerProduction::HEAL_RETRY, true, 300);

        $state = (object) ['ran' => false];
        $this->app->instance(ProductionRepair::class, new class($state) extends ProductionRepair
        {
            public function __construct(private object $state) {}

            public function run(bool $persistEnv = true): array
            {
                $this->state->ran = true;

                return ['migrate --force completed'];
            }
        });

        $middleware = $this->app->make(HealHostingerProduction::class);
        $healOnce = new \ReflectionMethod($middleware, 'healOnce');
        $healOnce->invoke($middleware);

        $this->assertFalse($state->ran);
    }

    public function test_web_heal_defaults_on_and_docs_name_the_self_heal(): void
    {
        $this->assertTrue(config('app.web_heal'));

        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL=true', $example);

        $deploy = (string) file_get_contents(base_path('docs/deploy-hostinger.md'));
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL', $deploy);
        $this->assertStringContainsString('ops:production-ready --repair', $deploy);

        $agents = (string) file_get_contents(base_path('AGENTS.md'));
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL', $agents);

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_04_06_094704_create_sites_table.php',
            '2026_04_21_070134_create_orders_table.php',
            '2026_04_21_070217_create_order_items_table.php',
            '2026_08_14_180000_create_welcome_bonus_settings_table.php',
            '2026_08_14_180100_create_welcome_bonus_claims_table.php',
            '2026_09_14_213000_create_billing_rule_settings_table.php',
            '2026_09_14_230000_create_staff_two_factor_table.php',
            '2026_09_15_040000_create_staff_capabilities_table.php',
            '2026_09_15_051500_create_legal_page_overrides_table.php',
        ] as $file) {
            $this->assertFileExists(database_path('migrations/'.$file));
        }
    }

    private function forceProduction(): void
    {
        app()['env'] = 'production';
        config(['app.env' => 'production']);
    }

    private function removeDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
