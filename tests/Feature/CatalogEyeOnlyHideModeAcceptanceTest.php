<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * End-state policy for eye-only-in-hide-mode:
 *
 * - Normals: real URL, no eye, open domain search.
 * - Hide mode: dual mask, eye, banner.
 * - Reveal API blocked outside hide mode; after clear → open again.
 * - Leave alone: bulk deals, cart names, dashboard recommended, emails.
 */
class CatalogEyeOnlyHideModeAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role, array $attrs = []): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ], $attrs));
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function putInHideMode(User $user): User
    {
        $user->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        return $user->fresh();
    }

    private function site(string $domain, string $name, array $extra = []): Site
    {
        $publisher = $this->userWithRole('publisher');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain.'/blog',
            'domain' => $domain,
            'da' => 40,
            'dr' => 55,
            'traffic' => 18000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Eye-only hide-mode acceptance.',
            'example_url' => 'https://'.$domain.'/sample-guest-post',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    // —— Normals ————————————————————————————————————————————————

    public function test_normals_see_real_url_no_eye_and_open_domain_search(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site('normal-open.example', 'Normal Open Brand');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'normal-open']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Normal Open Brand', $html);
        $this->assertStringContainsString('normal-open.example', $html);
        $this->assertStringNotContainsString('catalog-url-eye', $html);
        $this->assertStringNotContainsString('id="url-reveal-', $html);
        $this->assertStringNotContainsString('catalog-hide-mode-banner', $html);
        $this->assertStringNotContainsString('We’ve temporarily hidden listing names', $html);
        $this->assertStringContainsString('Name, domain', $html);
    }

    // —— Hide mode ——————————————————————————————————————————————

    public function test_hide_mode_dual_masks_with_eye_and_banner(): void
    {
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $this->site('dual-mask.example', 'Dual Mask Brand');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-hide-mode-banner', $html);
        $this->assertStringContainsString('We’ve temporarily hidden listing names and website addresses', $html);
        $this->assertStringContainsString('catalog-url-eye', $html);
        $this->assertStringContainsString('id="url-reveal-', $html);
        $this->assertStringContainsString('Show site name and URL', $html);
        $this->assertStringNotContainsString('Dual Mask Brand', $html);
        $this->assertStringNotContainsString('dual-mask.example', $html);
        $this->assertStringContainsString('inCatalogHideMode: true', $html);
        $this->assertMatchesRegularExpression('/id="catalogResults"[^>]*data-catalog-hide-mode="1"/', $html);
    }

    // —— Reveal API + clear ——————————————————————————————————————

    public function test_reveal_api_blocked_outside_hide_mode(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site('no-reveal-api.example', 'No Reveal Api Brand');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertStatus(403)
            ->assertJsonPath('code', 'hide_mode_only');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.hide-url', $site->id))
            ->assertStatus(403)
            ->assertJsonPath('code', 'hide_mode_only');
    }

    public function test_after_admin_clears_hide_mode_catalog_is_open_again(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));
        $site = $this->site('cleared-open.example', 'Cleared Open Brand');

        $hidden = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('catalog-url-eye', $hidden);
        $this->assertStringNotContainsString('cleared-open.example', $hidden);

        $this->actingAs($admin)
            ->post(route('admin.catalog-activity.lift-hide', $advertiser->id))
            ->assertRedirect();

        $advertiser->refresh();
        $this->assertFalse($advertiser->inCatalogHideMode());

        $open = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cleared Open Brand', $open);
        $this->assertStringContainsString('cleared-open.example', $open);
        $this->assertStringNotContainsString('catalog-url-eye', $open);
        $this->assertStringNotContainsString('catalog-hide-mode-banner', $open);
        $this->assertStringContainsString('inCatalogHideMode: false', $open);
        $this->assertMatchesRegularExpression('/id="catalogResults"[^>]*data-catalog-hide-mode="0"/', $open);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertStatus(403)
            ->assertJsonPath('code', 'hide_mode_only');
    }

    public function test_after_hide_expires_catalog_is_open_again(): void
    {
        $advertiser = $this->userWithRole('advertiser', [
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->subMinute(),
        ]);
        $this->site('expired-open.example', 'Expired Open Brand');

        $this->assertFalse($advertiser->inCatalogHideMode());

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Expired Open Brand', $html);
        $this->assertStringContainsString('expired-open.example', $html);
        $this->assertStringNotContainsString('catalog-url-eye', $html);
        $this->assertStringNotContainsString('catalog-hide-mode-banner', $html);
        $this->assertMatchesRegularExpression('/id="catalogResults"[^>]*data-catalog-hide-mode="0"/', $html);
    }

    // —— Leave alone ————————————————————————————————————————————

    public function test_bulk_deals_keep_real_identity_in_hide_mode(): void
    {
        $this->site('bulk-leave-alone.example', 'Bulk Leave Alone Brand', [
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bulk-deal-card', $html);
        $this->assertStringContainsString('bulk-leave-alone.example', $html);
        $this->assertStringContainsString('Bulk Leave Alone Brand', $html);
    }

    public function test_cart_keeps_real_site_name_in_hide_mode(): void
    {
        $site = $this->site('cart-leave-alone.example', 'Cart Leave Alone Brand');
        $advertiser = $this->putInHideMode($this->userWithRole('advertiser'));

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id, 'quantity' => 1])
            ->assertOk();

        $line = collect(session('cart', []))
            ->first(fn ($row) => (int) ($row['id'] ?? 0) === (int) $site->id);

        $this->assertNotNull($line);
        $this->assertSame('Cart Leave Alone Brand', $line['name'] ?? null);
        $this->assertSame('https://cart-leave-alone.example/blog', $line['url'] ?? null);
    }

    public function test_dashboard_recommended_stays_real_for_normals_without_eye(): void
    {
        $this->site('dash-recommended.example', 'Dash Recommended Brand');
        $advertiser = $this->userWithRole('advertiser');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Recommended for you', $html);
        $this->assertStringContainsString('dash-recommended.example', $html);
        $this->assertStringNotContainsString('catalog-url-eye', $html);
        $this->assertStringNotContainsString('url-reveal-', $html);
        $this->assertStringNotContainsString('catalog-hide-mode-banner', $html);
    }

    public function test_order_emails_use_raw_site_fields_not_visibility_masking(): void
    {
        $paymentMail = file_get_contents(resource_path('views/emails/order-payment-confirmed.blade.php'));
        $statusMail = file_get_contents(resource_path('views/emails/orders/status-changed.blade.php'));

        $this->assertStringContainsString('$item->site_name', $paymentMail);
        $this->assertStringContainsString('$item->site_url', $paymentMail);
        $this->assertStringContainsString('site_name', $statusMail);
        $this->assertStringNotContainsString('SiteUrlVisibility', $paymentMail);
        $this->assertStringNotContainsString('SiteUrlVisibility', $statusMail);
        $this->assertStringNotContainsString('hostFor', $paymentMail);
        $this->assertStringNotContainsString('maskName', $statusMail);
    }
}
