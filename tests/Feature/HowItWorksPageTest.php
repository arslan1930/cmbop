<?php

namespace Tests\Feature;

use App\Support\LocalizedPublicPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HowItWorksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_how_it_works_page_covers_advertiser_publisher_trust_and_schema(): void
    {
        $this->get('/how-it-works')
            ->assertOk()
            ->assertSee('HowTo', false)
            ->assertSee('FAQPage', false)
            ->assertSee('HowToStep', false)
            ->assertSee(__('messages.how_page_title'))
            ->assertSee(__('messages.how_page_adv_title'))
            ->assertSee(__('messages.how_page_adv_step_1_title'))
            ->assertSee(__('messages.how_page_adv_step_5_title'))
            ->assertSee(__('messages.how_page_money_title'))
            ->assertSee(__('messages.how_page_trust_title'))
            ->assertSee(__('messages.how_page_pub_title'))
            ->assertSee(__('messages.how_page_faq_q_1'))
            ->assertSee(__('messages.get_started'), false)
            ->assertSee(url('/register'), false)
            ->assertSee('/marketplace', false)
            ->assertSee('/become-a-publisher', false)
            ->assertSee('/about', false)
            ->assertSee('/faq', false)
            ->assertSee('72', false)
            ->assertSee('Verified', false)
            ->assertDontSee('AggregateRating', false);
    }

    public function test_homepage_still_uses_compact_three_step_teaser(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('messages.step_1_title'), false)
            ->assertSee(__('messages.step_3_title'), false)
            ->assertDontSee(__('messages.how_page_adv_step_5_title'), false)
            ->assertDontSee(__('messages.how_page_pub_title'), false);
    }

    public function test_german_how_it_works_is_localized(): void
    {
        $this->get(LocalizedPublicPath::publicPath('how-it-works', 'de'))
            ->assertOk()
            ->assertSee('Vom Katalog zum Live-Link', false)
            ->assertSee('Für Advertiser', false)
            ->assertSee('Für Publisher', false)
            ->assertSee('HowTo', false)
            ->assertSee('72', false);
    }

    public function test_meta_description_mentions_ratings_and_wallet(): void
    {
        $html = $this->get('/how-it-works')->assertOk()->getContent();

        $this->assertStringContainsString(
            'name="description"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="description"[^>]*content="[^"]*(ratings|completion|wallet)/i',
            $html
        );
    }
}
