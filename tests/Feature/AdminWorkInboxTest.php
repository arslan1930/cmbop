<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWorkInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_inbox_lists_open_disputes_and_pending_community(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Inbox Dispute Site',
            'site_url' => 'https://inbox-dispute.example',
            'domain' => 'inbox-dispute.example',
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
            'description' => 'Inbox site',
            'verified' => true,
            'active' => true,
        ]);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-INBOX-1',
            'reference_code' => 'REF-INBOX-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
            'live_url' => 'https://inbox-dispute.example/live',
        ]);
        OrderItemDispute::ensureTable();
        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live article was taken down after approval.',
        ]);
        ProblemReport::create([
            'name' => 'Inbox Reporter',
            'email' => 'inbox-ops@example.com',
            'subject' => 'Checkout stuck',
            'message' => 'Cannot finish paying',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertSee('Work inbox', false)
            ->assertSee('ORD-INBOX-1', false)
            ->assertSee('Live article was taken down after approval.', false)
            ->assertSee('Checkout stuck', false)
            ->assertSee(route('admin.orders.show', $order->id).'#order-disputes', false)
            ->assertSee(e(route('admin.community.index', ['tab' => 'problems', 'status' => 'pending'])), false);

        $this->actingAs($admin)
            ->get(route('admin.inbox.index', ['tab' => 'disputes']))
            ->assertOk()
            ->assertSee('ORD-INBOX-1', false)
            ->assertDontSee('Checkout stuck', false);

        $this->actingAs($this->userWithRole('marketing'))
            ->get(route('admin.inbox.index'))
            ->assertRedirect(route('marketing.dashboard'));
    }
}
