<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogExpandCorrectnessTest extends TestCase
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

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Expand Correct Blog',
            'site_url' => 'https://expand-correct.example',
            'domain' => 'expand-correct.example',
            'example_url' => 'https://expand-correct.example/sample',
            'da' => 32,
            'dr' => 12,
            'traffic' => 1200000,
            'country' => 'gb',
            'language' => 'en',
            'countries' => ['gb'],
            'languages' => ['en'],
            'category' => 'news',
            'price' => 90,
            'turnaround_time' => '3days',
            'publication_time' => '1year',
            'link_type' => 'nofollow',
            'description' => 'Expand correctness listing.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_expand_does_not_hardcode_dofollow_max_and_humanizes_labels(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Max 03 DoFollow links', $html);
        $this->assertStringNotContainsString('Max 03 nofollow links', $html);
        $this->assertStringNotContainsString('Max 03', $html);
        $this->assertStringContainsString('NoFollow', $html);
        $this->assertStringContainsString('1 year', $html);
        $this->assertStringContainsString('3 days', $html);
        $this->assertStringContainsString('Publication duration', $html);
    }

    public function test_expand_layout_separates_pricing_and_empty_states(): void
    {
        $this->makeSite([
            'example_url' => null,
            'sensitive_prices' => ['crypto' => 23],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-expand-grid', $html);
        $this->assertStringContainsString('catalog-expand-pricing', $html);
        $this->assertStringContainsString('Sensitive topics', $html);
        $this->assertStringContainsString('+€23.00', $html);
        $this->assertMatchesRegularExpression('/→\s*€\d+\.\d{2}/u', $html);
        $this->assertStringContainsString('Screenshot not available yet', $html);
        $this->assertStringContainsString('No sample article yet', $html);
        $this->assertStringNotContainsString('Not available</a>', $html);
    }
}
