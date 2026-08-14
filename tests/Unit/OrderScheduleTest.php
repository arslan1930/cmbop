<?php

namespace Tests\Unit;

use App\Models\Order;
use Carbon\Carbon;
use Tests\TestCase;

class OrderScheduleTest extends TestCase
{
    public function test_has_publication_schedule_is_false_for_immediate_orders(): void
    {
        $order = new Order([
            'status' => 'processing',
            'publication_mode' => 'immediate',
        ]);

        $this->assertFalse($order->isScheduled());
        $this->assertFalse($order->hasPublicationSchedule());
        $this->assertFalse($order->isAwaitingScheduledRelease());
        $this->assertSame('UTC', $order->scheduleTimezoneOrUtc());
        $this->assertNull($order->scheduledPublishAtInScheduleTimezone());
    }

    public function test_checkout_utc_timezone_alone_is_not_a_publication_schedule(): void
    {
        $order = new Order([
            'status' => 'pending',
            'publication_mode' => 'immediate',
            'schedule_timezone' => 'UTC',
        ]);

        $this->assertFalse($order->isScheduled());
        $this->assertFalse($order->hasPublicationSchedule());
        $this->assertFalse($order->isAwaitingScheduledRelease());
    }

    public function test_has_publication_schedule_after_release(): void
    {
        $order = new Order([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => Carbon::parse('2026-09-15 14:00:00', 'UTC'),
            'schedule_timezone' => 'Europe/Berlin',
            'schedule_released_at' => Carbon::parse('2026-09-15 14:00:05', 'UTC'),
        ]);

        $this->assertTrue($order->isScheduled());
        $this->assertTrue($order->hasPublicationSchedule());
        $this->assertFalse($order->isAwaitingScheduledRelease());
    }

    public function test_scheduled_publish_at_converts_to_advertiser_timezone(): void
    {
        $order = new Order([
            'status' => 'scheduled',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => Carbon::parse('2026-09-15 14:00:00', 'UTC'),
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $local = $order->scheduledPublishAtInScheduleTimezone();

        $this->assertSame('Europe/Berlin', $order->scheduleTimezoneOrUtc());
        $this->assertNotNull($local);
        $this->assertSame('2026-09-15 16:00:00', $local->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Berlin', $local->timezoneName);
        $this->assertTrue($order->isAwaitingScheduledRelease());
    }

    public function test_cancelled_or_completed_scheduled_order_is_not_awaiting_release(): void
    {
        $cancelled = new Order([
            'status' => 'cancelled',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => Carbon::parse('2026-09-15 14:00:00', 'UTC'),
        ]);
        $completed = new Order([
            'status' => 'completed',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => Carbon::parse('2026-09-15 14:00:00', 'UTC'),
        ]);
        $processing = new Order([
            'status' => 'processing',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => Carbon::parse('2026-09-15 14:00:00', 'UTC'),
        ]);

        $this->assertTrue($cancelled->hasPublicationSchedule());
        $this->assertFalse($cancelled->isAwaitingScheduledRelease());
        $this->assertTrue($completed->hasPublicationSchedule());
        $this->assertFalse($completed->isAwaitingScheduledRelease());
        $this->assertFalse($processing->isAwaitingScheduledRelease());
    }

    public function test_invalid_schedule_timezone_falls_back_to_utc(): void
    {
        $order = new Order([
            'scheduled_publish_at' => Carbon::parse('2026-09-15 14:00:00', 'UTC'),
            'schedule_timezone' => 'Not/AZone',
        ]);

        $local = $order->scheduledPublishAtInScheduleTimezone();

        $this->assertSame('UTC', $order->scheduleTimezoneOrUtc());
        $this->assertNotNull($local);
        $this->assertSame('2026-09-15 14:00:00', $local->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $local->timezoneName);
    }
}
