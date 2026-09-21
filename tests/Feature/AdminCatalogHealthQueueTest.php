<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\CatalogHealthQueue;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCatalogHealthQueueTest extends TestCase
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
        $domain = $overrides['domain'] ?? ('health-'.uniqid('', true).'.example');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Health Queue Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'category' => 'Technology',
            'categories' => ['Technology'],
            'price' => 99,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'A real German finance magazine for founders and operators.',
            'verified' => true,
            'active' => true,
            'site_image' => 'sites/cover.webp',
        ], $overrides));
    }

    public function test_health_scopes_exclude_clean_and_inactive_rows(): void
    {
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $clean = $this->makeSite($publisher, ['domain' => 'clean-health.example']);
        $below = $this->makeSite($publisher, [
            'domain' => 'below-health.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
        ]);
        $unverified = $this->makeSite($publisher, [
            'domain' => 'unverified-health.example',
            'verified' => false,
        ]);
        $placeholder = $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/guest',
            'description' => 'Lorem Ipsum is simply dummy text for testing purposes.',
        ]);
        $noCover = $this->makeSite($publisher, [
            'domain' => 'nocover-health.example',
            'site_image' => '',
            'screenshot_path' => '',
            'screenshot_thumb_path' => '',
        ]);
        $this->makeSite($publisher, [
            'domain' => 'inactive-below.example',
            'da' => 5,
            'active' => false,
        ]);

        $this->assertSame(
            [$below->id],
            CatalogHealthQueue::apply(Site::query(), CatalogHealthQueue::BELOW_QUALITY)->pluck('id')->all()
        );
        $this->assertSame(
            [$unverified->id],
            CatalogHealthQueue::apply(Site::query(), CatalogHealthQueue::UNVERIFIED)->pluck('id')->all()
        );
        $this->assertSame(
            [$placeholder->id],
            CatalogHealthQueue::apply(Site::query(), CatalogHealthQueue::PLACEHOLDER)->pluck('id')->all()
        );
        $this->assertSame(
            [$noCover->id],
            CatalogHealthQueue::apply(Site::query(), CatalogHealthQueue::MISSING_COVER)->pluck('id')->all()
        );
        $this->assertNotContains($clean->id, CatalogHealthQueue::apply(Site::query(), CatalogHealthQueue::BELOW_QUALITY)->pluck('id'));
    }

    public function test_records_health_filters_and_index_badges(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'domain' => 'clean-records.example',
            'site_url' => 'https://clean-records.example',
        ]);
        $this->makeSite($publisher, [
            'domain' => 'below-records.example',
            'site_url' => 'https://below-records.example',
            'da' => 12,
            'dr' => 12,
            'traffic' => 200,
        ]);
        $this->makeSite($publisher, [
            'domain' => 'unverified-records.example',
            'site_url' => 'https://unverified-records.example',
            'verified' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('below quality bar', false)
            ->assertSee('unverified active', false)
            ->assertSee('health=below_quality', false);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'below_quality']))
            ->assertOk()
            ->assertSee('Below quality bar', false)
            ->assertSee('https://below-records.example', false)
            ->assertDontSee('https://clean-records.example', false)
            ->assertDontSee('https://unverified-records.example', false);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'unverified']))
            ->assertOk()
            ->assertSee('https://unverified-records.example', false)
            ->assertDontSee('https://clean-records.example', false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['health' => 'below_quality']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('below-records.example', $body);
        $this->assertStringNotContainsString('clean-records.example', $body);
        $this->assertStringContainsString('url,countries,categories,active,health', $body);
    }

    public function test_sites_index_health_queue_lists_live_rows_in_place(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_name' => 'Clean Health Index Site',
            'domain' => 'clean-index.example',
            'site_url' => 'https://clean-index.example',
        ]);
        $below = $this->makeSite($publisher, [
            'site_name' => 'Thin Health Index Site',
            'domain' => 'below-index.example',
            'site_url' => 'https://below-index.example',
            'da' => 12,
            'dr' => 12,
            'traffic' => 200,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Unverified Health Index Site',
            'domain' => 'unverified-index.example',
            'site_url' => 'https://unverified-index.example',
            'verified' => false,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.index', ['health' => 'below_quality']))
            ->assertOk()
            ->assertSee('Catalog health', false)
            ->assertSee('Below quality bar', false)
            ->assertSee('Thin Health Index Site', false)
            ->assertDontSee('Clean Health Index Site', false)
            ->assertDontSee('Unverified Health Index Site', false)
            ->assertSee('DA/DR', false)
            ->assertSee('Open in records sheet', false)
            ->assertSee(route('admin.sites.records', ['health' => 'below_quality'], false), false)
            ->getContent();

        $this->assertStringContainsString((string) $below->da, $html);
        $this->assertStringContainsString('>Deactivate</button>', $html);
        $this->assertStringContainsString('toggle-active', $html);
        $this->assertStringContainsString('data-flat-queue="1"', $html);
        $this->assertStringContainsString('id="usersSection" class="d-none"', $html);

        $this->actingAs($admin)
            ->get(route('admin.sites.index', ['health' => 'unverified']))
            ->assertOk()
            ->assertSee('Unverified Health Index Site', false)
            ->assertDontSee('Thin Health Index Site', false)
            ->assertSee('Unverified active', false);
    }

    public function test_records_partial_health_filter_returns_json(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/x',
            'description' => 'Please replace this placeholder with a real site description before review.',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['health' => 'placeholder', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('health', 'placeholder')
            ->assertSee('demo86.com', false);
    }
}
