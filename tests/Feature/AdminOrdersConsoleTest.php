<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrdersConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Admin Orders Site',
            'site_url' => 'https://admin-orders.example',
            'domain' => 'admin-orders.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function orderFor(User $advertiser, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-ADMIN-'.uniqid(),
            'reference_code' => 'REF-ADMIN-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
            'modification_requested' => 'no',
        ]);

        return $order->fresh('items');
    }

    public function test_marketing_cannot_access_orders_console(): void
    {
        $marketing = $this->userWithRole('marketing');

        $this->actingAs($marketing)
            ->get(route('admin.orders.index'))
            ->assertStatus(403);

        $this->actingAs($marketing)
            ->getJson(route('admin.orders.data'))
            ->assertStatus(403);
    }

    public function test_advertiser_cannot_access_orders_console(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->get(route('admin.orders.index'))
            ->assertStatus(403);
    }

    public function test_admin_can_list_and_view_order_with_chat(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Please publish this week',
            'is_read' => false,
        ]);

        OrderActivity::create([
            'order_id' => $order->id,
            'actor_id' => $advertiser->id,
            'actor_name' => $advertiser->name,
            'actor_role' => 'advertiser',
            'event' => 'chat.message',
            'title' => 'Message sent',
            'description' => 'Please publish this week',
            'icon' => 'message-circle',
            'badge_color' => 'secondary',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Orders', false);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => $order->order_number]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Please publish this week')
            ->assertSee('Read-only')
            ->assertSee('Message sent')
            ->assertDontSee('chatForm', false);
    }

    public function test_stub_reports_and_settings_routes_are_gone(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get('/admin/reports')->assertNotFound();
        $this->actingAs($admin)->get('/admin/settings')->assertNotFound();
    }
}
