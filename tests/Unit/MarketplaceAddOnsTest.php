<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Site;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\LinkPresenceChecker;
use App\Services\Marketplace\PublisherReliabilityService;
use App\Services\Marketplace\WritingFeeService;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceAddOnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_writing_fee_defaults_by_language(): void
    {
        $fees = new WritingFeeService;

        $this->assertSame(25.0, $fees->defaultEurosForLanguage('en'));
        $this->assertSame(25.0, $fees->defaultEurosForLanguage('EN'));
        $this->assertSame(35.0, $fees->defaultEurosForLanguage('de'));
        $this->assertSame(35.0, $fees->defaultEurosForLanguage(null));
    }

    public function test_writing_offered_defaults_true_when_unset(): void
    {
        $site = new Site(['language' => 'en']);
        $site->syncOriginal();

        $this->assertTrue((new WritingFeeService)->writingOffered($site));

        $site->writing_enabled = false;
        $this->assertFalse((new WritingFeeService)->writingOffered($site));
    }

    public function test_checkout_addon_adds_writing_fee_and_advertiser_only_guarantee(): void
    {
        $site = new Site([
            'language' => 'en',
            'writing_enabled' => true,
            'writing_fee' => 25,
            'listing_writing_fee' => 25,
            'listing_currency' => 'EUR',
            'price' => 100,
        ]);
        $site->exists = true;
        $site->id = 9;

        $addons = app(CheckoutAddOnService::class);
        $decorated = $addons->decorateOrderItem(
            [
                'id' => 9,
                'site' => $site,
                'price' => 113.0,
                'publisher_amount' => 100.0,
            ],
            [
                'id' => 9,
                'content_source' => WritingFeeService::SOURCE_PUBLISHER,
            ],
            true
        );

        $this->assertSame(WritingFeeService::SOURCE_PUBLISHER, $decorated['content_source']);
        $this->assertEquals(25.0, $decorated['writing_fee']);
        $this->assertEquals(25.0, $decorated['listing_writing_fee']);
        $this->assertEquals(125.0, $decorated['publisher_amount']);
        $this->assertTrue($decorated['link_guarantee']);
        $this->assertEquals(138.0, $decorated['link_guarantee_covered_amount']);
        $this->assertEquals(13.8, $decorated['link_guarantee_fee']);
        $this->assertEquals(151.8, $decorated['price']);
        $this->assertSame('pending', $addons->orderColumns($decorated)['link_guarantee_status']);
    }

    public function test_guarantee_terms_are_plain_and_not_exaggerated(): void
    {
        $text = app(CheckoutAddOnService::class)->termsText();

        $this->assertStringContainsString('12-month', $text);
        $this->assertStringContainsString('7 days', $text);
        $this->assertStringContainsString('refund', $text);
        $this->assertStringContainsString('publishers pay nothing', strtolower($text));
        $this->assertStringContainsString('not a ranking', $text);
        $this->assertStringNotContainsString('casino', strtolower($text));
    }

    public function test_href_checker_matches_normalized_target(): void
    {
        $checker = new LinkPresenceChecker;
        $html = '<p><a href="https://Advertiser.Example/Offer/">tools</a></p>';

        $this->assertTrue($checker->hrefContainsTarget($html, 'https://advertiser.example/offer'));
        $this->assertTrue($checker->hrefContainsTarget($html, 'http://advertiser.example/offer/'));
        $this->assertFalse($checker->hrefContainsTarget($html, 'https://other.example/offer'));
        $this->assertFalse($checker->hrefContainsTarget('<p>https://advertiser.example/offer</p>', 'https://advertiser.example/offer'));
        $this->assertSame('advertiser.example/offer', $checker->normalizeUrl('https://Advertiser.Example/Offer/'));
    }

    public function test_reliability_buckets_and_badge_thresholds(): void
    {
        $this->assertNull(PublisherReliabilityService::completedBucket(4));
        $this->assertSame('5+', PublisherReliabilityService::completedBucket(5));
        $this->assertSame('10+', PublisherReliabilityService::completedBucket(10));
        $this->assertSame('10+', PublisherReliabilityService::completedBucket(24));
        $this->assertSame('25+', PublisherReliabilityService::completedBucket(25));
        $this->assertSame('50+', PublisherReliabilityService::completedBucket(50));
        $this->assertSame('100+', PublisherReliabilityService::completedBucket(140));
        $this->assertSame(5, PublisherReliabilityService::MIN_SAMPLE_STATS);
        $this->assertSame(10, PublisherReliabilityService::MIN_SAMPLE_BADGE);
    }

    public function test_email_catalog_builds_new_marketplace_mailables(): void
    {
        $restore = EmailCatalog::makeMailable('link_guarantee_restore');
        $alert = EmailCatalog::makeMailable('saved_search_alert');

        $this->assertNotNull($restore);
        $this->assertNotNull($alert);
        $this->assertSame('link_guarantee_restore', EmailCatalog::keyFromSubject('Restore the live link for order #ORD-1'));
        $this->assertSame('saved_search_alert', EmailCatalog::keyFromSubject('New sites match “Sample DE tech DR 40+”'));
    }

    public function test_order_terms_note_is_empty_without_guarantee(): void
    {
        $order = new Order(['link_guarantee' => false]);

        $this->assertSame('', $order->linkGuaranteeTermsNote());
    }

    public function test_hostinger_sql_is_guarded_and_targets_production_schema(): void
    {
        $sql = file_get_contents(base_path('database/sql/add_marketplace_catalog_addons.sql'));

        $this->assertIsString($sql);
        $this->assertStringStartsWith('USE u841082722_cmbop_db;', trim($sql));
        $this->assertStringContainsString('information_schema.COLUMNS', $sql);
        $this->assertStringContainsString('information_schema.TABLES', $sql);
        $this->assertStringContainsString('link_checks', $sql);
        $this->assertStringContainsString('saved_searches', $sql);
        $this->assertStringContainsString('publisher_reliability_stats', $sql);
        $this->assertStringContainsString('writing_fee', $sql);
        $this->assertStringContainsString('link_guarantee', $sql);
    }
}
