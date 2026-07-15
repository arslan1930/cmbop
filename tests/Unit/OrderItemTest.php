<?php

namespace Tests\Unit;

use App\Models\OrderItem;
use Carbon\Carbon;
use Tests\TestCase;

class OrderItemTest extends TestCase
{
    public function test_price_breakdown_without_sensitive_pricing(): void
    {
        $item = new OrderItem([
            'price' => 115.00,
            'additional_price' => 0,
            'sensitive_type' => null,
        ]);

        $this->assertFalse($item->hasSensitivePricing());
        $this->assertEquals(115.00, (float) $item->base_price);
        $breakdown = $item->price_breakdown;
        $this->assertEquals(115.00, (float) $breakdown['base_price']);
        $this->assertEquals(0, $breakdown['additional_price']);
        $this->assertNull($breakdown['sensitive_type']);
        $this->assertEquals(115.00, (float) $breakdown['total_price']);
    }

    public function test_price_breakdown_with_sensitive_pricing(): void
    {
        $item = new OrderItem([
            'price' => 135.00,
            'additional_price' => 20.00,
            'sensitive_type' => 'casino',
        ]);

        $this->assertTrue($item->hasSensitivePricing());
        $this->assertEquals(115.00, (float) $item->base_price);
        $this->assertSame('casino', $item->price_breakdown['sensitive_type']);
        $this->assertEquals(20.00, (float) $item->price_breakdown['additional_price']);
    }

    public function test_null_modification_requested_is_not_treated_as_requested(): void
    {
        $item = new OrderItem([
            'modification_requested' => null,
        ]);

        $this->assertFalse($item->isModificationRequested());
    }

    public function test_is_ready_for_auto_approve_after_48_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        $item = new OrderItem([
            'live_url' => 'https://example.com/post',
            'live_url_submitted_at' => Carbon::parse('2026-07-12 12:00:00'), // 72h before now
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        $this->assertTrue($item->isReadyForAutoApprove());

        Carbon::setTestNow();
    }

    public function test_is_not_ready_for_auto_approve_when_modification_requested(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        $item = new OrderItem([
            'live_url' => 'https://example.com/post',
            'live_url_submitted_at' => Carbon::parse('2026-07-10 12:00:00'),
            'modification_requested' => 'yes',
            'auto_approve_triggered' => false,
        ]);

        $this->assertFalse($item->isReadyForAutoApprove());

        Carbon::setTestNow();
    }

    public function test_is_not_ready_for_auto_approve_before_48_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        $item = new OrderItem([
            'live_url' => 'https://example.com/post',
            'live_url_submitted_at' => Carbon::parse('2026-07-14 12:00:00'),
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        $this->assertFalse($item->isReadyForAutoApprove());

        Carbon::setTestNow();
    }
}
