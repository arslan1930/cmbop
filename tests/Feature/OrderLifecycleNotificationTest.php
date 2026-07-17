<?php

namespace Tests\Feature;

use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderLifecycleNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_order_create_notifies_publisher_after_items_are_attached(): void
    {
        Mail::fake();

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $publisher = User::factory()->create([
            'email' => 'publisher-notify@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Notify Blog',
            'site_url' => 'https://notify-blog.example',
            'domain' => 'notify-blog.example',
            'example_url' => 'https://notify-blog.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Publisher site used for notification testing. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($advertiser, $site) {
            $order = Order::create([
                'user_id' => $advertiser->id,
                'order_number' => '100001',
                'reference_code' => 'REF-NOTIFY-1',
                'subtotal' => 115,
                'tax' => 0,
                'total_amount' => 115,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'status' => 'pending',
                'paid_at' => now(),
            ]);

            // Items attached after Order::create — lifecycle mail must wait for commit
            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'content_link' => 'https://example.com/article',
                'price' => 115,
                'additional_price' => 0,
            ]);
        });

        // PlatformMailable is ShouldQueue (sync connection in tests still hits the queue fake)
        Mail::assertQueued(OrderStatusChanged::class, function (OrderStatusChanged $mail) use ($publisher) {
            return $mail->hasTo($publisher->email);
        });
    }
}
