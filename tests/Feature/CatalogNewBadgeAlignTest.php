<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogNewBadgeAlignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function advertiser(): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeSite(array $overrides = []): Site
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'New Badge Site',
            'site_url' => 'https://new-badge.example',
            'domain' => 'new-badge.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 100,
            'publication_time' => 'permanent',
            'description' => 'NEW badge alignment and beep-only regression site.',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
            'created_at' => now()->subDays(2),
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 10,
        ], $overrides));
    }

    public function test_discount_and_status_chips_stay_on_one_nowrap_row(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-site-title-row', $html);
        $this->assertStringContainsString('catalog-site-badges', $html);
        $this->assertStringContainsString('catalog-site-deals', $html);
        $this->assertStringContainsString('site-chip--sale', $html);
        $this->assertStringContainsString('site-chip--verified', $html);
        $this->assertStringContainsString('site-badge-new', $html);
        $this->assertStringContainsString('catalog-new-ribbon', $html);
        // Custom 15% beats bulk 10% — only the winning chip (no dual “stacked” look).
        $this->assertStringNotContainsString('site-chip--bulk', $html);
        $this->assertStringNotContainsString('Bulk −10%', $html);

        // Sale/bulk live on the deals row; Verified stays with the name; NEW is the corner ribbon.
        $this->assertMatchesRegularExpression(
            '/catalog-new-ribbon[\s\S]*?catalog-site-title-row[\s\S]*?site-chip--verified[\s\S]*?catalog-site-deals[\s\S]*?site-chip--sale/',
            $html
        );

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-site-title-row', $css);
        $this->assertStringContainsString('.catalog-site-deals', $css);
        $this->assertStringContainsString('flex-wrap: nowrap', $css);
    }

    public function test_dual_chips_only_when_bulk_beats_custom_on_packs(): void
    {
        // Custom still applies on qty 1–2; bulk is the pack winner — both chips OK.
        $this->makeSite([
            'custom_discount_percent' => 10,
            'bulk_discount_percent' => 15,
            'created_at' => now()->subMonths(2),
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-chip--sale', $html);
        $this->assertStringContainsString('−10%', $html);
        $this->assertStringContainsString('catalog-bulk-note', $html);
        // Pack floors at publisher payout → effective ~11.5%, not nominal 15%.
        $this->assertStringContainsString('−11.5% on 3+', $html);
        $this->assertStringNotContainsString('Bulk −15%', $html);
    }

    public function test_hides_bulk_chip_when_custom_sale_is_stronger(): void
    {
        $this->makeSite([
            'custom_discount_percent' => 20,
            'bulk_discount_percent' => 15,
            'created_at' => now()->subMonths(2),
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-chip--sale', $html);
        // €113 list → €100 pay ⇒ effective 11.5%, not the nominal −20%.
        $this->assertStringContainsString('−11.5%', $html);
        $this->assertStringNotContainsString('−20%', $html);
        $this->assertStringNotContainsString('site-chip--bulk', $html);
        $this->assertStringNotContainsString('Bulk −15%', $html);
    }

    public function test_new_badge_restores_red_zoom_pulse_without_border_ring(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-badge-new', $html);
        $this->assertStringNotContainsString('site-badge-new__pulse', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('background: #ef4444 !important', $css);
        $this->assertStringContainsString('animation: siteNewPulse 1.6s ease-in-out infinite', $css);
        $this->assertStringNotContainsString('siteNewRing', $css);
        $this->assertStringNotContainsString('.site-badge-new__pulse', $css);
        $this->assertStringContainsString('transform: scale(1.08)', $css);
        $this->assertStringContainsString('siteNewAlertPop', $css);
        $this->assertStringContainsString('.site-badge-new.is-alerting', $css);

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('catalogNewBadgeBeepedV2', $js);
        $this->assertStringContainsString('playCatalogNewBeep', $js);
        $this->assertStringContainsString('armGestureBeep', $js);
        $this->assertStringContainsString("badge.classList.add('is-alerting')", $js);
        $this->assertStringContainsString('flashNewBadges', $js);
        // Session flag is set only after a successful beep, not before.
        $this->assertMatchesRegularExpression(
            '/playCatalogNewBeep\(\)\.then\(function \(ok\) \{[\s\S]*?markBeeped\(\)/',
            $js
        );
        // Alignment row styles remain so discount chips do not wrap Verified/NEW.
        $this->assertStringContainsString('flex-wrap: nowrap', $css);
    }

    public function test_catalog_ok_when_created_at_is_unparseable(): void
    {
        $site = $this->makeSite(['site_name' => 'Leftover Created Site']);
        DB::table('sites')->where('id', $site->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->assertFalse($site->fresh()->isRecentlyCreated());

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->getContent();

        $this->assertStringContainsString('data-site-name="Leftover Created Site"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-site-name="Leftover Created Site"[\s\S]{0,2500}?site-badge-new/',
            $html
        );
    }

    public function test_new_badge_filter_excludes_unparseable_created_at(): void
    {
        $live = $this->makeSite(['site_name' => 'Really New Site']);
        $leftover = $this->makeSite([
            'site_name' => 'Garbage Created Site',
            'site_url' => 'https://garbage-created.example',
            'domain' => 'garbage-created.example',
        ]);
        DB::table('sites')->where('id', $leftover->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $html = (string) $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', ['new_badge' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-site-name="Really New Site"', $html);
        $this->assertStringNotContainsString('data-site-name="Garbage Created Site"', $html);
        $this->assertTrue($live->fresh()->isRecentlyCreated());
    }

    public function test_newest_sort_does_not_promote_unparseable_created_at(): void
    {
        $fresh = $this->makeSite(['site_name' => 'Really Newest Site']);
        $leftover = $this->makeSite([
            'site_name' => 'Garbage Newest Site',
            'site_url' => 'https://garbage-newest.example',
            'domain' => 'garbage-newest.example',
        ]);
        DB::table('sites')->where('id', $leftover->id)->update([
            'created_at' => 'not-a-date',
        ]);
        DB::table('sites')->where('id', $fresh->id)->update([
            'created_at' => now()->subMinute()->toDateTimeString(),
        ]);

        $html = (string) $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', ['sort' => 'newest']))
            ->assertOk()
            ->getContent();

        $freshPos = strpos($html, 'data-site-name="Really Newest Site"');
        $leftoverPos = strpos($html, 'data-site-name="Garbage Newest Site"');
        $this->assertNotFalse($freshPos);
        $this->assertNotFalse($leftoverPos);
        $this->assertLessThan($leftoverPos, $freshPos);
    }
}
