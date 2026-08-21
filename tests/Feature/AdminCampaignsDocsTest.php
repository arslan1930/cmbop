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
        $this->assertStringContainsString('fresh** pending Email Center', $body);
        $this->assertStringContainsString('audience_campaign|{email}|AudienceCampaignMail', $body);
        $this->assertStringContainsString('only one failed log per job UUID', $body);
        $this->assertStringContainsString('drop that job UUID from the retry list', $body);
        $this->assertStringContainsString('must not swallow another campaign\'s failed job', $body);
        $this->assertStringContainsString('must not treat another campaign\'s generic-key delivery as this send', $body);
        $this->assertStringContainsString('without an extractable payload dedupe key', $body);
        $this->assertStringContainsString('stale stamp must not suppress a Welcome job', $body);
        $this->assertStringContainsString('must also clear the fail streak', $body);
        $this->assertStringContainsString('already has a delivered/failed log FK', $body);
        $this->assertStringContainsString('must not beat a delivered log', $body);
        $this->assertStringContainsString('must not beat a delivered sibling', $body);
        $this->assertStringContainsString('generic-key pending sibling as in-flight', $body);
        $this->assertStringContainsString('when the grouped extras miss', $body);
        $this->assertStringContainsString('second database table without `payload`', $body);
        $this->assertStringContainsString('failed_jobs', $body);
        $this->assertStringContainsString('must **not** block expire', $body);
        $this->assertStringContainsString('must **not** look like “no job”', $body);
        $this->assertStringContainsString('ops-mail-reminders.md', $body);
        $this->assertStringContainsString('even if that staff account also has a marketplace role', $body);
        $this->assertStringContainsString('re-check staff roles at send time', $body);
        $this->assertStringContainsString('unreadable roles lookup is treated as staff', $body);
        $this->assertStringContainsString('leftover recipient timestamp must not abort recover', $body);
        $this->assertStringContainsString('unreadable marketing preference is treated as an opt-out', $body);
        $this->assertStringContainsString('payment_status=completed', $body);
        $this->assertStringContainsString('Preference, disabled, and unverified skips stay skipped', $body);
        $this->assertStringContainsString('inline SMTP (`sync` mail)', $body);
        $this->assertStringContainsString('delivered log still wins when a', $body);
        $this->assertStringContainsString('younger than the stall window', $body);
        $this->assertStringContainsString('must not skip-stale a queued recipient who already has a delivered log', $body);
        $this->assertStringContainsString('Lost transactional pending logs', $body);
        $this->assertStringContainsString('must **not** abort that expire', $body);
        $this->assertStringContainsString('admin.campaigns.show', $body);
        $this->assertStringContainsString('no** resend-all', $body);
        $this->assertStringContainsString('never insert recipient rows on a draft', $body);
        $this->assertStringContainsString('Send clones', $body);
        $this->assertStringContainsString('draft_id', $body);
        $this->assertStringContainsString('canDuplicate()', $body);
        $this->assertStringContainsString('~N emailable', $body);
        $this->assertStringContainsString('admin.campaigns.drafts.edit', $body);
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
