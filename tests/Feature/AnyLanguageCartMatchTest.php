<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AnyLanguageCartMatchTest extends TestCase
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

    public function test_any_language_article_can_be_assigned_to_any_site(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $deSite = $this->activeSite($publisher, 'de-any', 'de', 'de');
        $nlArticle = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/nl', 'nl', 'nl');

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
                'content_submission_id' => $nlArticle->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($nlArticle->id, (int) (session('cart')[0]['content_submission_id'] ?? 0));
    }

    public function test_library_article_attaches_even_when_site_language_differs(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $frSite = $this->activeSite($publisher, 'fr-any', 'fr', 'fr');
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
        $this->assertSame($deArticle->id, (int) ($cart[0]['content_submission_id'] ?? 0));
    }
}
