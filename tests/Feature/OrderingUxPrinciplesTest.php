<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class OrderingUxPrinciplesTest extends TestCase
{
    use CreatesContentSubmissions;
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

    private function site(string $language = 'en'): Site
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'UX Principles Site',
            'site_url' => 'https://ux-principles.example',
            'domain' => 'ux-principles.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => $language,
            'countries' => ['us'],
            'languages' => [$language],
            'category' => 'Marketing, PR & Advertising',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_catalog_shows_path_stepper_and_needs_article_readiness(): void
    {
        $advertiser = $this->advertiser();
        $this->site('en');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Market', $html);
        $this->assertStringContainsString('Publishers', $html);
        $this->assertStringContainsString('Content', $html);
        $this->assertStringContainsString('Pay', $html);
        $this->assertStringContainsString('Needs English article', $html);
        $this->assertStringContainsString('Place a guest post · Publishers', $html);
    }

    public function test_catalog_shows_ready_when_matching_article_exists(): void
    {
        Storage::fake('local');
        $advertiser = $this->advertiser();
        $this->site('en');

        $this->createApprovedSubmission($advertiser, null, 0, 'best software tools', 'https://example.com/tools', 'us', 'en');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Ready · English article', false)
            ->assertDontSee('Needs English article', false);
    }

    public function test_content_library_and_checkout_show_path_stepper(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Place a guest post · Content', false)
            ->assertSee('Market', false)
            ->assertSee('Publishers', false);

        $site = $this->site('en');
        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'content_submission_id' => null,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('Place a guest post · Pay', false)
            ->assertSee('Market', false)
            ->assertSee('Publishers', false);
    }

    public function test_cart_drawer_markup_includes_checklist_and_disabled_proceed(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="cartChecklist"', $html);
        $this->assertStringContainsString('id="cartProceedHint"', $html);
        $this->assertStringContainsString('cartLinesMissingArticles', $html);
        $this->assertStringContainsString('Finish the checklist above', $html);
    }
}
