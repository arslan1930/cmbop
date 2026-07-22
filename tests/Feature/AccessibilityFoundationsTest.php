<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessibilityFoundationsTest extends TestCase
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

    public function test_public_pages_have_skip_link_and_main_landmark(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="#main-content"', false)
            ->assertSee('Skip to main content', false)
            ->assertSee('<main id="main-content"', false)
            ->assertSee('aria-label="SEOLinkBuildings on LinkedIn"', false);
    }

    public function test_advertiser_shell_has_landmarks_and_cart_dialog(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('href="#main-content"', false)
            ->assertSee('Skip to main content', false)
            ->assertSee('<main id="main-content"', false)
            ->assertSee('<nav id="sidebar" aria-label="Advertiser"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('id="cartTitle"', false)
            ->assertSee('aria-controls="sidebar"', false)
            ->assertSee('class="nav-label"', false);
    }

    public function test_register_radiogroup_has_describedby_hint(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('id="roleHint"', false)
            ->assertSee('aria-describedby="roleHint"', false)
            ->assertSee('role="radiogroup"', false);
    }

    public function test_testimonial_carousel_indicators_are_labeled(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Slide 1"', false)
            ->assertSee('aria-label="Slide 2"', false);
    }
}
