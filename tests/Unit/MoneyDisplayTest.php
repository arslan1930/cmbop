<?php

namespace Tests\Unit;

use App\Support\CartDisplayFx;
use App\Support\FxRate;
use App\Support\MoneyDisplay;
use App\Support\ViewerCountry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MoneyDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_european_viewer_sees_euros(): void
    {
        $this->withLocalCountry(null);

        $this->assertSame('EUR', app(MoneyDisplay::class)->currency());
        $this->assertSame('€12.00', format_money(12));
        $this->assertSame('€12.00', format_money_pay(12));
        $this->assertFalse(app(MoneyDisplay::class)->displaysUsd());
    }

    public function test_us_viewer_converts_euros_to_usd_not_one_to_one(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);
        $this->withLocalCountry('US');

        $this->assertTrue(app(MoneyDisplay::class)->displaysUsd());
        $this->assertSame('$13.20', format_money(12));
        $this->assertNotSame('$12.00', format_money(12));
    }

    public function test_canada_and_australia_use_us_dollars(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);

        $this->withLocalCountry('CA');
        $this->assertSame('USD', app(MoneyDisplay::class)->currency());
        $this->assertSame('$13.20', format_money(12));

        $this->withLocalCountry('AU');
        $this->assertSame('USD', app(MoneyDisplay::class)->currency());
        $this->assertSame('$13.20', format_money(12));
    }

    public function test_uk_viewer_converts_to_pounds(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.86]);
        $this->withLocalCountry('GB');

        $this->assertSame('GBP', app(MoneyDisplay::class)->currency());
        $this->assertSame('£', app(MoneyDisplay::class)->symbol());
        $this->assertSame('£10.32', format_money(12));
        $this->assertNotSame('£12.00', format_money(12));
    }

    public function test_uk_alias_maps_to_pounds(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.80]);
        config(['fx.fake_country' => 'UK', 'fx.force_display' => '']);

        $this->assertSame('GBP', app(ViewerCountry::class)->displayCurrency());
        $this->assertSame('£9.60', format_money(12));
    }

    public function test_local_cf_header_is_enough_without_cloudflare_peer(): void
    {
        $this->fakeFrankfurter(['USD' => 2.00, 'GBP' => 0.85]);
        config(['fx.fake_country' => '', 'fx.force_display' => '']);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'US',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $this->app->instance('request', $request);

        $this->assertTrue(app(ViewerCountry::class)->isUs($request));
        $this->assertSame('$24.00', app(MoneyDisplay::class)->format(12));
    }

    public function test_local_cf_header_canada_and_uk(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.90]);
        config(['fx.fake_country' => '', 'fx.force_display' => '']);

        $ca = Request::create('/', 'GET', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'CA',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $this->assertSame('USD', app(ViewerCountry::class)->displayCurrency($ca));

        $uk = Request::create('/', 'GET', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'UK',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $this->assertSame('GBP', app(ViewerCountry::class)->displayCurrency($uk));
    }

    public function test_force_display_gbp_override(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);
        config(['fx.fake_country' => '', 'fx.force_display' => 'gbp']);

        $this->assertSame('£10.20', format_money(12));
    }

    public function test_fx_falls_back_when_live_feed_fails(): void
    {
        Http::fake([
            'api.frankfurter.app/*' => Http::response('nope', 503),
            'www.ecb.europa.eu/*' => Http::response('nope', 503),
        ]);
        config([
            'fx.currencies.USD.fallback' => 1.25,
            'fx.force_display' => 'usd',
        ]);

        $this->assertSame(1.25, app(FxRate::class)->usdPerEur());
        $this->assertSame('$15.00', format_money(12));
    }

    public function test_js_payload_includes_currency_and_rate(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);
        $this->withLocalCountry('AU');

        $this->assertSame([
            'currency' => 'USD',
            'usd' => true,
            'rate' => 1.10,
            'cart_rate' => 1.10,
            'symbol' => '$',
            'guide' => true,
        ], money_js());
    }

    public function test_cart_pay_label_locks_rate_when_live_fx_moves(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);
        $this->withLocalCountry('US');
        session([
            'cart' => [['id' => 1, 'price' => 12, 'quantity' => 1]],
        ]);
        app(CartDisplayFx::class)->remember();

        $this->assertSame('€12.00 · about $13.20', format_money_pay(12));
        $this->assertSame('€12.00 · about $24.00', format_money_pay(12, ['rate' => 2.0]));
        $this->assertSame(1.10, app(CartDisplayFx::class)->rate());
        $this->assertSame(1.10, money_js()['cart_rate']);

        app(CartDisplayFx::class)->forget();
        $this->assertNull(app(CartDisplayFx::class)->rate());
    }

    /**
     * @param  array<string, float>  $rates
     */
    public function test_public_ip_country_sets_display_currency_without_cloudflare(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);
        config(['fx.fake_country' => '', 'fx.force_display' => '']);
        $this->app['env'] = 'production';
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['rates' => ['USD' => 1.10, 'GBP' => 0.85]], 200),
            'ipwho.is/*' => Http::response(['success' => true, 'country_code' => 'US'], 200),
        ]);

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
        ]);
        $this->app->instance('request', $request);

        $this->assertSame('US', app(ViewerCountry::class)->code($request));
        $this->assertSame('USD', app(ViewerCountry::class)->displayCurrency($request));
    }

    private function fakeFrankfurter(array $rates): void
    {
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['rates' => $rates], 200),
        ]);
    }

    public function test_listing_to_euros_uses_site_country_not_one_to_one(): void
    {
        $this->fakeFrankfurter(['USD' => 1.10, 'GBP' => 0.85]);
        $this->withLocalCountry(null);

        $money = app(MoneyDisplay::class);
        $this->assertSame('USD', $money->currencyForCountry('us'));
        $this->assertSame('GBP', $money->currencyForCountry('uk'));
        $this->assertSame('EUR', $money->currencyForCountry('de'));
        $this->assertSame('$', listing_price_symbol('us'));
        $this->assertSame('£', listing_price_symbol('gb'));
        $this->assertSame(12.0, $money->toEuros(13.20, 'USD'));
        $this->assertSame(13.20, $money->fromEuros(12, 'USD'));
        $this->assertSame(12.0, $money->toEuros(12, 'EUR'));
    }

    private function withLocalCountry(?string $country): void
    {
        config([
            'fx.fake_country' => $country ?? '',
            'fx.force_display' => '',
            'fx.currencies.USD.fallback' => 1.08,
        ]);
    }
}
