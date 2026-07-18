<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdvertiserStartFlowGuidanceTest extends TestCase
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

    public function test_new_advertiser_dashboard_teaches_upload_first_path(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Place a guest post', false)
            ->assertSee('Upload your article', false)
            ->assertSee(route('advertiser.content-library', ['upload' => 1]), false)
            ->assertDontSee('Order an article', false)
            ->assertDontSee('Coming soon', false);
    }

    public function test_returning_advertiser_without_article_cta_points_to_library_upload(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Place a guest post', false)
            ->assertSee(route('advertiser.content-library', ['upload' => 1]), false);
    }

    public function test_returning_advertiser_with_orderable_article_cta_points_to_catalog(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Place a guest post', false)
            ->assertSee(route('advertiser.catalog'), false);
    }

    public function test_catalog_shows_missing_article_guidance_when_none_approved(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Checkout needs an', false)
            ->assertSee('approved', false)
            ->assertSee(route('advertiser.content-library', ['upload' => 1]), false)
            ->assertDontSee('Order an article', false);
    }

    public function test_catalog_hides_missing_article_guidance_when_approved_exists(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Checkout needs an', false);
    }

    public function test_content_library_empty_state_explains_path(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('upload', false)
            ->assertSee('.docx', false)
            ->assertSee('No articles yet', false)
            ->assertSee('Upload article', false)
            ->assertDontSee('Order an article', false);
    }

    private function makeCompletedOrder(User $advertiser): Order
    {
        return Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
    }
}
