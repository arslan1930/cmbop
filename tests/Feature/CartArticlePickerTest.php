<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CartArticlePickerTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);

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

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Picker Site',
            'site_url' => 'https://picker.example',
            'domain' => 'picker.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de', 'en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Bilingual listing for picker tests.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_add_to_cart_includes_full_language_and_country_codes(): void
    {
        $site = $this->site();

        $line = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->json('cart.0');

        $this->assertSame('de', $line['language']);
        $this->assertEqualsCanonicalizing(['de', 'en'], $line['languages']);
        $this->assertSame('de', $line['country']);
        $this->assertEqualsCanonicalizing(['de'], $line['countries']);
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en', $line['languages']));
        $this->assertFalse(ContentSubmission::languageFitsSiteLanguages('nl', $line['languages']));
    }

    public function test_cart_get_refreshes_languages_from_live_listing(): void
    {
        $site = $this->site();

        $line = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 40,
                    'quantity' => 1,
                    'language' => 'de',
                    'country' => 'de',
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json('cart.0');

        $this->assertEqualsCanonicalizing(['de', 'en'], $line['languages']);
        $this->assertEqualsCanonicalizing(['de'], $line['countries']);
    }

    public function test_drawer_js_reads_cart_line_languages_array(): void
    {
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));

        $this->assertStringContainsString('function siteLanguageCodes', $layout);
        $this->assertStringContainsString('item?.languages', $layout);
        $this->assertStringContainsString('function articleFitsSiteLanguages', $layout);
    }
}
