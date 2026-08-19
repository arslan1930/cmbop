<?php

namespace Tests\Feature;

use App\Mail\SiteDiscountEnded;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteFeaturePurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartPricingService;
use App\Services\SitePromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_feature_succeeds_when_existing_featured_until_is_unparseable(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->update(['featured_until' => now()->addDays(3)]);
        DB::table('sites')->where('id', $site->id)->update([
            'featured_until' => 'not-a-date',
        ]);

        $this->assertFalse($site->fresh()->isFeatured());

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($site->fresh()->isFeatured());
        $this->assertSame(40.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
    }

    public function test_promo_ajax_ok_when_sibling_promo_dates_are_unparseable(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->update([
            'featured_until' => now()->addDays(3),
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'featured_until' => 'not-a-date',
            'custom_discount_starts_at' => 'not-a-date',
            'custom_discount_ends_at' => 'also-bad',
        ]);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 12])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.discount', $site->id), ['percent' => 20, 'days' => 7])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($site->fresh()->hasActiveCustomDiscount());
        $this->assertTrue($site->fresh()->joinsBulkDiscount());
    }

    public function test_feature_cannot_spend_promotional_bonus(): void
    {
        $publisher = $this->publisherWithWallet(50);
        Wallet::where('user_id', $publisher->id)->update([
            'bonus_balance' => 50,
        ]);
        $site = $this->site($publisher);

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertStatus(422)
            ->assertJson(['needs_top_up' => true]);

        $this->assertFalse($site->fresh()->isFeatured());
        $this->assertEqualsWithDelta(50.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'), 0.01);
        $this->assertEqualsWithDelta(50.0, (float) Wallet::where('user_id', $publisher->id)->value('bonus_balance'), 0.01);
    }

    public function test_feature_requires_sufficient_balance(): void
    {
        $publisher = $this->publisherWithWallet(5);
        $site = $this->site($publisher);

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertStatus(422)
            ->assertJson(['needs_top_up' => true]);
    }

    public function test_cannot_feature_archived_site(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->forceFill([
            'archived_at' => now(),
            'active' => false,
        ])->save();

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse($site->fresh()->isFeatured());
    }

    public function test_bulk_discount_applies_for_three_to_five_articles(): void
    {
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);
        app(SitePromotionService::class)->joinBulkDiscount($site, 10);
        $site->refresh();

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site, null, 3);
        // list = 100 * 1.13 = 113; 10% off => 101.7
        $this->assertSame(113.0, $pricing['list_total']);
        $this->assertSame(10.0, $pricing['discount_percent']);
        $this->assertSame(101.7, $pricing['total']);

        $noBulk = app(CartPricingService::class)->priceForAdvertiser($site, null, 2);
        $this->assertSame(0.0, $noBulk['discount_percent']);
        $this->assertSame(113.0, $noBulk['total']);
    }

    public function test_join_bulk_flash_states_better_of_not_stacked(): void
    {
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 12])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['message' => 'Joined bulk discount programme (12% on 3–5 articles). Exclusive better-of with any timed sale — not stacked; advertisers see the post-fee-floor rate.']);

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.bulk_discount_joined')->count());

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 12])
            ->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.bulk_discount_joined')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'site.bulk_discount_updated')->count());
    }

    public function test_publisher_can_join_bulk_at_eighty_percent(): void
    {
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 80])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($site->fresh()->joinsBulkDiscount());
        $this->assertSame(80.0, (float) $site->fresh()->bulk_discount_percent);
    }

    public function test_updating_bulk_percent_says_updated_not_joined(): void
    {
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 10])
            ->assertOk();

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 80])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['message' => 'Updated bulk discount to 80% on 3–5 articles. Exclusive better-of with any timed sale — not stacked; advertisers see the post-fee-floor rate.']);

        $this->assertSame(80.0, (float) $site->fresh()->bulk_discount_percent);
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.bulk_discount_joined')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.bulk_discount_updated')->count());
    }

    public function test_leave_bulk_and_clear_discount_log_once(): void
    {
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 15])
            ->assertOk();
        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.discount', $site->id), ['percent' => 20, 'days' => 7])
            ->assertOk();

        $this->actingAs($publisher)
            ->deleteJson(route('publisher.sites.bulk-leave', $site->id))
            ->assertOk();
        $this->actingAs($publisher)
            ->deleteJson(route('publisher.sites.discount.clear', $site->id))
            ->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.bulk_discount_left')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.discount_cleared')->count());

        $this->actingAs($publisher)
            ->deleteJson(route('publisher.sites.bulk-leave', $site->id))
            ->assertOk();
        $this->actingAs($publisher)
            ->deleteJson(route('publisher.sites.discount.clear', $site->id))
            ->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.bulk_discount_left')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.discount_cleared')->count());
    }

    public function test_bulk_percent_outside_ten_to_eighty_is_rejected(): void
    {
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 81])
            ->assertStatus(422);

        $this->actingAs($publisher)
            ->postJson(route('publisher.sites.bulk-join', $site->id), ['percent' => 9])
            ->assertStatus(422);

        $this->assertFalse($site->fresh()->joinsBulkDiscount());
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
        Mail::assertQueued(SiteDiscountEnded::class);
    }

    public function test_expiry_job_skips_unparseable_discount_ends(): void
    {
        Mail::fake();
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);
        $this->actingAs($publisher)->postJson(route('publisher.sites.discount', $site->id), [
            'percent' => 20,
            'days' => 7,
        ])->assertOk();

        DB::table('sites')->where('id', $site->id)->update([
            'custom_discount_ends_at' => 'not-a-date',
            'custom_discount_notified_at' => null,
        ]);

        $sent = app(SitePromotionService::class)->notifyExpiredCustomDiscounts();
        $this->assertSame(0, $sent);
        Mail::assertNothingQueued();
        $this->assertSame(20.0, (float) $site->fresh()->custom_discount_percent);
        $this->assertNull($site->fresh()->custom_discount_notified_at);
    }

    public function test_expiry_job_clears_sale_when_notified_at_is_leftover(): void
    {
        Mail::fake();
        $publisher = $this->publisherWithWallet();
        $site = $this->site($publisher);
        $this->actingAs($publisher)->postJson(route('publisher.sites.discount', $site->id), [
            'percent' => 20,
            'days' => 1,
        ])->assertOk();

        DB::table('sites')->where('id', $site->id)->update([
            'custom_discount_ends_at' => now()->subMinute()->toDateTimeString(),
            'custom_discount_notified_at' => 'not-a-date',
        ]);

        $sent = app(SitePromotionService::class)->notifyExpiredCustomDiscounts();
        $this->assertSame(1, $sent);
        Mail::assertQueued(SiteDiscountEnded::class);

        $fresh = $site->fresh();
        $this->assertNull($fresh->custom_discount_percent);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->custom_discount_notified_at);
    }

    public function test_promotions_wallet_summary_uses_withdrawable_not_bonus(): void
    {
        $publisher = $this->publisherWithWallet(50);
        Wallet::where('user_id', $publisher->id)->update([
            'bonus_balance' => 50,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.promotions.wallet'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('balance', 0)
            ->assertJsonPath('withdrawable', 0)
            ->assertJsonPath('feature_price', 10);
    }

    public function test_feature_from_stripe_is_idempotent_for_the_same_session(): void
    {
        $publisher = $this->publisherWithWallet(5);
        $site = $this->site($publisher);
        $promotions = app(SitePromotionService::class);

        $first = $promotions->featureFromStripePayment($site, $publisher, 'cs_test_feature_dup');
        $second = $promotions->featureFromStripePayment($site->fresh(), $publisher, 'cs_test_feature_dup');

        $this->assertTrue($first['success']);
        $this->assertFalse($first['already'] ?? false);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['already']);
        $this->assertSame(1, SiteFeaturePurchase::where('stripe_session_id', 'cs_test_feature_dup')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.featured_stripe')->count());

        $until = $site->fresh()->featured_until;
        $this->assertNotNull($until);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $until->timestamp, 5);
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

    public function test_wallet_feature_does_not_debit_pending_site(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->update(['active' => false, 'verified' => false]);

        $result = app(SitePromotionService::class)->featureWithWallet($site, $publisher);

        $this->assertFalse($result['success']);
        $this->assertFalse($site->fresh()->isFeatured());
        $this->assertEqualsWithDelta(50.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'), 0.01);
        $this->assertDatabaseMissing('site_feature_purchases', [
            'site_id' => $site->id,
            'payment_method' => 'wallet',
        ]);
    }

    public function test_feature_rejects_cancelled_bulk_leftover(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $site->forceFill(['bulk_site_request_id' => $bulk->id])->save();

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This listing is not in the catalog and cannot be promoted.');

        $this->assertFalse($site->fresh()->isFeatured());
        $this->assertSame(50.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
    }

    public function test_active_unverified_site_can_set_timed_sale(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->update(['verified' => false, 'active' => true]);

        $this->actingAs($publisher)->postJson(route('publisher.sites.discount', $site->id), [
            'percent' => 20,
            'days' => 7,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($site->fresh()->hasActiveCustomDiscount());
        $this->assertSame(20.0, (float) $site->fresh()->custom_discount_percent);
    }

    public function test_active_unverified_site_can_join_bulk(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->update(['verified' => false, 'active' => true]);

        $this->actingAs($publisher)->postJson(route('publisher.sites.bulk-join', $site->id), [
            'percent' => 12,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($site->fresh()->joinsBulkDiscount());
        $this->assertSame(12.0, (float) $site->fresh()->bulk_discount_percent);
    }

    public function test_pending_site_cannot_set_timed_sale(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->update(['verified' => false, 'active' => false]);

        $this->actingAs($publisher)->postJson(route('publisher.sites.discount', $site->id), [
            'percent' => 20,
            'days' => 7,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only verified or active sites can use promotions.');

        $this->assertFalse($site->fresh()->hasActiveCustomDiscount());
    }

    public function test_sale_rejects_cancelled_bulk_leftover(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $site->forceFill(['bulk_site_request_id' => $bulk->id])->save();

        $this->actingAs($publisher)->postJson(route('publisher.sites.discount', $site->id), [
            'percent' => 20,
            'days' => 7,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This listing is not in the catalog and cannot be promoted.');

        $this->assertFalse($site->fresh()->hasActiveCustomDiscount());
    }

    public function test_active_unverified_site_can_feature_with_wallet(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $site->update(['verified' => false, 'active' => true]);

        $this->actingAs($publisher)->postJson(route('publisher.sites.feature', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($site->fresh()->isFeatured());
        $this->assertSame(40.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
    }

    public function test_wallet_feature_does_not_debit_cancelled_bulk_leftover(): void
    {
        $publisher = $this->publisherWithWallet(50);
        $site = $this->site($publisher);
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $site->forceFill(['bulk_site_request_id' => $bulk->id])->save();

        $result = app(SitePromotionService::class)->featureWithWallet($site, $publisher);

        $this->assertFalse($result['success']);
        $this->assertFalse($site->fresh()->isFeatured());
        $this->assertSame(50.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
    }

    public function test_feature_stripe_amount_must_match_configured_price(): void
    {
        config(['site_promotions.feature.price' => 10]);
        $promotions = app(SitePromotionService::class);

        $promotions->assertStripeChargeMatchesFeaturePrice((object) ['amount_total' => 1000]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('featured placement costs');
        $promotions->assertStripeChargeMatchesFeaturePrice((object) ['amount_total' => 100]);
    }

    public function test_feature_owner_mismatch_credits_payer_wallet_once(): void
    {
        config(['site_promotions.feature.price' => 10]);
        $payer = $this->publisherWithWallet(5);
        $newOwner = $this->publisherWithWallet(0);
        $site = $this->site($payer);
        $site->update(['publisher_id' => $newOwner->id]);

        $promotions = app(SitePromotionService::class);
        $first = $promotions->creditPayerWhenFeatureCannotApply($site, $payer, 'cs_feature_mismatch');
        $second = $promotions->creditPayerWhenFeatureCannotApply($site, $payer, 'cs_feature_mismatch');

        $this->assertTrue($first['success']);
        $this->assertTrue($first['credited']);
        $this->assertFalse($first['already']);
        $this->assertTrue($second['already']);
        $this->assertNull($site->fresh()->featured_until);
        $this->assertEqualsWithDelta(15.0, (float) Wallet::where('user_id', $payer->id)->value('balance'), 0.01);
        $this->assertSame(1, SiteFeaturePurchase::where('stripe_session_id', 'cs_feature_mismatch')->count());
        $this->assertDatabaseHas('site_feature_purchases', [
            'stripe_session_id' => 'cs_feature_mismatch',
            'payment_method' => 'stripe_credit',
            'user_id' => $payer->id,
        ]);

        $apply = $promotions->featureFromStripePayment($site->fresh(), $newOwner, 'cs_feature_mismatch');
        $this->assertTrue($apply['success']);
        $this->assertTrue($apply['already']);
        $this->assertTrue($apply['credited']);
        $this->assertNull($site->fresh()->featured_until);
        $this->assertStringContainsString('changed owner', $first['message']);
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.feature_stripe_credited')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'site.featured_stripe')->count());
    }

    public function test_feature_from_stripe_credits_cancelled_bulk_leftover(): void
    {
        config(['site_promotions.feature.price' => 10]);
        $publisher = $this->publisherWithWallet(5);
        $site = $this->site($publisher);
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $site->forceFill(['bulk_site_request_id' => $bulk->id])->save();

        $promotions = app(SitePromotionService::class);
        $first = $promotions->featureFromStripePayment($site, $publisher, 'cs_feature_leftover');
        $second = $promotions->featureFromStripePayment($site->fresh(), $publisher, 'cs_feature_leftover');

        $this->assertTrue($first['success']);
        $this->assertTrue($first['credited']);
        $this->assertFalse($first['already']);
        $this->assertTrue($second['already']);
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.feature_stripe_credited')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'site.featured_stripe')->count());
        $this->assertStringContainsString('no longer in the catalog', $first['message']);
        $this->assertNull($site->fresh()->featured_until);
        $this->assertEqualsWithDelta(15.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'), 0.01);
        $this->assertSame(1, SiteFeaturePurchase::where('stripe_session_id', 'cs_feature_leftover')->count());
        $this->assertDatabaseHas('site_feature_purchases', [
            'stripe_session_id' => 'cs_feature_leftover',
            'payment_method' => 'stripe_credit',
            'user_id' => $publisher->id,
        ]);
    }
}
