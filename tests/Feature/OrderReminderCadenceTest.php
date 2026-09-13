<?php

namespace Tests\Feature;

use App\Mail\AdminStalledOrderAlert;
use App\Mail\AdvertiserOrderStalledNotice;
use App\Mail\AdvertiserReviewNudge;
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherPublishNudge;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The reminder cadences shorten the wait between payment and a live link, but a
 * reminder system that misfires is worse than none: it trains people to ignore
 * us. So the behaviour worth pinning is mostly about restraint — not nudging a
 * publisher who is inside their own turnaround, not repeating a stage, not
 * sending six emails to one person in a morning.
 */
class OrderReminderCadenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function site(User $publisher, string $turnaround = '3days'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Reminder Site '.uniqid(),
            'site_url' => 'https://reminder-'.uniqid().'.example',
            'domain' => 'reminder-'.uniqid().'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => $turnaround,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Test site for reminders',
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * Reminder mail only.
     *
     * Creating an order fires the usual lifecycle mail, so a raw queued count
     * would measure the fixtures rather than the cadence under test.
     */
    private function assertRemindersQueued(int $expected): void
    {
        $count = collect([
            PublisherAcceptNudge::class,
            PublisherPublishNudge::class,
            AdvertiserReviewNudge::class,
            AdvertiserOrderStalledNotice::class,
        ])->sum(fn (string $class) => Mail::queued($class)->count());

        $this->assertSame($expected, $count, "Expected {$expected} reminder(s), got {$count}.");
    }

    /**
     * @param  array<string, mixed>  $itemExtra
     */
    private function order(User $advertiser, Site $site, string $status, array $itemExtra = [], array $orderExtra = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-REM-'.uniqid(),
            'reference_code' => 'REF-REM-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $status,
            'paid_at' => now(),
        ], $orderExtra));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'publisher_price' => 85,
            'content_link' => 'https://example.com/article.docx',
        ], $itemExtra));

        return $order->fresh('items');
    }

    // —— Prerequisite ————————————————————————————————————————————————

    public function test_accepting_an_order_records_when_the_turnaround_started(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);
        $order = $this->order($this->userWithRole('advertiser'), $site, 'pending');
        $item = $order->items->first();

        $this->actingAs($publisher)
            ->post(route('publisher.orders.accept', $item->id))
            ->assertOk();

        $item->refresh();

        // Without this the publish cadence has no clock to measure against.
        $this->assertNotNull($item->accepted_at);
        $this->assertSame('accepted', $item->publisher_status);
    }

    // —— Turnaround parsing ——————————————————————————————————————————

    public function test_turnaround_time_is_read_from_the_listing_in_every_format(): void
    {
        $publisher = $this->userWithRole('publisher');

        $this->assertSame(24, $this->site($publisher, '24h')->turnaroundHours());
        $this->assertSame(48, $this->site($publisher, '48h')->turnaroundHours());
        $this->assertSame(72, $this->site($publisher, '3days')->turnaroundHours());
        // Older rows hold free text rather than the form's enum values.
        $this->assertSame(168, $this->site($publisher, '7 days')->turnaroundHours());
        $this->assertSame(336, $this->site($publisher, '2 weeks')->turnaroundHours());
        $this->assertNull($this->site($publisher, '')->turnaroundHours());
    }

    // —— Publisher: accept track ————————————————————————————————————

    public function test_publisher_is_chased_when_a_paid_order_sits_unaccepted(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);
        $order = $this->order($this->userWithRole('advertiser'), $site, 'pending', [], [
            'paid_at' => now()->subHours(20),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertQueued(PublisherAcceptNudge::class);
        $this->assertSame(1, (int) $order->items->first()->fresh()->accept_nudge_stage);
    }

    public function test_an_order_paid_minutes_ago_is_left_alone(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [], [
            'paid_at' => now()->subMinutes(30),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertNotQueued(PublisherAcceptNudge::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->accept_nudge_stage);
    }

    public function test_each_accept_stage_fires_only_once(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [], [
            'paid_at' => now()->subHours(20),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();
        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        // Stage 2 is not due until 36h, so the second run must find nothing.
        $this->assertRemindersQueued(1);
        $this->assertSame(1, (int) $order->items->first()->fresh()->accept_nudge_stage);
    }

    public function test_the_last_accept_stage_reaches_an_admin(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [
            'accept_nudge_stage' => 2,
        ], ['paid_at' => now()->subHours(80)]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertQueued(AdminStalledOrderAlert::class, fn ($mail) => $mail->hasTo($admin->email));
        $this->assertSame(3, (int) $order->items->first()->fresh()->accept_nudge_stage);
    }

    public function test_an_upcoming_scheduled_order_is_not_accept_nudged(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [], [
            'paid_at' => now()->subHours(80),
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertNotQueued(PublisherAcceptNudge::class);
        Mail::assertNotQueued(AdminStalledOrderAlert::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->accept_nudge_stage);
    }

    // —— Publisher: publish track ——————————————————————————————————

    public function test_a_publisher_inside_their_own_turnaround_is_not_nagged(): void
    {
        $publisher = $this->userWithRole('publisher');
        // 7-day turnaround, accepted an hour ago: six days of slack left.
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher, '7days'), 'processing', [
            'accepted_at' => now()->subHour(),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertNotQueued(PublisherPublishNudge::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->publish_nudge_stage);
    }

    public function test_a_warning_arrives_before_the_deadline_not_after(): void
    {
        $publisher = $this->userWithRole('publisher');
        // 72h turnaround accepted 60h ago: 12h left, inside the due-soon window.
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher, '3days'), 'processing', [
            'accepted_at' => now()->subHours(60),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertQueued(PublisherPublishNudge::class, fn ($mail) => $mail->stage === 1);
        $this->assertSame(1, (int) $order->items->first()->fresh()->publish_nudge_stage);
    }

    public function test_an_already_late_order_is_not_greeted_with_due_soon(): void
    {
        $publisher = $this->userWithRole('publisher');
        // 24h turnaround accepted four days ago: 72h past the deadline, which is
        // stage 3 on the overdue ladder.
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subDays(4),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        // Telling someone a four-day-late order is "due soon" reads as broken,
        // and a correction next run has already cost the credibility.
        Mail::assertQueued(PublisherPublishNudge::class, fn ($mail) => $mail->stage === 3);
        $this->assertSame(3, (int) $order->items->first()->fresh()->publish_nudge_stage);
    }

    public function test_only_one_reminder_leaves_per_run_however_late_the_order(): void
    {
        $publisher = $this->userWithRole('publisher');
        // Well past the final threshold: a long outage must not fire the whole
        // ladder into someone's inbox at once.
        $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subDays(30),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        $this->assertRemindersQueued(1);
    }

    public function test_the_ladder_stops_once_a_stage_is_spent(): void
    {
        $publisher = $this->userWithRole('publisher');
        // 30h past a 24h deadline earns stage 2 and nothing further until 72h.
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subHours(54),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();
        $this->assertSame(2, (int) $order->items->first()->fresh()->publish_nudge_stage);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();
        $this->assertSame(2, (int) $order->items->first()->fresh()->publish_nudge_stage);
        $this->assertRemindersQueued(1);
    }

    public function test_a_publisher_late_on_several_orders_gets_one_email(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');

        for ($i = 0; $i < 3; $i++) {
            $this->order($advertiser, $this->site($publisher, '24h'), 'processing', [
                'accepted_at' => now()->subHours(60),
                'publish_nudge_stage' => 1,
            ]);
        }

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertQueued(PublisherPublishNudge::class, fn ($mail) => $mail->rows->count() === 3);
        $this->assertRemindersQueued(1);
    }

    public function test_below_the_batch_threshold_one_order_is_chased_at_a_time(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');

        for ($i = 0; $i < 2; $i++) {
            $this->order($advertiser, $this->site($publisher, '24h'), 'processing', [
                'accepted_at' => now()->subHours(60),
                'publish_nudge_stage' => 1,
            ]);
        }

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        // Two overdue orders read better as a single specific email than a table.
        Mail::assertQueued(PublisherPublishNudge::class, fn ($mail) => $mail->rows->count() === 1);
    }

    public function test_a_scheduled_order_is_measured_against_the_requested_date(): void
    {
        $publisher = $this->userWithRole('publisher');
        // Accepted long ago, but the advertiser asked for publication next week.
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subDays(10),
        ], [
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(7),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertNotQueued(PublisherPublishNudge::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->publish_nudge_stage);
    }

    public function test_a_published_order_drops_out_of_the_cadence(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'review', [
            'accepted_at' => now()->subDays(5),
            'live_url' => 'https://example.com/the-post',
            'live_url_submitted_at' => now(),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertNotQueued(PublisherPublishNudge::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->publish_nudge_stage);
    }

    public function test_a_listing_with_no_turnaround_time_creates_no_deadline(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher, ''), 'processing', [
            'accepted_at' => now()->subDays(30),
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        // Nothing was promised, so there is nothing to hold them to.
        Mail::assertNotQueued(PublisherPublishNudge::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->publish_nudge_stage);
    }

    // —— Fatigue cap ————————————————————————————————————————————————

    public function test_one_person_is_not_flooded_across_separate_tracks(): void
    {
        config(['reminders.daily_cap_per_user' => 1]);

        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');

        // Unaccepted on one site, overdue on another: two tracks, one publisher.
        $this->order($advertiser, $this->site($publisher), 'pending', [], [
            'paid_at' => now()->subHours(20),
        ]);
        $this->order($advertiser, $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subHours(60),
            'publish_nudge_stage' => 1,
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        // The cap is the only thing standing between a bad data day and a
        // publisher muting us, so it holds even mid-run.
        $this->assertRemindersQueued(1);
    }

    // —— Advertiser: review track ——————————————————————————————————

    public function test_advertiser_is_nudged_partway_through_the_review_window(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->order($advertiser, $this->site($publisher), 'review', [
            'accepted_at' => now()->subDays(3),
            'live_url' => 'https://example.com/the-post',
            // 72h window, submitted 30h ago: past the 33% mark, before the 48h
            // point where the existing final warning takes over.
            'live_url_submitted_at' => now()->subHours(30),
            'modification_requested' => 'no',
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        Mail::assertQueued(AdvertiserReviewNudge::class, fn ($mail) => $mail->hasTo($advertiser->email));
        $this->assertNotNull($order->items->first()->fresh()->review_nudge_sent_at);
    }

    public function test_the_review_nudge_is_sent_once(): void
    {
        $publisher = $this->userWithRole('publisher');
        $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'review', [
            'live_url' => 'https://example.com/the-post',
            'live_url_submitted_at' => now()->subHours(30),
            'modification_requested' => 'no',
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();
        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        $this->assertRemindersQueued(1);
    }

    public function test_an_advertiser_who_asked_for_changes_is_not_nudged_to_review(): void
    {
        $publisher = $this->userWithRole('publisher');
        $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'review', [
            'live_url' => 'https://example.com/the-post',
            'live_url_submitted_at' => now()->subHours(30),
            // The ball is back with the publisher.
            'modification_requested' => 'yes',
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        Mail::assertNotQueued(AdvertiserReviewNudge::class);
    }

    // —— Advertiser: stalled notice ————————————————————————————————

    public function test_advertiser_is_told_when_their_publisher_runs_late(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        // 24h turnaround, accepted 5 days ago: four days past the deadline.
        $order = $this->order($advertiser, $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subDays(5),
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        Mail::assertQueued(AdvertiserOrderStalledNotice::class, fn ($mail) => $mail->hasTo($advertiser->email));
        $this->assertNotNull($order->items->first()->fresh()->stalled_notice_sent_at);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'audience' => InAppNotification::AUDIENCE_ADVERTISER,
        ]);
    }

    public function test_a_slightly_late_order_does_not_alarm_the_advertiser(): void
    {
        $publisher = $this->userWithRole('publisher');
        // Six hours past a 24h deadline: the publisher is being chased, but this
        // is not yet worth worrying the advertiser about.
        $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subHours(30),
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        Mail::assertNotQueued(AdvertiserOrderStalledNotice::class);
    }

    public function test_the_stalled_notice_is_sent_once_per_order(): void
    {
        $publisher = $this->userWithRole('publisher');
        $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'processing', [
            'accepted_at' => now()->subDays(5),
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();
        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        $this->assertRemindersQueued(1);
    }

    public function test_an_unpaid_order_is_outside_every_cadence(): void
    {
        $publisher = $this->userWithRole('publisher');
        $this->order($this->userWithRole('advertiser'), $this->site($publisher, '24h'), 'pending', [
            'accepted_at' => now()->subDays(5),
        ], ['payment_status' => 'pending', 'paid_at' => null]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();
        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        $this->assertRemindersQueued(0);
    }

    public function test_failed_or_refunded_review_is_not_nudged_as_live_review(): void
    {
        $publisher = $this->userWithRole('publisher');
        $reviewItem = [
            'live_url' => 'https://example.com/the-post',
            'live_url_submitted_at' => now()->subHours(30),
            'modification_requested' => 'no',
        ];

        $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'review', $reviewItem, [
            'payment_status' => 'failed',
        ]);
        $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'review', $reviewItem, [
            'payment_status' => 'refunded',
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        Mail::assertNotQueued(AdvertiserReviewNudge::class);
    }
}
