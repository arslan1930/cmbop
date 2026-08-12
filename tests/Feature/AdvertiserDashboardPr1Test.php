<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\AdvertiserDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvertiserDashboardPr1Test extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dash Test Site',
            'site_url' => 'https://dash-test.example',
            'domain' => 'dash-test.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'de',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, array $attrs = []): Order
    {
        $publisher = User::factory()->create();
        $site = $this->siteFor($publisher);

        return DB::transaction(function () use ($advertiser, $site, $attrs) {
            $order = Order::create(array_merge([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-'.uniqid(),
                'reference_code' => 'REF-'.uniqid(),
                'subtotal' => 100,
                'tax' => 0,
                'total_amount' => 100,
                'payment_method' => 'card',
                'payment_status' => 'paid',
                'status' => 'processing',
                'paid_at' => now(),
            ], $attrs));

            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => (float) ($attrs['total_amount'] ?? 100),
                'additional_price' => 0,
                'content_link' => 'https://example.com/a.docx',
                'live_url' => $attrs['live_url'] ?? null,
            ]);

            return $order->fresh(['items']);
        });
    }

    public function test_unpaid_pending_does_not_inflate_in_progress(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, [
            'status' => 'pending',
            'payment_status' => 'pending',
            'paid_at' => null,
            'payment_method' => 'card',
        ]);
        $this->makeOrder($user, [
            'status' => 'processing',
            'payment_status' => 'paid',
            'total_amount' => 55,
        ]);

        $stats = app(AdvertiserDashboardService::class)->orderStats($user->id);
        $this->assertSame(1, $stats['in_progress']);
        $this->assertSame(1, $stats['awaiting_payment']);
        $this->assertSame(1, $stats['total']); // completed(0)+in_progress(1)+needs_review(0)

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk();
    }

    public function test_needs_action_banner_when_review_has_live_url(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, [
            'status' => 'review',
            'payment_status' => 'paid',
            'live_url' => 'https://live.example/post',
        ]);

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('your attention', false)
            ->assertSee('Open orders', false);
    }

    public function test_dashboard_uses_controller_not_closure(): void
    {
        $user = $this->advertiser();

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Welcome back', false);
    }
}
