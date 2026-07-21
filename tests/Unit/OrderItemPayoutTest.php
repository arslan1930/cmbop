<?php

namespace Tests\Unit;

use App\Models\OrderItem;
use PHPUnit\Framework\TestCase;

class OrderItemPayoutTest extends TestCase
{
    public function test_publisher_receives_snapshotted_base_and_platform_keeps_fee(): void
    {
        $item = new OrderItem([
            'price' => 113.00,
            'additional_price' => 0,
            'publisher_price' => 100.00,
            'platform_fee_percent' => 13.00,
            'platform_fee_amount' => 13.00,
        ]);

        $this->assertSame(100.00, $item->publisherBasePrice());
        $this->assertSame(100.00, $item->publisherPayoutAmount());
        $this->assertSame(13.00, $item->platformFeeAmount());
    }

    public function test_sensitive_add_on_is_not_marked_up_and_goes_to_publisher(): void
    {
        // Advertiser pays: (100 * 1.13) + 20 = 133
        $item = new OrderItem([
            'price' => 133.00,
            'additional_price' => 20.00,
            'sensitive_type' => 'casino',
            'publisher_price' => 100.00,
            'platform_fee_percent' => 13.00,
            'platform_fee_amount' => 13.00,
        ]);

        $this->assertSame(100.00, $item->publisherBasePrice());
        $this->assertSame(120.00, $item->publisherPayoutAmount());
        $this->assertSame(13.00, $item->platformFeeAmount());
    }

    public function test_legacy_rows_without_snapshot_use_flat_fifteen_percent(): void
    {
        $item = new OrderItem([
            'price' => 115.00,
            'additional_price' => 0,
            'publisher_price' => null,
            'platform_fee_amount' => null,
        ]);

        $this->assertSame(100.00, $item->publisherBasePrice());
        $this->assertSame(100.00, $item->publisherPayoutAmount());
        $this->assertSame(15.00, $item->platformFeeAmount());
    }
}
