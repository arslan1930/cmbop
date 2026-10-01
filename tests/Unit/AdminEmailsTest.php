<?php

namespace Tests\Unit;

use App\Models\EmailLog;
use App\Support\AdminEmails;
use App\Support\EmailCatalog;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminEmailsTest extends TestCase
{
    public function test_index_query_drops_array_junk_and_unknown_template(): void
    {
        $request = Request::create('/admin/emails', 'GET', [
            'status' => ['failed'],
            'template_key' => ['welcome'],
            'to_email' => ['ops@example.com'],
            'page' => ['2'],
            'source' => ['test'],
        ]);

        $this->assertSame([], AdminEmails::indexQuery($request));
    }

    public function test_index_query_keeps_scalars_and_page(): void
    {
        $request = Request::create('/admin/emails', 'GET', [
            'status' => 'failed',
            'template_key' => 'welcome',
            'to_email' => 'ops@example.com',
            'page' => 2,
            'source' => 'test',
            'date_from' => '2026-09-01',
        ]);

        $this->assertSame([
            'status' => 'failed',
            'template_key' => 'welcome',
            'to_email' => 'ops@example.com',
            'date_from' => '2026-09-01',
            'source' => 'test',
            'page' => 2,
        ], AdminEmails::indexQuery($request));
    }

    public function test_return_url_keeps_filters_and_fragment(): void
    {
        $this->assertSame(
            route('admin.emails.index', ['status' => 'failed', 'page' => 2]).'#ec-recent',
            AdminEmails::listUrl(['status' => 'failed', 'page' => 2])
        );
    }

    public function test_kpi_query_windows(): void
    {
        $today = now()->toDateString();
        $this->assertSame(['date_from' => $today, 'date_to' => $today], AdminEmails::kpiQuery('sent_today'));
        $this->assertSame(['status' => 'pending'], AdminEmails::kpiQuery('pending'));
        $this->assertSame(['status' => 'failed'], AdminEmails::kpiQuery('failed'));
        $this->assertSame([
            'status' => 'delivered',
            'date_from' => $today,
            'date_to' => $today,
        ], AdminEmails::kpiQuery('delivered'));
    }

    public function test_is_test_log_and_template_name(): void
    {
        $test = new EmailLog([
            'template_key' => 'welcome',
            'dedupe_key' => 'email_center_test:welcome:abc',
            'meta' => ['source' => 'email_center_test'],
        ]);
        $live = new EmailLog([
            'template_key' => 'welcome',
            'dedupe_key' => 'welcome:1',
            'meta' => [],
        ]);

        $this->assertTrue(AdminEmails::isTestLog($test));
        $this->assertFalse(AdminEmails::isTestLog($live));
        $this->assertSame('Welcome Email', AdminEmails::templateName('welcome'));
        $this->assertSame('—', AdminEmails::templateName(''));
    }

    public function test_preview_variants_for_welcome_and_completed_order(): void
    {
        $publisher = EmailCatalog::previewHtml('welcome', 'publisher');
        $this->assertIsString($publisher);
        $this->assertStringContainsString('Add your first website', $publisher);

        $completed = EmailCatalog::previewHtml('order_status_changed', 'completed');
        $this->assertIsString($completed);
        $this->assertStringContainsString('Ready to place another order', $completed);
    }

    public function test_dashboard_kpis_include_today_subtotals(): void
    {
        $kpis = EmailLog::dashboardKpis();

        $this->assertArrayHasKey('sent_today', $kpis);
        $this->assertArrayHasKey('pending', $kpis);
        $this->assertArrayHasKey('failed', $kpis);
        $this->assertArrayHasKey('delivered', $kpis);
        $this->assertArrayHasKey('pending_today', $kpis);
        $this->assertArrayHasKey('failed_today', $kpis);
    }

    public function test_preview_audience_accepts_known_variants_only(): void
    {
        $this->assertSame('publisher', AdminEmails::previewAudience('publisher'));
        $this->assertSame('completed', AdminEmails::previewAudience('completed'));
        $this->assertNull(AdminEmails::previewAudience('staff'));
        $this->assertNull(AdminEmails::previewAudience(null));
    }
}
