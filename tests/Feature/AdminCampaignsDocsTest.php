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
        $this->assertStringContainsString('recipientRowQuery', $body);
        $this->assertStringContainsString('queryForAudienceKey', $body);
        $this->assertStringContainsString('each** queue connection', $body);
        $this->assertStringContainsString('25s timeout mid-batch', $body);
        $this->assertStringContainsString('must not overwrite `sent`', $body);
        $this->assertStringContainsString('Do **not** use `ShouldBeUnique`', $body);
        $this->assertStringContainsString('mail:drain-queue', $body);
        $this->assertStringContainsString('Fail streak is stored in cache', $body);
        $this->assertStringContainsString('must **not** give up leftover pending while', $body);
        $this->assertStringContainsString('touches', $body);
        $this->assertStringContainsString('JSON-escaped payloads', $body);
        $this->assertStringContainsString('recipientBuilder', $body);
        $this->assertStringContainsString('reconciled against `email_logs`', $body);
        $this->assertStringContainsString('must **not** finalize as sent', $body);
        $this->assertStringContainsString('reclaims them to `pending`', $body);
        $this->assertStringContainsString('must still reclaim even if the unused', $body);
        $this->assertStringContainsString('second retry doubles the send', $body);
        $this->assertStringContainsString('must **not** skip a recipient whose', $body);
        $this->assertStringContainsString('queued row with a pending Email Center log', $body);
        $this->assertStringContainsString('only one failed log per job UUID', $body);
        $this->assertStringContainsString('must also clear the fail streak', $body);
        $this->assertStringContainsString('already has a delivered/failed log FK', $body);
        $this->assertStringContainsString('second database table without `payload`', $body);
        $this->assertStringContainsString('failed_jobs', $body);
        $this->assertStringContainsString('must **not** look like “no job”', $body);
        $this->assertStringContainsString('ops-mail-reminders.md', $body);
        $this->assertStringContainsString('even if that staff account also has a marketplace role', $body);
        $this->assertStringContainsString('re-check staff roles at send time', $body);
        $this->assertStringContainsString('payment_status=completed', $body);
        $this->assertStringContainsString('Preference, disabled, unverified, and staff skips stay skipped', $body);
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
