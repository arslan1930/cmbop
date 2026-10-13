<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Publisher domains are metered rather than hidden.
 *
 * Anyone allowed to evaluate a site before buying will end up knowing its
 * domain, so the goal is not secrecy — it is that the whole inventory cannot be
 * harvested in an afternoon, and that when a publisher reports being approached
 * directly there is a record of who could have known.
 *
 * Browsing and opening addresses are unlimited — an agency may legitimately work
 * through hundreds — so what most of this file checks is that the real value
 * never reaches the browser unasked, and that a disclosure is always recorded.
 */
class CatalogUrlRevealTest extends TestCase
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

    private function putInHideMode(User $user): User
    {
        $user->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        return $user->fresh();
    }

    private function site(?User $publisher = null, string $domain = 'secret-inventory.example'): Site
    {
        $publisher ??= $this->userWithRole('publisher');

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.uniqid(),
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'example_url' => 'https://'.$domain.'/a-sample-guest-post',
            'da' => 40, 'dr' => 45, 'traffic' => 12000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent', 'link_type' => 'dofollow',
            'description' => 'A listing used to test domain visibility.',
            'verified' => true, 'active' => true,
        ]);
    }

    private function visibility(): SiteUrlVisibility
    {
        return app(SiteUrlVisibility::class);
    }

    // —— Normal vs hide-mode catalog HTML —————————————————————————

    public function test_normal_catalog_shows_real_url_without_eye_controls(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site(domain: 'open-inventory.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('open-inventory.example', $html);
        $this->assertStringNotContainsString('open***.example', $html);
        $this->assertStringNotContainsString('id="url-reveal-', $html);
        $this->assertStringNotContainsString('id="url-hide-', $html);
        $this->assertStringNotContainsString('toggle-url', $html);
        $this->assertStringNotContainsString('catalog-url-eye', $html);
        $this->assertStringNotContainsString('Name and URL hidden', $html);
        $this->assertStringNotContainsString('Masked for publishers', $html);
        $this->assertStringNotContainsString('catalog-hide-mode-banner', $html);
        $this->assertStringContainsString('Mass-copying addresses can temporarily hide', $html);
        $this->assertStringContainsString('Name, domain', $html);
        $this->assertStringContainsString('site-open-link', $html);
        $this->assertStringContainsString('rel="noopener nofollow"', $html);
        $this->assertStringContainsString('/advertiser/go/', $html);
        $this->assertStringNotContainsString('catalog-site-url--hidden', $html);
        $this->assertStringNotContainsString('catalog-site-url__reveal', $html);
        $this->assertStringNotContainsString('fa-solid fa-lock', $html);
    }

    public function test_hide_mode_catalog_masks_url_and_shows_eye(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $this->site(domain: 'secret-inventory.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('secret-inventory.example', $html);
        $this->assertStringContainsString('secr***.example', $html);
        $this->assertStringContainsString('id="url-reveal-', $html);
        $this->assertStringContainsString('catalog-url-eye', $html);
        $this->assertStringContainsString('catalog-hide-mode-banner', $html);
        $this->assertStringContainsString('Name and URL hidden', $html);
        $this->assertStringContainsString('Use the eye to show or hide a row', $html);
        $this->assertStringContainsString('rows stay masked', $html);
        $this->assertStringContainsString('catalog-site-url--hidden', $html);
        $this->assertStringContainsString('fa-lock', $html);
        $this->assertStringContainsString('>Reveal</button>', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<a[^>]+class="[^"]*site-open-link[^"]*"[^>]*>\s*secr\*\*\*\.example/',
            $html
        );
    }

    public function test_reveal_outside_hide_mode_returns_hide_mode_only(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site(domain: 'no-eye-outside.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertStatus(403)
            ->assertJsonPath('code', 'hide_mode_only');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.hide-url', $site->id))
            ->assertStatus(403)
            ->assertJsonPath('code', 'hide_mode_only');
    }

    public function test_the_sample_article_does_not_give_the_domain_away_in_hide_mode(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $this->site(domain: 'sample-leak.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // The sample post lives on the same domain, so printing its URL would
        // hand over the address the row is masking.
        $this->assertStringNotContainsString('a-sample-guest-post', $html);
        $this->assertStringContainsString('Use the eye to show this listing', $html);
    }

    public function test_sample_and_claim_need_no_reveal_outside_hide_mode(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site(domain: 'open-sample.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('a-sample-guest-post', $html);
        $this->assertStringNotContainsString('href="https://open-sample.example/a-sample-guest-post"', $html);
        $this->assertStringNotContainsString('Use the eye to show this listing', $html);
        $this->assertStringContainsString('data-site-url="https://open-sample.example"', $html);
        $this->assertStringContainsString('btn-claim-site', $html);
    }

    public function test_normal_catalog_always_shows_the_real_domain(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site(domain: 'already-visible.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('already-visible.example');
    }

    public function test_eye_reveal_stays_visible_after_catalog_reload(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site(domain: 'sticky-eye.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertOk()
            ->assertJsonPath('url', 'sticky-eye.example')
            ->assertJsonPath('sticky', true);

        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
        $this->assertNull(
            SiteUrlReveal::query()
                ->where('user_id', $advertiser->id)
                ->where('site_id', $site->id)
                ->value('concealed_at')
        );

        // Fresh page load must still show the full host — not remask and force
        // another eye click before hide works.
        $this->visibility()->flush();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('sticky-eye.example', $html);
        $this->assertStringNotContainsString('stic***.example', $html);
    }

    public function test_missing_reveals_table_is_healed_so_eye_stays_sticky(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site(domain: 'heal-sticky.example');

        Schema::dropIfExists('site_url_reveals');
        SiteUrlVisibility::forgetSchemaCache();
        $this->visibility()->flush();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertOk()
            ->assertJsonPath('url', 'heal-sticky.example');

        $this->assertTrue(Schema::hasTable('site_url_reveals'));
        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);

        $this->visibility()->flush();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('heal-sticky.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.hide-url', $site->id))
            ->assertOk()
            ->assertJsonPath('masked', 'heal***.example');
    }

    public function test_hiding_an_open_address_keeps_it_masked_after_reload(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site(domain: 'toggle-me.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertOk()
            ->assertJsonPath('url', 'toggle-me.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.hide-url', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('masked', 'togg***.example');

        // Audit trail stays; only the display preference flipped.
        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
        $this->assertNotNull(
            SiteUrlReveal::query()
                ->where('user_id', $advertiser->id)
                ->where('site_id', $site->id)
                ->value('concealed_at')
        );

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('toggle-me.example', $html);
        $this->assertStringContainsString('togg***.example', $html);
    }

    public function test_opening_a_hidden_address_again_does_not_count_as_a_new_disclosure(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site(domain: 'reopen-me.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertOk();
        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.hide-url', $site->id))
            ->assertOk();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertOk()
            ->assertJsonPath('url', 'reopen-me.example');

        $this->assertSame(1, SiteUrlReveal::where('user_id', $advertiser->id)->count());
        $this->assertNull(
            SiteUrlReveal::query()
                ->where('user_id', $advertiser->id)
                ->where('site_id', $site->id)
                ->value('concealed_at')
        );

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('reopen-me.example');
    }

    public function test_the_catalog_wires_a_sticky_hide_endpoint(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('hideUrl:', $html);
        $this->assertStringContainsString('function requestConceal(', $js);
        $this->assertStringContainsString('hideUrlEndpoint', $js);
        $this->assertStringContainsString("createElement(bareHost ? 'a' : 'span')", $js);
        $this->assertStringContainsString("setAttribute('rel', 'noopener nofollow')", $js);
        $this->assertStringContainsString('catalog-site-url--hidden', $js);
        $this->assertStringContainsString('fa-solid fa-lock', $js);
    }

    // —— Revealing ——————————————————————————————————————————————

    public function test_asking_returns_the_domain_and_records_it(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site(domain: 'revealed-once.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('url', 'revealed-once.example');

        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_CATALOG,
        ]);
    }

    public function test_asking_twice_is_one_disclosure(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site();

        $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', $site->id))->assertOk();
        $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', $site->id))->assertOk();

        // Re-opening something they have already seen must not cost a second
        // allowance, or the sticky reveal is a trap rather than a convenience.
        $this->assertSame(1, SiteUrlReveal::where('user_id', $advertiser->id)->count());
    }

    public function test_a_guest_cannot_ask(): void
    {
        $site = $this->site();

        $this->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertStatus(401);
    }

    public function test_volume_alone_never_blocks_anyone(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');

        // An agency planning a campaign legitimately works through hundreds of
        // listings across a day. There is no daily ceiling to hit — the checks
        // are about pace, so a human rhythm passes however much of it there is.
        // Deliberately uneven: a person pauses to read, gets distracted, comes
        // back. That irregularity is the whole difference between a buyer and a
        // loop, so a realistic test has to have it.
        $gaps = [7, 34, 12, 3, 58, 19, 5, 91, 26, 11, 44, 8];

        for ($i = 1; $i <= 300; $i++) {
            $site = $this->site($publisher, "volume-{$i}.example");

            $this->travel($gaps[$i % count($gaps)])->seconds();

            $this->actingAs($advertiser)
                ->postJson(route('advertiser.catalog.reveal-url', $site->id))
                ->assertOk()
                ->assertJsonPath('url', "volume-{$i}.example");
        }

        $this->assertSame(300, SiteUrlReveal::where('user_id', $advertiser->id)->count());
        $this->travelBack();
    }

    public function test_a_big_basket_is_never_refused(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        // Nothing should ever stand between someone and a purchase.
        for ($i = 1; $i <= 30; $i++) {
            $site = $this->site($publisher, "bigbasket-{$i}.example");

            $this->actingAs($advertiser)
                ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
                ->assertOk();
        }
    }

    // —— Owned ————————————————————————————————————————————————————

    public function test_putting_a_site_in_the_cart_reveals_it(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site(domain: 'in-my-cart.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk();

        // Hide-mode rows are masked — carting unlocks identity for checkout.
        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_CART,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('in-my-cart.example');
    }

    public function test_cart_outside_hide_mode_does_not_record_a_reveal(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site(domain: 'open-cart.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk();

        $this->assertDatabaseMissing('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_cart_add_does_not_unmask_the_catalog_during_a_slow_down(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => true,
            'catalog.url_reveal.pace.slow_after' => 2,
            'catalog.url_reveal.pace.slow_window_seconds' => 60,
            'catalog.url_reveal.pace.freeze_after' => 250,
        ]);

        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');

        foreach (['cart-slow-a.example', 'cart-slow-b.example'] as $domain) {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.catalog.reveal-url', $this->site($publisher, $domain)->id))
                ->assertOk();
        }

        $blocked = $this->site($publisher, 'cart-slow-should-stay-masked.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $blocked->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $blocked->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('cart-slow-should-stay-masked.example');
    }

    public function test_cart_add_does_not_unmask_the_catalog_while_frozen(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => true,
            'catalog.url_reveal.pace.freeze_after' => 3,
            'catalog.url_reveal.pace.freeze_window_minutes' => 30,
        ]);

        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $publisher = $this->userWithRole('publisher');

        foreach (['cart-freeze-a.example', 'cart-freeze-b.example', 'cart-freeze-c.example'] as $domain) {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.catalog.reveal-url', $this->site($publisher, $domain)->id))
                ->assertOk();
        }

        $blocked = $this->site($publisher, 'cart-freeze-should-stay-masked.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $blocked->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $blocked->id,
        ]);
    }

    // —— Who else can see ————————————————————————————————————————

    public function test_a_publisher_always_sees_their_own_listing(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'my-own-site.example');

        $this->assertTrue($this->visibility()->canSee($publisher, $site));
        $this->assertSame('my-own-site.example', $this->visibility()->hostFor($publisher, $site));
    }

    public function test_staff_are_not_the_audience_this_protects_against(): void
    {
        $admin = $this->userWithRole('admin');
        $site = $this->site(domain: 'staff-can-see.example');

        $this->assertSame('staff-can-see.example', $this->visibility()->hostFor($admin, $site));
    }

    public function test_a_guest_gets_nothing(): void
    {
        $site = $this->site(domain: 'no-guests.example');

        $this->assertFalse($this->visibility()->canSee(null, $site));
        $this->assertSame('no-g***.example', $this->visibility()->hostFor(null, $site));
    }

    // —— Masking itself ————————————————————————————————————————

    public function test_the_mask_keeps_the_shape_without_the_answer(): void
    {
        $vis = $this->visibility();

        $this->assertSame('exam***.com', $vis->mask('https://example.com'));
        $this->assertSame('exam***.com', $vis->mask('https://www.example.com/some/path'));
        $this->assertSame('blog***.uk', $vis->mask('http://blog.co.uk'));
        $this->assertSame('••••••', $vis->mask(''));
    }

    public function test_ordered_sites_stay_visible_after_checkout(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site(domain: 'bought-this.example');

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 150, 'tax' => 0, 'total_amount' => 150,
            'payment_method' => 'wallet', 'payment_status' => 'paid',
            'status' => 'processing', 'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 150, 'publisher_price' => 120,
            'content_link' => 'https://example.com/article.docx',
        ]);

        // Buying it is the strongest claim there is; the reveal is recorded at
        // cart time and the order pages have always shown the address.
        $this->visibility()->reveal($advertiser, $site, SiteUrlReveal::SOURCE_ORDER);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('bought-this.example');
    }
}
