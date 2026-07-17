<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserBlacklist;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogFavoritesBlacklistTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function site(User $publisher, string $name = 'Fav Site'): Site
    {
        $domain = strtolower(preg_replace('/\s+/', '', $name)).'.example';

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
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

    public function test_advertiser_can_save_favorites(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Heart Site');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.favorites.save'), [
                'favorites' => [$site->id],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 1,
            ]);

        $this->assertDatabaseHas('user_favorites', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_advertiser_can_clear_favorites(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Clear Fav');

        UserFavorite::create([
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.favorites.save'), [
                'favorites' => [],
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0]);

        $this->assertDatabaseMissing('user_favorites', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_advertiser_can_save_and_clear_blacklist(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Block Site');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.blacklist.save'), [
                'blacklist' => [$site->id],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 1,
            ]);

        $this->assertDatabaseHas('user_blacklist', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.blacklist.save'), [
                'blacklist' => [],
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0]);

        $this->assertDatabaseMissing('user_blacklist', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_blacklisted_sites_hidden_from_catalog_unless_filter(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $kept = $this->site($publisher, 'Visible Site');
        $blocked = $this->site($publisher, 'Hidden Site');

        UserBlacklist::create([
            'user_id' => $advertiser->id,
            'site_id' => $blocked->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Visible Site', false)
            ->assertDontSee('Hidden Site', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['blacklist_filter' => 1]))
            ->assertOk()
            ->assertSee('Hidden Site', false)
            ->assertDontSee('Visible Site', false);
    }

    public function test_favorites_filter_shows_only_favorited_sites(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $fav = $this->site($publisher, 'Loved Site');
        $other = $this->site($publisher, 'Other Site');

        UserFavorite::create([
            'user_id' => $advertiser->id,
            'site_id' => $fav->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['favorites_filter' => 1]))
            ->assertOk()
            ->assertSee('Loved Site', false)
            ->assertDontSee('Other Site', false);
    }

    public function test_unblocking_returns_site_to_main_catalog(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Unblock Me');

        UserBlacklist::create([
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.blacklist.save'), [
                'blacklist' => [],
            ])
            ->assertOk();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Unblock Me', false);
    }
}
