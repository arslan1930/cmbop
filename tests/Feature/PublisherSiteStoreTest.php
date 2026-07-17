<?php

namespace Tests\Feature;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Models\Category;
use App\Models\Country;
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
use Illuminate\Support\Facades\Queue;
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
}
