<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserBlacklist;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedSitesPageTest extends TestCase
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

    private function site(User $publisher, string $name): Site
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

    public function test_saved_sites_page_lists_favorites_and_blacklist(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $fav = $this->site($publisher, 'Favorite One');
        $blocked = $this->site($publisher, 'Blocked One');

        UserFavorite::create(['user_id' => $advertiser->id, 'site_id' => $fav->id]);
        UserBlacklist::create(['user_id' => $advertiser->id, 'site_id' => $blocked->id]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.saved-sites', ['tab' => 'favorites']))
            ->assertOk()
            ->assertSee('Saved Sites', false)
            ->assertSee('Favorite One', false)
            ->assertDontSee('Blocked One', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.saved-sites', ['tab' => 'blacklist']))
            ->assertOk()
            ->assertSee('Blocked One', false)
            ->assertDontSee('Favorite One', false);
    }

    public function test_can_remove_favorite_from_saved_page(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Remove Fav');

        UserFavorite::create(['user_id' => $advertiser->id, 'site_id' => $site->id]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.saved-sites.favorites.remove'), [
                'site_id' => $site->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0]);

        $this->assertDatabaseMissing('user_favorites', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_can_unblock_from_saved_page(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Unblock Saved');

        UserBlacklist::create(['user_id' => $advertiser->id, 'site_id' => $site->id]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.saved-sites.blacklist.remove'), [
                'site_id' => $site->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0]);

        $this->assertDatabaseMissing('user_blacklist', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_can_move_favorite_to_blacklist(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Move Block');

        UserFavorite::create(['user_id' => $advertiser->id, 'site_id' => $site->id]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.saved-sites.move.blacklist'), [
                'site_id' => $site->id,
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'favorites_count' => 0,
                'blacklist_count' => 1,
            ]);

        $this->assertDatabaseMissing('user_favorites', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
        $this->assertDatabaseHas('user_blacklist', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_can_move_blacklist_to_favorites(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, 'Move Fav');

        UserBlacklist::create(['user_id' => $advertiser->id, 'site_id' => $site->id]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.saved-sites.move.favorites'), [
                'site_id' => $site->id,
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'favorites_count' => 1,
                'blacklist_count' => 0,
            ]);

        $this->assertDatabaseHas('user_favorites', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
        $this->assertDatabaseMissing('user_blacklist', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_sidebar_includes_saved_sites_link(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee(route('advertiser.saved-sites'), false)
            ->assertSee('Saved Sites', false);
    }

    public function test_sidebar_places_saved_sites_after_scheduled(): void
    {
        $layout = file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $scheduledPos = strpos($layout, 'advertiser.scheduled-orders');
        $savedPos = strpos($layout, 'advertiser.saved-sites');

        $this->assertNotFalse($scheduledPos);
        $this->assertNotFalse($savedPos);
        $this->assertLessThan($savedPos, $scheduledPos, 'Saved Sites should appear after Scheduled in the sidebar');
    }
}
