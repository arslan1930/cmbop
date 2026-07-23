<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryMultiSiteCartTest extends TestCase
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

    private function activeSite(User $publisher, string $slug, float $price = 40, string $country = 'us', string $language = 'en'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => $country,
            'language' => $language,
            'countries' => [$country],
            'languages' => [$language],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_library_order_keeps_existing_cart_and_attaches_article_to_next_site(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $existingSite = $this->activeSite($publisher, 'keep-me', 40, 'us', 'en');
        $nextSite = $this->activeSite($publisher, 'attach-me', 50, 'gb', 'en');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $existingSite->id,
                    'name' => $existingSite->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                ]],
            ])
            ->get(route('advertiser.content-library.order', $article))
            ->assertRedirect();

        $this->assertCount(1, session('cart'));
        $this->assertSame($existingSite->id, session('cart')[0]['id']);
        $this->assertSame($article->id, session('checkout_content_submission_id'));

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => session('cart'),
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $nextSite->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $attached = collect($cart)->firstWhere('id', $nextSite->id);
        $this->assertSame($article->id, $attached['content_submission_id'] ?? null);
    }

    public function test_can_add_multiple_sites_and_assign_distinct_articles(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'multi-a', 40, 'us', 'en');
        $siteB = $this->activeSite($publisher, 'multi-b', 55, 'gb', 'en');
        $articleA = $this->createApprovedSubmission($advertiser, null, 0, 'anchor a', 'https://example.com/a', 'us', 'en');
        $articleB = $this->createApprovedSubmission($advertiser, null, 0, 'anchor b', 'https://example.com/b', 'gb', 'en');
        $articleA->update(['title' => 'Article A']);
        $articleB->update(['title' => 'Article B']);

        $this->actingAs($advertiser)
            ->withSession([
                'checkout_content_submission_id' => $articleA->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $siteA->id])
            ->assertOk();

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => session('cart'),
                'checkout_content_submission_id' => $articleA->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $siteB->id])
            ->assertOk();

        $this->assertCount(2, session('cart'));

        // Article A already used — second site should not auto-attach it.
        $lineB = collect(session('cart'))->firstWhere('id', $siteB->id);
        $this->assertEmpty($lineB['content_submission_id'] ?? null);

        $this->actingAs($advertiser)
            ->withSession(['cart' => session('cart')])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $siteB->id,
                'content_submission_id' => $articleB->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $payload = $this->actingAs($advertiser)
            ->withSession(['cart' => session('cart')])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $this->assertCount(2, $payload['cart']);
        $this->assertNotEmpty($payload['approved_articles']);

        $this->fundAdvertiserWallet($advertiser);
        $checkout = $this->actingAs($advertiser)
            ->withSession([
                'cart' => session('cart'),
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'MULTI1',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    (string) $siteA->id => [$articleA->id],
                    (string) $siteB->id => [$articleB->id],
                ],
            ]);

        $checkout->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, OrderItem::where('content_submission_id', $articleA->id)->count());
        $this->assertSame(1, OrderItem::where('content_submission_id', $articleB->id)->count());
    }

    public function test_cannot_assign_same_article_to_two_cart_sites(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'dup-a', 40, 'us', 'en');
        $siteB = $this->activeSite($publisher, 'dup-b', 55, 'gb', 'en');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $cart = [
            [
                'id' => $siteA->id,
                'name' => $siteA->site_name,
                'price' => 46,
                'quantity' => 1,
                'language' => 'en',
                'content_submission_id' => $article->id,
            ],
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
                'id' => $siteB->id,
                'content_submission_id' => $article->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_cannot_reuse_same_article_on_two_placements_of_same_site(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'qty-dup', 40, 'us', 'en');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'price' => 46,
            'quantity' => 2,
            'language' => 'en',
            'content_submission_id' => $article->id,
            'content_submission_ids' => [0 => $article->id, 1 => 0],
        ]];

        $this->actingAs($advertiser)
            ->withSession(['cart' => $cart])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $site->id,
                'content_submission_id' => $article->id,
                'copy_index' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($advertiser)
            ->withSession(['cart' => $cart])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $site->id,
                'content_submission_id' => $article->id,
                'copy_index' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_qty_two_checkout_requires_two_distinct_articles(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'qty-two', 40, 'us', 'en');
        $articleA = $this->createApprovedSubmission($advertiser, null, 0, 'anchor a', 'https://example.com/a', 'us', 'en');
        $articleB = $this->createApprovedSubmission($advertiser, null, 0, 'anchor b', 'https://example.com/b', 'us', 'en');

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'price' => 46,
            'quantity' => 2,
            'language' => 'en',
            'country' => 'us',
            'content_submission_id' => $articleA->id,
            'content_submission_ids' => [0 => $articleA->id, 1 => $articleB->id],
        ]];

        $this->fundAdvertiserWallet($advertiser);

        $this->actingAs($advertiser)
            ->withSession(['cart' => $cart])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'QTY2OK',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    (string) $site->id => [$articleA->id, $articleB->id],
                ],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, OrderItem::where('content_submission_id', $articleA->id)->count());
        $this->assertSame(1, OrderItem::where('content_submission_id', $articleB->id)->count());
    }

    public function test_qty_two_rejects_reusing_one_article_for_both_placements(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'qty-reuse', 40, 'us', 'en');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $this->fundAdvertiserWallet($advertiser);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 2,
                    'language' => 'en',
                    'content_submission_id' => $article->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'QTY2BAD',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    (string) $site->id => [$article->id, $article->id],
                ],
            ]);

        // Whole line stays deferred when a second placement reuses the same article.
        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, OrderItem::where('content_submission_id', $article->id)->count());
    }

    public function test_checkout_page_has_no_inline_docx_upload(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'no-upload', 40, 'us', 'en');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $html = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'url' => $site->site_url,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                    'content_submission_id' => $article->id,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Or upload a new .docx', $html);
        $this->assertStringNotContainsString('upload-btn', $html);
        $this->assertStringContainsString('Content Library', $html);
    }
}
