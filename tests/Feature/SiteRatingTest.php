<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteRatingTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Rated Site',
            'site_url' => 'https://rated.example',
            'domain' => 'rated.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 50,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_advertiser_can_rate_a_site_and_aggregate_updates(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.sites.rate', $site->id), [
            'rating' => 5,
            'comment' => 'Great publisher',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('site_ratings', [
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'rating' => 5,
            'status' => 'approved',
        ]);

        $site->refresh();
        $this->assertSame(5.0, (float) $site->rating_avg);
        $this->assertSame(1, (int) $site->rating_count);
    }

    public function test_advertiser_can_update_their_rating(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $this->actingAs($advertiser)->postJson(route('advertiser.sites.rate', $site->id), [
            'rating' => 3,
        ])->assertOk();

        $this->actingAs($advertiser)->postJson(route('advertiser.sites.rate', $site->id), [
            'rating' => 4,
            'comment' => 'Updated',
        ])->assertOk();

        $this->assertSame(1, SiteRating::where('site_id', $site->id)->count());
        $this->assertSame(4, (int) SiteRating::where('site_id', $site->id)->value('rating'));
        $site->refresh();
        $this->assertSame(4.0, (float) $site->rating_avg);
    }

    public function test_admin_can_hide_rating_and_aggregate_excludes_it(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $site = $this->site($publisher);

        $rating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'rating' => 5,
            'status' => SiteRating::STATUS_APPROVED,
        ]);
        SiteRating::refreshSiteAggregate($site->id);

        $this->actingAs($admin)->putJson(route('admin.site-ratings.update', $rating->id), [
            'status' => 'hidden',
        ])->assertOk();

        $site->refresh();
        $this->assertSame(0, (int) $site->rating_count);
        $this->assertSame(0.0, (float) $site->rating_avg);
    }

    public function test_admin_can_delete_rating(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $site = $this->site($publisher);

        $rating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'rating' => 2,
            'status' => SiteRating::STATUS_APPROVED,
        ]);
        SiteRating::refreshSiteAggregate($site->id);

        $this->actingAs($admin)->deleteJson(route('admin.site-ratings.destroy', $rating->id))
            ->assertOk();

        $this->assertDatabaseMissing('site_ratings', ['id' => $rating->id]);
        $site->refresh();
        $this->assertSame(0, (int) $site->rating_count);
    }
}
