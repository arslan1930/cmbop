<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogFilterStatus;
use App\Services\Catalog\CatalogUrlQuery;
use App\Support\PublicationDuration;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPublicationDurationFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSite(string $name, array $overrides = []): Site
    {
        $host = strtolower(str_replace(' ', '-', $name)).'.example';

        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$host,
            'domain' => $host,
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => PublicationDuration::PERMANENT,
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Publication duration catalog fixture.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_publication_time_is_allowlisted_and_filter_ui_lists_multi_year_terms(): void
    {
        $this->assertContains('publication_time', CatalogUrlQuery::KEYS);
        $this->assertContains('publication_time', CatalogFilterStatus::QUERY_KEYS);

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="publication_time"', $html);
        $this->assertStringContainsString('value="2years"', $html);
        $this->assertStringContainsString('value="3years"', $html);
        $this->assertStringContainsString('2 Years', $html);
        $this->assertStringContainsString('3 Years', $html);
        $this->assertStringContainsString('publicationDurationLabels', $html);
    }

    public function test_catalog_filter_keeps_matching_duration_only(): void
    {
        $this->makeSite('Two Year Listing', [
            'publication_time' => PublicationDuration::TWO_YEARS,
        ]);
        $this->makeSite('Three Year Listing', [
            'publication_time' => PublicationDuration::THREE_YEARS,
        ]);
        $this->makeSite('Permanent Listing', [
            'publication_time' => PublicationDuration::PERMANENT,
        ]);

        $filtered = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['publication_time' => '2years']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Two Year Listing', $filtered);
        $this->assertStringNotContainsString('Three Year Listing', $filtered);
        $this->assertStringNotContainsString('Permanent Listing', $filtered);
        $this->assertStringContainsString('Duration: 2 Years', $filtered);
        $this->assertStringContainsString('2 years', $filtered);

        $alias = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['publication_time' => '24months']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Two Year Listing', $alias);
        $this->assertStringNotContainsString('Permanent Listing', $alias);
    }
}
