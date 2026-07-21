<?php

namespace Tests\Unit;

use App\Services\PlatformFeeService;
use PHPUnit\Framework\TestCase;

class PlatformFeeServiceTest extends TestCase
{
    private PlatformFeeService $fees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fees = new PlatformFeeService(PlatformFeeService::defaultTiers());
    }

    public function test_under_one_hundred_uses_fifteen_percent(): void
    {
        $this->assertSame(15.0, $this->fees->feePercentForBase(40));
        $this->assertSame(15.0, $this->fees->feePercentForBase(99.99));
        $this->assertSame(46.0, $this->fees->advertiserBase(40));
        $this->assertSame(114.99, $this->fees->advertiserBase(99.99));
    }

    public function test_one_hundred_to_three_hundred_uses_thirteen_percent(): void
    {
        $this->assertSame(13.0, $this->fees->feePercentForBase(100));
        $this->assertSame(13.0, $this->fees->feePercentForBase(299.99));
        $this->assertSame(113.0, $this->fees->advertiserBase(100));
        $this->assertSame(338.99, $this->fees->advertiserBase(299.99));
    }

    public function test_three_hundred_to_one_thousand_uses_twelve_percent(): void
    {
        $this->assertSame(12.0, $this->fees->feePercentForBase(300));
        $this->assertSame(12.0, $this->fees->feePercentForBase(999.99));
        $this->assertSame(336.0, $this->fees->advertiserBase(300));
    }

    public function test_one_thousand_plus_uses_ten_percent(): void
    {
        $this->assertSame(10.0, $this->fees->feePercentForBase(1000));
        $this->assertSame(1100.0, $this->fees->advertiserBase(1000));
    }

    public function test_sql_expression_covers_tiers(): void
    {
        $sql = $this->fees->advertiserBaseSqlExpression('price');

        $this->assertStringContainsString('WHEN price >= 0 AND price <= 99.99 THEN ROUND(price * 1.15, 2)', $sql);
        $this->assertStringContainsString('WHEN price >= 100 AND price <= 299.99 THEN ROUND(price * 1.13, 2)', $sql);
        $this->assertStringContainsString('WHEN price >= 300 AND price <= 999.99 THEN ROUND(price * 1.12, 2)', $sql);
        $this->assertStringContainsString('WHEN price >= 1000 THEN ROUND(price * 1.1, 2)', $sql);
    }
}
