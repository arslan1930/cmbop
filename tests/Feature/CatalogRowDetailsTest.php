<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRowDetailsTest extends TestCase
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
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Row Details Blog',
            'site_url' => 'https://demo3.com/blog',
            'domain' => 'demo3.com',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'categories' => ['Marketing'],
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => '<p>Ein <strong>deutscher</strong> Verlag für Gastbeiträge mit klarer Zielgruppe.</p>',
            'verified' => true,
            'active' => 1,
            'partner_material' => true,
        ], $overrides));
    }

    private function hideModeAdvertiser(): User
    {
        $this->advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        return $this->advertiser->fresh();
    }

    public function test_visit_control_sits_on_the_rooted_url_not_the_title_row(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="[^"]*site-open-link[^"]*catalog-site-rooted-url[^"]*"[^>]*>\s*https:\/\/demo3\.com\s*</',
            $html
        );
        $this->assertStringContainsString('advertiser/go/', $html);
        $this->assertStringNotContainsString('fa-arrow-up-right-from-square', $html);

        $titleStart = strpos($html, 'catalog-site-title-row');
        $identityStart = strpos($html, 'catalog-site-identity');
        $this->assertNotFalse($titleStart);
        $this->assertNotFalse($identityStart);
        $titleChunk = substr($html, $titleStart, $identityStart - $titleStart);
        $this->assertStringContainsString('catalog-site-name', $titleChunk);
        $this->assertStringContainsString('site-chip--verified', $titleChunk);
        $this->assertStringNotContainsString('catalog-details-toggle', $titleChunk);
        $this->assertStringNotContainsString('site-open-link', $titleChunk);
        $this->assertStringNotContainsString('Partner article', $titleChunk);

        $this->assertStringContainsString('catalog-details-toggle', $html);
        $this->assertStringContainsString('Partner article', $html);
        $this->assertMatchesRegularExpression(
            '/catalog-site-identity[\s\S]*?Partner article[\s\S]*?catalog-site-deals|catalog-site-identity[\s\S]*?Partner article/',
            $html
        );
    }

    public function test_card_metrics_keep_traffic_dr_da_labels(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-mobile-metrics__label', $html);
        $this->assertMatchesRegularExpression('/catalog-mobile-metrics__label[\s\S]*?>[\s\S]*?Traffic/', $html);
        $this->assertMatchesRegularExpression('/catalog-mobile-metrics__label[\s\S]*?>[\s\S]*?DR/', $html);
        $this->assertMatchesRegularExpression('/catalog-mobile-metrics__label[\s\S]*?>[\s\S]*?DA/', $html);
    }

    public function test_description_stays_on_the_page_without_a_translate_control(): void
    {
        $site = $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<strong>deutscher</strong>', $html);
        $this->assertStringContainsString(e(site_description_excerpt($site->description)), $html);
        $this->assertStringNotContainsString('Translate to English', $html);
        $this->assertStringNotContainsString('translate.google.com', $html);
        $this->assertStringNotContainsString('catalog-description-translate', $html);
        $this->assertStringNotContainsString('Brief in German', $html);
    }

    public function test_hide_mode_blanks_description_until_identity_is_shown(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->hideModeAdvertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('then the description appears', $html);
        $this->assertStringNotContainsString('Translate to English', $html);
        $this->assertStringNotContainsString('<strong>deutscher</strong>', $html);
        $this->assertStringNotContainsString('Ein deutscher Verlag', $html);
    }

    public function test_card_buy_keeps_price_hooks_and_claim_is_quiet_copy(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-card-buy', $html);
        $this->assertStringContainsString('buy-now', $html);
        $this->assertStringContainsString('base-price-display', $html);
        $this->assertStringContainsString('list-price-display', $html);
        $this->assertStringContainsString('btn-claim-site', $html);
        $this->assertStringContainsString('Is this your site?', $html);
        $this->assertStringContainsString('Awaiting first ratings', $html);
        $this->assertStringContainsString('No completed orders yet', $html);

        $cardBuy = strpos($html, 'catalog-card-buy');
        $this->assertNotFalse($cardBuy);
        $chunk = substr($html, $cardBuy, 1200);
        $buyPos = strpos($chunk, 'buy-now');
        $pricePos = strpos($chunk, 'catalog-price');
        $this->assertNotFalse($buyPos);
        $this->assertNotFalse($pricePos);
        $this->assertLessThan($pricePos, $buyPos);
    }

    public function test_tile_title_says_initials_are_not_a_country_code(): void
    {
        $tile = (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-site-tile.blade.php')
        );

        $this->assertStringContainsString('not a country code', $tile);
        $this->assertStringContainsString('aria-hidden="true"', $tile);
    }
}
