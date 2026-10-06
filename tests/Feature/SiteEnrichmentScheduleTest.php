<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SiteEnrichmentScheduleTest extends TestCase
{
    public function test_scheduled_enrich_queues_jobs_instead_of_running_sync(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'sites:enrich'));

        $this->assertNotNull($scheduled, 'sites:enrich must be scheduled.');
        $this->assertStringContainsString('sites:enrich --stale', (string) $scheduled->command);
        $this->assertStringNotContainsString('--sync', (string) $scheduled->command);

        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("command('sites:enrich --stale')", $bootstrap);
        $this->assertStringNotContainsString('sites:enrich --stale --sync', $bootstrap);
    }

    public function test_catalog_favicon_warm_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'catalog:warm-favicons'));

        $this->assertNotNull($scheduled, 'catalog:warm-favicons must be scheduled.');
        $this->assertStringContainsString('catalog:warm-favicons --limit=5', (string) $scheduled->command);
        $this->assertSame('* * * * *', $scheduled->expression);
    }
}
