<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Site;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\LinkPresenceChecker;
use App\Services\Marketplace\PublisherReliabilityService;
use App\Services\Marketplace\WritingFeeService;
use App\Support\EmailCatalog;
use App\Support\PublicationDuration;
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
            'publication_time' => 'permanent',
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

    public function test_slb_writing_is_flat_and_stays_off_publisher_amount(): void
    {
        $site = new Site([
            'language' => 'de',
            'writing_enabled' => false,
            'writing_fee' => 25,
            'listing_writing_fee' => 25,
            'listing_currency' => 'EUR',
            'price' => 100,
            'publication_time' => 'permanent',
        ]);
        $site->exists = true;
        $site->id = 10;

        $decorated = app(CheckoutAddOnService::class)->decorateOrderItem(
            [
                'id' => 10,
                'site' => $site,
                'price' => 113.0,
                'publisher_amount' => 100.0,
            ],
            [
                'id' => 10,
                'content_source' => WritingFeeService::SOURCE_SLB,
            ],
            true
        );

        $this->assertSame(WritingFeeService::SOURCE_SLB, $decorated['content_source']);
        $this->assertEquals(WritingFeeService::SLB_FLAT_EUR, $decorated['writing_fee']);
        $this->assertEquals(0.0, $decorated['listing_writing_fee']);
        $this->assertEquals(100.0, $decorated['publisher_amount']);
        $this->assertTrue($decorated['link_guarantee']);
        $this->assertEquals(148.0, $decorated['link_guarantee_covered_amount']);
        $this->assertEquals(14.8, $decorated['link_guarantee_fee']);
        $this->assertEquals(162.8, $decorated['price']);
    }

    public function test_guarantee_terms_are_plain_and_not_exaggerated(): void
    {
        $text = app(CheckoutAddOnService::class)->termsText();

        $this->assertStringContainsString('12-month', $text);
        $this->assertStringContainsString('7 days', $text);
        $this->assertStringContainsString('refund', $text);
        $this->assertStringContainsString('publishers pay nothing', strtolower($text));
        $this->assertStringContainsString('not a ranking', $text);
        $this->assertStringContainsString('seolinkbuildings', strtolower($text));
        $this->assertStringContainsString('6-month', strtolower($text));
        $this->assertStringContainsString('permanent', strtolower($text));
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

    public function test_decorate_cart_line_uses_publisher_fee_or_slb_flat(): void
    {
        $publisherSite = new Site([
            'language' => 'en',
            'writing_enabled' => true,
            'writing_fee' => 40,
            'listing_writing_fee' => 40,
            'listing_currency' => 'EUR',
            'price' => 100,
            'publication_time' => 'permanent',
        ]);
        $publisherSite->exists = true;
        $publisherSite->id = 21;

        $slbSite = new Site([
            'language' => 'de',
            'writing_enabled' => false,
            'writing_fee' => 25,
            'listing_writing_fee' => 25,
            'listing_currency' => 'EUR',
            'price' => 100,
            'publication_time' => 'permanent',
        ]);
        $slbSite->exists = true;
        $slbSite->id = 22;

        $addons = app(CheckoutAddOnService::class);
        $publisher = $addons->decorateCartLine(['id' => 21, 'content_source' => WritingFeeService::SOURCE_WRITTEN], $publisherSite);
        $slb = $addons->decorateCartLine(['id' => 22, 'content_source' => WritingFeeService::SOURCE_WRITTEN], $slbSite);

        $this->assertSame(WritingFeeService::SOURCE_PUBLISHER, $publisher['content_source']);
        $this->assertEquals(40.0, $publisher['writing_fee']);
        $this->assertTrue($publisher['writing_offered']);
        $this->assertArrayNotHasKey('listing_writing_fee', $publisher);
        $this->assertArrayNotHasKey('publisher_price', $publisher);

        $this->assertSame(WritingFeeService::SOURCE_SLB, $slb['content_source']);
        $this->assertEquals(WritingFeeService::SLB_FLAT_EUR, $slb['writing_fee']);
        $this->assertFalse($slb['writing_offered']);
        $this->assertArrayNotHasKey('listing_writing_fee', $slb);
    }

    public function test_link_guarantee_is_ignored_when_publication_period_is_short(): void
    {
        $site = new Site([
            'language' => 'en',
            'writing_enabled' => true,
            'writing_fee' => 25,
            'listing_writing_fee' => 25,
            'listing_currency' => 'EUR',
            'price' => 100,
            'publication_time' => '6months',
        ]);
        $site->exists = true;
        $site->id = 11;

        $decorated = app(CheckoutAddOnService::class)->decorateOrderItem(
            [
                'id' => 11,
                'site' => $site,
                'price' => 113.0,
                'publisher_amount' => 100.0,
            ],
            [
                'id' => 11,
                'content_source' => WritingFeeService::SOURCE_BUYER,
            ],
            true
        );

        $this->assertFalse($decorated['link_guarantee']);
        $this->assertEquals(0.0, $decorated['link_guarantee_fee']);
        $this->assertEquals(113.0, $decorated['price']);
        $this->assertFalse($decorated['link_guarantee_offered']);
    }

    public function test_publication_duration_qualifies_for_link_guarantee(): void
    {
        $this->assertFalse(PublicationDuration::qualifiesForLinkGuarantee('6months'));
        $this->assertFalse(PublicationDuration::qualifiesForLinkGuarantee('7 days'));
        $this->assertFalse(PublicationDuration::qualifiesForLinkGuarantee(null));
        $this->assertTrue(PublicationDuration::qualifiesForLinkGuarantee('1year'));
        $this->assertTrue(PublicationDuration::qualifiesForLinkGuarantee('2years'));
        $this->assertTrue(PublicationDuration::qualifiesForLinkGuarantee('3years'));
        $this->assertTrue(PublicationDuration::qualifiesForLinkGuarantee('permanent'));
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

        $workflow = file_get_contents(base_path('database/sql/add_article_writing_workflow.sql'));
        $this->assertIsString($workflow);
        $this->assertStringStartsWith('USE u841082722_cmbop_db;', trim($workflow));
        $this->assertStringContainsString('writing_status', $workflow);
        $this->assertStringContainsString('link_guarantee_publisher_choice', $workflow);
    }
}
