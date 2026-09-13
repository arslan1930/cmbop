<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCoverEmptyTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $this->advertiser->roles()->attach($advRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        $domain = $overrides['domain'] ?? ('cover-empty-'.uniqid('', true).'.test');

        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Cover Empty Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'example_url' => 'https://'.$domain.'/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Cover empty-state catalog fixture for Site Details.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_details_without_cover_shows_monogram_and_no_cover_yet(): void
    {
        $this->makeSite([
            'site_name' => 'Bare Cover Site',
            'domain' => 'bare-cover.test',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-cover-empty', $html);
        $this->assertStringContainsString('No cover yet', $html);
        $this->assertStringContainsString('catalog-tile', $html);
        $this->assertStringNotContainsString('Screenshot not available yet', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/catalog-cover-empty[^>]*>\s*<img/s',
            $html
        );
    }

    public function test_details_with_cover_keeps_img_chain(): void
    {
        $this->makeSite([
            'site_name' => 'Has Cover Site',
            'domain' => 'has-cover.test',
            'site_image' => 'sites/covers/has-cover.webp',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Has Cover']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-deferred-preview', $html);
        $this->assertStringContainsString('/media/sites/covers/has-cover.webp', $html);
        $this->assertStringContainsString('data-preview-chain', $html);
        $this->assertStringNotContainsString('catalog-cover-empty', $html);
    }
}
