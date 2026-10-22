<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryArticlePlacementTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug, float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 46,
            'tax' => 0,
            'total_amount' => 46,
            'payment_method' => 'wallet',
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ], $overrides));
    }

    private function attachOrderItem(Order $order, Site $site, $submission, array $overrides = []): OrderItem
    {
        $item = OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ], $overrides));
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        return $item;
    }

    private function cartLine(Site $site, $article, ?string $assignedAt = null): array
    {
        $line = [
            'id' => $site->id,
            'name' => $site->site_name,
            'price' => 46,
            'quantity' => 1,
            'language' => 'en',
            'content_submission_id' => $article->id,
            'content_submission_ids' => [0 => $article->id],
        ];
        if ($assignedAt !== null) {
            $line['content_assigned_at'] = [0 => $assignedAt];
        }

        return $line;
    }

    public function test_in_cart_badge_pay_now_and_go_to_checkout(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'carted');
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Carted Piece']);

        $html = $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine($site, $article)]])
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Carted Piece')
            ->assertSee('In cart · '.$site->site_name.' · Not paid', false)
            ->assertSee('Pay now', false)
            ->assertSee('Go to checkout', false)
            ->assertDontSee('>Order</a>', false)
            ->getContent();

        $this->assertStringContainsString(route('advertiser.checkout', absolute: false), $html);
        $this->assertStringContainsString('library-status-box--in_cart', $html);
        $this->assertStringContainsString('>In cart</span>', $html);
        $this->assertStringContainsString('availability=in_cart', $html);
        $this->assertMatchesRegularExpression('/library-status-box--in_cart[\s\S]*?>\s*1\s*</', $html);
    }

    public function test_in_cart_filter_tab_lists_only_carted_articles(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'filter-cart');
        $carted = $this->createApprovedSubmission($advertiser);
        $carted->update(['title' => 'In Cart Filter']);
        $free = $this->createApprovedSubmission($advertiser);
        $free->update(['title' => 'Still Available']);

        $html = $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine($site, $carted)]])
            ->get(route('advertiser.content-library', ['availability' => 'in_cart', 'status' => 'all']))
            ->assertOk()
            ->assertSee('In Cart Filter')
            ->assertDontSee('Still Available')
            ->getContent();

        $this->assertMatchesRegularExpression('/library-status-box--in_cart\s+is-active/', $html);
    }

    public function test_stale_unpaid_cart_shows_in_app_hint(): void
    {
        config(['content_library.stale_cart_days' => 3]);
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'stale');
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Stale Cart Piece']);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [$this->cartLine($site, $article, now()->subDays(4)->toIso8601String())],
            ])
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Still in your cart — finish payment?', false);
    }

    public function test_fresh_cart_does_not_show_stale_hint(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'fresh');
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Fresh Cart Piece']);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [$this->cartLine($site, $article, now()->subDay()->toIso8601String())],
            ])
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Fresh Cart Piece')
            ->assertDontSee('Still in your cart — finish payment?', false);
    }

    public function test_paid_processing_badge_maps_waiting_for_publisher(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'waiting');
        $article = $this->createApprovedSubmission($advertiser, $site->id);
        $article->update(['title' => 'Waiting Piece']);
        $order = $this->makeOrder($advertiser, [
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $this->attachOrderItem($order, $site, $article);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'ordered', 'status' => 'all']))
            ->assertOk()
            ->assertSee('Waiting Piece')
            ->assertSee('Ordered · '.$site->site_name.' · Waiting for publisher', false)
            ->assertSee('View order', false)
            ->assertSee('library-status-box--ordered', false)
            ->getContent();

        $this->assertStringContainsString('#order-'.$order->id, $html);
        $this->assertMatchesRegularExpression('/library-status-box--ordered[\s\S]*?>\s*1\s*</', $html);
    }

    public function test_review_and_approval_stages(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $reviewSite = $this->activeSite($publisher, 'review');
        $approvalSite = $this->activeSite($publisher, 'approval');
        $reviewArticle = $this->createApprovedSubmission($advertiser, $reviewSite->id);
        $reviewArticle->update(['title' => 'Review Piece']);
        $approvalArticle = $this->createApprovedSubmission($advertiser, $approvalSite->id);
        $approvalArticle->update(['title' => 'Approval Piece']);

        $reviewOrder = $this->makeOrder($advertiser, ['payment_status' => 'paid', 'status' => 'review', 'paid_at' => now()]);
        $this->attachOrderItem($reviewOrder, $reviewSite, $reviewArticle);

        $approvalOrder = $this->makeOrder($advertiser, ['payment_status' => 'paid', 'status' => 'review', 'paid_at' => now()]);
        $this->attachOrderItem($approvalOrder, $approvalSite, $approvalArticle, [
            'live_url' => 'https://live.example/awaiting',
            'live_url_submitted_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress', 'status' => 'all']))
            ->assertOk()
            ->assertSee('Ordered · '.$reviewSite->site_name.' · In review', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'completed', 'status' => 'all']))
            ->assertOk()
            ->assertSee('Live on '.$approvalSite->site_name, false)
            ->assertSee('https://live.example/awaiting', false);
    }

    public function test_published_badge_and_live_url(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'live');
        $article = $this->createApprovedSubmission($advertiser, $site->id);
        $article->update(['title' => 'Live Piece']);
        $order = $this->makeOrder($advertiser, [
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);
        $this->attachOrderItem($order, $site, $article, [
            'live_url' => 'https://live.example/post',
            'live_url_submitted_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('Live Piece')
            ->assertSee('Live on '.$site->site_name, false)
            ->assertSee('https://live.example/post', false);
    }

    public function test_lock_blocks_assign_to_second_site(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'lock-a');
        $siteB = $this->activeSite($publisher, 'lock-b');
        $article = $this->createApprovedSubmission($advertiser);

        $cart = [
            $this->cartLine($siteA, $article),
            [
                'id' => $siteB->id,
                'name' => $siteB->site_name,
                'price' => 55,
                'quantity' => 1,
                'language' => 'en',
            ],
        ];

        $response = $this->actingAs($advertiser)
            ->withSession(['cart' => $cart])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('already reserved', (string) $response->json('error'));
    }

    public function test_unlock_after_cart_removal(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'unlock-a');
        $siteB = $this->activeSite($publisher, 'unlock-b');
        $article = $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine($siteA, $article)]])
            ->postJson(route('advertiser.cart.remove'), ['id' => $siteA->id])
            ->assertOk();

        $this->actingAs($advertiser)
            ->withSession(['cart' => [[
                'id' => $siteB->id,
                'name' => $siteB->site_name,
                'price' => 55,
                'quantity' => 1,
                'language' => 'en',
            ]]])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_clearing_assignment_unlocks_article(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'clear-a');
        $siteB = $this->activeSite($publisher, 'clear-b');
        $article = $this->createApprovedSubmission($advertiser);

        $cart = [
            $this->cartLine($siteA, $article),
            [
                'id' => $siteB->id,
                'name' => $siteB->site_name,
                'price' => 55,
                'quantity' => 1,
                'language' => 'en',
            ],
        ];

        $this->actingAs($advertiser)
            ->withSession(['cart' => $cart])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteA->id,
                'content_submission_id' => 0,
            ])
            ->assertOk();

        $this->actingAs($advertiser)
            ->withSession(['cart' => session('cart')])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_paid_order_locks_other_sites_until_cancelled(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'paid-a');
        $siteB = $this->activeSite($publisher, 'paid-b');
        $article = $this->createApprovedSubmission($advertiser, $siteA->id);
        $order = $this->makeOrder($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachOrderItem($order, $siteA, $article);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [[
                'id' => $siteB->id,
                'name' => $siteB->site_name,
                'price' => 55,
                'quantity' => 1,
                'language' => 'en',
            ]]])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ])
            ->assertStatus(422);

        $order->update(['status' => 'cancelled']);
        $article->refresh();

        $this->actingAs($advertiser)
            ->withSession(['cart' => [[
                'id' => $siteB->id,
                'name' => $siteB->site_name,
                'price' => 55,
                'quantity' => 1,
                'language' => 'en',
            ]]])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_refunded_order_unlocks_article(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'refund-a');
        $siteB = $this->activeSite($publisher, 'refund-b');
        $article = $this->createApprovedSubmission($advertiser, $siteA->id);
        $order = $this->makeOrder($advertiser, [
            'payment_status' => 'refunded',
            'status' => 'pending',
        ]);
        $this->attachOrderItem($order, $siteA, $article);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [[
                'id' => $siteB->id,
                'name' => $siteB->site_name,
                'price' => 55,
                'quantity' => 1,
                'language' => 'en',
            ]]])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_rejected_item_unlocks_article(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'reject-a');
        $siteB = $this->activeSite($publisher, 'reject-b');
        $article = $this->createApprovedSubmission($advertiser, $siteA->id);
        $order = $this->makeOrder($advertiser, [
            'payment_status' => 'paid',
            'status' => 'cancelled',
            'paid_at' => now(),
        ]);
        $this->attachOrderItem($order, $siteA, $article, [
            'rejected_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [[
                'id' => $siteB->id,
                'name' => $siteB->site_name,
                'price' => 55,
                'quantity' => 1,
                'language' => 'en',
            ]]])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_library_order_redirects_to_checkout_when_already_in_cart(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'go-checkout');
        $article = $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine($site, $article)]])
            ->get(route('advertiser.content-library.order', $article))
            ->assertRedirect(route('advertiser.checkout'));
    }

    public function test_catalog_and_cart_show_reverse_sign(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'reverse');
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Reverse Piece']);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine($site, $article)]])
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('You already have an article for this site', false)
            ->assertSee('View article', false);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine($site, $article)]])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->assertJsonPath('occupied_sites.'.$site->id.'.label', 'You already have an article for this site')
            ->assertJsonPath('occupied_sites.'.$site->id.'.submission_id', $article->id);
    }

    public function test_advisory_scores_still_orderable_when_in_cart(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'advisory');
        $article = $this->createApprovedSubmission($advertiser);
        $article->update([
            'title' => 'Advisory Piece',
            'uniqueness_score' => 20,
            'quality_score' => 20,
        ]);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine($site, $article)]])
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Advisory — still orderable', false)
            ->assertSee('Go to checkout', false);
    }
}
