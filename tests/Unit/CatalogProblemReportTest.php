<?php

namespace Tests\Unit;

use App\Models\ProblemReport;
use App\Models\Site;
use App\Support\CatalogProblemReport;
use Tests\TestCase;

class CatalogProblemReportTest extends TestCase
{
    public function test_site_id_does_not_treat_ten_as_one(): void
    {
        $this->assertSame(10, CatalogProblemReport::siteId("Catalog listing\nSite ID: 10\nName: Ten"));
        $this->assertSame(1, CatalogProblemReport::siteId("Catalog listing\nSite ID: 1\nName: One"));
        $this->assertNull(CatalogProblemReport::siteId('No site line here'));
    }

    public function test_user_message_reads_unix_and_windows_line_endings(): void
    {
        $unix = "Catalog listing\nSite ID: 4\n\nWhat they wrote\nhello world";
        $windows = "Catalog listing\r\nSite ID: 4\r\n\r\nWhat they wrote\r\nhello world";

        $this->assertSame('hello world', CatalogProblemReport::userMessage($unix));
        $this->assertSame('hello world', CatalogProblemReport::userMessage($windows));
        $this->assertSame('hello world', CatalogProblemReport::userMessage("What they wrote\nhello world"));
        $this->assertSame('', CatalogProblemReport::userMessage("Catalog listing\nSite ID: 4\nNo marker"));
    }

    public function test_admin_edit_urls_are_not_treated_as_live_pages(): void
    {
        $this->assertTrue(CatalogProblemReport::isAdminSiteEditUrl('https://app.test/admin/sites/12/edit'));
        $this->assertTrue(CatalogProblemReport::isAdminSiteEditUrl('http://localhost/admin/sites/12/edit?tab=metrics'));
        $this->assertFalse(CatalogProblemReport::isAdminSiteEditUrl('https://owned-news.example/admin/sites/12/edit-me'));
        $this->assertFalse(CatalogProblemReport::isAdminSiteEditUrl('https://owned-news.example/'));
    }

    public function test_summaries_use_advertiser_text_and_drop_admin_page_urls(): void
    {
        $report = new ProblemReport([
            'subject' => 'Catalog site: Example',
            'message' => "Catalog listing\nSite ID: 3\n\nWhat they wrote\nadmin-check-orange-ladder",
            'page_url' => 'https://app.test/admin/sites/3/edit',
        ]);
        $report->id = 50;

        $plain = new ProblemReport([
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
            'page_url' => 'https://app.example/checkout',
        ]);
        $plain->id = 51;

        $summaries = CatalogProblemReport::summariesFor([$report, $plain]);

        $this->assertArrayHasKey(50, $summaries);
        $this->assertArrayNotHasKey(51, $summaries);
        $this->assertSame('admin-check-orange-ladder', $summaries[50]['user_message']);
        $this->assertSame(3, $summaries[50]['site_id']);
        $this->assertNull($summaries[50]['live_url']);
        $this->assertNull($summaries[50]['listing_url']);
    }

    public function test_staff_listing_url_is_sites_index_not_edit(): void
    {
        $site = new Site(['publisher_id' => 8]);
        $site->id = 14;
        $url = CatalogProblemReport::staffListingUrl($site);

        $this->assertNotNull($url);
        $this->assertStringContainsString('publisher=8&site=14', $url);
        $this->assertStringNotContainsString('/sites/14/edit', $url);
        $relative = CatalogProblemReport::staffListingUrl($site, false);
        $this->assertSame('/admin/sites?publisher=8&site=14', $relative);
    }

    public function test_is_catalog_from_subject_or_message(): void
    {
        $bySubject = new ProblemReport([
            'subject' => 'Catalog site: Example',
            'message' => 'plain complaint',
        ]);
        $byBody = new ProblemReport([
            'subject' => 'Something else',
            'message' => "Catalog listing\nSite ID: 3\n",
        ]);
        $other = new ProblemReport([
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
        ]);

        $this->assertTrue(CatalogProblemReport::isCatalog($bySubject));
        $this->assertTrue(CatalogProblemReport::isCatalog($byBody));
        $this->assertFalse(CatalogProblemReport::isCatalog($other));
    }

    public function test_sanitize_strips_tags_and_caps_length(): void
    {
        $this->assertSame('safe', CatalogProblemReport::sanitizePlain('<b>safe</b>', 80));
        $this->assertSame(10, mb_strlen(CatalogProblemReport::sanitizePlain(str_repeat('x', 40), 10)));
    }
}
