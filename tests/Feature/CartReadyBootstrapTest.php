<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartReadyBootstrapTest extends TestCase
{
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

    private function makeSite(string $slug, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => $slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Cart ready bootstrap listing.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_advertiser_layout_exposes_cart_api_before_the_drawer_script(): void
    {
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $cartJs = (string) file_get_contents(public_path('assets/js/advertiser-cart.js'));

        $this->assertStringContainsString('id="advertiser-cart-config"', $layout);
        $this->assertStringContainsString("route('advertiser.cart.add')", $layout);
        $this->assertStringContainsString('assets/js/advertiser-cart.js', $layout);
        $this->assertDoesNotMatchRegularExpression(
            '/advertiser-cart\.js[^>]+\bdefer\b/',
            $layout
        );
        $this->assertStringContainsString('window.addToCart = bootstrapAddToCart', $cartJs);
        $this->assertStringContainsString('function bootstrapOpenCart', $cartJs);
        $this->assertStringContainsString('if (!sidebar) return false', $cartJs);
        $this->assertStringContainsString('window.__slbOnCartPayload', $layout);
        $this->assertStringContainsString('toggleCartBtn?.addEventListener', $layout);
        $this->assertStringContainsString('if (!cartSidebar)', $layout);
    }

    public function test_catalog_js_falls_back_instead_of_toasting_when_add_to_cart_is_missing(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function catalogEnsureAddToCart', $js);
        $this->assertStringContainsString('function catalogFallbackAddToCart', $js);
        $this->assertStringContainsString('function catalogOpenCart', $js);
        $this->assertStringContainsString('catalogEnsureAddToCart(id, name, finalPrice', $js);
        $this->assertStringContainsString('Array.isArray(window.advertiserCart)', $js);
        $this->assertStringNotContainsString(
            'window.catalogSyncInCartButtons(window.advertiserCart || [])',
            $js
        );

        $toastAt = strpos($js, 'Cart is not ready. Refresh the page and try again.');
        $this->assertNotFalse($toastAt);
        $ensureAt = strpos($js, 'function catalogEnsureAddToCart');
        $this->assertNotFalse($ensureAt);
        $clickGuard = strpos($js, "if (typeof window.addToCart !== 'function')");
        $this->assertFalse(
            $clickGuard,
            'Buy clicks must not toast solely because window.addToCart is late or missing.'
        );
    }

    public function test_catalog_page_ships_csrf_and_cart_add_route_for_the_fallback(): void
    {
        $site = $this->makeSite('odigital');

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="advertiser-cart-config"', $html);
        $this->assertStringContainsString('assets/js/advertiser-cart.js', $html);
        $this->assertStringContainsString('cartAdd:', $html);
        $this->assertStringContainsString('csrf-token', $html);
        $this->assertStringContainsString((string) $site->id, $html);

        $this->assertSame(1, preg_match(
            '/<script type="application\/json" id="advertiser-cart-config">(\{.*?\})<\/script>/s',
            $html,
            $matches
        ));
        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsArray($config);
        $this->assertSame(route('advertiser.cart.add'), $config['addUrl'] ?? null);
        $this->assertNotSame('', $config['csrf'] ?? '');
    }

    public function test_in_cart_buttons_survive_when_a_second_site_is_added(): void
    {
        $first = $this->makeSite('odigital');
        $second = $this->makeSite('digitalfernsehen', [
            'site_name' => 'Digitalfernsehen.de',
            'site_url' => 'https://digitalfernsehen.example',
            'domain' => 'digitalfernsehen.example',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $first->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart_count', 1);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="[^"]*buy-now[^"]*is-in-cart[^"]*"[^>]*data-id="'.$first->id.'"/',
            $html
        );
        $this->assertStringContainsString('data-in-cart="1"', $html);
        $this->assertStringContainsString('In cart', $html);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $second->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart_count', 2);

        $payload = $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $ids = collect($payload['cart'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
        $this->assertArrayHasKey('writing_fee', $payload['cart'][0]);
        $this->assertArrayHasKey('guarantee_offered', $payload['cart'][0]);
        $this->assertArrayHasKey('article_copy', $payload);
    }

    public function test_advertiser_cart_js_parses(): void
    {
        $path = public_path('assets/js/advertiser-cart.js');
        $this->assertFileExists($path);

        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->assertStringContainsString('function bootstrapAddToCart', (string) file_get_contents($path));

            return;
        }

        $result = null;
        $output = [];
        exec(escapeshellarg($node).' --check '.escapeshellarg($path).' 2>&1', $output, $result);
        $this->assertSame(0, $result, implode("\n", $output));
    }

    public function test_rendered_advertiser_layout_cart_script_parses(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required to syntax-check the rendered cart script');
        }

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertGreaterThan(0, preg_match_all(
            '#<script(?![^>]*src=)[^>]*>(.*?)</script>#s',
            $html,
            $scripts
        ));
        $cartScript = '';
        foreach ($scripts[1] as $js) {
            if (str_contains($js, 'function updateCartDisplay') && str_contains($js, 'window.addToCart')) {
                $cartScript = $js;
                break;
            }
        }
        $this->assertNotSame('', $cartScript, 'Advertiser layout cart script was not rendered');
        $this->assertStringContainsString('const pickerMarkup = written ? \'\' : placementIds.map', $cartScript);
        $this->assertStringContainsString('${pickerMarkup}', $cartScript);
        $this->assertStringNotContainsString('${uploadPickers}', $cartScript);
        $this->assertStringNotContainsString("}).join('') : '';", $cartScript);

        $file = sys_get_temp_dir().'/slb-cart-inline-'.uniqid().'.js';
        file_put_contents($file, $cartScript);
        $result = null;
        $output = [];
        exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $output, $result);
        @unlink($file);
        $this->assertSame(0, $result, implode("\n", $output));
    }
}
