<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartPricingService;
use App\Services\SitePromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SitePromotionTest extends TestCase
{
    use RefreshDatabase;

    private function publisherWithWallet(float $balance = 50): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return $user;
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Promo Site',
            'site_url' => 'https://promo.example',
            'domain' => 'promo.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => '3',
            'description' => 'Test promo site',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_publisher_can_feature_site_with_wallet(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $site->refresh();
        $this->assertTrue($site->isFeatured());
        $this->assertSame(40.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
    }

    public function test_feature_requires_sufficient_balance(): void
    {
        $publisher = $this->publisherWithWallet(5);
        $site = $this->site($publisher);

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertStatus(422)
            ->assertJson(['needs_top_up' => true]);
    }

    public function test_bulk_discount_applies_for_three_to_five_articles(): void
    {
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);
        app(SitePromotionService::class)->joinBulkDiscount($site, 10);
        $site->refresh();

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site, null, 3);
        // list = 100 * 1.15 = 115; 10% off => 103.5
        $this->assertSame(115.0, $pricing['list_total']);
        $this->assertSame(10.0, $pricing['discount_percent']);
        $this->assertSame(103.5, $pricing['total']);

        $noBulk = app(CartPricingService::class)->priceForAdvertiser($site, null, 2);
        $this->assertSame(0.0, $noBulk['discount_percent']);
        $this->assertSame(115.0, $noBulk['total']);
    }

    public function test_custom_discount_and_expiry_notification(): void
    {
        Mail::fake();
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);

        $this->actingAs($publisher)->postJson(route('publisher.sites.discount', $site->id), [
            'percent' => 20,
            'days' => 1,
        ])->assertOk();

        $site->refresh();
        $this->assertTrue($site->hasActiveCustomDiscount());

        $site->forceFill([
            'custom_discount_ends_at' => now()->subMinute(),
            'custom_discount_notified_at' => null,
        ])->save();

        $sent = app(SitePromotionService::class)->notifyExpiredCustomDiscounts();
        $this->assertSame(1, $sent);
        Mail::assertQueued(\App\Mail\SiteDiscountEnded::class);
    }

    public function test_feature_from_stripe_payment_applies_without_wallet_debit(): void
    {
        $publisher = $this->publisherWithWallet(5);
        $site = $this->site($publisher);

        $result = app(SitePromotionService::class)->featureFromStripePayment(
            $site,
            $publisher,
            'cs_test_feature_audit_1'
        );

        $this->assertTrue($result['success']);
        $site->refresh();
        $this->assertTrue($site->isFeatured());
        $this->assertSame(5.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
        $this->assertDatabaseHas('site_feature_purchases', [
            'site_id' => $site->id,
            'payment_method' => 'stripe',
            'stripe_session_id' => 'cs_test_feature_audit_1',
        ]);
    }
}
