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
        $this->assertSame(['de', 'en'], $line['languages']);
        $this->assertEqualsCanonicalizing(['de'], $line['countries']);
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en-US', $line['languages']));
    }

    public function test_drawer_js_reads_cart_line_languages_array(): void
    {
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));

        $this->assertStringContainsString('function siteLanguageCodes', $layout);
        $this->assertStringContainsString('item?.languages', $layout);
        $this->assertStringContainsString('function articleFitsSiteLanguages', $layout);
        $this->assertStringContainsString('function languagePrimaryTag', $layout);
        $this->assertStringContainsString('function articlePickerLabel', $layout);
        $this->assertStringContainsString('function articleTitleLooksLikeId', $layout);
        $this->assertStringContainsString('raw.replace(/\\s+\\(\\d+\\)\\s*$/', $layout);
        $this->assertStringContainsString('flips >= 4 || raw.length >= 20', $layout);
        $this->assertStringContainsString('Assigned article', $layout);
        $this->assertStringContainsString("label: 'Matches this site'", $layout);
        $this->assertStringContainsString('<optgroup label="', $layout);
        $this->assertStringContainsString('None of your articles match — you can still assign one.', $layout);
        $this->assertStringContainsString('!selectedId && matching.length === 0 && other.length > 0', $layout);
        $this->assertStringContainsString('selectedId && item.language_note', $layout);
        $this->assertStringNotContainsString('Assigned document #', $layout);
        $this->assertStringNotContainsString("' · different language'", $layout);
        $this->assertStringNotContainsString('/^[A-Za-z0-9_-]{12,}$/', $layout);
    }

    public function test_cart_get_includes_assigned_article_past_the_hundred_cap(): void
    {
        $site = $this->site();
        $assigned = $this->createApprovedSubmission($this->advertiser, null, 0, 'anchor', 'https://example.com/a', 'de', 'de');
        $assigned->update(['title' => 'Assigned older article']);

        for ($i = 0; $i < 100; $i++) {
            $clone = $assigned->replicate();
            $clone->title = 'Newer article '.$i;
            $clone->original_filename = 'newer-'.$i.'.docx';
            $clone->save();
        }

        $payload = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 40,
                    'quantity' => 1,
                    'language' => 'de',
                    'country' => 'de',
                    'content_submission_id' => $assigned->id,
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $articleIds = collect($payload['approved_articles'] ?? [])->pluck('id')->all();
        $this->assertContains($assigned->id, $articleIds);
        $this->assertSame($assigned->id, (int) ($payload['cart'][0]['content_submission_id'] ?? 0));
        $this->assertContains('Assigned older article', collect($payload['approved_articles'])->pluck('title')->all());
    }

    public function test_second_cart_get_does_not_rewrite_a_stable_session(): void
    {
        $site = $this->site();

        $this->actingAs($this->advertiser)
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
            ->assertOk();

        $afterFirst = session('cart');

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.cart.get'))
            ->assertOk();

        $this->assertSame($afterFirst, session('cart'));
    }
}
