<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTrustStripTest extends TestCase
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
            'site_name' => 'Trust Strip Blog',
            'site_url' => 'https://trust-strip.example',
            'domain' => 'trust-strip.example',
            'example_url' => 'https://trust-strip.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'categories' => ['Marketing'],
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Trust strip catalog fixture.',
            'verified' => true,
            'active' => 1,
            'rating_avg' => 0,
            'rating_count' => 0,
            'completed_orders_count' => 0,
        ], $overrides));
    }

    public function test_unrated_site_hides_stars_and_shows_empty_trust_copy(): void
    {
        $this->makeSite(['site_name' => 'Unrated Trust Site']);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-trust-compact', $html);
        $this->assertStringContainsString('New · No ratings yet', $html);
        $this->assertStringContainsString('No completion history yet', $html);
        $this->assertStringContainsString('Ratings from advertisers after completed orders', $html);
        $this->assertStringNotContainsString('No completions yet', $html);
        $this->assertStringContainsString('<dt>Trust</dt>', $html);
    }

    public function test_leftover_rating_without_completions_hides_stars(): void
    {
        $this->makeSite([
            'site_name' => 'Orphan Rating Site',
            'site_url' => 'https://orphan-rating.example',
            'domain' => 'orphan-rating.example',
            'rating_avg' => 5.0,
            'rating_count' => 1,
            'completed_orders_count' => 0,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Orphan Rating']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('New · No ratings yet', $html);
        $this->assertStringContainsString('No completion history yet', $html);
        $this->assertStringNotContainsString('site-trust-compact__stars', $html);
        $this->assertStringNotContainsString('5.0', $html);
        $this->assertStringNotContainsString('1 rating', $html);
    }

    public function test_leftover_rating_with_cancelled_only_history_hides_stars(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Cancelled Only Rating',
            'site_url' => 'https://cancelled-only-rating.example',
            'domain' => 'cancelled-only-rating.example',
            'rating_avg' => 5.0,
            'rating_count' => 1,
            'completed_orders_count' => 0,
        ]);

        $cancelled = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'refunded',
            'status' => 'cancelled',
        ]);
        OrderItem::create([
            'order_id' => $cancelled->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'content_link' => 'https://example.com/article.docx',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Cancelled Only']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('New · No ratings yet', $html);
        $this->assertStringContainsString('0% completed', $html);
        $this->assertStringNotContainsString('site-trust-compact__stars', $html);
        $this->assertStringNotContainsString('5.0', $html);
    }

    public function test_rated_site_shows_score_count_and_completion_rate(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Rated Trust Site',
            'site_url' => 'https://rated-trust.example',
            'domain' => 'rated-trust.example',
            'rating_avg' => 4.5,
            'rating_count' => 12,
            'completed_orders_count' => 9,
        ]);

        $completed = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        OrderItem::create([
            'order_id' => $completed->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'content_link' => 'https://example.com/article.docx',
        ]);

        $cancelled = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'refunded',
            'status' => 'cancelled',
        ]);
        OrderItem::create([
            'order_id' => $cancelled->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'content_link' => 'https://example.com/article.docx',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Rated Trust']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('4.5', $html);
        $this->assertStringContainsString('12 ratings', $html);
        $this->assertStringContainsString('90% completed', $html);
        $this->assertStringContainsString('fa-star-half-stroke', $html);
        $this->assertStringNotContainsString('12 completed', $html);
    }

    public function test_rating_min_and_has_completions_filters_and_sort_are_wired(): void
    {
        $this->assertContains('rating_min', CatalogUrlQuery::KEYS);
        $this->assertContains('has_completions', CatalogUrlQuery::KEYS);

        $low = $this->makeSite([
            'site_name' => 'Low Rated Filter',
            'site_url' => 'https://low-rated.example',
            'domain' => 'low-rated.example',
            'rating_avg' => 2.5,
            'rating_count' => 2,
            'completed_orders_count' => 0,
        ]);
        $high = $this->makeSite([
            'site_name' => 'High Rated Filter',
            'site_url' => 'https://high-rated.example',
            'domain' => 'high-rated.example',
            'rating_avg' => 4.8,
            'rating_count' => 5,
            'completed_orders_count' => 3,
        ]);

        $minHtml = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['rating_min' => '4']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('High Rated Filter', $minHtml);
        $this->assertStringNotContainsString('Low Rated Filter', $minHtml);

        $doneHtml = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['has_completions' => '1']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('High Rated Filter', $doneHtml);
        $this->assertStringNotContainsString('Low Rated Filter', $doneHtml);

        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));
        $this->assertStringContainsString('value="rating_desc"', $blade);
        $this->assertStringContainsString('name="rating_min"', $blade);
        $this->assertStringContainsString('name="has_completions"', $blade);

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString("'rating_min'", $js);
        $this->assertStringContainsString("'has_completions'", $js);

        unset($low, $high);
    }

    public function test_shared_trust_partial_is_the_single_source(): void
    {
        $partial = (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-site-trust.blade.php')
        );
        $results = (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-results.blade.php')
        );
        $shell = (string) file_get_contents(
            resource_path('views/advertiser/catalog.blade.php')
        );

        $this->assertStringContainsString('completionRatePercent()', $partial);
        $this->assertStringContainsString('No ratings yet', $partial);
        $this->assertStringContainsString("@include('advertiser.partials.catalog-site-trust'", $results);
        // Full page shell must not re-inline results/trust — live + SSR share catalog-results.
        $this->assertStringContainsString("@include('advertiser.partials.catalog-results')", $shell);
        $this->assertStringNotContainsString("@include('advertiser.partials.catalog-site-trust'", $shell);
        $this->assertStringNotContainsString('No completions yet', $partial);
    }
}
