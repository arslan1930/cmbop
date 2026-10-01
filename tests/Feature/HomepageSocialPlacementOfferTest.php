<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HomepageSocialPlacementOfferTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function basePayload(string $domain): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        return [
            'siteName' => 'Placement Offer Site',
            'siteUrl' => 'https://'.$domain,
            'exampleUrl' => 'https://'.$domain.'/sample',
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
        ];
    }

    public function test_publisher_can_leave_homepage_and_social_empty(): void
    {
        Queue::fake();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), $this->basePayload('empty-placement.example'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'empty-placement.example')->first();
        $this->assertNotNull($site);
        $this->assertNull($site->homepage_placement_prices);
        $this->assertNull($site->social_promotion);
        $this->assertFalse($site->offersHomepagePlacement());
        $this->assertFalse($site->offersSocialPromotion());
        $this->assertNull($site->longestFreeHomepageDays());
        $this->assertSame([], $site->enabledSocialChannels());
    }

    public function test_publisher_can_offer_free_and_paid_homepage_plus_social(): void
    {
        Queue::fake();

        $payload = $this->basePayload('homepage-social.example') + [
            'homepage' => ['1' => '1', '7' => '1', '30' => '1'],
            'price_homepage' => ['1' => '0', '7' => '25', '30' => '60'],
            'social' => ['facebook' => '1', 'x' => '1'],
        ];

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'homepage-social.example')->first();
        $this->assertNotNull($site);
        $this->assertSame([
            1 => 0.0,
            7 => 25.0,
            30 => 60.0,
        ], $site->homepagePlacementOptions());
        $this->assertSame(1, $site->longestFreeHomepageDays());
        $this->assertSame(['facebook', 'x'], $site->enabledSocialChannels());
        $this->assertTrue($site->offersHomepagePlacement());
        $this->assertTrue($site->offersSocialPromotion());
    }

    public function test_checked_homepage_with_blank_price_is_stored_as_free(): void
    {
        Queue::fake();

        $payload = $this->basePayload('blank-fee.example') + [
            'homepage' => ['7' => '1'],
            'price_homepage' => ['7' => ''],
        ];

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'blank-fee.example')->first();
        $this->assertSame([7 => 0.0], $site->homepagePlacementOptions());
        $this->assertSame(7, $site->longestFreeHomepageDays());
    }

    public function test_longest_free_homepage_days_picks_longest(): void
    {
        $site = new Site([
            'homepage_placement_prices' => ['1' => 0, '7' => 0, '30' => 40],
        ]);

        $this->assertSame(7, $site->longestFreeHomepageDays());
    }

    public function test_negative_homepage_fee_is_rejected(): void
    {
        Queue::fake();

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), $this->basePayload('neg-fee.example') + [
                'homepage' => ['7' => '1'],
                'price_homepage' => ['7' => '-5'],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('price_homepage.7');

        $this->assertDatabaseMissing('sites', ['domain' => 'neg-fee.example']);
    }

    public function test_edit_data_includes_homepage_and_social_offers(): void
    {
        Queue::fake();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), $this->basePayload('edit-data.example') + [
                'homepage' => ['30' => '1'],
                'price_homepage' => ['30' => '40'],
                'social' => ['instagram' => '1'],
            ])
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'edit-data.example')->firstOrFail();

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.edit-data', $site->id))
            ->assertOk()
            ->assertJsonPath('site.homepage_placement_prices.30', 40)
            ->assertJsonPath('site.social_promotion.instagram', true);
    }

    public function test_update_can_clear_homepage_and_social_offers(): void
    {
        Queue::fake();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), $this->basePayload('clear-offers.example') + [
                'homepage' => ['7' => '1'],
                'price_homepage' => ['7' => '10'],
                'social' => ['facebook' => '1', 'instagram' => '1'],
            ])
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'clear-offers.example')->firstOrFail();

        $payload = $this->basePayload('clear-offers.example');
        unset($payload['siteName'], $payload['siteUrl']);

        $this->actingAs($this->publisher)
            ->put(route('publisher.sites.update', $site->id), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertNull($site->homepage_placement_prices);
        $this->assertNull($site->social_promotion);
        $this->assertFalse($site->offersHomepagePlacement());
        $this->assertFalse($site->offersSocialPromotion());
    }

    public function test_websites_page_includes_placement_disclosure(): void
    {
        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Homepage &amp; social promotions (optional)', false)
            ->assertSee('name="homepage[1]"', false)
            ->assertSee('name="social[facebook]"', false);
    }

    public function test_admin_can_set_homepage_and_social_offers_on_edit(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Admin Placement Site',
            'site_url' => 'https://admin-placement.example',
            'domain' => 'admin-placement.example',
            'da' => 20,
            'dr' => 22,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'price' => 50,
            'publication_time' => 'permanent',
            'description' => str_repeat('Admin can set homepage social offers. ', 3),
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Placement extras', false)
            ->assertSee('name="placement_offers_form"', false)
            ->assertSee('name="sensitive[crypto]"', false)
            ->assertSee('Add site for this publisher', false);

        $this->actingAs($admin)
            ->put(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'da' => 20,
                'dr' => 22,
                'traffic' => 1000,
                'price' => 50,
                'country' => 'de',
                'language' => 'de',
                'category' => 'News',
                'description' => $site->description,
                'placement_offers_form' => 1,
                'homepage' => ['7' => '1', '30' => '1'],
                'price_homepage' => ['7' => '15', '30' => '40'],
                'social' => ['facebook' => '1', 'instagram' => '1'],
            ])
            ->assertRedirect();

        $site->refresh();
        $this->assertSame([7 => 15.0, 30 => 40.0], $site->homepagePlacementOptions());
        $this->assertSame(['facebook', 'instagram'], $site->enabledSocialChannels());
    }

    public function test_store_repairs_missing_homepage_placement_prices_and_saves_offer(): void
    {
        Queue::fake();

        if (Schema::hasColumn('sites', 'homepage_placement_prices')) {
            Schema::table('sites', function ($table) {
                $table->dropColumn('homepage_placement_prices');
            });
        }
        $this->assertFalse(Schema::hasColumn('sites', 'homepage_placement_prices'));

        $payload = $this->basePayload('repaired-placement.example') + [
            'homepage' => ['7' => '1'],
            'price_homepage' => ['7' => '25'],
        ];

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(Schema::hasColumn('sites', 'homepage_placement_prices'));
        $site = Site::where('domain', 'repaired-placement.example')->first();
        $this->assertNotNull($site);
        $this->assertSame([7 => 25.0], $site->homepagePlacementOptions());
        $this->assertSame(1, Site::countWithHomepagePlacement());
    }
}
