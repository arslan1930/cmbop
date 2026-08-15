<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ContentUpload\ScheduledOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdvertiserScheduledOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        Role::firstOrCreate(['name' => 'publisher']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisherWithSite(): array
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($role->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Sched UX Site',
            'site_url' => 'https://sched-ux.example',
            'domain' => 'sched-ux.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Scheduled UX test site',
            'verified' => true,
            'active' => true,
        ]);

        return [$publisher, $site];
    }

    private function scheduledOrder(User $advertiser, Site $site, array $attrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addWeek(),
            'schedule_timezone' => 'Europe/Berlin',
            'paid_at' => now(),
        ], $attrs));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article',
            'price' => 40,
        ]);

        return $order->fresh(['items.site']);
    }

    public function test_index_splits_upcoming_with_publisher_and_history(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();

        $upcoming = $this->scheduledOrder($advertiser, $site, [
            'order_number' => '111111',
            'scheduled_publish_at' => now()->addDays(5),
        ]);
        $withPublisher = $this->scheduledOrder($advertiser, $site, [
            'order_number' => '222222',
            'scheduled_publish_at' => now()->subHour(),
            'schedule_released_at' => now()->subHour(),
        ]);
        $history = $this->scheduledOrder($advertiser, $site, [
            'order_number' => '333333',
            'status' => 'cancelled',
            'scheduled_publish_at' => now()->subDays(2),
        ]);

        $upcomingPage = $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'upcoming']))
            ->assertOk()
            ->assertSee('Upcoming')
            ->assertSee('#'.$upcoming->order_number)
            ->assertDontSee('#'.$withPublisher->order_number)
            ->assertDontSee('#'.$history->order_number)
            ->assertSee('Funds held · refunded on cancel')
            ->assertSee('focus=order', false)
            ->assertSee('order='.$upcoming->id, false);
        $upcomingHtml = $upcomingPage->getContent();
        $this->assertStringContainsString(
            'action="'.route('advertiser.scheduled-orders.update', $upcoming, false).'"',
            $upcomingHtml
        );
        $this->assertStringNotContainsString(
            'action="'.route('advertiser.scheduled-orders.update', $upcoming).'"',
            $upcomingHtml
        );

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'with_publisher']))
            ->assertOk()
            ->assertSee('#'.$withPublisher->order_number)
            ->assertSee('Waiting on publisher')
            ->assertDontSee('#'.$upcoming->order_number);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'history']))
            ->assertOk()
            ->assertSee('#'.$history->order_number)
            ->assertDontSee('#'.$upcoming->order_number);
    }

    public function test_empty_upcoming_shows_catalog_and_library_ctas(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'upcoming']))
            ->assertOk()
            ->assertSee('No upcoming scheduled publications.')
            ->assertSee(route('advertiser.catalog', [], false), false)
            ->assertSee(route('advertiser.content-library', [], false), false);
    }

    public function test_publish_now_notifies_publisher_and_moves_to_with_publisher(): void
    {
        Mail::fake();
        $advertiser = $this->advertiser();
        [$publisher, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'publish_now'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertNotNull($order->schedule_released_at);
        $this->assertSame('scheduled', $order->publication_mode);
        $this->assertNotNull($order->scheduled_publish_at);

        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $publisher->id)
                ->where('title', 'like', 'Publish today%')
                ->exists()
        );

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'with_publisher']))
            ->assertOk()
            ->assertSee('#'.$order->order_number);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'upcoming']))
            ->assertOk()
            ->assertDontSee('#'.$order->order_number);
    }

    public function test_cannot_cancel_or_reschedule_after_release(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site, [
            'schedule_released_at' => now()->subMinute(),
            'scheduled_publish_at' => now()->subMinute(),
        ]);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pending', $order->fresh()->status);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), [
                'action' => 'reschedule',
                'scheduled_date' => now()->addDays(10)->toDateString(),
                'scheduled_time' => '10:00',
                'timezone' => 'UTC',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_reschedule_updates_upcoming_order_timezone(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site);

        $newDate = now('Europe/Paris')->addDays(12)->toDateString();

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), [
                'action' => 'reschedule',
                'scheduled_date' => $newDate,
                'scheduled_time' => '14:30',
                'timezone' => 'Europe/Paris',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('Europe/Paris', $order->schedule_timezone);
        $this->assertSame(
            $newDate,
            $order->scheduled_publish_at->copy()->timezone('Europe/Paris')->toDateString()
        );
        $this->assertNull($order->schedule_reminder_sent_at);
    }

    public function test_dashboard_and_nav_surface_upcoming_count(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $this->scheduledOrder($advertiser, $site);
        $this->scheduledOrder($advertiser, $site, [
            'order_number' => '444444',
            'scheduled_publish_at' => now()->addDays(3),
        ]);

        Wallet::firstOrCreate(
            ['user_id' => $advertiser->id, 'role_id' => Wallet::advertiserRoleId()],
            ['balance' => 0, 'reserved_balance' => 0, 'currency' => 'EUR']
        );

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('id="dashUpcomingScheduledAction"', false)
            ->assertSee('2 publications waiting', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('title="Upcoming scheduled orders"', false)
            ->assertSee('>2</span>', false);
    }

    public function test_publish_now_requires_paid_payment(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site, [
            'payment_status' => 'pending',
        ]);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'publish_now'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($order->fresh()->schedule_released_at);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'upcoming']))
            ->assertOk()
            ->assertDontSee('>Publish now</button>', false);
    }

    public function test_with_publisher_review_phase_label(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site, [
            'status' => 'review',
            'schedule_released_at' => now()->subHour(),
            'scheduled_publish_at' => now()->subHour(),
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'with_publisher']))
            ->assertOk()
            ->assertSee('#'.$order->order_number)
            ->assertSee('Needs your review')
            ->assertDontSee('Waiting on publisher');
    }

    public function test_unpaid_past_due_stays_cancellable_in_upcoming(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site, [
            'payment_status' => 'pending',
            'scheduled_publish_at' => now()->subDay(),
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'upcoming']))
            ->assertOk()
            ->assertSee('#'.$order->order_number);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'with_publisher']))
            ->assertOk()
            ->assertDontSee('#'.$order->order_number);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_reschedule_accepts_max_day_evening_in_western_timezone(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site, [
            'schedule_timezone' => 'America/Los_Angeles',
        ]);

        $scheduler = app(ScheduledOrderService::class);
        $maxLocalDate = $scheduler->maxScheduleDateString('America/Los_Angeles');

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), [
                'action' => 'reschedule',
                'scheduled_date' => $maxLocalDate,
                'scheduled_time' => '20:00',
                'timezone' => 'America/Los_Angeles',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('America/Los_Angeles', $order->schedule_timezone);
        $this->assertSame(
            $maxLocalDate,
            $order->scheduled_publish_at->copy()->timezone('America/Los_Angeles')->toDateString()
        );
    }

    public function test_accepted_or_review_moves_out_of_upcoming(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();

        $processing = $this->scheduledOrder($advertiser, $site, [
            'order_number' => '555555',
            'status' => 'processing',
            'scheduled_publish_at' => now()->addWeek(),
        ]);
        $review = $this->scheduledOrder($advertiser, $site, [
            'order_number' => '666666',
            'status' => 'review',
            'scheduled_publish_at' => now()->addDays(3),
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'upcoming']))
            ->assertOk()
            ->assertDontSee('#'.$processing->order_number)
            ->assertDontSee('#'.$review->order_number);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'with_publisher']))
            ->assertOk()
            ->assertSee('#'.$processing->order_number)
            ->assertSee('#'.$review->order_number)
            ->assertSee('Needs your review');

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $processing), ['action' => 'cancel'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('processing', $processing->fresh()->status);
    }

    public function test_cancel_after_release_is_blocked_under_lock(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site);

        // Simulate a concurrent release winning the race before cancel runs.
        $order->update(['schedule_released_at' => now()]);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_release_due_skips_orders_already_in_review(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();

        $pendingDue = $this->scheduledOrder($advertiser, $site, [
            'order_number' => '777777',
            'scheduled_publish_at' => now()->subHour(),
        ]);
        $reviewDue = $this->scheduledOrder($advertiser, $site, [
            'order_number' => '888888',
            'status' => 'review',
            'scheduled_publish_at' => now()->subHour(),
        ]);

        $released = app(ScheduledOrderService::class)->releaseDueOrders();

        $this->assertTrue($released->contains('id', $pendingDue->id));
        $this->assertFalse($released->contains('id', $reviewDue->id));
        $this->assertNotNull($pendingDue->fresh()->schedule_released_at);
        $this->assertNull($reviewDue->fresh()->schedule_released_at);
    }

    public function test_with_publisher_tab_hides_edit_actions(): void
    {
        $advertiser = $this->advertiser();
        [, $site] = $this->publisherWithSite();
        $order = $this->scheduledOrder($advertiser, $site, [
            'schedule_released_at' => now(),
            'scheduled_publish_at' => now()->subHour(),
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.scheduled-orders', ['tab' => 'with_publisher']))
            ->assertOk()
            ->assertSee('#'.$order->order_number)
            ->assertDontSee('name="action" value="cancel"', false)
            ->assertDontSee('Publish now');
    }
}
