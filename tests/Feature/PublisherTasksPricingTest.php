<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherTasksPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'publisher']);
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Tasks Pricing Site',
            'site_url' => 'https://tasks-pricing.example',
            'domain' => 'tasks-pricing.example',
            'example_url' => 'https://tasks-pricing.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Tech',
            'categories' => ['Tech'],
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'turnaround_time' => '3days',
            'description' => str_repeat('Tasks pricing site description text. ', 4),
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_tasks_list_splits_base_sensitive_and_homepage_into_you_earn(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'PR-'.uniqid(),
            'subtotal' => 165,
            'total_amount' => 165,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article',
            'price' => 165,
            'publisher_price' => 100,
            'additional_price' => 25,
            'sensitive_type' => 'casino',
            'homepage_days' => 7,
            'homepage_price' => 40,
        ]);

        $this->assertSame(100.0, $item->publisherBasePrice());
        $this->assertSame(165.0, $item->publisherPayoutAmount());

        $row = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.0');

        $this->assertSame($item->id, $row['id']);
        $this->assertEquals(100.0, $row['publisher_base']);
        $this->assertEquals(25.0, $row['additional_price']);
        $this->assertEquals(40.0, $row['homepage_price']);
        $this->assertSame(7, $row['homepage_days']);
        $this->assertEquals(165.0, $row['you_earn']);
        $this->assertEquals(165.0, $row['price']);

        $details = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.details', $item->id))
            ->assertOk()
            ->json('data');
        $this->assertEquals(100.0, $details['publisher_base']);
        $this->assertEquals(165.0, $details['you_earn']);

        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('>You earn<', $blade);
        $this->assertStringContainsString('item.publisher_base', $blade);
        $this->assertStringContainsString('item.you_earn', $blade);
        $this->assertStringContainsString('You earn: €', $blade);
        $this->assertStringContainsString('Homepage', $blade);
        $this->assertStringNotContainsString('var basePrice = parseFloat(item.price) - additionalPrice;', $blade);
        $this->assertStringNotContainsString('>Total Price<', $blade);
        $this->assertStringNotContainsString('Total Amount:', $blade);
        $this->assertStringNotContainsString('Total: €', $blade);
    }
}
