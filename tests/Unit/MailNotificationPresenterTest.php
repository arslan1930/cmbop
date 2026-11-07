<?php

namespace Tests\Unit;

use App\Support\LegalIdentity;
use App\Support\MailNotificationPresenter;
use Tests\TestCase;

class MailNotificationPresenterTest extends TestCase
{
    public function test_order_status_labels_use_sentence_case_in_review(): void
    {
        $this->assertSame('In review', MailNotificationPresenter::orderStatusLabel('review'));
        $this->assertSame('In review', MailNotificationPresenter::orderStatusLabel('in_review'));
        $this->assertSame('Processing', MailNotificationPresenter::orderStatusLabel('processing'));
        $this->assertSame('Paid', MailNotificationPresenter::paymentStatusLabel('paid'));
    }

    public function test_datetime_includes_explicit_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        $label = MailNotificationPresenter::formatDateTime('2026-11-07 12:00:00');

        $this->assertStringContainsString('2026', $label);
        $this->assertMatchesRegularExpression('/\b(UTC|GMT)\b/', $label);
    }

    public function test_live_url_rejects_unsafe_and_non_http_values(): void
    {
        $this->assertSame('https://example.com/post', MailNotificationPresenter::safeLiveUrl('https://example.com/post'));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl('javascript:alert(1)'));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl(''));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl('/relative/path'));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl('not-a-url'));
    }

    public function test_legal_identity_normalizes_partners_with_wording(): void
    {
        $this->assertTrue(LegalIdentity::isMisleadingPartnerWording('SEOLinkBuildings Partners with (Teqno LTD)'));
        $this->assertSame(
            'Teqno Ltd trading as SEOLinkBuildings',
            LegalIdentity::normalizeLegalName('SEOLinkBuildings Partners with (Teqno LTD)')
        );
        $this->assertStringContainsString('trading name of Teqno Ltd', LegalIdentity::footer());
        $this->assertStringContainsString('17392540', LegalIdentity::footer());
    }
}
