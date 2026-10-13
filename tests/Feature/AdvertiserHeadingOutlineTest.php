<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertiserHeadingOutlineTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->advertiser->roles()->attach($role->id);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keyAdvertiserPages(): array
    {
        return [
            'dashboard' => ['advertiser.dashboard', 'Dashboard · SEOLinkBuildings'],
            'catalog' => ['advertiser.catalog', 'Catalog · SEOLinkBuildings'],
            'checkout' => ['advertiser.checkout', 'Checkout · SEOLinkBuildings'],
            'orders' => ['advertiser.orders', 'My Orders · SEOLinkBuildings'],
        ];
    }

    #[DataProvider('keyAdvertiserPages')]
    public function test_key_pages_have_one_h1_and_a_full_title(string $route, string $title): void
    {
        $request = $this->actingAs($this->advertiser);
        if ($route === 'advertiser.checkout') {
            $request = $request->withSession(['cart' => [$this->cartLine()]]);
        }

        $html = (string) $request
            ->get(route($route))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString('<title>'.$title.'</title>', $html);
        $this->assertStringNotContainsString('<h3 class="nc-title"', $html);
        $this->assertStringNotContainsString('<h5 id="cartTitle"', $html);
        $this->assertStringContainsString('id="ncTitle"', $html);
        $this->assertStringContainsString('id="cartTitle"', $html);
        $this->assertDoesNotMatchRegularExpression('/<h[1-6][^>]*>\s*Notifications\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/<h[1-6][^>]*>\s*Your Cart\s*</', $html);
    }

    public function test_checkout_wizard_chrome_is_not_a_second_h1(): void
    {
        $html = (string) $this->actingAs($this->advertiser)
            ->withSession(['cart' => [$this->cartLine()]])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Place a guest post · Pay', $html);
        $this->assertStringContainsString('wizard-chrome__title', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Place a guest post/', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Checkout\s*</', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function cartLine(): array
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Heading Outline Site',
            'site_url' => 'https://heading-outline.example',
            'domain' => 'heading-outline.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Heading outline fixture.',
            'verified' => true,
            'active' => true,
        ]);

        return [
            'id' => $site->id,
            'name' => $site->site_name,
            'price' => 46,
            'quantity' => 1,
            'language' => 'en',
            'content_submission_id' => null,
        ];
    }
}
