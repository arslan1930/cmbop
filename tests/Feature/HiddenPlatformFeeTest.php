<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CartPricingService;
use App\Services\PlatformFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HiddenPlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = $this->role($roleName);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, float $price = 100): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Fee Test Site',
            'site_url' => 'https://fee-test.example',
            'domain' => 'fee-test.example',
            'price' => $price,
            'active' => true,
            'verified' => true,
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'link_type' => 'dofollow',
            'category' => 'Technology',
            'publication_time' => '3',
            'description' => 'Fee test site',
        ]);
    }

    public function test_publisher_websites_show_entered_base_price_not_marked_up(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, 100);

        $this->assertSame(100.0, (float) $site->price);
        $this->assertSame(113.0, app(PlatformFeeService::class)->advertiserBase((float) $site->price));

        // Publisher listing price stays at entered base; advertiser fee is never applied here.
        $this->assertNotSame(
            (float) $site->price,
            app(PlatformFeeService::class)->advertiserBase((float) $site->price)
        );

        $this->actingAs($publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertDontSee('platform fee', false)
            ->assertDontSee('commission', false);

        // Websites table is AJAX-loaded HTML; publisher must see entered base, not fee-inflated price.
        $this->actingAs($publisher)
            ->get(route('publisher.sites.ajax'))
            ->assertOk()
            ->assertSee('€100.00', false)
            ->assertDontSee('€113.00', false);
    }

    public function test_advertiser_catalog_applies_tiered_price_without_fee_copy(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($publisher, 100);

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(113.0, $pricing['total']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('platform fee', false)
            ->assertDontSee('commission', false);
    }

    public function test_checkout_snapshots_publisher_price_and_payout_uses_it(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, 100);

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(100.0, $pricing['publisher_price']);
        $this->assertSame(13.0, $pricing['platform_fee_percent']);
        $this->assertSame(113.0, $pricing['total']);

        $item = OrderItem::make([
            'price' => $pricing['total'],
            'additional_price' => 0,
            'publisher_price' => $pricing['publisher_price'],
            'platform_fee_percent' => $pricing['platform_fee_percent'],
            'platform_fee_amount' => $pricing['platform_fee_amount'],
        ]);

        $this->assertSame(100.0, $item->publisherPayoutAmount());
        $this->assertSame(13.0, $item->platformFeeAmount());
    }

    public function test_fee_tiers_match_config(): void
    {
        $fees = app(PlatformFeeService::class);

        $this->assertSame(15.0, $fees->feePercentForBase(50));
        $this->assertSame(13.0, $fees->feePercentForBase(100));
        $this->assertSame(12.0, $fees->feePercentForBase(300));
        $this->assertSame(10.0, $fees->feePercentForBase(1000));
    }
}
