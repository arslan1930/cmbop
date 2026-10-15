<?php

namespace Tests\Feature;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Mail\NewSiteNotification;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\CountryLanguageSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublisherSiteStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CountryLanguageSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    public function test_publisher_can_add_site_with_long_multi_categories_and_manual_metrics(): void
    {
        Queue::fake();
        config(['site_enrichment.enabled' => true]);

        $cats = Category::query()
            ->orderByRaw('LENGTH(name) DESC')
            ->limit(3)
            ->pluck('name')
            ->all();

        $this->assertNotEmpty($cats);
        $joined = implode('|', $cats);
        $this->assertGreaterThan(50, strlen($joined));

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Long Category News',
            'siteUrl' => 'long-category-news.example',
            'exampleUrl' => 'https://long-category-news.example/sample-post',
            'da' => 55,
            'dr' => 60,
            'traffic' => 25000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $joined,
            'price' => 120,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();

        $site = Site::where('domain', 'long-category-news.example')->first();
        $this->assertNotNull($site);
        $this->assertSame(55, (int) $site->da);
        $this->assertSame(60, (int) $site->dr);
        $this->assertSame(25000, (int) $site->traffic);
        $this->assertTrue((bool) $site->metrics_manual);
        $this->assertSame('manual', $site->metrics_provider);
        $this->assertSame(strtolower($country->code), $site->country);
        $this->assertSame(strtolower($language->code), $site->language);
        $this->assertIsArray($site->categories);
        $this->assertCount(3, $site->categories);
        $this->assertGreaterThan(50, strlen((string) $site->category));

        Queue::assertPushed(CaptureSiteScreenshotJob::class, function ($job) use ($site) {
            return $job->siteId === $site->id && $job->triggeredBy === 'publisher_create';
        });
    }

    public function test_site_description_rejects_short_plain_text_even_with_html_padding(): void
    {
        $country = Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)->from(route('publisher.websites'))->post(route('publisher.sites.store'), [
            'siteName' => 'Short Desc Site',
            'siteUrl' => 'https://short-desc.example',
            'exampleUrl' => 'https://short-desc.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => [$category->name],
            'price' => 80,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => '<p><strong>'.str_repeat('x', 20).'</strong></p>',
            'site_tag' => 'as_you_prefer',
        ])->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('siteDescription');

        $this->assertDatabaseMissing('sites', ['domain' => 'short-desc.example']);
    }

    public function test_site_description_rejects_array_input_without_500(): void
    {
        $country = Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)->from(route('publisher.websites'))->post(route('publisher.sites.store'), [
            'siteName' => 'Array Desc Site',
            'siteUrl' => 'https://array-desc.example',
            'exampleUrl' => 'https://array-desc.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => [['de']],
            'language' => strtolower($language->code),
            'categories' => [1, [$category->name]],
            'price' => 80,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => ['<p>Poisoned description</p>'],
            'site_tag' => 'as_you_prefer',
        ])->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('siteDescription');

        $this->assertDatabaseMissing('sites', ['domain' => 'array-desc.example']);
    }

    public function test_site_description_rejects_over_five_hundred_words(): void
    {
        $country = Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();
        $tooLong = implode(' ', array_fill(0, 501, 'word'));

        $this->actingAs($this->publisher)->from(route('publisher.websites'))->post(route('publisher.sites.store'), [
            'siteName' => 'Long Desc Site',
            'siteUrl' => 'https://long-desc.example',
            'exampleUrl' => 'https://long-desc.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => [$category->name],
            'price' => 80,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => $tooLong,
            'site_tag' => 'as_you_prefer',
        ])->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('siteDescription');

        $this->assertDatabaseMissing('sites', ['domain' => 'long-desc.example']);
    }

    public function test_category_names_with_commas_are_preserved(): void
    {
        Queue::fake();

        $name = 'Marketing, PR & Advertising';
        Category::query()->firstOrCreate(['name' => $name], ['group' => 'Business']);

        $country = Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->firstOrFail();

        $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Comma Cat Site',
            'siteUrl' => 'https://comma-cat.example',
            'exampleUrl' => 'https://comma-cat.example/post',
            'da' => 40,
            'dr' => 41,
            'traffic' => 1000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $name,
            'price' => 80,
            'turnaround_time' => '48h',
            'publicationTime' => '1year',
            'link_type' => 'nofollow',
            'siteDescription' => str_repeat('Editorial description for testing commas. ', 3),
        ])->assertSessionHas('success');

        $site = Site::where('domain', 'comma-cat.example')->firstOrFail();
        $this->assertSame([$name], $site->categories);
    }

    public function test_english_language_map_includes_chinese_gulf_and_english_regions(): void
    {
        $this->seed(CountryLanguageSeeder::class);

        $response = $this->actingAs($this->publisher)->get(route('publisher.websites'));
        $response->assertOk();

        $map = $response->viewData('languageCountryMap');
        $this->assertIsArray($map);
        $this->assertArrayHasKey('en', $map);

        $countryMap = $response->viewData('countryLanguageMap');
        $this->assertIsArray($countryMap);
        $this->assertArrayHasKey('de', $countryMap);
        $deLangs = collect($countryMap['de'])->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $this->assertSame(['de'], $deLangs);

        $codes = collect($map['en'])->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        foreach (['us', 'uk', 'au', 'nz', 'za', 'sg', 'cn', 'tw', 'hk', 'mo', 'ae', 'sa', 'qa', 'kw', 'bh', 'om'] as $code) {
            $this->assertContains($code, $codes, "Expected English map to include {$code}");
        }
    }

    public function test_publisher_can_add_english_site_for_china_and_gulf(): void
    {
        Queue::fake();
        $this->seed(CountryLanguageSeeder::class);

        $category = Category::query()->firstOrFail()->name;

        foreach (['cn' => 'china-en.example', 'ae' => 'gulf-en.example'] as $country => $domain) {
            $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
                'siteName' => 'English '.$country,
                'siteUrl' => 'https://'.$domain,
                'exampleUrl' => 'https://'.$domain.'/post',
                'da' => 45,
                'dr' => 50,
                'traffic' => 5000,
                'country' => $country,
                'language' => 'en',
                'categories' => $category,
                'price' => 90,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('English guest post site for marketplace testing. ', 3),
            ])->assertSessionHas('success');

            $site = Site::where('domain', $domain)->firstOrFail();
            $this->assertSame($country, $site->country);
            $this->assertSame('en', $site->language);
        }
    }

    public function test_fit_category_column_uses_first_name_when_pipe_join_exceeds_varchar_50(): void
    {
        Cache::put('sites_category_column_max_length', 50, 60);

        $list = ['Business & Finance', 'Technology', 'Health & Wellness', 'Travel & Tourism'];
        $joined = implode('|', $list);
        $this->assertGreaterThan(50, strlen($joined));

        $fitted = Site::fitCategoryColumn($joined, $list);
        $this->assertSame('Business & Finance', $fitted);
        $this->assertLessThanOrEqual(50, strlen($fitted));

        Site::flushSchemaColumnCache();
    }

    public function test_store_skips_missing_optional_columns(): void
    {
        Queue::fake();

        // Simulate older Hostinger DB without enrichment / JSON helpers
        $optional = [
            'metrics_manual',
            'metrics_provider',
            'metrics_fetched_at',
            'enrichment_status',
            'enrichment_error',
            'countries',
            'languages',
            'categories',
        ];

        foreach ($optional as $column) {
            if (! Schema::hasColumn('sites', $column)) {
                continue;
            }
            try {
                Schema::table('sites', function ($table) use ($column) {
                    $table->dropColumn($column);
                });
            } catch (\Throwable) {
                // SQLite may refuse dropping indexed columns; skip that column
            }
        }

        Cache::put('sites_category_column_max_length', 50, 60);

        $cats = Category::query()->orderByRaw('LENGTH(name) DESC')->limit(4)->pluck('name')->all();
        $joined = implode('|', $cats);
        $this->assertGreaterThan(50, strlen($joined));

        $country = Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->firstOrFail();
        $domain = 'legacy-schema-'.uniqid().'.example';

        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Legacy Schema Site',
            'siteUrl' => 'https://'.$domain,
            'exampleUrl' => 'https://'.$domain.'/post',
            'da' => 33,
            'dr' => 34,
            'traffic' => 1200,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $joined,
            'price' => 75,
            'turnaround_time' => '48h',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Legacy schema publisher listing description. ', 3),
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();

        $site = Site::where('domain', $domain)->first();
        $this->assertNotNull($site);
        $this->assertLessThanOrEqual(50, strlen((string) $site->category));

        Site::flushSchemaColumnCache();
    }

    public function test_store_notifies_admins_by_role_pivot_and_enters_review_queue(): void
    {
        Queue::fake();
        Mail::fake();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $marketingRole = Role::where('name', 'marketing')->firstOrFail();

        // Admin role on pivot, but active role is marketing — old active_role_id lookup missed these.
        $admin = User::factory()->create([
            'email' => 'ops-admin@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $admin->roles()->attach([$adminRole->id, $marketingRole->id]);

        $country = Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail()->name;
        $domain = 'notify-review-'.uniqid().'.example';

        $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Notify Review Site',
            'siteUrl' => 'https://'.$domain,
            'exampleUrl' => 'https://'.$domain.'/sample',
            'da' => 40,
            'dr' => 42,
            'traffic' => 8000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $category,
            'price' => 95,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Publisher site ready for admin review notification. ', 3),
        ])->assertSessionHas('success');

        $site = Site::where('domain', $domain)->firstOrFail();
        $this->assertTrue($site->needsAdminReview());
        $this->assertNotNull($site->publisher_accepted_at);

        Mail::assertQueued(NewSiteNotification::class, function (NewSiteNotification $mail) use ($admin, $site) {
            return $mail->hasTo($admin->email)
                && (int) $mail->site->id === (int) $site->id
                && $mail->action === 'create'
                && is_string($mail->openUrl)
                && str_contains($mail->openUrl, '/marketing/sites')
                && str_contains($mail->openUrl, 'needs_review=1')
                && ! str_contains($mail->openUrl, '/admin/sites');
        });

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->where('related_type', Site::class)
            ->where('related_id', $site->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertSame('New site to review', $note->title);
        $this->assertStringContainsString('/marketing/sites', (string) $note->action_url);
        $this->assertStringNotContainsString('/admin/sites', (string) $note->action_url);
        $this->assertStringContainsString('needs_review=1', (string) $note->action_url);
        $this->assertStringContainsString('publisher='.$this->publisher->id, (string) $note->action_url);
        $this->assertStringContainsString('site='.$site->id, (string) $note->action_url);

        $this->assertTrue(
            User::query()
                ->whereKey($this->publisher->id)
                ->whereHas('sites', fn ($q) => $q->needsAdminReview())
                ->exists()
        );
    }

    public function test_new_site_notification_links_to_needs_review_queue(): void
    {
        $publisher = $this->publisher;
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Deep Link Site',
            'site_url' => 'https://deeplink-site.example',
            'domain' => 'deeplink-site.example',
            'example_url' => 'https://deeplink-site.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Deep link notification site description. ', 2),
            'verified' => false,
            'active' => false,
            'publisher_accepted_at' => now(),
        ]);

        $mailable = new NewSiteNotification($site, 'create');
        $html = $mailable->render();

        $this->assertStringContainsString('needs_review=1', $html);
        $this->assertStringContainsString('publisher='.$publisher->id, $html);
        $this->assertStringContainsString('site='.$site->id, $html);
        $this->assertStringNotContainsString('/admin/sites/'.$site->id.'/review', $html);
    }

    public function test_store_fails_gracefully_when_marketplace_country_list_is_empty(): void
    {
        Queue::fake();

        // No DB rows match this allow-list → Country::marketplace() is empty.
        config(['markets.allowed_country_codes' => ['zz']]);

        $language = Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail()->name;
        $domain = 'empty-market-'.uniqid().'.example';

        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Empty Market Site',
            'siteUrl' => 'https://'.$domain,
            'exampleUrl' => 'https://'.$domain.'/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => strtolower($language->code),
            'categories' => $category,
            'price' => 80,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Should not save when marketplace countries are empty. ', 2),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('country');
        $this->assertNull(Site::where('domain', $domain)->first());
    }

    public function test_mark_ready_for_admin_review_sets_queue_status(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Ready Review Site',
            'site_url' => 'https://ready-review.example',
            'domain' => 'ready-review.example',
            'example_url' => 'https://ready-review.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'categories' => ['Technology'],
            'price' => 50,
            'turnaround_time' => '48h',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Ready for admin review helper description. ', 3),
            'verified' => false,
            'active' => false,
            'publisher_accepted_at' => now(),
            'onboarding_status' => Site::ONBOARDING_DETAILS_COMPLETE,
        ]);

        $this->assertTrue($site->markReadyForAdminReview());
        $site->refresh();
        $this->assertTrue($site->isReadyForAdminReview());
        $this->assertTrue($site->needsAdminReview());
    }

    public function test_mark_details_complete_sets_intermediate_status(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Details Complete Site',
            'site_url' => 'https://details-complete.example',
            'domain' => 'details-complete.example',
            'example_url' => 'https://details-complete.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'categories' => ['Technology'],
            'price' => 50,
            'turnaround_time' => '48h',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Details complete helper description text. ', 3),
            'verified' => false,
            'active' => false,
            'publisher_accepted_at' => now(),
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->assertTrue($site->markDetailsComplete());
        $site->refresh();
        $this->assertSame(Site::ONBOARDING_DETAILS_COMPLETE, $site->onboarding_status);
        $this->assertFalse($site->needsAdminReview());
    }

    public function test_publisher_can_add_site_with_crypto_sensitive_using_html_on_checkbox(): void
    {
        Queue::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        // Browsers send "on" when a checkbox has no value= attribute — that used
        // to fail Laravel's boolean rule ("must be true or false").
        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Crypto Sensitive News',
                'siteUrl' => 'https://crypto-sensitive.example',
                'exampleUrl' => 'https://crypto-sensitive.example/sample',
                'da' => 40,
                'dr' => 42,
                'traffic' => 5000,
                'country' => strtolower($country->code),
                'language' => strtolower($language->code),
                'categories' => [$category->name],
                'price' => 90,
                'turnaround_time' => '3days',
                'publicationTime' => '1year',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
                'site_tag' => '',
                'sensitive' => ['crypto' => 'on'],
                'price_sensitive' => ['crypto' => '25'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $site = Site::where('domain', 'crypto-sensitive.example')->first();
        $this->assertNotNull($site);
        $this->assertEqualsWithDelta(25.0, (float) $site->sensitive_prices['crypto'], 0.001);
    }

    public function test_publisher_can_add_site_with_crypto_sensitive_using_value_one(): void
    {
        Queue::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Crypto One News',
                'siteUrl' => 'https://crypto-one.example',
                'exampleUrl' => 'https://crypto-one.example/sample',
                'da' => 40,
                'dr' => 42,
                'traffic' => 5000,
                'country' => strtolower($country->code),
                'language' => strtolower($language->code),
                'categories' => [$category->name],
                'price' => 90,
                'turnaround_time' => '3days',
                'publicationTime' => '1year',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
                'site_tag' => '',
                'sensitive' => ['crypto' => '1'],
                'price_sensitive' => ['crypto' => '15.50'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'crypto-one.example')->first();
        $this->assertNotNull($site);
        $this->assertEqualsWithDelta(15.5, (float) $site->sensitive_prices['crypto'], 0.001);
    }

    public function test_sensitive_checkboxes_declare_value_one_in_websites_form(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/websites.blade.php'));
        $this->assertIsString($blade);
        $this->assertMatchesRegularExpression(
            '/name="sensitive\[\s*\{\{\s*\$topic\s*\}\}\s*\]"[^>]*value="1"/s',
            $blade
        );
    }

    public function test_publisher_form_uses_glossary_tag_labels(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Partner article', $html);
        $this->assertStringContainsString('No tags', $html);
        $this->assertStringNotContainsString('Partner Materials', $html);
    }

    public function test_sponsored_tag_clears_other_flags(): void
    {
        Queue::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Sponsored Only News',
                'siteUrl' => 'https://sponsored-only.example',
                'exampleUrl' => 'https://sponsored-only.example/sample',
                'da' => 40,
                'dr' => 42,
                'traffic' => 5000,
                'country' => strtolower($country->code),
                'language' => strtolower($language->code),
                'categories' => [$category->name],
                'price' => 90,
                'turnaround_time' => '3days',
                'publicationTime' => '1year',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
                'site_tag' => 'sponsored',
                'partner_material' => '1',
                'as_you_prefer' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'sponsored-only.example')->firstOrFail();
        $this->assertTrue((bool) $site->sponsored);
        $this->assertFalse((bool) $site->partner_material);
        $this->assertFalse((bool) $site->as_you_prefer);
        $this->assertSame('sponsored', $site->tagValue());
    }

    public function test_empty_site_tag_stores_no_tags(): void
    {
        Queue::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Untagged News',
                'siteUrl' => 'https://untagged-news.example',
                'exampleUrl' => 'https://untagged-news.example/sample',
                'da' => 40,
                'dr' => 42,
                'traffic' => 5000,
                'country' => strtolower($country->code),
                'language' => strtolower($language->code),
                'categories' => [$category->name],
                'price' => 90,
                'turnaround_time' => '3days',
                'publicationTime' => '1year',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
                'site_tag' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'untagged-news.example')->firstOrFail();
        $this->assertFalse((bool) $site->sponsored);
        $this->assertFalse((bool) $site->partner_material);
        $this->assertFalse((bool) $site->as_you_prefer);
        $this->assertNull($site->tagValue());
    }

    public function test_add_site_wizard_lists_two_and_three_year_publication_terms(): void
    {
        $html = (string) $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2years"', $html);
        $this->assertStringContainsString('value="3years"', $html);
        $this->assertStringContainsString('2 Years', $html);
        $this->assertStringContainsString('3 Years', $html);
        $this->assertStringContainsString('`2years`', $html);
        $this->assertStringContainsString('`3years`', $html);
    }

    public function test_publisher_can_store_two_year_publication_duration(): void
    {
        Queue::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Two Year News',
                'siteUrl' => 'https://two-year-news.example',
                'exampleUrl' => 'https://two-year-news.example/sample',
                'da' => 40,
                'dr' => 42,
                'traffic' => 5000,
                'country' => strtolower($country->code),
                'language' => strtolower($language->code),
                'categories' => [$category->name],
                'price' => 90,
                'turnaround_time' => '3days',
                'publicationTime' => '2years',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sites', [
            'domain' => 'two-year-news.example',
            'publication_time' => '2years',
        ]);
    }
}
