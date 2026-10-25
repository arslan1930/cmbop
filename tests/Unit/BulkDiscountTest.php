<?php

namespace Tests\Unit;

use App\Support\BulkDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BulkDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_joins_set_tiers_with_middle_dots(): void
    {
        $this->assertSame(
            '3 for −10% · 5 for −15% · 10 for −25%',
            BulkDiscount::label([3 => 10, 5 => 15, 10 => 25])
        );
        $this->assertSame('5 for −15%', BulkDiscount::label([5 => 15]));
        $this->assertNull(BulkDiscount::label([]));
    }

    public function test_config_pack_max_qty_is_ten(): void
    {
        $this->assertSame(10, (int) config('site_promotions.bulk.max_qty'));
        $this->assertSame(10, BulkDiscount::maxQty());
    }

    public function test_normalize_clamps_and_requires_monotonic_tiers(): void
    {
        $this->assertSame(
            [3 => 10.0, 5 => 80.0, 10 => null],
            BulkDiscount::normalizeTiers([3 => 5, 5 => 90, 10 => null])
        );

        try {
            BulkDiscount::normalizeTiers([3 => 20, 5 => 15, 10 => null]);
            $this->fail('Expected ValidationException for a lower 5-article rate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percent_5', $e->errors());
        }

        try {
            BulkDiscount::normalizeTiers([3 => 20, 5 => null, 10 => 10]);
            $this->fail('Expected ValidationException for a lower 10-article rate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percent_10', $e->errors());
        }
    }
}
