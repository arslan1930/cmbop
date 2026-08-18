<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteTag;
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
        $this->assertStringContainsString('Turnaround', $html);
    }

    public function test_expand_separates_link_type_from_tags(): void
    {
        $this->makeSite([
            'site_name' => 'Sponsored Expand Blog',
            'site_url' => 'https://expand-sponsored.example',
            'domain' => 'expand-sponsored.example',
            'sponsored' => true,
        ]);
        $this->makeSite([
            'site_name' => 'Partner Expand Blog',
            'site_url' => 'https://expand-partner.example',
            'domain' => 'expand-partner.example',
            'partner_material' => true,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Desktop expand: link attribute under its own heading, not under Listing tag.
        $this->assertMatchesRegularExpression(
            '/catalog-expand-meta[\s\S]*?<strong>Link type<\/strong>[\s\S]*?NoFollow[\s\S]*?Listing tag[\s\S]*?Sponsored/u',
            $html
        );
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Link type'));
        $this->assertStringContainsString('NoFollow', $html);
        $this->assertStringContainsString('Sponsored', $html);
        $this->assertStringContainsString('Partner article', $html);
        $this->assertStringContainsString('site-chip--sponsored', $html);
        $this->assertStringContainsString('site-chip--partner', $html);
        $this->assertStringContainsString(SiteTag::DETAILS_HEADING, $html);
        $this->assertStringContainsString(SiteTag::FILTER_TOOLTIP, $html);
        $this->assertStringContainsString('catalog-tag-definition', $html);
        $this->assertStringContainsString(
            'Paid placement disclosed as sponsored — not the DoFollow / NoFollow link attribute',
            $html
        );
        $this->assertStringNotContainsString('<strong>Tags</strong>', $html);
        $this->assertStringNotContainsString('<dt>Tags</dt>', $html);
    }

    public function test_expand_paints_no_tags_chip_and_closed_row_stays_empty(): void
    {
        $this->makeSite([
            'site_name' => 'Untagged Expand Blog',
            'site_url' => 'https://expand-untagged.example',
            'domain' => 'expand-untagged.example',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Desktop expand + mobile Details only — closed row / card badges stay empty.
        $this->assertSame(2, substr_count($html, 'site-chip--none'));
        $this->assertStringContainsString(SiteTag::NONE_LABEL, $html);
        $this->assertStringContainsString(SiteTag::NONE_CHIP_TITLE, $html);
        $this->assertStringContainsString('catalog-tag-definition', $html);
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
        $this->assertStringNotContainsString('No extra pricing options for this listing.', $html);
        $this->assertStringNotContainsString('Base guest post only', $html);
    }

    public function test_expand_shows_homepage_and_social_in_site_details_meta(): void
    {
        $this->makeSite([
            'sensitive_prices' => null,
            'homepage_placement_prices' => ['7' => 25, '30' => 40],
            'social_promotion' => ['facebook' => true, 'instagram' => true],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Placement offers live in Site Details meta — not only as closed-row chips.
        $this->assertStringContainsString('Homepage promotions', $html);
        $this->assertStringContainsString('homepage-placement-group', $html);
        $this->assertMatchesRegularExpression(
            '/catalog-expand-meta[\s\S]*?Homepage promotions[\s\S]*?<strong>Social<\/strong>/u',
            $html
        );
        $this->assertStringContainsString('Facebook', $html);
        $this->assertStringContainsString('Instagram', $html);
        // No sensitive add-ons → pricing column stays collapsed.
        $this->assertStringNotContainsString('catalog-expand-pricing', $html);
        $this->assertStringNotContainsString('Base guest post only', $html);
        // Mobile Details also lists the offers.
        $this->assertStringContainsString('<dt>Homepage promotions</dt>', $html);
        $this->assertStringContainsString('<dt>Social</dt>', $html);
    }

    public function test_expand_collapses_pricing_when_no_extras(): void
    {
        $this->makeSite([
            'description' => '',
            'sensitive_prices' => null,
            'homepage_placement_prices' => null,
            'social_promotion' => null,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('catalog-expand-pricing', $html);
        $this->assertStringNotContainsString('No extra pricing options for this listing.', $html);
        $this->assertStringContainsString('Base guest post only — no homepage, social, or sensitive add-ons.', $html);
        // Site Details always lists Homepage / Social (empty state when not offered).
        $this->assertStringContainsString('Homepage promotions', $html);
        $this->assertStringContainsString('Not offered on this listing.', $html);
        $this->assertStringContainsString('<strong>Social</strong>', $html);
        $this->assertStringContainsString('No social sharing included on this listing.', $html);
        $this->assertStringContainsString('No description yet', $html);
        $this->assertStringContainsString('Turnaround', $html);
    }

    public function test_mobile_card_details_include_social_and_homepage(): void
    {
        $this->makeSite([
            'homepage_placement_prices' => ['7' => 15],
            'social_promotion' => ['facebook' => true],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-chip--social', $html);
        $this->assertStringContainsString('Facebook', $html);
        $this->assertStringContainsString('<dt>Social</dt>', $html);
        $this->assertStringContainsString('<dt>Homepage promotions</dt>', $html);
        $this->assertStringContainsString('Choose a duration above Buy.', $html);
    }

    public function test_you_pay_copy_is_aligned_across_desktop_and_mobile(): void
    {
        $this->makeSite([
            'sensitive_prices' => ['crypto' => 15],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('You pay:', $html);
        $this->assertStringNotContainsString('Current price:', $html);

        $js = file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertIsString($js);
        $this->assertStringContainsString("You pay: <strong>€'", $js);
        $this->assertStringNotContainsString('Current price:', $js);
    }

    public function test_row_shows_homepage_and_social_chips_and_paid_hint(): void
    {
        $this->makeSite([
            'homepage_placement_prices' => ['7' => 25, '30' => 40],
            'social_promotion' => ['facebook' => true, 'instagram' => true],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-chip--homepage', $html);
        $this->assertStringContainsString('Homepage</span>', $html);
        $this->assertStringNotContainsString('Free homepage', $html);
        $this->assertStringContainsString('site-chip--social', $html);
        $this->assertStringContainsString('Homepage placement available in Details.', $html);
    }

    public function test_free_homepage_chip_skips_paid_only_hint(): void
    {
        $this->makeSite([
            'homepage_placement_prices' => ['1' => 0, '7' => 20],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Free homepage', $html);
        $this->assertStringNotContainsString('Homepage placement available in Details.', $html);
    }

    public function test_mobile_card_details_cover_desktop_decision_fields(): void
    {
        $this->makeSite([
            'example_url' => null,
            'sponsored' => true,
            'description' => '<p>Full mobile description for parity.</p>',
            'screenshot_path' => 'site-screenshots/mobile-parity.webp',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-card-details', $html);
        $this->assertStringContainsString('Homepage preview', $html);
        $this->assertStringContainsString('Full mobile description for parity.', $html);
        $this->assertStringContainsString('Sponsored', $html);
        $this->assertStringContainsString('site-chip--sponsored', $html);
        $this->assertStringContainsString('No sample article yet', $html);
        $this->assertStringContainsString('catalog-deferred-preview', $html);
        $this->assertStringContainsString('Publisher trust', $html);
    }
}
