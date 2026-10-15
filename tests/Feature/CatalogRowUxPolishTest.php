<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\PublicI18n;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRowUxPolishTest extends TestCase
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
            'display_currency' => 'EUR',
        ]);
        $this->advertiser->roles()->attach($advRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function site(string $name, array $extra = []): Site
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name) ?: 'site');

        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 8000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'categories' => ['Marketing'],
            'price' => 100,
            'listing_currency' => 'EUR',
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Catalog row UX fixture.',
            'verified' => true,
            'active' => 1,
            'created_at' => now()->subMonths(4),
        ], $extra));
    }

    private function catalogHtml(array $query = []): string
    {
        return (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', $query))
            ->assertOk()
            ->getContent();
    }

    public function test_discount_badge_appears_once_beside_the_price(): void
    {
        $this->site('Same Painted Discount', [
            'custom_discount_percent' => 13,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);

        $html = $this->catalogHtml(['search' => 'Same Painted Discount']);
        $closed = $this->closedDesktopBuy($html);

        $this->assertStringContainsString('11.5% off', $closed);
        $this->assertSame(1, substr_count($closed, 'class="catalog-price__offer"'));
        $this->assertStringNotContainsString('site-chip--sale', $html);
        $this->assertStringNotContainsString('catalog-site-deals', $html);
        $this->assertStringNotContainsString('catalog-bulk-note', $closed);
        $this->assertStringNotContainsString('on 3+', $html);
        $this->assertStringNotContainsString('−11.5%', $closed);
    }

    public function test_multi_buy_note_only_when_pack_percent_differs(): void
    {
        $this->site('Pack Beats Sale', [
            'custom_discount_percent' => 10,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);

        $html = $this->catalogHtml(['search' => 'Pack Beats Sale']);
        $closed = $this->closedDesktopBuy($html);

        $this->assertStringContainsString('10% off', $closed);
        $this->assertStringContainsString('catalog-bulk-note', $closed);
        $this->assertStringContainsString('−11.5% when buying 3+', $closed);
        $this->assertStringNotContainsString('on 3+', $html);
        $this->assertStringNotContainsString('site-chip--sale', $html);
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('function catalogRowBuyButton(', $js);
        $this->assertStringContainsString('.catalog-row-actions .buy-now', $js);
    }

    public function test_sensitive_chip_is_neutral_and_report_keeps_a_tooltip(): void
    {
        $this->site('Sensitive Neutral Chip', [
            'sensitive_prices' => ['crypto' => 5, 'trading' => 8, 'cbd' => 12],
        ]);

        $html = $this->catalogHtml(['search' => 'Sensitive Neutral Chip']);

        $this->assertStringContainsString('site-chip--sensitive', $html);
        $this->assertStringContainsString('Sensitive topics from', $html);
        $this->assertStringContainsString('catalog-site-report', $html);
        $this->assertStringContainsString('fa-triangle-exclamation', $html);
        $this->assertStringContainsString('This is not a warning about sensitive topics.', $html);
        $this->assertStringContainsString('data-glass-tip-title="Report listing"', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertDoesNotMatchRegularExpression(
            '/\.site-chip--sensitive \{\s*background:\s*#fff7ed;/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.site-chip--sensitive \{\s*background:\s*var\(--brand-primary-bg/',
            $css
        );

        $blade = (string) file_get_contents(
            resource_path('views/advertiser/catalog.blade.php')
        );
        $this->assertStringContainsString('.catalog-page .site-chip--sensitive{', $blade);
    }

    public function test_price_is_prefixed_from_when_paid_addons_exist(): void
    {
        $this->site('From Price Addons', [
            'sensitive_prices' => ['crypto' => 5],
        ]);
        $this->site('Plain List Only', [
            'site_url' => 'https://plain-list-only.example',
            'domain' => 'plain-list-only.example',
        ]);

        $withAddons = $this->closedDesktopBuy(
            $this->catalogHtml(['search' => 'From Price Addons'])
        );
        $this->assertStringContainsString('catalog-price__from', $withAddons);
        $this->assertDoesNotMatchRegularExpression('/catalog-price__from"[^>]*hidden/', $withAddons);
        $this->assertStringContainsString('>from</span>', $withAddons);

        $plain = $this->closedDesktopBuy(
            $this->catalogHtml(['search' => 'Plain List Only'])
        );
        $this->assertMatchesRegularExpression('/catalog-price__from"[^>]*hidden/', $plain);
    }

    public function test_turnaround_is_always_days(): void
    {
        $this->site('Turnaround Days Row', ['turnaround_time' => '24h']);

        $html = $this->catalogHtml(['search' => 'Turnaround Days Row']);

        $this->assertStringContainsString('>1 day<', $html);
        $this->assertStringNotContainsString('24 hours', $html);
        $this->assertStringNotContainsString('48 hours', $html);
        $this->assertSame('2 days', (new Site(['turnaround_time' => '48h']))->turnaroundLabel());
        $this->assertSame('3 days', (new Site(['turnaround_time' => '3days']))->turnaroundLabel());
    }

    public function test_new_ribbon_only_within_thirty_days(): void
    {
        $fresh = $this->site('BrandNewListingX');
        $fresh->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $old = $this->site('VeteranListingY', [
            'site_url' => 'https://veteran-listing-y.example',
            'domain' => 'veteran-listing-y.example',
        ]);
        $old->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();

        $this->assertTrue($fresh->fresh()->isRecentlyCreated());
        $this->assertFalse($old->fresh()->isRecentlyCreated());

        $freshHtml = $this->catalogHtml(['search' => 'BrandNewListingX']);
        $oldHtml = $this->catalogHtml(['search' => 'VeteranListingY']);
        $this->assertStringContainsString('site-badge-new catalog-new-ribbon', $freshHtml);
        $this->assertStringNotContainsString('site-badge-new catalog-new-ribbon', $oldHtml);
    }

    public function test_catalog_row_copy_is_translated_in_every_locale_file(): void
    {
        $keys = [
            'catalog_price_from',
            'catalog_percent_off',
            'catalog_bulk_when_buying',
            'catalog_turnaround_one',
            'catalog_turnaround_many',
            'catalog_sensitive_topics_from',
            'catalog_new_listing_body',
            'catalog_report_listing_body',
        ];

        foreach (PublicI18n::supported() as $locale) {
            $fileLocale = PublicI18n::messagesFallback($locale) ?: $locale;
            $path = resource_path('lang/'.$fileLocale.'/messages.php');
            $messages = require $path;
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $messages, $locale.' missing '.$key);
                $this->assertNotSame('', trim((string) $messages[$key]));
            }
        }

        $de = require resource_path('lang/de/messages.php');
        $this->assertSame('ab', $de['catalog_price_from']);
        $this->assertSame('2 Tage', __('messages.catalog_turnaround_many', ['count' => 2], 'de'));
    }

    private function closedDesktopBuy(string $html): string
    {
        $actionStart = strpos($html, 'catalog-td-action');
        $detailsStart = strpos($html, 'catalog-site-details');
        $this->assertNotFalse($actionStart);
        $this->assertNotFalse($detailsStart);
        $this->assertGreaterThan($actionStart, $detailsStart);

        return substr($html, $actionStart, $detailsStart - $actionStart);
    }
}
