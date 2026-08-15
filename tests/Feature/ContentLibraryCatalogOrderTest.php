<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryCatalogOrderTest extends TestCase
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

    public function test_order_opens_full_catalog_without_language_prefilter(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $usSite = $this->activeSite($publisher, 'us-site', 40, 'us', 'en');
        $gbSite = $this->activeSite($publisher, 'gb-site', 50, 'gb', 'en');
        $deSite = $this->activeSite($publisher, 'de-site', 45, 'de', 'de');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'gb', 'en');
        $article->update(['title' => 'UK Guide']);

        $response = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $article));

        $response->assertRedirect(route('advertiser.catalog', [
            'content_submission_id' => $article->id,
        ]));
        $this->assertSame($article->id, session('checkout_content_submission_id'));
        $this->assertTrue((bool) session('ordering_from_library'));

        $catalog = $this->actingAs($advertiser)
            ->withSession([
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->get(route('advertiser.catalog', [
                'content_submission_id' => $article->id,
            ]));

        $catalog->assertOk()
            ->assertSee('UK Guide')
            ->assertSee($gbSite->site_name)
            ->assertSee($usSite->site_name)
            ->assertSee($deSite->site_name)
            ->assertDontSee('language=en', false);
    }

    public function test_catalog_query_does_not_start_ordering_an_incomplete_link_article(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['anchor_text' => 'only the label', 'target_url' => null]);

        $this->actingAs($advertiser)
            ->withSession([
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->get(route('advertiser.catalog', [
                'content_submission_id' => $article->id,
            ]))
            ->assertOk();

        $this->assertTrue(session()->missing('checkout_content_submission_id'));
        $this->assertTrue(session()->missing('ordering_from_library'));
    }

    public function test_add_to_cart_does_not_auto_attach_an_incomplete_link_article(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'no-auto-link', 40);
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['target_url' => null]);

        $this->actingAs($advertiser)
            ->withSession([
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEmpty(session('cart')[0]['content_submission_id'] ?? null);
        $this->assertTrue(session()->missing('checkout_content_submission_id'));
    }

    public function test_library_add_to_cart_allows_any_site_language(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $usSite = $this->activeSite($publisher, 'us-cart', 40, 'us', 'en');
        $gbSite = $this->activeSite($publisher, 'gb-cart', 55, 'gb', 'en');
        $frSite = $this->activeSite($publisher, 'fr-cart', 50, 'fr', 'fr');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor text', 'https://example.com/a', 'us', 'en');

        // English article can attach to a French site (no language lock).
        $this->actingAs($advertiser)
            ->withSession([
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $frSite->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart_count', 1);

        $this->assertSame($article->id, session('cart')[0]['content_submission_id'] ?? null);
        $this->assertSame($frSite->id, session('cart')[0]['id'] ?? null);

        // Second site can be added; article already used so it is not auto-attached.
        $this->actingAs($advertiser)
            ->withSession([
                'cart' => session('cart'),
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $gbSite->id])
            ->assertOk();

        $this->assertCount(2, session('cart'));
        $gbLine = collect(session('cart'))->firstWhere('id', $gbSite->id);
        $this->assertEmpty($gbLine['content_submission_id'] ?? null);

        // Checkout the line that already has the article assigned.
        $this->fundAdvertiserWallet($advertiser);
        $checkoutCart = [collect(session('cart'))->firstWhere('id', $frSite->id)];
        $checkout = $this->actingAs($advertiser)
            ->withSession([
                'cart' => $checkoutCart,
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'LIBCAT1',
                'publication_mode' => 'immediate',
            ]);

        $checkout->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, OrderItem::where('content_submission_id', $article->id)->count());
        $this->assertNotNull($article->fresh()->order_id);
        $this->assertNotNull($usSite);
    }

    public function test_order_is_blocked_when_approved_images_lack_rights(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update([
            'preview_html' => '<p>Body</p><img src="/storage/content-articles/1/x.png" alt="">',
            'image_rights' => null,
            'image_rights_declared_at' => null,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $article))
            ->assertRedirect(route('advertiser.content-library'))
            ->assertSessionHas('error', ContentUploadService::imageRightsRequiredMessage());
    }

    public function test_catalog_array_content_submission_id_attaches_the_named_article(): void
    {
        $advertiser = $this->advertiser();
        $first = $this->createApprovedSubmission($advertiser);
        $second = $this->createApprovedSubmission($advertiser);
        $this->assertSame(1, (int) [$second->id], 'PHP casts a non-empty array to 1.');
        $this->assertNotSame($first->id, $second->id);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', [
                'content_submission_id' => [$second->id],
            ]))
            ->assertOk();

        $this->assertSame($second->id, session('checkout_content_submission_id'));
    }

    public function test_catalog_garbage_array_content_submission_id_does_not_attach_article_one(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', [
                'content_submission_id' => ['nope'],
            ]))
            ->assertOk();

        $this->assertNull(session('checkout_content_submission_id'));
    }
}
