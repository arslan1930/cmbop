<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartPricingService;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\WritingFeeService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CartWritingChoiceTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config(['content_moderation.enabled' => false]);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => $advertiserRole->id,
            'balance' => 2000,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function makeSite(string $slug, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Write Cart '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Cart writing choice listing.',
            'verified' => true,
            'active' => true,
            'writing_enabled' => false,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function addAndWrite(Site $site, array $writing = []): array
    {
        $added = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->json('cart.0');

        return $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.configure'), array_merge([
                'id' => $site->id,
                'sensitive_type' => $added['sensitive_type'] ?? '',
                'homepage_days' => $added['homepage_days'] ?? 'none',
                'content_source' => WritingFeeService::SOURCE_WRITTEN,
                'writing_target_url' => 'https://advertiser.example/offer',
                'writing_anchor_text' => 'guest post',
            ], $writing))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();
    }

    public function test_cart_payload_offers_advertiser_writing_fee_never_partner_rates(): void
    {
        $slbSite = $this->makeSite('slb-fee', [
            'writing_enabled' => false,
            'writing_fee' => 25,
            'listing_writing_fee' => 25,
        ]);
        $publisherSite = $this->makeSite('pub-fee', [
            'domain' => 'pub-fee.example',
            'site_url' => 'https://pub-fee.example',
            'writing_enabled' => true,
            'writing_fee' => 40,
            'listing_writing_fee' => 40,
        ]);

        $slb = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $slbSite->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->json('cart.0');

        $this->assertFalse((bool) $slb['writing_offered']);
        $this->assertEquals(WritingFeeService::SLB_FLAT_EUR, (float) $slb['writing_fee']);
        $this->assertSame(WritingFeeService::SOURCE_BUYER, $slb['content_source']);
        $this->assertArrayNotHasKey('listing_writing_fee', $slb);
        $this->assertArrayNotHasKey('publisher_price', $slb);
        $this->assertArrayNotHasKey('platform_fee_amount', $slb);
        $this->assertArrayNotHasKey('partner_rate', $slb);

        $this->actingAs($this->advertiser)->postJson(route('advertiser.cart.clear'))->assertOk();

        $publisher = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $publisherSite->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->json('cart.0');

        $this->assertTrue((bool) $publisher['writing_offered']);
        $this->assertEquals(40.0, (float) $publisher['writing_fee']);
        $this->assertArrayNotHasKey('listing_writing_fee', $publisher);
        $this->assertArrayNotHasKey('publisher_price', $publisher);
    }

    public function test_choosing_writing_stores_source_and_brief_on_the_cart_line(): void
    {
        $site = $this->makeSite('choose-write');

        $payload = $this->addAndWrite($site);
        $line = $payload['cart'][0];

        $this->assertTrue(CheckoutAddOnService::isWrittenSource($line));
        $this->assertSame(WritingFeeService::SOURCE_SLB, $line['content_source']);
        $this->assertSame('written', $line['article_choice']);
        $this->assertSame('https://advertiser.example/offer', $line['writing_target_url']);
        $this->assertSame('guest post', $line['writing_anchor_text']);
        $this->assertEquals(WritingFeeService::SLB_FLAT_EUR, (float) $line['writing_fee']);
        $this->assertSame(0, (int) ($line['content_submission_id'] ?? 0));

        $session = session('cart')[0];
        $this->assertSame(WritingFeeService::SOURCE_SLB, $session['content_source']);
        $this->assertSame('https://advertiser.example/offer', $session['writing_target_url']);
    }

    public function test_publisher_self_write_stores_publisher_source_and_listing_fee(): void
    {
        $site = $this->makeSite('self-write', [
            'writing_enabled' => true,
            'writing_fee' => 40,
            'listing_writing_fee' => 40,
        ]);

        $line = $this->addAndWrite($site)['cart'][0];

        $this->assertSame(WritingFeeService::SOURCE_PUBLISHER, $line['content_source']);
        $this->assertEquals(40.0, (float) $line['writing_fee']);
        $this->assertTrue((bool) $line['writing_offered']);
    }

    public function test_written_cart_line_is_payable_and_preselected_at_checkout(): void
    {
        $site = $this->makeSite('checkout-preselect');
        $this->addAndWrite($site);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertViewHas('payableReady', true)
            ->assertViewHas('payableCount', 1)
            ->assertDontSee('Nothing ready to pay yet', false)
            ->getContent();

        $this->assertStringContainsString('id="contentSourceWritten'.$site->id.'"', $html);
        $this->assertMatchesRegularExpression(
            '/id="contentSourceWritten'.$site->id.'"[^>]*checked/',
            $html
        );
        $this->assertStringContainsString('value="https://advertiser.example/offer"', $html);
        $this->assertStringContainsString('value="guest post"', $html);
        $this->assertStringNotContainsString('listing_writing_fee', $html);
        $this->assertStringNotContainsString('partner_rate', $html);
    }

    public function test_wallet_checkout_pays_a_written_line_without_an_uploaded_article(): void
    {
        $site = $this->makeSite('wallet-write');
        $this->addAndWrite($site);
        $priced = app(CartPricingService::class)->priceForAdvertiser($site, null, 1, 'none', false);
        $expected = round((float) $priced['total'] + WritingFeeService::SLB_FLAT_EUR, 2);

        session(['checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC']]);

        $response = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'WRTCART',
                'publication_mode' => 'immediate',
                'content_source' => [$site->id => WritingFeeService::SOURCE_WRITTEN],
                'writing_brief' => [
                    $site->id => [
                        'target_url' => 'https://advertiser.example/offer',
                        'anchor_text' => 'guest post',
                    ],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::query()->where('reference_code', 'WRTCART')->firstOrFail();
        $item = $order->items()->firstOrFail();
        $this->assertSame(WritingFeeService::SOURCE_SLB, $item->content_source);
        $this->assertEquals(WritingFeeService::SLB_FLAT_EUR, (float) $item->writing_fee);
        $this->assertEquals(0.0, (float) ($item->listing_writing_fee ?? 0));
        $this->assertEquals($expected, (float) $order->total_amount);
        $this->assertSame([], session('cart', []));
    }

    public function test_unready_line_without_writing_stays_deferred(): void
    {
        $site = $this->makeSite('still-deferred');

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertViewHas('payableReady', false)
            ->assertSee('Nothing ready to pay yet', false)
            ->assertSee('have the article written', false);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'NODEFER',
                'publication_mode' => 'immediate',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::count());
    }

    public function test_assigning_an_article_clears_the_writing_choice(): void
    {
        $site = $this->makeSite('switch-upload');
        $this->addAndWrite($site);
        $sub = $this->createApprovedSubmission($this->advertiser, null);

        $sessionLine = session('cart')[0];
        $line = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $site->id,
                'sensitive_type' => $sessionLine['sensitive_type'] ?? '',
                'homepage_days' => $sessionLine['homepage_days'] ?? 'none',
                'content_submission_id' => $sub->id,
            ])
            ->assertOk()
            ->json('cart.0');

        $this->assertSame(WritingFeeService::SOURCE_BUYER, $line['content_source']);
        $this->assertSame($sub->id, (int) $line['content_submission_id']);
        $this->assertFalse(CheckoutAddOnService::isWrittenSource($line));
    }

    public function test_cart_payload_includes_writing_copy_and_guarantee_flag(): void
    {
        $site = $this->makeSite('copy-flag');

        $line = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->assertJsonPath('writing_turnaround_text', '2–3 working days')
            ->assertJsonPath('writing_guarantee_note', '12-month link guarantee available at checkout')
            ->json('cart.0');

        $this->assertTrue((bool) $line['guarantee_offered']);
        $this->assertSame('2–3 working days', $line['writing_turnaround_text']);
        $this->assertStringContainsString('Written by the SEOLinkBuildings team', (string) $line['writing_explainer']);
        $this->assertStringNotContainsString('word count', strtolower((string) $line['writing_explainer']));
        $this->assertDoesNotMatchRegularExpression('/\d+\s*words?/i', (string) $line['writing_explainer']);
        $this->assertArrayNotHasKey('listing_writing_fee', $line);
        $this->assertArrayNotHasKey('partner_rate', $line);
    }

    public function test_write_all_applies_writing_only_to_lines_without_articles(): void
    {
        $first = $this->makeSite('bulk-one');
        $second = $this->makeSite('bulk-two', [
            'domain' => 'bulk-two.example',
            'site_url' => 'https://bulk-two.example',
            'site_name' => 'Write Cart bulk-two',
        ]);
        $kept = $this->makeSite('bulk-keep', [
            'domain' => 'bulk-keep.example',
            'site_url' => 'https://bulk-keep.example',
            'site_name' => 'Write Cart bulk-keep',
        ]);
        $sub = $this->createApprovedSubmission($this->advertiser, null);

        foreach ([$first, $second, $kept] as $site) {
            $this->actingAs($this->advertiser)
                ->postJson(route('advertiser.cart.add'), [
                    'id' => $site->id,
                    'homepage_days' => 'none',
                ])
                ->assertOk();
        }

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $kept->id,
                'sensitive_type' => '',
                'homepage_days' => 'none',
                'content_submission_id' => $sub->id,
            ])
            ->assertOk();

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.write-all'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        $byId = collect($payload['cart'])->keyBy(fn ($row) => (int) $row['id']);
        $this->assertTrue(CheckoutAddOnService::isWrittenSource($byId[$first->id]));
        $this->assertTrue(CheckoutAddOnService::isWrittenSource($byId[$second->id]));
        $this->assertSame(WritingFeeService::SOURCE_BUYER, $byId[$kept->id]['content_source']);
        $this->assertSame($sub->id, (int) $byId[$kept->id]['content_submission_id']);
        $this->assertArrayNotHasKey('listing_writing_fee', $byId[$first->id]);
    }

    public function test_admin_can_override_writing_turnaround_text(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->actingAs($admin)
            ->post(route('admin.writing-queue.turnaround'), [
                'turnaround_text' => '4 working days',
            ])
            ->assertRedirect();

        $this->assertSame('4 working days', app(CheckoutAddOnService::class)->writingTurnaroundText());

        $site = $this->makeSite('turnaround-live');
        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->assertJsonPath('writing_turnaround_text', '4 working days')
            ->assertJsonPath('cart.0.writing_turnaround_text', '4 working days');
    }
}
