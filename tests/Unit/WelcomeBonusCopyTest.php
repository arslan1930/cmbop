<?php

namespace Tests\Unit;

use App\Services\Wallet\WelcomeBonusService;
use App\Support\WelcomeBonusCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeBonusCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_swaps_live_amount_and_falls_back_when_disabled(): void
    {
        $this->assertSame(
            'Top up by card (Stripe) or bank transfer where enabled. New advertisers get €20 welcome credit (spend-only, not withdrawable). Site prices are clear before checkout.',
            WelcomeBonusCopy::message('how_page_adv_step_2_body', 'how_page_adv_step_2_body_off')
        );

        app(WelcomeBonusService::class)->setAmount(35);
        $this->assertSame(
            'Top up by card (Stripe) or bank transfer where enabled. New advertisers get €35 welcome credit (spend-only, not withdrawable). Site prices are clear before checkout.',
            WelcomeBonusCopy::message('how_page_adv_step_2_body', 'how_page_adv_step_2_body_off')
        );

        app(WelcomeBonusService::class)->setEnabled(false);
        $this->assertSame(
            'Top up by card (Stripe) or bank transfer where enabled. Site prices are clear before checkout.',
            WelcomeBonusCopy::message('how_page_adv_step_2_body', 'how_page_adv_step_2_body_off')
        );
        $this->assertFalse(WelcomeBonusCopy::canGrant());
        $this->assertSame(
            'Create Account | SEOLinkBuildings',
            WelcomeBonusCopy::message('meta_register_title', 'meta_register_title_off')
        );
    }

    public function test_llms_txt_rewrites_the_grant_line(): void
    {
        $snapshot = "- New advertisers: €20 welcome credit for first orders (spend-only, not withdrawable).\n";

        $this->assertSame($snapshot, WelcomeBonusCopy::applyToLlmsTxt($snapshot));

        app(WelcomeBonusService::class)->setAmount(35);
        $this->assertSame(
            "- New advertisers: €35 welcome credit for first orders (spend-only, not withdrawable).\n",
            WelcomeBonusCopy::applyToLlmsTxt($snapshot)
        );

        app(WelcomeBonusService::class)->setEnabled(false);
        $this->assertSame(
            "- New advertisers: promotional welcome credit is spend-only when granted; it is not always offered.\n",
            WelcomeBonusCopy::applyToLlmsTxt($snapshot)
        );
    }

    public function test_scrub_removes_grant_promises_when_disabled(): void
    {
        $html = '<p>New advertisers receive a welcome wallet credit under the current signup rules; treat it as purchasing power for placements, not a cash withdrawal.</p>';

        $this->assertStringContainsString('New advertisers receive a welcome wallet credit', WelcomeBonusCopy::scrubGrantAdvertisingHtml($html));

        app(WelcomeBonusService::class)->setEnabled(false);
        $scrubbed = WelcomeBonusCopy::scrubGrantAdvertisingHtml($html);
        $this->assertStringNotContainsString('New advertisers receive a welcome wallet credit', $scrubbed);
        $this->assertStringContainsString('When a welcome promotion is active', $scrubbed);
    }
}
