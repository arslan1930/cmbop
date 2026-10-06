<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogCountryInventory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogCountryInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'publisher'],
            ['guard_name' => 'web']
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function site(User $publisher, array $attrs = []): Site
    {
        $domain = $attrs['domain'] ?? ('inv-'.uniqid('', true).'.test');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Inventory '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Inventory fixture site for country counts.',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    public function test_counts_active_sites_once_per_primary_country(): void
    {
        Cache::flush();
        $publisher = $this->publisher();

        $this->site($publisher, ['country' => 'de', 'countries' => ['de'], 'domain' => 'de-one.test']);
        $this->site($publisher, ['country' => 'de', 'countries' => ['de'], 'domain' => 'de-two.test']);
        $this->site($publisher, [
            'country' => 'us',
            'countries' => ['us', 'uk', 'ca'], // legacy multi — count primary only
            'domain' => 'us-multi.test',
        ]);
        $this->site($publisher, [
            'country' => 'fr',
            'countries' => ['fr'],
            'active' => false,
            'domain' => 'fr-inactive.test',
        ]);

        $inventory = app(CatalogCountryInventory::class);
        $counts = $inventory->counts();

        $this->assertSame(2, $counts['de'] ?? 0);
        $this->assertSame(1, $counts['us'] ?? 0);
        $this->assertArrayNotHasKey('uk', $counts);
        $this->assertArrayNotHasKey('ca', $counts);
        $this->assertArrayNotHasKey('fr', $counts);
    }

    public function test_counts_include_active_unverified_listings(): void
    {
        Cache::flush();
        $publisher = $this->publisher();
        $this->site($publisher, [
            'country' => 'pt',
            'countries' => ['pt'],
            'verified' => false,
            'active' => true,
            'domain' => 'pt-unverified.test',
        ]);

        $counts = app(CatalogCountryInventory::class)->counts();
        $this->assertSame(1, $counts['pt'] ?? 0);

        $codes = collect(app(CatalogCountryInventory::class)->pickerSections()['sections'])
            ->flatMap(fn ($s) => $s['options'])
            ->pluck('code')
            ->all();
        $this->assertContains('pt', $codes);
        $this->assertContains('de', $codes);
    }

    public function test_counts_json_country_when_scalar_country_is_empty(): void
    {
        Cache::flush();
        $publisher = $this->publisher();
        $this->site($publisher, [
            'country' => '',
            'countries' => ['it'],
            'domain' => 'it-json-only.test',
        ]);

        $counts = app(CatalogCountryInventory::class)->counts();
        $this->assertSame(1, $counts['it'] ?? 0);
    }

    public function test_counts_ignore_countries_outside_allowlist(): void
    {
        Cache::flush();
        $publisher = $this->publisher();

        $this->site($publisher, [
            'country' => 'xx',
            'countries' => ['xx'],
            'domain' => 'outside-allow.test',
        ]);

        $counts = app(CatalogCountryInventory::class)->counts();
        $this->assertArrayNotHasKey('xx', $counts);
    }

    public function test_cache_is_invalidated_when_site_country_or_active_changes(): void
    {
        Cache::flush();
        $publisher = $this->publisher();
        $site = $this->site($publisher, ['country' => 'nl', 'countries' => ['nl'], 'domain' => 'nl-cache.test']);

        $inventory = app(CatalogCountryInventory::class);
        $this->assertSame(1, $inventory->counts()['nl'] ?? 0);

        $site->update(['active' => false]);
        $this->assertArrayNotHasKey('nl', $inventory->counts());

        $site->update(['active' => true, 'country' => 'be', 'countries' => ['be']]);
        $counts = $inventory->counts();
        $this->assertArrayNotHasKey('nl', $counts);
        $this->assertSame(1, $counts['be'] ?? 0);
    }

    public function test_primary_country_code_prefers_country_column(): void
    {
        $inventory = app(CatalogCountryInventory::class);

        $this->assertSame('de', $inventory->primaryCountryCode('de', ['at', 'ch']));
        $this->assertSame('at', $inventory->primaryCountryCode('', ['at', 'ch']));
        $this->assertNull($inventory->primaryCountryCode('', []));
    }

    public function test_constrain_query_matches_scalar_country_not_json_contains(): void
    {
        $publisher = $this->publisher();
        $deMulti = $this->site($publisher, [
            'country' => 'de',
            'countries' => ['de', 'us'],
            'domain' => 'de-multi.example',
        ]);
        $usOnly = $this->site($publisher, [
            'country' => 'us',
            'countries' => ['us'],
            'domain' => 'us-only.example',
        ]);

        $inventory = app(CatalogCountryInventory::class);

        $usIds = Site::query()
            ->tap(fn ($q) => $inventory->constrainQueryToPrimaryCountries($q, ['us']))
            ->pluck('id')
            ->all();

        $this->assertSame([$usOnly->id], $usIds);
        $this->assertNotContains($deMulti->id, $usIds);

        $deIds = Site::query()
            ->tap(fn ($q) => $inventory->constrainQueryToPrimaryCountries($q, ['de']))
            ->pluck('id')
            ->all();

        $this->assertSame([$deMulti->id], $deIds);
    }

    public function test_counts_use_scalar_country_when_countries_json_column_is_missing(): void
    {
        Cache::flush();
        $publisher = $this->publisher();
        $this->site($publisher, [
            'country' => 'nl',
            'countries' => ['nl'],
            'domain' => 'nl-scalar-only.test',
        ]);

        if (Schema::hasColumn('sites', 'countries')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('countries');
            });
        }

        CatalogCountryInventory::forget();
        $counts = app(CatalogCountryInventory::class)->counts();
        $this->assertSame(1, $counts['nl'] ?? 0);
        $this->assertFalse(Schema::hasColumn('sites', 'countries'));
    }
}
