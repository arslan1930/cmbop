<?php

namespace Tests\Unit;

use App\Models\OrderItem;
use PHPUnit\Framework\TestCase;

class OrderItemPayoutTest extends TestCase
{
    public function test_publisher_receives_base_price_and_platform_keeps_markup(): void
    {
        $item = new OrderItem([
            'price' => 115.00,
            'additional_price' => 0,
        ]);

        $this->assertSame(100.00, $item->publisherBasePrice());
        $this->assertSame(100.00, $item->publisherPayoutAmount());
        $this->assertSame(15.00, $item->platformFeeAmount());
    }

    public function test_sensitive_add_on_is_not_marked_up_and_goes_to_publisher(): void
    {
        // Advertiser pays: (100 * 1.15) + 20 = 135
        $item = new OrderItem([
            'price' => 135.00,
            'additional_price' => 20.00,
            'sensitive_type' => 'casino',
        ]);

        $this->assertSame(100.00, $item->publisherBasePrice());
        $this->assertSame(120.00, $item->publisherPayoutAmount());
        $this->assertSame(15.00, $item->platformFeeAmount());
    }
}
