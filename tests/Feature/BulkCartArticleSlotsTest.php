<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CartPricingService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Bulk deals (Buy 3–10) must land in the cart as N placements so the advertiser
 * can attach a separate Content Library article for each one to publish.
 */
class BulkCartArticleSlotsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

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
    }

    private function makeBulkSite(): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Bulk Pack Site',
            'site_url' => 'https://bulk-pack.example',
            'domain' => 'bulk-pack.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Joined the bulk discount programme.',
            'verified' => true,
            'active' => 1,
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
        ]);
    }

    public function test_bulk_add_starts_cart_line_at_three_article_slots(): void
    {
        $site = $this->makeBulkSite();

        $response = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = session('cart');
        $this->assertIsArray($cart);
        $this->assertCount(1, $cart);
        $this->assertSame(3, (int) $cart[0]['quantity']);
        $this->assertSame([0, 0, 0], array_values($cart[0]['content_submission_ids'] ?? []));
        $this->assertStringContainsString('3 article placements', (string) $response->json('message'));
        $this->assertGreaterThan(0, (float) ($cart[0]['discount_percent'] ?? 0));
    }

    public function test_bulk_add_honours_selected_four_articles(): void
    {
        $site = $this->makeBulkSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
                'quantity' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = session('cart');
        $this->assertSame(4, (int) $cart[0]['quantity']);
        $this->assertCount(4, $cart[0]['content_submission_ids'] ?? []);
    }

    public function test_bulk_add_clamps_below_minimum_up_to_three(): void
    {
        $site = $this->makeBulkSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
                'quantity' => 1,
            ])
            ->assertOk();

        $this->assertSame(3, (int) session('cart')[0]['quantity']);
    }

    public function test_regular_add_still_starts_at_one(): void
    {
        $site = $this->makeBulkSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
            ])
            ->assertOk();

        $this->assertSame(1, (int) session('cart')[0]['quantity']);
    }

    public function test_bulk_deal_cards_have_no_article_quantity_picker(): void
    {
        $this->makeBulkSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Deal cards stay simple — quantity is fixed at 3 in the cart flow.
        $this->assertStringNotContainsString('bulk-deal-qty', $html);
        $this->assertStringNotContainsString('bulk-deal-card__articles', $html);
        $this->assertStringNotContainsString('data-bulk-qty-for', $html);
        $this->assertStringNotContainsString('>4 articles<', $html);
        $this->assertStringNotContainsString('>5 articles<', $html);
        $this->assertStringContainsString('data-bulk-hint="1"', $html);
        $this->assertStringContainsString('data-bulk-qty="3"', $html);
        $this->assertStringContainsString('Add 3 to cart', $html);

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('cartOptions.bulk = true', $js);
        $this->assertStringContainsString('button.dataset.bulkQty', $js);
        $this->assertStringNotContainsString('bulk-deal-qty', $js);
    }

    public function test_save_cart_clamps_bulk_pack_qty_and_reprices(): void
    {
        $site = $this->makeBulkSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
            ])
            ->assertOk();

        $response = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.save'), [
                'cart' => [[
                    'id' => $site->id,
                    'quantity' => 12,
                    'bulk_pack' => true,
                    'content_submission_ids' => [0, 0, 0],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $line = $response->json('cart.0');
        $this->assertSame(10, (int) $line['quantity']);
        $this->assertTrue((bool) $line['bulk_pack']);
        $this->assertGreaterThan(0, (float) ($line['discount_percent'] ?? 0));
        $this->assertCount(10, $line['content_submission_ids'] ?? []);

        $inBand = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.save'), [
                'cart' => [[
                    'id' => $site->id,
                    'quantity' => 9,
                    'bulk_pack' => true,
                ]],
            ])
            ->assertOk()
            ->json('cart.0');

        $this->assertSame(9, (int) $inBand['quantity']);
        $this->assertCount(9, $inBand['content_submission_ids'] ?? []);

        $tooLow = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.save'), [
                'cart' => [[
                    'id' => $site->id,
                    'quantity' => 1,
                    'bulk_pack' => true,
                ]],
            ])
            ->assertOk()
            ->json('cart.0');

        $this->assertSame(3, (int) $tooLow['quantity']);
        $this->assertGreaterThan(0, (float) ($tooLow['discount_percent'] ?? 0));
    }

    public function test_checkout_items_preserve_bulk_content_submission_ids(): void
    {
        $site = $this->makeBulkSite();
        $a = $this->createApprovedSubmission($this->advertiser, null, 0, 'one', 'https://example.com/1');
        $b = $this->createApprovedSubmission($this->advertiser, null, 1, 'two', 'https://example.com/2');
        $c = $this->createApprovedSubmission($this->advertiser, null, 2, 'three', 'https://example.com/3');

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => 3,
            'bulk_pack' => true,
            'content_submission_id' => $a->id,
            'content_submission_ids' => [0 => $a->id, 1 => $b->id, 2 => $c->id],
        ]];

        $built = app(CartPricingService::class)->buildCheckoutItems($cart);
        $line = $built['items'][0] ?? [];

        $this->assertSame($a->id, (int) ($line['content_submission_id'] ?? 0));
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            array_values(array_map('intval', $line['content_submission_ids'] ?? []))
        );

        // Order summary loads articles for every slot — not only the scalar id.
        $a->update(['title' => 'Bulk Slot One']);
        $b->update(['title' => 'Bulk Slot Two']);
        $c->update(['title' => 'Bulk Slot Three']);

        $html = $this->actingAs($this->advertiser)
            ->withSession(['cart' => $cart])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk Slot One', $html);
        $this->assertStringContainsString('Bulk Slot Two', $html);
        $this->assertStringContainsString('Bulk Slot Three', $html);
        $this->assertStringContainsString('data-content-submission-id="'.$b->id.'"', $html);
        $this->assertStringContainsString('data-content-submission-id="'.$c->id.'"', $html);
    }

    public function test_bulk_deal_card_now_price_floors_at_publisher_payout(): void
    {
        // €100 publisher → €113 list; 15% off would be €96.05 without the floor.
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Floor Pack Site',
            'site_url' => 'https://floor-pack.example',
            'domain' => 'floor-pack.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'High bulk discount that hits the publisher floor.',
            'verified' => true,
            'active' => 1,
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 15,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Floored unit €100 × 3 = €300.00 (not €288.15 from raw 15% off €339).
        $this->assertStringContainsString('bulk-deal-card__now', $html);
        $this->assertStringContainsString('€300.00', $html);
        $this->assertStringNotContainsString('€288.15', $html);
    }

    public function test_cart_drawer_renders_pack_domain_and_three_article_slots(): void
    {
        $site = $this->makeBulkSite();

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        $line = $payload['cart'][0];
        $this->assertSame('bulk-pack.example', $line['domain']);
        $this->assertSame(3, (int) $line['quantity']);
        $this->assertTrue((bool) $line['bulk_pack']);
        $this->assertSame([0, 0, 0], array_values($line['content_submission_ids'] ?? []));
        $this->assertArrayHasKey('article_copy', $payload);
        $this->assertSame('Choose an article option above to continue', $payload['article_copy']['proceed_hint'] ?? null);

        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('const pickerMarkup = written ? \'\' : placementIds.map', $layout);
        $this->assertStringContainsString('${pickerMarkup}', $layout);
        $this->assertStringNotContainsString('${uploadPickers}', $layout);
        $this->assertStringContainsString('data-cart-retry-line', $layout);
        $this->assertStringContainsString('Use Retry to load article choices for this pack.', $layout);
        $this->assertStringNotContainsString('Could not show this site. Refresh the page.', $layout);
        $this->assertStringContainsString("const siteName = siteDomain || item.name || 'Website'", $layout);
    }

    public function test_assigned_pack_articles_reach_checkout_pay(): void
    {
        $site = $this->makeBulkSite();
        $a = $this->createApprovedSubmission($this->advertiser, null, 0, 'one', 'https://example.com/1');
        $b = $this->createApprovedSubmission($this->advertiser, null, 1, 'two', 'https://example.com/2');
        $c = $this->createApprovedSubmission($this->advertiser, null, 2, 'three', 'https://example.com/3');
        $a->update(['title' => 'Pack Slot One']);
        $b->update(['title' => 'Pack Slot Two']);
        $c->update(['title' => 'Pack Slot Three']);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
            ])
            ->assertOk();

        $saved = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.save'), [
                'cart' => [[
                    'id' => $site->id,
                    'quantity' => 3,
                    'bulk_pack' => true,
                    'article_choice' => 'upload',
                    'content_submission_ids' => [$a->id, $b->id, $c->id],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('cart.0');

        $this->assertSame(
            [$a->id, $b->id, $c->id],
            array_values(array_map('intval', $saved['content_submission_ids'] ?? []))
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Pack Slot One', $html);
        $this->assertStringContainsString('Pack Slot Two', $html);
        $this->assertStringContainsString('Pack Slot Three', $html);
        $this->assertStringContainsString('Pay now', $html);
        $this->assertStringContainsString('bulk-pack.example', $html);
    }
}
