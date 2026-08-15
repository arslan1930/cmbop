<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminCampaignsDocsTest extends TestCase
{
    public function test_admin_campaigns_doc_exists(): void
    {
        $path = base_path('docs/admin-campaigns.md');
        $this->assertFileExists($path);

        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('SendEmailCampaignJob', $body);
        $this->assertStringContainsString('email_campaign_recipients', $body);
        $this->assertStringContainsString('Campaign queued for N recipient(s).', $body);
        $this->assertStringContainsString('emails', $body);
        $this->assertStringContainsString('MAIL_CAMPAIGN_MAX_AGE_HOURS', $body);
        $this->assertStringContainsString('email.unsubscribe', $body);
        $this->assertStringContainsString('marketing_emails', $body);
        $this->assertStringContainsString('List-Unsubscribe', $body);
        $this->assertStringContainsString('includeUnverified', $body);
        $this->assertStringContainsString('advertisers_no_paid_orders', $body);
        $this->assertStringContainsString('collectRecipientRows', $body);
        $this->assertStringContainsString('Do **not** use `ShouldBeUnique`', $body);
        $this->assertStringContainsString('mail:drain-queue', $body);
        $this->assertStringContainsString('ops-mail-reminders.md', $body);
        $this->assertStringNotContainsString('/advertiser/campaigns', $body);
    }

    public function test_ops_mail_doc_links_admin_campaigns(): void
    {
        $path = base_path('docs/ops-mail-reminders.md');
        $this->assertFileExists($path);

        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('admin-campaigns.md', $body);
        $this->assertStringContainsString('SendEmailCampaignJob', $body);
        $this->assertStringContainsString('/email/unsubscribe/{user}', $body);
    }
}
