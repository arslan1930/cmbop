<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\CartPricingService;
use PHPUnit\Framework\TestCase;

class CartPricingServiceTest extends TestCase
{
    private CartPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new CartPricingService();
    }

    public function test_advertiser_price_includes_platform_markup(): void
    {
        $site = new Site([
            'site_name' => 'Example',
            'price' => 100,
            'sensitive_prices' => null,
        ]);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(115.0, $result['base']);
        $this->assertSame(0.0, $result['additional']);
        $this->assertSame(115.0, $result['total']);
        $this->assertNull($result['sensitive_type']);
    }

    public function test_sensitive_add_on_comes_from_site_config_not_client(): void
    {
        $site = new Site([
            'site_name' => 'Example',
            'price' => 100,
            'sensitive_prices' => ['casino' => 25],
        ]);

        $result = $this->pricing->priceForAdvertiser($site, 'casino');

        $this->assertSame(115.0, $result['base']);
        $this->assertSame(25.0, $result['additional']);
        $this->assertSame(140.0, $result['total']);
        $this->assertSame('casino', $result['sensitive_type']);
    }

    public function test_invalid_sensitive_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $site = new Site([
            'site_name' => 'Example',
            'price' => 100,
            'sensitive_prices' => ['casino' => 25],
        ]);

        $this->pricing->priceForAdvertiser($site, 'cbd');
    }
}
