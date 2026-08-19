<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartDrawerDensityTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_cart_drawer_copy_and_totals_contract(): void
    {
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));

        $this->assertStringContainsString('id="cartChecklist"', $layout);
        $this->assertStringContainsString('id="cartProceedHint"', $layout);
        $this->assertStringContainsString('id="cartScheduleHint"', $layout);
        $this->assertStringContainsString('cartSchedule', $layout);
        $this->assertStringContainsString('class="cart-totals d-none"', $layout);
        $this->assertMatchesRegularExpression(
            '/id="checkoutFromCart"[^>]*\bdisabled\b/',
            $layout
        );
        $this->assertStringContainsString('itemKeyAttr', $layout);
        $this->assertStringContainsString('sensitiveAttr', $layout);
        $this->assertStringContainsString('Assign an article to at least one website to checkout', $layout);
        $this->assertStringContainsString('Sites without articles stay in your cart.', $layout);
        $this->assertStringNotContainsString('Assign a document to at least one website to checkout', $layout);
        $this->assertStringNotContainsString('Sites without documents stay in your cart.', $layout);
        $this->assertStringContainsString('1 article still needed', $layout);
        $this->assertStringContainsString('itemKey.replace', $layout);
        $this->assertStringNotContainsString('1 site needs an article', $layout);
        $this->assertStringContainsString('id="cartTotalLabel"', $layout);
        $this->assertStringContainsString('Pay now', $layout);
        $this->assertStringContainsString('In cart €', $layout);
        $this->assertStringContainsString('cart-checklist__status', $layout);
        $this->assertStringContainsString('Pay sites that have an article. Others stay in the cart.', $layout);
        $this->assertStringNotContainsString('Assign a document to each website', $layout);
        $this->assertStringNotContainsString('Before Pay', $layout);
        $this->assertStringNotContainsString('Order document', $layout);
        $this->assertStringContainsString('Upload article', $layout);
        $this->assertStringContainsString('Upload another', $layout);
        $this->assertStringContainsString('titleLooksLikeId', $layout);
        $this->assertMatchesRegularExpression('/cart-item-remove[^>]*>\s*Remove\s*</', $layout);
        $this->assertStringNotContainsString('Articles attached — proceed to pay', $layout);
        $this->assertStringNotContainsString("selectedId ? 'Attached'", $layout);
        $this->assertStringContainsString('Decrease placements', $layout);
        $this->assertStringContainsString('Placements — each needs its own article', $layout);
        $this->assertStringContainsString('cart-item-meta', $layout);
        $this->assertStringContainsString("metricBits.push('DA '", $layout);
        $this->assertStringContainsString("€' + unitPrice + ' × '", $layout);
        $this->assertStringNotContainsString('each</div>', $layout);
        $this->assertStringContainsString('cart-keep-browsing', $layout);
        $this->assertStringContainsString("document.body.classList.add('cart-open')", $layout);
        $this->assertStringContainsString("document.body.classList.remove('cart-open')", $layout);
        $this->assertStringContainsString('What happens after you pay', $layout);
        $this->assertStringContainsString('buy-confidence', $layout);
        $this->assertStringNotContainsString('btn-outline-secondary w-100 mt-2', $layout);
    }

    public function test_cart_css_owns_checklist_and_compact_spacing(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/cart.css'));
        $stepper = (string) file_get_contents(resource_path('views/advertiser/wizard/_stepper.blade.php'));

        $this->assertStringContainsString('.cart-checklist', $css);
        $this->assertStringContainsString('#checkoutFromCart:disabled', $css);
        $this->assertStringContainsString('padding: 12px 16px', $css);
        $this->assertStringContainsString('.cart-item-meta', $css);
        $this->assertStringContainsString('.cart-keep-browsing', $css);
        $this->assertStringContainsString('body.cart-open .slb-toast-stack', $css);
        $this->assertStringContainsString('bottom: auto', $css);
        $this->assertStringContainsString('.cart-totals__held', $css);
        $this->assertStringContainsString('.cart-schedule-hint', $css);
        $this->assertStringContainsString('width: min(420px, 94vw)', $css);
        $this->assertStringContainsString('max-height: 100vh', $css);
        $this->assertStringContainsString('flex: 1 1 auto', $css);
        $this->assertDoesNotMatchRegularExpression('/^\s*height:\s*100vh;/m', $css);

        $this->assertStringNotContainsString('.cart-checklist', $stepper);
        $this->assertStringNotContainsString('#checkoutFromCart:disabled', $stepper);
    }

    public function test_catalog_still_renders_cart_drawer(): void
    {
        $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('id="cartChecklist"', false)
            ->assertSee('id="checkoutFromCart"', false)
            ->assertSee('Assign an article to at least one website to checkout', false)
            ->assertDontSee('Assign a document to at least one website to checkout', false)
            ->assertSee('buy-confidence', false)
            ->assertSee('What happens after you pay', false)
            ->assertDontSee('Assign a document to each website', false)
            ->assertDontSee('Before Pay', false);
    }
}
