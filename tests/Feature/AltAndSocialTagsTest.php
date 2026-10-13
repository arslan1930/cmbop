<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AltAndSocialTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_public_pages_include_complete_og_and_x_tags(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:type" content="website"', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('assets/brand/web/og-share-1200x630.png', $html);
        $this->assertStringContainsString('property="og:site_name" content="SEOLinkBuildings"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('name="twitter:site" content="@seolinkbuildings"', $html);
        $this->assertStringContainsString('name="twitter:title"', $html);
        $this->assertStringContainsString('name="twitter:description"', $html);
        $this->assertStringContainsString('name="twitter:image"', $html);
        $this->assertStringNotContainsString('noindex, nofollow', $html);
    }

    public function test_advertiser_pages_have_social_tags_noindex_and_payment_alts(): void
    {
        $advertiser = $this->userWithRole('advertiser', [
            'name' => 'Ada Lovelace',
            'avatar' => 'https://avatars.example/ada.png',
        ]);

        $checkout = (string) $this->actingAs($advertiser)
            ->withSession(['cart' => [$this->cartLine()]])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertPrivateSocialTags($checkout, 'Checkout · SEOLinkBuildings');
        $this->assertStringContainsString('alt="Visa"', $checkout);
        $this->assertStringContainsString('alt="Mastercard"', $checkout);
        $this->assertStringContainsString('alt="PayPal"', $checkout);
        $this->assertStringContainsString('alt="Ada Lovelace"', $checkout);

        $catalog = (string) $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertPrivateSocialTags($catalog, 'Catalog · SEOLinkBuildings');
    }

    public function test_publisher_and_admin_layouts_have_social_tags_and_stay_noindex(): void
    {
        $publisher = $this->userWithRole('publisher');
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisher->active_role_id,
            'balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $publisherHtml = (string) $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->getContent();
        $this->assertPrivateSocialTags($publisherHtml, 'SEOLinkBuildings');

        $admin = $this->userWithRole('admin');
        $adminHtml = (string) $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();
        $this->assertPrivateSocialTags($adminHtml, 'SEOLinkBuildings');
    }

    private function assertPrivateSocialTags(string $html, string $titleNeedle): void
    {
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString($titleNeedle, $html);
        $this->assertStringContainsString('property="og:type" content="website"', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('assets/brand/web/og-share-1200x630.png', $html);
        $this->assertStringContainsString('property="og:site_name" content="SEOLinkBuildings"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('name="twitter:site" content="@seolinkbuildings"', $html);
        $this->assertStringContainsString('name="twitter:title"', $html);
        $this->assertStringContainsString('name="twitter:description"', $html);
        $this->assertStringContainsString('name="twitter:image"', $html);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
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
            'site_name' => 'Social Tags Site',
            'site_url' => 'https://social-tags.example',
            'domain' => 'social-tags.example',
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
            'description' => 'Social tags fixture.',
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
