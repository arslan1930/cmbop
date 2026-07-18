<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\CartPricingService;
use App\Services\PlatformFeeService;
use PHPUnit\Framework\TestCase;

class CartPricingServiceTest extends TestCase
{
    private CartPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new CartPricingService(new PlatformFeeService(PlatformFeeService::defaultTiers()));
    }

    public function test_advertiser_price_includes_tiered_platform_fee(): void
    {
        $site = $this->siteWithoutPromotions(100);

        $result = $this->pricing->priceForAdvertiser($site);

        // €100 → 13% fee → €113
        $this->assertSame(113.0, $result['base']);
        $this->assertSame(0.0, $result['additional']);
        $this->assertSame(113.0, $result['total']);
        $this->assertSame(100.0, $result['publisher_price']);
        $this->assertSame(13.0, $result['platform_fee_percent']);
        $this->assertSame(13.0, $result['platform_fee_amount']);
        $this->assertNull($result['sensitive_type']);
    }

    public function test_low_price_uses_fifteen_percent_fee(): void
    {
        $site = $this->siteWithoutPromotions(40);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(46.0, $result['base']);
        $this->assertSame(15.0, $result['platform_fee_percent']);
        $this->assertSame(40.0, $result['publisher_price']);
    }

    public function test_mid_high_price_uses_twelve_percent_fee(): void
    {
        $site = $this->siteWithoutPromotions(300);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(336.0, $result['base']);
        $this->assertSame(12.0, $result['platform_fee_percent']);
        $this->assertSame(300.0, $result['publisher_price']);
    }

    public function test_high_price_uses_ten_percent_fee(): void
    {
        $site = $this->siteWithoutPromotions(1000);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(1100.0, $result['base']);
        $this->assertSame(10.0, $result['platform_fee_percent']);
        $this->assertSame(1000.0, $result['publisher_price']);
    }

    public function test_sensitive_add_on_comes_from_site_config_not_client(): void
    {
        $site = $this->siteWithoutPromotions(100, ['casino' => 25]);

        $result = $this->pricing->priceForAdvertiser($site, 'casino');

        $this->assertSame(113.0, $result['base']);
        $this->assertSame(25.0, $result['additional']);
        $this->assertSame(138.0, $result['total']);
        $this->assertSame('casino', $result['sensitive_type']);
    }

    public function test_invalid_sensitive_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $site = $this->siteWithoutPromotions(100, ['casino' => 25]);

        $this->pricing->priceForAdvertiser($site, 'cbd');
    }

    /**
     * @param  array<string, float|int>|null  $sensitive
     */
    private function siteWithoutPromotions(float $price, ?array $sensitive = null): Site
    {
        $site = new class extends Site
        {
            public function activeCustomDiscountPercent(): ?float
            {
                return null;
            }

            public function joinsBulkDiscount(): bool
            {
                return false;
            }
        };

        $site->forceFill([
            'site_name' => 'Example',
            'price' => $price,
            'sensitive_prices' => $sensitive,
        ]);

        return $site;
    }
}
