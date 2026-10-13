<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Catalog search + click-through behaviour under the hide-mode-only mask policy.
 *
 * Normals: free name/domain search with real identity on the row.
 * Hide mode: free search still returns hits; name/URL stay masked until eye.
 * Copy-strike + pace (not everyday URL masking) are what resist harvest.
 */
class CatalogHarvestResistanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    private function site(User $publisher, string $domain): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.uniqid(),
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40, 'dr' => 45, 'traffic' => 12000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent', 'link_type' => 'dofollow',
            'description' => 'Inventory that should not be harvestable.',
            'verified' => true, 'active' => true,
        ]);
    }

    /** @return list<Site> */
    private function inventory(int $count, string $prefix = 'inv'): array
    {
        $publisher = $this->userWithRole('publisher');
        $sites = [];

        for ($i = 1; $i <= $count; $i++) {
            $sites[] = $this->site($publisher, "{$prefix}-{$i}.example");
        }

        return $sites;
    }

    private function fund(User $user): void
    {
        DepositRequest::create([
            'user_id' => $user->id,
            'amount' => 50,
            'status' => 'approved',
            'payment_method' => 'bank_transfer',
            'reference_code' => 'REF-'.uniqid(),
        ]);
    }

    private function putInHideMode(User $user): User
    {
        $user->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        return $user->fresh();
    }

    // —— Search ———————————————————————————————————————————————————

    public function test_search_finds_real_domains_for_normal_advertisers(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'guessable-domain.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'guessable-domain']))
            ->assertOk()
            ->getContent();

        // Free domain search + real identity — no reveal required.
        $this->assertStringContainsString('data-id="'.$site->id.'"', $html);
        $this->assertStringContainsString('guessable-domain.example', $html);
        $this->assertStringNotContainsString('gues***.example', $html);
        $this->assertStringNotContainsString('id="url-reveal-', $html);
    }

    public function test_search_finds_rows_in_hide_mode_but_keeps_domains_masked(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'guessable-domain.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'guessable-domain']))
            ->assertOk()
            ->getContent();

        // Hit is not blocked — harvest “oracle” accepted — but display stays masked.
        $this->assertStringContainsString('data-id="'.$site->id.'"', $html);
        $this->assertStringContainsString('gues***.example', $html);
        $this->assertStringNotContainsString('guessable-domain.example', $html);
        $this->assertStringContainsString('id="url-reveal-', $html);
    }

    public function test_search_still_works_on_everything_a_buyer_would_type(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'findable-by-name.example');
        $site->update(['site_name' => 'Northern Marketing Weekly']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Northern Marketing']))
            ->assertOk()
            ->assertSee('findable-by-name.example');
    }

    public function test_search_matches_domain_without_prior_reveal(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'never-revealed.example');

        $this->assertSame(0, SiteUrlReveal::where('user_id', $advertiser->id)->count());

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'never-revealed']))
            ->assertOk()
            ->assertSee('never-revealed.example')
            ->assertSee('data-id="'.$site->id.'"', false);
    }

    public function test_search_matches_domain_column_even_when_site_url_differs(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'brand-site.example');
        // site_url does not contain the public domain string — only `domain` does.
        $site->update([
            'site_url' => 'https://cdn-proxy.test/r/'.$site->id,
            'domain' => 'brand-site.example',
            'site_name' => 'Brand Listing Only',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'https://www.brand-site.example/promo']))
            ->assertOk()
            ->assertSee('cdn-proxy.test', false)
            ->assertSee('Brand Listing Only');
    }

    public function test_search_placeholder_explains_open_domain_search(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Results update as you type', false)
            ->assertSee('id="catalogSearchInput"', false);
    }

    public function test_hide_mode_search_placeholder_notes_masked_rows(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Matching rows stay masked until you use the eye', false);
    }

    public function test_free_text_search_does_not_expand_to_all_sites_in_a_language_or_country(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $english = $this->site($publisher, 'alpha-weekly.example');
        $english->update([
            'site_name' => 'Alpha Weekly Digest',
            'language' => 'en',
            'languages' => ['en'],
            'country' => 'us',
            'countries' => ['us'],
            'category' => 'marketing',
        ]);

        $german = $this->site($publisher, 'berlin-journal.example');
        $german->update([
            'site_name' => 'Berlin Business Journal',
            'language' => 'de',
            'languages' => ['de'],
            'country' => 'de',
            'countries' => ['de'],
            'category' => 'marketing',
        ]);

        // Short codes / country labels must not dump every market match.
        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'en']))
            ->assertOk()
            ->assertDontSee('Alpha Weekly Digest')
            ->assertDontSee('alpha-weekly.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Germany']))
            ->assertOk()
            ->assertDontSee('Berlin Business Journal')
            ->assertDontSee('berlin-journal.example');

        // Name match still works.
        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Berlin Business']))
            ->assertOk()
            ->assertSee('Berlin Business Journal')
            ->assertSee('berlin-journal.example');
    }

    // —— The page itself must be worthless to a scraper ————————

    public function test_the_catalog_row_shows_the_real_domain_for_normal_advertisers(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, 'visible-in-the-html.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('visible-in-the-html.example', $html);
        // Open still routes through us so clicks stay attributed.
        $this->assertStringContainsString('/advertiser/go/', $html);
    }

    public function test_sample_article_href_is_our_redirect_not_the_publisher_url(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'sample-href.example');
        $site->update(['example_url' => 'https://sample-href.example/guest-post']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('guest-post', $html);
        $this->assertStringNotContainsString('href="https://sample-href.example/guest-post"', $html);
        $this->assertStringContainsString('/advertiser/go/'.$site->id.'?sample=1', $html);
        $this->assertStringContainsString('catalog-site-details', $html);
        $this->assertStringContainsString('data-id="'.$site->id.'"', $html);
        $this->assertStringContainsString('catalog-sample-url', $html);
        $this->assertStringContainsString('catalog-site-url', $html);
    }

    public function test_description_listing_href_is_our_redirect_not_the_publisher_url(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'desc-href.example');
        $site->update([
            'description' => '<p>See <a href="https://desc-href.example/about">our about page</a>.</p>',
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('our about page', $html);
        $this->assertStringNotContainsString('href="https://desc-href.example/about"', $html);
        $this->assertStringContainsString('/advertiser/go/'.$site->id.'?path=', $html);
    }

    public function test_description_path_visit_stays_on_the_listing_host(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'desc-path.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', [
                'site' => $site->id,
                'path' => '/about?ref=1',
            ]))
            ->assertRedirect('https://desc-path.example/about?ref=1');
    }

    public function test_description_path_visit_rejects_a_host(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'desc-safe.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', [
                'site' => $site->id,
                'path' => '//evil.example/phish',
            ]))
            ->assertRedirect('https://desc-safe.example');
    }

    public function test_sample_article_opens_through_our_redirect(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'sample-redirect.example');
        $site->update(['example_url' => 'https://sample-redirect.example/guest-post']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', ['site' => $site->id, 'sample' => 1]))
            ->assertRedirect('https://sample-redirect.example/guest-post');
    }

    public function test_sample_visit_falls_back_to_the_listing_when_example_url_is_unsafe(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'sample-fallback.example');
        $site->update(['example_url' => 'javascript:alert(1)']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', ['site' => $site->id, 'sample' => 1]))
            ->assertRedirect('https://sample-fallback.example');
    }

    public function test_clicking_through_sends_them_to_the_site(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'visited-once.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $site->id))
            ->assertRedirect('https://visited-once.example');

        // They already see the address in the catalog — no extra disclosure row.
        $this->assertDatabaseMissing('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_clicking_through_in_hide_mode_records_the_visit(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'hide-visit.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $site->id))
            ->assertRedirect('https://hide-visit.example');

        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_VISIT,
        ]);
    }

    public function test_the_redirect_is_closed_to_guests(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'no-guest-visits.example');

        $this->get(route('advertiser.catalog.visit', $site->id))
            ->assertRedirect(route('login'));
    }

    public function test_clicking_through_is_not_a_way_round_a_pause(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => true,
            'catalog.url_reveal.pace.freeze_after' => 3,
            'catalog.url_reveal.pace.freeze_window_minutes' => 30,
        ]);

        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');

        foreach (['p1.example', 'p2.example', 'p3.example'] as $domain) {
            $site = $this->site($publisher, $domain);
            $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', $site->id));
        }

        $blocked = $this->site($publisher, 'should-not-open.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $blocked->id))
            ->assertRedirect(route('advertiser.catalog'));

        $this->assertDatabaseMissing('site_url_reveals', ['site_id' => $blocked->id]);
    }

    public function test_clicking_through_is_not_a_way_round_a_slow_down(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => true,
            'catalog.url_reveal.pace.slow_after' => 2,
            'catalog.url_reveal.pace.slow_window_seconds' => 60,
            'catalog.url_reveal.pace.freeze_after' => 250,
        ]);

        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');

        foreach (['slow-a.example', 'slow-b.example'] as $domain) {
            $site = $this->site($publisher, $domain);
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.catalog.reveal-url', $site->id))
                ->assertOk();
        }

        $blocked = $this->site($publisher, 'slow-should-not-open.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $blocked->id))
            ->assertRedirect(route('advertiser.catalog'));

        $this->assertDatabaseMissing('site_url_reveals', ['site_id' => $blocked->id]);
    }

    public function test_already_opened_visit_still_works_during_slow_down(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => true,
            'catalog.url_reveal.pace.slow_after' => 2,
            'catalog.url_reveal.pace.slow_window_seconds' => 60,
            'catalog.url_reveal.pace.freeze_after' => 250,
        ]);

        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');
        $opened = $this->site($publisher, 'slow-already-open.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $opened->id))
            ->assertOk();
        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $this->site($publisher, 'slow-peer.example')->id))
            ->assertOk();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $opened->id))
            ->assertRedirect('https://slow-already-open.example');
    }

    public function test_pace_does_not_block_visits_outside_hide_mode(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => true,
            'catalog.url_reveal.pace.freeze_after' => 3,
            'catalog.url_reveal.pace.freeze_window_minutes' => 30,
        ]);

        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        // Seed enough disclosure history that a hide-mode visit would freeze —
        // outside hide mode the open redirect must still work.
        foreach (['hist-a.example', 'hist-b.example', 'hist-c.example', 'hist-d.example'] as $domain) {
            SiteUrlReveal::create([
                'user_id' => $advertiser->id,
                'site_id' => $this->site($publisher, $domain)->id,
                'source' => SiteUrlReveal::SOURCE_CATALOG,
            ]);
        }

        $open = $this->site($publisher, 'still-opens.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $open->id))
            ->assertRedirect('https://still-opens.example');

        $this->assertDatabaseMissing('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $open->id,
        ]);
    }
}
