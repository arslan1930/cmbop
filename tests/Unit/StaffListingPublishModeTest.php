<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Support\EmailCatalog;
use App\Support\StaffListingPublishMode;
use Tests\TestCase;

class StaffListingPublishModeTest extends TestCase
{
    public function test_parse_defaults_missing_and_review_to_invite(): void
    {
        $this->assertSame(StaffListingPublishMode::INVITE, StaffListingPublishMode::parse(null));
        $this->assertSame(StaffListingPublishMode::INVITE, StaffListingPublishMode::parse(''));
        $this->assertSame(StaffListingPublishMode::INVITE, StaffListingPublishMode::parse('invite'));
        $this->assertSame(StaffListingPublishMode::INVITE, StaffListingPublishMode::parse('review'));
        $this->assertSame(StaffListingPublishMode::PUBLISH, StaffListingPublishMode::parse('publish'));
        $this->assertNull(StaffListingPublishMode::parse('live'));
        $this->assertNull(StaffListingPublishMode::parse(['publish']));
    }

    public function test_staff_assign_invite_stays_off_catalog(): void
    {
        $attrs = StaffListingPublishMode::staffAssignAttributes(StaffListingPublishMode::INVITE);

        $this->assertFalse($attrs['active']);
        $this->assertFalse($attrs['verified']);
        $this->assertNull($attrs['publisher_accepted_at']);
        $this->assertNull($attrs['onboarding_status']);
    }

    public function test_staff_assign_publish_is_live_unverified(): void
    {
        $attrs = StaffListingPublishMode::staffAssignAttributes(StaffListingPublishMode::PUBLISH);

        $this->assertTrue($attrs['active']);
        $this->assertFalse($attrs['verified']);
        $this->assertNotNull($attrs['publisher_accepted_at']);
        $this->assertNull($attrs['onboarding_status']);
    }

    public function test_bulk_done_review_waits_on_publisher(): void
    {
        $attrs = StaffListingPublishMode::bulkDoneAttributes(StaffListingPublishMode::REVIEW);

        $this->assertFalse($attrs['active']);
        $this->assertFalse($attrs['verified']);
        $this->assertNull($attrs['publisher_accepted_at']);
        $this->assertNull($attrs['assigned_by_user_id']);
        $this->assertSame(Site::ONBOARDING_DETAILS_COMPLETE, $attrs['onboarding_status']);
    }

    public function test_bulk_done_publish_matches_staff_publish_flags(): void
    {
        $attrs = StaffListingPublishMode::bulkDoneAttributes(StaffListingPublishMode::PUBLISH);

        $this->assertTrue($attrs['active']);
        $this->assertFalse($attrs['verified']);
        $this->assertNotNull($attrs['publisher_accepted_at']);
        $this->assertNull($attrs['assigned_by_user_id']);
        $this->assertNull($attrs['onboarding_status']);
    }

    public function test_email_catalog_registers_publish_now_mail(): void
    {
        $this->assertArrayHasKey('admin_published_site', EmailCatalog::all());
        $this->assertArrayHasKey('admin_published_site', config('email_notifications.types'));
        $this->assertEqualsCanonicalizing(
            array_keys(config('email_notifications.types')),
            array_keys(EmailCatalog::all())
        );
        $this->assertSame('admin_published_site', EmailCatalog::keyFromSubject('A website is live on your account'));
        $this->assertSame('admin_published_site', EmailCatalog::keyFromSubject('Websites are live on your account'));

        $html = EmailCatalog::previewHtml('admin_published_site');
        $this->assertIsString($html);
        $this->assertStringContainsString('live on your account', $html);
        $this->assertStringContainsString('do not need to Accept', $html);
        $this->assertStringNotContainsString('Review & accept site', $html);
    }
}
