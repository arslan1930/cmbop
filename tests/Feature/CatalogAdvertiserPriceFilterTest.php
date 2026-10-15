<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogAdvertiserPriceFilter;
use App\Services\PlatformFeeService;
use App\Support\ListingMoney;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogAdvertiserPriceFilterTest extends TestCase
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
            'display_currency' => 'EUR',
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->actingAs($this->advertiser);
    }

    private function site(float $price, string $name, array $extra = []): Site
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
            'price' => $price,
            'listing_currency' => 'EUR',
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Price filter fixture.',
            'verified' => true,
            'active' => 1,
        ], $extra));
    }

    public function test_sql_advertiser_price_matches_php_checkout_rounding(): void
    {
        $this->actingAs($this->advertiser);
        $prices = [10, 50, 80, 99.99, 100, 113, 299.99, 300, 500, 1000, 1500];
        foreach ($prices as $i => $price) {
            $this->site((float) $price, 'Price '.$price.' '.$i);
        }

        $listing = app(ListingMoney::class);
        $fees = app(PlatformFeeService::class);
        $sql = app(CatalogAdvertiserPriceFilter::class)->advertiserFacingSql($listing);

        $rows = Site::query()
            ->select('id', 'price', 'listing_currency')
            ->selectRaw($sql.' as adv_sql')
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $php = $fees->advertiserBase(
                $listing->buyerFacingEuros((float) $row->price, $row->listingCurrency(), 'EUR')
            );
            $this->assertEqualsWithDelta(
                $php,
                (float) $row->adv_sql,
                0.001,
                'SQL advertiser price must match checkout PHP for stored '.$row->price
            );
        }
    }

    public function test_sql_price_filter_returns_the_same_ids_as_the_legacy_php_filter(): void
    {
        $this->actingAs($this->advertiser);
        $cheap = $this->site(40, 'Cheap Listing');
        $mid = $this->site(120, 'Mid Listing');
        $high = $this->site(400, 'High Listing');
        $this->site(40, 'Inactive Cheap', ['active' => 0, 'site_name' => 'Inactive Cheap', 'domain' => 'inactive-cheap.example', 'site_url' => 'https://inactive-cheap.example']);

        $filter = app(CatalogAdvertiserPriceFilter::class);
        $min = 100.0;
        $max = 200.0;

        $phpIds = $filter->phpMatchingIds($min, $max);
        $sqlIds = Site::query()
            ->select('id')
            ->selectRaw($filter->advertiserFacingSql().' as adv')
            ->get()
            ->filter(fn ($row) => (float) $row->adv >= $min && (float) $row->adv <= $max)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $constrainIds = Site::query()
            ->tap(fn ($q) => $filter->constrain($q, $min, $max))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        sort($phpIds);
        sort($sqlIds);
        sort($constrainIds);

        $this->assertContains($mid->id, $sqlIds);
        $this->assertNotContains($cheap->id, $sqlIds);
        $this->assertNotContains($high->id, $sqlIds);
        $this->assertSame($phpIds, $sqlIds);
        $this->assertSame($phpIds, $constrainIds);
    }

    public function test_catalog_price_min_does_not_select_the_whole_sites_table(): void
    {
        $this->site(80, 'Under Floor');
        $this->site(200, 'Over Floor');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['price_min' => 100]))
            ->assertOk();

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('sidebarProjects', $sql);
        $this->assertDoesNotMatchRegularExpression(
            '/select\s+[`"]?id[`"]?,\s*[`"]?price[`"]?(,\s*[`"]?listing_currency[`"]?)?\s+from\s+[`"]?sites[`"]?\s*$/im',
            $sql
        );
    }
}
