<?php

namespace Tests\Feature;

use App\Mail\AdminAssignedSiteNotification;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Support\SiteDescriptionRules;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class MarketingAssignSiteForPublisherTest extends TestCase
{
    use CreatesBlogUploads;
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->where('name', 'News')->value('name')
            ?? Category::query()->orderBy('name')->value('name');

        return array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Marketing Added News',
            'site_url' => 'https://marketing-added-news.example',
            'example_url' => 'https://marketing-added-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower((string) $country->code),
            'language' => strtolower((string) $language->code),
            'categories' => $niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
            'written_request' => 1,
        ], $overrides);
    }

    public function test_marketing_create_page_uses_verify_first_copy_and_quality_bar(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertSee('Add site for publisher', false)
            ->assertSee(__('messages.staff_handbook_title'), false)
            ->assertSee('Activate makes the listing live and verifies it', false)
            ->assertSee('The Verify button stays admin-only', false)
            ->assertDontSee('admin verifies first', false)
            ->assertDontSee('You Activate only after that', false)
            ->assertSee('Marketing Activate needs DA ≥ '.Site::GOOD_MIN_DA, false)
            ->assertSee('id="qualityBarWarn"', false)
            ->assertDontSee('Activate / Deactivate as usual', false)
            ->assertSee('id="selectedLanguage"', false)
            ->assertSee('id="publisherFilter"', false)
            ->assertSee('written_request', false)
            ->assertSee('I have a written request', false)
            ->assertSee('This emails and bells the publisher', false)
            ->assertSee('Click to toggle; type to search; Enter adds the highlighted match. Max 7.', false)
            ->assertDontSee('Click niches one by one', false)
            ->assertSee('data-site-description-editor', false)
            ->assertSee('name="description"', false)
            ->assertSee('data-max-chars="'.SiteDescriptionRules::MAX_CHARS.'"', false)
            ->assertSee('max 500 words', false)
            ->assertSee('Shown to advertisers on the listing', false)
            ->assertSee('name="price_homepage[7]"', false)
            ->assertSee('name="social[facebook]"', false)
            ->assertSee('name="sensitive[crypto]"', false)
            ->assertSee('name="price_sensitive[crypto]"', false)
            ->assertSee('optional homepage, social, and sensitive-topic prices', false)
            ->assertSee('Must be on the same domain as the site URL.', false)
            ->getContent();

        $this->assertStringContainsString('data-min-da="'.Site::GOOD_MIN_DA.'"', $html);
        $this->assertStringContainsString('data-min-traffic="'.Site::GOOD_MIN_TRAFFIC.'"', $html);
        $this->assertStringContainsString('href="'.e(route('marketing.sites.index')).'"', $html);
        $this->assertMatchesRegularExpression(
            '/<select[^>]+id="language"[^>]+name="language"|<select[^>]+name="language"[^>]+id="language"/',
            $html
        );
    }

    public function test_marketing_create_page_prefills_publisher_query(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="'.$this->publisher->id.'"[^>]+selected/',
            $html
        );
        $this->assertStringContainsString('publisher='.$this->publisher->id, $html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*d-none[^"]*" id="unverifiedPublisherWarn"/',
            $html
        );
    }

    public function test_marketing_can_create_site_for_publisher_pending_acceptance(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload());

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $site = Site::where('domain', 'marketing-added-news.example')->first();
        $this->assertNotNull($site);

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/marketing/sites', $location);
        $this->assertStringContainsString('publisher='.$this->publisher->id, $location);
        $this->assertStringContainsString('site='.$site->id, $location);
        $this->assertSame((int) $this->publisher->id, (int) $site->publisher_id);
        $this->assertSame((int) $this->marketer->id, (int) $site->assigned_by_user_id);
        $this->assertNull($site->publisher_accepted_at);
        $this->assertFalse((bool) $site->active);
        $this->assertFalse((bool) $site->verified);
        $this->assertSame(40, (int) $site->da);
        $this->assertSame(45, (int) $site->dr);
        $this->assertSame(12000, (int) $site->traffic);
        $this->assertEqualsWithDelta(99.0, (float) $site->price, 0.001);
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertNull($site->sensitive_prices);
        $this->assertStringContainsString('Invites', (string) session('success'));
        $this->assertStringNotContainsString('below the marketing Activate bar', (string) session('success'));

        Mail::assertQueued(AdminAssignedSiteNotification::class, function ($mail) {
            return $mail->hasTo($this->publisher->email);
        });

        $bell = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Please accept a website we added for you')
            ->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('status=invites', (string) $bell->action_url);
    }

    public function test_marketing_store_resolves_technology_alias_to_canonical_niche(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://tech-alias.example',
                'example_url' => 'https://tech-alias.example/sample',
                'categories' => 'Technology',
            ]))
            ->assertRedirect();

        $site = Site::where('domain', 'tech-alias.example')->first();
        $this->assertNotNull($site);
        $this->assertContains('Technology & Gadgets', $site->categories ?? []);
        $this->assertStringContainsString('Technology & Gadgets', (string) $site->category);
    }

    public function test_marketing_store_rejects_unknown_niche(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://unknown-niche.example',
                'example_url' => 'https://unknown-niche.example/sample',
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('categories');

        $this->assertNull(Site::where('domain', 'unknown-niche.example')->first());
    }

    public function test_marketing_store_below_quality_bar_still_saves_with_flash(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://below-bar.example',
                'example_url' => 'https://below-bar.example/sample',
                'da' => 5,
                'dr' => 8,
                'traffic' => 100,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $site = Site::where('domain', 'below-bar.example')->first();
        $this->assertNotNull($site);
        $this->assertFalse($site->hasGoodMetrics());
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertStringContainsString('below the marketing Activate bar', (string) session('success'));
    }

    public function test_marketing_store_blocks_when_marketplace_languages_are_empty(): void
    {
        $payload = $this->validPayload([
            'site_url' => 'https://empty-geo.example',
            'example_url' => 'https://empty-geo.example/sample',
        ]);
        config(['markets.allowed_language_codes' => []]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $payload)
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('country');

        $this->assertNull(Site::where('domain', 'empty-geo.example')->first());
        $this->assertStringContainsString(
            'not configured',
            (string) session('errors')->first('country')
        );
    }

    public function test_marketing_create_hides_unverified_publishers_unless_prefilled(): void
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $unverified = User::factory()->create([
            'name' => 'Unverified Pub',
            'email' => 'unverified-pub@example.com',
            'email_verified_at' => null,
            'active_role_id' => $publisherRole->id,
        ]);
        $unverified->roles()->attach($publisherRole->id);

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertDontSee('unverified-pub@example.com', false);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create', ['publisher' => $unverified->id]))
            ->assertOk()
            ->assertSee('unverified-pub@example.com', false)
            ->assertSee('cannot log in to Accept', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="'.$unverified->id.'"[^>]+selected/',
            $html
        );
        $this->assertStringContainsString('publisher='.$unverified->id, $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*d-none[^"]*" id="unverifiedPublisherWarn"/',
            $html
        );
    }

    public function test_validation_error_keeps_posted_publisher_on_back_and_cancel(): void
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $other = User::factory()->create([
            'name' => 'Other Verified Pub',
            'email' => 'other-verified-pub@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $other->roles()->attach($publisherRole->id);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'publisher_id' => $other->id,
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->assertSessionHasErrors('categories');

        $create = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="'.$other->id.'"[^>]+selected/',
            $create
        );
        $this->assertStringContainsString('publisher='.$other->id, $create);
    }

    public function test_marketing_store_requires_written_request(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'written_request' => 0,
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('written_request');

        $this->assertNull(Site::where('domain', 'marketing-added-news.example')->first());
    }

    public function test_marketing_store_rejects_live_duplicate_domain(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Live Duplicate',
            'site_url' => 'https://live-dup.example',
            'domain' => 'live-dup.example',
            'example_url' => 'https://live-dup.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Existing live listing description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://www.live-dup.example',
                'example_url' => 'https://www.live-dup.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertSame(1, Site::where('domain', 'live-dup.example')->count());
    }

    public function test_marketing_store_rejects_archived_domain_with_restore_message(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Archived Duplicate',
            'site_url' => 'https://archived-dup.example',
            'domain' => 'archived-dup.example',
            'example_url' => 'https://archived-dup.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Archived listing description text here. ', 3),
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://archived-dup.example',
                'example_url' => 'https://archived-dup.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This domain is already registered (including archived). Ask an admin to restore or hard-delete.',
            (string) session('errors')->first('site_url')
        );
        $this->assertSame(1, Site::where('domain', 'archived-dup.example')->count());
    }

    public function test_marketing_store_rejects_description_over_character_max(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://long-desc.example',
                'example_url' => 'https://long-desc.example/sample',
                'description' => str_repeat('a', 5001),
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('description');

        $messages = session('errors')->get('description');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('at most 5000 characters', $messages[0]);
        $this->assertNull(Site::where('domain', 'long-desc.example')->first());
    }

    public function test_marketing_store_accepts_quill_wrapped_max_length_description(): void
    {
        $plain = str_repeat('a', SiteDescriptionRules::MAX_CHARS);

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://quill-max-desc.example',
                'example_url' => 'https://quill-max-desc.example/sample',
                'description' => '<p>'.$plain.'</p>',
            ]))
            ->assertRedirect();

        $site = Site::where('domain', 'quill-max-desc.example')->first();
        $this->assertNotNull($site);
        $this->assertSame($plain, SiteDescriptionRules::plainText((string) $site->description));
    }

    public function test_marketing_store_rejects_short_description_once(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://short-desc.example',
                'example_url' => 'https://short-desc.example/sample',
                'description' => str_repeat('x', 40),
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('description');

        $messages = session('errors')->get('description');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('at least 50 characters', $messages[0]);
        $this->assertNull(Site::where('domain', 'short-desc.example')->first());
    }

    public function test_marketing_store_rejects_description_over_word_max(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://wordy-desc.example',
                'example_url' => 'https://wordy-desc.example/sample',
                'description' => implode(' ', array_fill(0, 501, 'word')),
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('description');

        $this->assertStringContainsString(
            'at most 500 words',
            (string) session('errors')->first('description')
        );
        $this->assertNull(Site::where('domain', 'wordy-desc.example')->first());
    }

    public function test_marketing_store_persists_homepage_social_and_sensitive_prices(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://extras-listing.example',
                'example_url' => 'https://extras-listing.example/sample',
                'homepage' => ['7' => '1'],
                'price_homepage' => ['7' => '25'],
                'social' => ['facebook' => '1', 'x' => '1'],
                'sensitive' => ['crypto' => '1'],
                'price_sensitive' => ['crypto' => '15'],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $site = Site::where('domain', 'extras-listing.example')->first();
        $this->assertNotNull($site);
        $this->assertSame([7 => 25.0], $site->homepagePlacementOptions());
        $this->assertSame(['facebook', 'x'], $site->enabledSocialChannels());
        $this->assertEqualsWithDelta(15.0, (float) ($site->sensitive_prices['crypto'] ?? 0), 0.001);
        $this->assertTrue($site->isPendingPublisherAcceptance());
    }

    public function test_marketing_store_treats_checked_sensitive_blank_price_as_zero(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://crypto-free.example',
                'example_url' => 'https://crypto-free.example/sample',
                'sensitive' => ['crypto' => '1'],
                'price_sensitive' => ['crypto' => ''],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $site = Site::where('domain', 'crypto-free.example')->first();
        $this->assertNotNull($site);
        $this->assertEqualsWithDelta(0.0, (float) ($site->sensitive_prices['crypto'] ?? -1), 0.001);
    }

    public function test_validation_error_keeps_homepage_and_sensitive_old_input(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'homepage' => ['7' => '1'],
                'price_homepage' => ['7' => '25'],
                'social' => ['facebook' => '1'],
                'sensitive' => ['crypto' => '1'],
                'price_sensitive' => ['crypto' => '15'],
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('categories');

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="staffHomepage7"[^>]*checked|checked[^>]*id="staffHomepage7"/',
            $html
        );
        $this->assertStringContainsString('value="25"', $html);
        $this->assertMatchesRegularExpression(
            '/id="staffSocialFacebook"[^>]*checked|checked[^>]*id="staffSocialFacebook"/',
            $html
        );
        $this->assertStringContainsString('id="sensitiveDisclosurePanel"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/id="sensitiveDisclosurePanel"[^>]*hidden/',
            $html
        );
        $this->assertStringContainsString('value="15"', $html);
    }

    public function test_array_shaped_homepage_price_old_input_does_not_crash_create_page(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'price_homepage' => ['7' => ['25']],
                'price_sensitive' => ['crypto' => ['15']],
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors();

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertDontSee('htmlspecialchars', false)
            ->assertSee('Add site for publisher', false);
    }

    public function test_marketing_store_rejects_negative_homepage_fee_with_readable_name(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://neg-home.example',
                'example_url' => 'https://neg-home.example/sample',
                'homepage' => ['7' => '1'],
                'price_homepage' => ['7' => '-5'],
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('price_homepage.7');

        $this->assertStringContainsString(
            '7-day homepage fee',
            (string) session('errors')->first('price_homepage.7')
        );
        $this->assertNull(Site::where('domain', 'neg-home.example')->first());
    }

    public function test_array_shaped_language_old_input_does_not_crash_create_page(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'language' => ['de'],
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors();

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertDontSee('htmlspecialchars', false)
            ->assertSee('id="selectedLanguage"', false)
            ->assertSee('Add site for publisher', false);
    }

    public function test_array_shaped_publisher_id_keeps_first_scalar_after_validation_error(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'publisher_id' => [$this->publisher->id],
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors();

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="'.$this->publisher->id.'"[^>]+selected/',
            $html
        );
        $this->assertStringContainsString('publisher='.$this->publisher->id, $html);
    }

    public function test_zero_sensitive_extra_keeps_disclosure_open_after_validation_error(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'price_sensitive' => ['crypto' => '0'],
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('categories');

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/id="sensitiveDisclosurePanel"[^>]*hidden/',
            $html
        );
        $this->assertStringContainsString('value="0"', $html);
    }

    public function test_marketing_update_rejects_archived_domain_with_restore_message(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Archived Collision',
            'site_url' => 'https://archived-collision.example',
            'domain' => 'archived-collision.example',
            'example_url' => 'https://archived-collision.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Archived collision description text. ', 3),
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Collision',
            'site_url' => 'https://pending-collision.example',
            'domain' => 'pending-collision.example',
            'example_url' => 'https://pending-collision.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending collision description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Collision',
                'site_url' => 'https://archived-collision.example',
                'example_url' => 'https://pending-collision.example/sample',
                'price' => 50,
                'da' => 40,
                'dr' => 40,
                'traffic' => 12000,
                'country' => 'de',
                'language' => 'de',
                'categories' => 'News',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This domain is already registered (including archived). Ask an admin to restore or hard-delete.',
            (string) session('errors')->first('site_url')
        );
        $this->assertSame('pending-collision.example', $pending->fresh()->domain);
    }

    public function test_array_shaped_site_url_does_not_save_array_domain(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => ['https://poison-url.example'],
                'example_url' => ['https://poison-url.example/sample'],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(Site::where('domain', 'array')->first());
        $site = Site::where('domain', 'poison-url.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('https://poison-url.example', $site->site_url);
    }

    public function test_non_string_categories_old_input_does_not_crash_create_page(): void
    {
        $this->actingAs($this->marketer)
            ->withSession([
                '_old_input' => [
                    'categories' => 1,
                    'country' => ['de'],
                    'language' => ['de'],
                    'site_url' => ['https://poison-niches.example'],
                ],
            ])
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertSee('Add site for publisher', false)
            ->assertDontSee('htmlspecialchars', false);

        $html = $this->actingAs($this->marketer)
            ->withSession([
                '_old_input' => [
                    'categories' => [['News']],
                    'country' => ['de'],
                    'language' => ['de'],
                ],
            ])
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="de"[^>]+selected/',
            $html
        );
    }

    public function test_integer_categories_payload_does_not_500_store(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://int-niches.example',
                'example_url' => 'https://int-niches.example/sample',
                'categories' => 1,
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('categories');

        $this->assertNull(Site::where('domain', 'int-niches.example')->first());
    }

    public function test_marketing_update_rejects_array_shaped_site_url(): void
    {
        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Url Guard',
            'site_url' => 'https://pending-url-guard.example',
            'domain' => 'pending-url-guard.example',
            'example_url' => 'https://pending-url-guard.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending url guard description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Url Guard',
                'site_url' => ['https://poison-edit.example'],
                'example_url' => ['https://poison-edit.example/sample'],
                'price' => 50,
                'da' => 40,
                'dr' => 40,
                'traffic' => 12000,
                'country' => 'de',
                'language' => 'de',
                'categories' => 'News',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('site_url');

        $pending->refresh();
        $this->assertSame('pending-url-guard.example', $pending->domain);
        $this->assertNull(Site::where('domain', 'array')->first());
    }

    public function test_marketing_update_integer_categories_does_not_500(): void
    {
        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Niche Guard',
            'site_url' => 'https://pending-niche-guard.example',
            'domain' => 'pending-niche-guard.example',
            'example_url' => 'https://pending-niche-guard.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending niche guard description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Niche Guard',
                'site_url' => 'https://pending-niche-guard.example',
                'example_url' => 'https://pending-niche-guard.example/sample',
                'price' => 50,
                'da' => 40,
                'dr' => 40,
                'traffic' => 12000,
                'country' => 'de',
                'language' => 'de',
                'categories' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('categories');

        $this->assertSame(['News'], $pending->fresh()->categories);
    }

    public function test_marketing_update_rejects_array_language_without_500(): void
    {
        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Language Guard',
            'site_url' => 'https://pending-language-guard.example',
            'domain' => 'pending-language-guard.example',
            'example_url' => 'https://pending-language-guard.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending language guard description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Language Guard',
                'site_url' => 'https://pending-language-guard.example',
                'example_url' => 'https://pending-language-guard.example/sample',
                'price' => 50,
                'da' => 40,
                'dr' => 40,
                'traffic' => 12000,
                'country' => [['de']],
                'language' => [''],
                'categories' => 'News',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('language');

        $this->assertSame('de', $pending->fresh()->language);
    }

    public function test_marketing_update_rejects_array_site_url_without_500(): void
    {
        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Url Guard',
            'site_url' => 'https://pending-url-guard.example',
            'domain' => 'pending-url-guard.example',
            'example_url' => 'https://pending-url-guard.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending url guard description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Url Guard',
                'site_url' => ['https://pending-url-guard.example'],
                'example_url' => ['https://pending-url-guard.example/sample'],
                'price' => 50,
                'da' => 40,
                'dr' => 40,
                'traffic' => 12000,
                'country' => 'de',
                'language' => 'de',
                'categories' => 'News',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('site_url');

        $this->assertSame('https://pending-url-guard.example', $pending->fresh()->site_url);
    }

    public function test_duplicate_domain_prefers_live_listing_message_over_archived(): void
    {
        $otherPublisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $this->publisher->active_role_id,
        ]);
        $otherPublisher->roles()->attach($this->publisher->active_role_id);

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Archived Twin',
            'site_url' => 'https://twin-domain.example',
            'domain' => 'twin-domain.example',
            'example_url' => 'https://twin-domain.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Archived twin listing description text. ', 3),
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        Site::create([
            'publisher_id' => $otherPublisher->id,
            'site_name' => 'Live Twin',
            'site_url' => 'https://twin-domain.example',
            'domain' => 'twin-domain.example',
            'example_url' => 'https://twin-domain.example/live',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Live twin listing description text here. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://twin-domain.example',
                'example_url' => 'https://twin-domain.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertStringNotContainsString(
            'Ask an admin to restore',
            (string) session('errors')->first('site_url')
        );
    }

    public function test_image_store_failure_returns_image_error_not_generic_site_url(): void
    {
        $disk = \Mockery::mock();
        $disk->shouldReceive('makeDirectory')->andReturn(true);
        $disk->shouldReceive('put')->andReturn(true);
        $disk->shouldReceive('putFile')->andReturn('sites/fail.jpg');
        $disk->shouldReceive('putFileAs')->andReturn('sites/fail.jpg');
        $disk->shouldReceive('exists')->andReturn(false);
        Storage::shouldReceive('disk')
            ->with('public')
            ->andReturn($disk);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://img-fail.example',
                'example_url' => 'https://img-fail.example/sample',
                'site_image' => $this->fakeBlogUpload('cover.jpg', 20, 20),
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_image')
            ->assertSessionDoesntHaveErrors('site_url');

        $this->assertNull(Site::where('domain', 'img-fail.example')->first());
        $this->assertSame(
            'Could not save the site image to storage. Check disk permissions and MEDIA_PATH.',
            (string) session('errors')->first('site_image')
        );
    }

    public function test_edit_page_survives_poisoned_niche_and_language_old_input(): void
    {
        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Edit Poison Guard',
            'site_url' => 'https://edit-poison-guard.example',
            'domain' => 'edit-poison-guard.example',
            'example_url' => 'https://edit-poison-guard.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Edit poison guard description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->withSession([
                '_old_input' => [
                    'categories' => 1,
                    'country' => ['de'],
                    'language' => ['de'],
                ],
            ])
            ->get(route('marketing.sites.edit', $pending->id))
            ->assertOk()
            ->assertSee('Fill metrics, geo', false)
            ->assertDontSee('htmlspecialchars', false)
            ->assertSee('id="language"', false)
            ->assertDontSee('type="url"', false)
            ->assertSee('type="text" id="site_url"', false);
    }

    public function test_store_does_not_claim_notify_when_mail_and_bell_fail(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->mock(InAppNotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyPublisherSiteAssignedForAcceptance')
                ->once()
                ->andThrow(new \RuntimeException('bell down'));
        });

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://no-notify.example',
                'example_url' => 'https://no-notify.example/sample',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull(Site::where('domain', 'no-notify.example')->first());
        $this->assertStringContainsString('could not notify', (string) session('success'));
        $this->assertStringNotContainsString('Publisher was notified', (string) session('success'));
        $this->assertStringContainsString('Invites', (string) session('success'));
    }

    public function test_array_shaped_turnaround_old_input_keeps_create_page_usable(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'turnaround_time' => ['3days'],
                'publication_time' => ['permanent'],
                'link_type' => ['dofollow'],
                'site_tag' => ['sponsored'],
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors();

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Add site for publisher', $html);
        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="3days"[^>]+selected/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="tag_sponsored"[^>]*checked|checked[^>]*id="tag_sponsored"/',
            $html
        );
    }

    public function test_marketing_store_rejects_whitespace_only_site_name(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_name' => '   ',
                'site_url' => 'https://blank-name.example',
                'example_url' => 'https://blank-name.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_name');

        $this->assertNull(Site::where('domain', 'blank-name.example')->first());
    }

    public function test_marketing_store_rejects_price_over_column_max(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://huge-price.example',
                'example_url' => 'https://huge-price.example/sample',
                'price' => 100000000,
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('price')
            ->assertSessionDoesntHaveErrors('site_url');

        $this->assertNull(Site::where('domain', 'huge-price.example')->first());
        $this->assertStringContainsString('999,999.99', (string) session('errors')->first('price'));
    }

    public function test_trailing_dot_domain_matches_existing_listing(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Live Dot',
            'site_url' => 'https://dot-twin.example',
            'domain' => 'dot-twin.example',
            'example_url' => 'https://dot-twin.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Live trailing-dot twin description text. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://www.dot-twin.example./path',
                'example_url' => 'https://dot-twin.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertNull(Site::where('domain', 'dot-twin.example.')->first());
        $this->assertNull(Site::where('domain', 'www.dot-twin.example.')->first());
    }

    public function test_port_domain_matches_existing_listing(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Live Port',
            'site_url' => 'https://port-twin.example',
            'domain' => 'port-twin.example',
            'example_url' => 'https://port-twin.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Live port twin description text here. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://www.port-twin.example:443/path',
                'example_url' => 'https://port-twin.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertNull(Site::where('domain', 'port-twin.example:443')->first());
        $this->assertSame(1, Site::where('domain', 'port-twin.example')->count());
    }

    public function test_legacy_www_domain_matches_existing_listing(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Legacy Www',
            'site_url' => 'https://www.legacy-www-store.example',
            'domain' => 'www.legacy-www-store.example',
            'example_url' => 'https://www.legacy-www-store.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Legacy www store description text here. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://legacy-www-store.example',
                'example_url' => 'https://legacy-www-store.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertNull(Site::where('domain', 'legacy-www-store.example')->first());
    }

    public function test_store_strips_url_userinfo_and_default_port(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://user:secret@cred-store.example:443/path',
                'example_url' => 'https://user:secret@cred-store.example/sample',
            ]))
            ->assertRedirect();

        $site = Site::where('domain', 'cred-store.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('https://cred-store.example/path', $site->site_url);
        $this->assertSame('https://cred-store.example/sample', $site->example_url);
        $this->assertStringNotContainsString('secret', (string) $site->site_url);
        $this->assertStringNotContainsString('user', (string) $site->site_url);
    }

    public function test_store_rejects_ftp_and_javascript_urls(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'ftp://ftp-store.example/path',
                'example_url' => 'https://ftp-store.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', 'ftp-store.example')->first());

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'javascript:alert(1)',
                'example_url' => 'https://js-store.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', 'javascript')->first());
        $this->assertNull(Site::where('domain', 'js-store.example')->first());
    }

    public function test_store_accepts_protocol_relative_urls(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => '//proto-rel.example/path',
                'example_url' => '//proto-rel.example/sample',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'proto-rel.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('https://proto-rel.example/path', $site->site_url);
        $this->assertSame('https://proto-rel.example/sample', $site->example_url);
        $this->assertSame(12000, (int) $site->traffic);
    }

    public function test_store_accepts_host_port_without_scheme(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'port-paste.example:8080/x',
                'example_url' => 'port-paste.example:8080/sample',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'port-paste.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('https://port-paste.example:8080/x', $site->site_url);
        $this->assertSame('https://port-paste.example:8080/sample', $site->example_url);
        $this->assertSame('port-paste.example', $site->domain);
        $this->assertSame(12000, (int) $site->traffic);
    }

    public function test_store_rejects_urls_with_raw_whitespace(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://space-path.example/foo bar',
                'example_url' => 'https://space-path.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', 'space-path.example')->first());
    }

    public function test_legacy_port_domain_matches_existing_listing(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Legacy Port',
            'site_url' => 'https://legacy-port.example',
            'domain' => 'legacy-port.example:443',
            'example_url' => 'https://legacy-port.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Legacy port listing description text. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://legacy-port.example',
                'example_url' => 'https://legacy-port.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertNull(Site::where('domain', 'legacy-port.example')->first());
    }

    public function test_legacy_non_default_port_domain_matches_existing_listing(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Legacy Custom Port',
            'site_url' => 'https://legacy-8080.example:8080',
            'domain' => 'legacy-8080.example:8080',
            'example_url' => 'https://legacy-8080.example:8080/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Legacy custom port listing description text. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://legacy-8080.example',
                'example_url' => 'https://legacy-8080.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertNull(Site::where('domain', 'legacy-8080.example')->first());
    }

    public function test_store_rejects_nbsp_only_site_name(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_name' => "\u{00A0}\u{00A0}",
                'site_url' => 'https://nbsp-name.example',
                'example_url' => 'https://nbsp-name.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_name');

        $this->assertNull(Site::where('domain', 'nbsp-name.example')->first());
    }

    public function test_store_rejects_localhost_and_bare_tld(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://localhost/path',
                'example_url' => 'https://localhost/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', 'localhost')->first());

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://com',
                'example_url' => 'https://com/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', 'com')->first());

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://127.1/path',
                'example_url' => 'https://127.1/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', '127.1')->first());
    }

    public function test_store_rejects_invalid_url_ports(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://zero-port.example:0/path',
                'example_url' => 'https://zero-port.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', 'zero-port.example')->first());

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://huge-port.example:65536/path',
                'example_url' => 'https://huge-port.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertNull(Site::where('domain', 'huge-port.example')->first());
    }

    public function test_idn_unicode_host_matches_existing_punycode_listing(): void
    {
        $this->assertTrue(function_exists('idn_to_ascii'));
        $ascii = idn_to_ascii('münchen-idn.example', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        $this->assertIsString($ascii);

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Live Idn',
            'site_url' => 'https://'.$ascii,
            'domain' => $ascii,
            'example_url' => 'https://'.$ascii.'/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Live IDN twin description text here. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://www.münchen-idn.example/path',
                'example_url' => 'https://münchen-idn.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertSame(1, Site::where('domain', $ascii)->count());
        $this->assertNull(Site::where('domain', 'münchen-idn.example')->first());
    }

    public function test_store_rejects_example_url_on_other_domain(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://same-host.example',
                'example_url' => 'https://other-host.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('example_url');

        $this->assertSame(
            'Example URL must be on the same website domain.',
            (string) session('errors')->first('example_url')
        );
        $this->assertNull(Site::where('domain', 'same-host.example')->first());
    }

    public function test_store_accepts_www_example_url_for_apex_site(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://apex-host.example',
                'example_url' => 'https://www.apex-host.example/sample',
            ]))
            ->assertRedirect();

        $site = Site::where('domain', 'apex-host.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('https://www.apex-host.example/sample', $site->example_url);
    }

    public function test_store_rejects_zero_width_only_site_name(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_name' => "\u{200B}\u{200B}",
                'site_url' => 'https://zwsp-name.example',
                'example_url' => 'https://zwsp-name.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_name');

        $this->assertNull(Site::where('domain', 'zwsp-name.example')->first());
    }

    public function test_marketing_update_coerces_decimal_and_grouped_metrics(): void
    {
        $niche = Category::query()->where('name', 'News')->value('name')
            ?? Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Metrics Coerce',
            'site_url' => 'https://pending-metrics-coerce.example',
            'domain' => 'pending-metrics-coerce.example',
            'example_url' => 'https://pending-metrics-coerce.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => $niche,
            'categories' => [$niche],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending metrics coerce description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Metrics Coerce',
                'site_url' => 'https://pending-metrics-coerce.example',
                'example_url' => 'https://pending-metrics-coerce.example/sample',
                'price' => 50,
                'da' => '40.0',
                'dr' => '41.0',
                'traffic' => '15,000',
                'country' => 'de',
                'language' => 'de',
                'categories' => $niche,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pending->refresh();
        $this->assertSame(40, (int) $pending->da);
        $this->assertSame(41, (int) $pending->dr);
        $this->assertSame(15000, (int) $pending->traffic);
    }

    public function test_marketing_metrics_only_update_does_not_email_publisher(): void
    {
        Mail::fake();

        $niche = Category::query()->where('name', 'News')->value('name')
            ?? Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Metrics Quiet',
            'site_url' => 'https://pending-metrics-quiet.example',
            'domain' => 'pending-metrics-quiet.example',
            'example_url' => 'https://pending-metrics-quiet.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => $niche,
            'categories' => [$niche],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending metrics quiet description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Metrics Quiet',
                'site_url' => 'https://pending-metrics-quiet.example',
                'example_url' => 'https://pending-metrics-quiet.example/sample',
                'price' => 50,
                'da' => 42,
                'dr' => 40,
                'traffic' => 12000,
                'country' => 'de',
                'language' => 'de',
                'categories' => $niche,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertStringNotContainsString('Publisher notified', (string) session('success'));
        Mail::assertNothingOutgoing();
        $this->assertSame(42, (int) $pending->fresh()->da);
    }

    public function test_marketing_update_does_not_email_for_canonical_url_only_change(): void
    {
        Mail::fake();

        $niche = Category::query()->where('name', 'News')->value('name')
            ?? Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Pending Case Url',
            'site_url' => 'https://Pending-Case.example:443/Path',
            'domain' => 'pending-case.example',
            'example_url' => 'https://Pending-Case.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => $niche,
            'categories' => [$niche],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending case url description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => 'Pending Case Url',
                'site_url' => 'https://Pending-Case.example:443/Path',
                'example_url' => 'https://Pending-Case.example/sample',
                'price' => 50,
                'da' => 41,
                'dr' => 40,
                'traffic' => 12000,
                'country' => 'de',
                'language' => 'de',
                'categories' => $niche,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('Publisher notified', (string) session('success'));
        Mail::assertNothingOutgoing();
        $pending->refresh();
        $this->assertSame('https://pending-case.example/Path', $pending->site_url);
        $this->assertSame(41, (int) $pending->da);
    }

    public function test_marketing_update_does_not_email_for_canonical_name_only_change(): void
    {
        Mail::fake();

        $niche = Category::query()->where('name', 'News')->value('name')
            ?? Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $pending = Site::create([
            'publisher_id' => $this->publisher->id,
            'publisher_accepted_at' => now(),
            'site_name' => "Pending  Name\u{200B}",
            'site_url' => 'https://pending-name-quiet.example',
            'domain' => 'pending-name-quiet.example',
            'example_url' => 'https://pending-name-quiet.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => $niche,
            'categories' => [$niche],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending name quiet description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $pending->id))
            ->put(route('marketing.sites.update', $pending->id), [
                'site_name' => "Pending  Name\u{200B}",
                'site_url' => 'https://pending-name-quiet.example',
                'example_url' => 'https://pending-name-quiet.example/sample',
                'price' => 50,
                'da' => 41,
                'dr' => 40,
                'traffic' => 12000,
                'country' => 'de',
                'language' => 'de',
                'categories' => $niche,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('Publisher notified', (string) session('success'));
        Mail::assertNothingOutgoing();
        $pending->refresh();
        $this->assertSame('Pending Name', $pending->site_name);
        $this->assertSame(41, (int) $pending->da);
    }
}
