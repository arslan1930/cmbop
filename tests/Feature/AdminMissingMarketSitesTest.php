<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMissingMarketSitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
    }

    private function userWithRoles(array $roleNames, ?string $active = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $ids = [];
        foreach ($roleNames as $name) {
            $ids[$name] = Role::where('name', $name)->value('id');
            $user->roles()->attach($ids[$name]);
        }
        $activeName = $active ?? $roleNames[0];
        $user->active_role_id = $ids[$activeName];
        $user->save();

        return $user->fresh(['roles']);
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        $domain = $overrides['domain'] ?? ('missing-market-'.uniqid('', true).'.example');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Missing Market Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 20,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'category' => 'Technology',
            'categories' => ['Technology'],
            'price' => 99,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Missing market hygiene test site.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_active_missing_market_scope_excludes_inactive_and_country_sites(): void
    {
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $missingActive = $this->makeSite($publisher, [
            'domain' => 'active-missing.example',
            'country' => '',
            'countries' => [],
            'active' => true,
        ]);
        $this->makeSite($publisher, [
            'domain' => 'inactive-missing.example',
            'country' => '',
            'countries' => [],
            'active' => false,
        ]);
        $this->makeSite($publisher, [
            'domain' => 'active-de.example',
            'country' => 'de',
            'countries' => ['de'],
            'active' => true,
        ]);

        $ids = Site::query()->activeMissingMarketplaceCountry()->pluck('id')->all();
        $this->assertSame([$missingActive->id], $ids);
    }

    public function test_admin_sites_index_shows_missing_market_badge(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'domain' => 'badge-missing.example',
            'country' => '',
            'countries' => [],
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('missing market country', false)
            ->assertSee('missing_market=1', false)
            ->assertSee('>1 missing<', false);
    }

    public function test_records_missing_market_filter_and_export(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'domain' => 'export-missing.example',
            'site_url' => 'https://export-missing.example',
            'country' => '',
            'countries' => [],
            'active' => true,
        ]);
        $this->makeSite($publisher, [
            'domain' => 'export-de.example',
            'site_url' => 'https://export-de.example',
            'country' => 'de',
            'countries' => ['de'],
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['missing_market' => 1]))
            ->assertOk()
            ->assertSee('Missing market', false)
            ->assertSee('https://export-missing.example', false)
            ->assertDontSee('https://export-de.example', false)
            ->assertSee('Missing market', false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['missing_market' => 1]));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('export-missing.example', $body);
        $this->assertStringNotContainsString('export-de.example', $body);
        $this->assertStringContainsString('url,countries,categories,active', $body);
    }

    public function test_activating_site_without_country_is_blocked(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'domain' => 'activate-missing.example',
            'country' => '',
            'countries' => [],
            'active' => false,
            'verified' => true,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.sites.active', ['id' => $site->id]), [
                'active' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertStringContainsString(
            'marketplace country',
            (string) $response->json('message')
        );

        $this->assertFalse((bool) $site->fresh()->active);
    }
}
