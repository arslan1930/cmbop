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

    public function test_web_heal_defaults_on_and_docs_name_the_self_heal(): void
    {
        $this->assertTrue(config('app.web_heal'));

        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL=true', $example);

        $deploy = (string) file_get_contents(base_path('docs/deploy-hostinger.md'));
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL', $deploy);
        $this->assertStringContainsString('ops:production-ready --repair', $deploy);
        $this->assertStringContainsString('public_html/assets/', $deploy);

        $splitIndex = (string) file_get_contents(public_path('index.hostinger.php'));
        $this->assertStringContainsString('usePublicPath(__DIR__)', $splitIndex);
        $this->assertStringContainsString('/../laravel_app', $splitIndex);
        $this->assertStringContainsString('@property --shell-rail', (string) file_get_contents(public_path('assets/css/app-shell.css')));

        $agents = (string) file_get_contents(base_path('AGENTS.md'));
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL', $agents);

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_04_06_094704_create_sites_table.php',
            '2026_04_21_070134_create_orders_table.php',
            '2026_04_21_070217_create_order_items_table.php',
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
