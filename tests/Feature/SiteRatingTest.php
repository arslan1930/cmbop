<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SiteRatingTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Rated Site',
            'site_url' => 'https://rated.example',
            'domain' => 'rated.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 50,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function completedOrderItem(User $advertiser, Site $site, string $status = 'completed'): OrderItem
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $status,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ]);
    }

    public function test_rating_requires_completed_order(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'review');

        $this->actingAs($advertiser)->postJson(route('advertiser.ratings.store'), [
            'order_item_id' => $item->id,
            'rating' => 5,
        ])->assertStatus(422);
    }

    public function test_advertiser_can_rate_after_completed_order(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');

        $this->actingAs($advertiser)->postJson(route('advertiser.ratings.store'), [
            'order_item_id' => $item->id,
            'rating' => 5,
            'comment' => 'Great publisher',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('site_ratings', [
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_item_id' => $item->id,
            'rating' => 5,
            'status' => 'approved',
        ]);

        $site->refresh();
        $this->assertSame(5.0, (float) $site->rating_avg);
        $this->assertSame(1, (int) $site->rating_count);
    }

    public function test_completed_orders_count_refreshes(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $this->completedOrderItem($advertiser, $site, 'completed');
        $this->completedOrderItem($advertiser, $site, 'completed');

        Site::refreshCompletedOrdersCount($site->id);
        $site->refresh();

        $this->assertSame(2, (int) $site->completed_orders_count);
        $this->assertSame('2 completed orders', $site->completedOrdersLabel());
    }

    public function test_sites_table_has_completed_orders_count_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('sites', 'completed_orders_count'),
            'sites.completed_orders_count must exist so order approval can refresh the counter'
        );
    }

    public function test_refresh_completed_orders_count_is_safe_without_column(): void
    {
        // Simulate a deploy that has not migrated the counter yet.
        Schema::table('sites', function ($table) {
            $table->dropColumn('completed_orders_count');
        });

        $this->assertFalse(Site::hasSitesColumn('completed_orders_count'));

        Site::refreshCompletedOrdersCount(1); // must not throw

        // Restore for later tests in this class / process.
        Schema::table('sites', function ($table) {
            $table->unsignedInteger('completed_orders_count')->default(0);
        });
    }

    public function test_admin_can_hide_rating_and_aggregate_excludes_it(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');

        $rating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'rating' => 5,
            'status' => SiteRating::STATUS_APPROVED,
        ]);
        SiteRating::refreshSiteAggregate($site->id);

        $this->actingAs($admin)->putJson(route('admin.site-ratings.update', $rating->id), [
            'status' => 'hidden',
        ])->assertOk();

        $site->refresh();
        $this->assertSame(0, (int) $site->rating_count);
    }
}
