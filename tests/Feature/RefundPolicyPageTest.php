<?php

namespace Tests\Feature;

use App\Support\LocalizedPublicPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_policy_covers_wallet_rules_disputes_and_schema(): void
    {
        $this->get('/refund-policy')
            ->assertOk()
            ->assertSee('WebPage', false)
            ->assertSee('FAQPage', false)
            ->assertSee(__('messages.refund_section_9_title'))
            ->assertSee(__('messages.refund_faq_q_1'))
            ->assertSee('30 days', false)
            ->assertSee('Promotional welcome credit', false)
            ->assertDontSee('€20 welcome', false)
            ->assertSee('72', false)
            ->assertSee('/how-it-works', false)
            ->assertSee('/become-a-publisher', false)
            ->assertSee('/terms-of-services', false)
            ->assertSee('/contact', false)
            ->assertSee('support@seolinkbuildings.com', false)
            ->assertDontSee('for example within 30 days', false)
            ->assertDontSee('messages.refund_', false);
    }

    public function test_german_refund_policy_is_localized(): void
    {
        $this->get(LocalizedPublicPath::publicPath('refund-policy', 'de'))
            ->assertOk()
            ->assertSee('Rückerstattungsrichtlinie', false)
            ->assertSee('30 Tagen', false)
            ->assertSee('Wallet-Einzahlungen', false)
            ->assertSee('FAQPage', false);
    }
}
