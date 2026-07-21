<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class EnglishUniversalCartMatchTest extends TestCase
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

    private function activeSite(User $publisher, string $slug, string $country, string $language): Site
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
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_english_article_can_be_assigned_to_german_site(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $deSite = $this->activeSite($publisher, 'de-site', 'de', 'de');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $this->withSession([
            'cart' => [[
                'id' => $deSite->id,
                'name' => $deSite->site_name,
                'price' => 40,
                'quantity' => 1,
                'language' => 'de',
                'country' => 'de',
            ]],
        ])->actingAs($advertiser)
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $deSite->id,
                'content_submission_id' => $article->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = session('cart');
        $this->assertSame($article->id, (int) ($cart[0]['content_submission_id'] ?? 0));
    }

    public function test_dutch_article_is_cleared_quietly_on_german_site(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $deSite = $this->activeSite($publisher, 'de-site-2', 'de', 'de');
        $nlArticle = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/nl', 'nl', 'nl');

        $response = $this->withSession([
            'cart' => [[
                'id' => $deSite->id,
                'name' => $deSite->site_name,
                'price' => 40,
                'quantity' => 1,
                'language' => 'de',
                'country' => 'de',
                'content_submission_id' => $nlArticle->id,
            ]],
        ])->actingAs($advertiser)
            ->getJson(route('advertiser.cart.get'))
            ->assertOk();

        $cart = $response->json('cart');
        $this->assertIsArray($cart);
        $this->assertArrayNotHasKey('content_submission_id', $cart[0] ?? []);
        $this->assertArrayNotHasKey('content_submission_id', session('cart')[0] ?? []);
    }

    public function test_library_order_adds_mismatched_site_without_error(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $frSite = $this->activeSite($publisher, 'fr-site', 'fr', 'fr');
        $deArticle = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/de', 'de', 'de');

        $this->withSession([
            'ordering_from_library' => true,
            'checkout_content_submission_id' => $deArticle->id,
            'cart' => [],
        ])->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $frSite->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertArrayNotHasKey('content_submission_id', $cart[0] ?? []);
    }
}
