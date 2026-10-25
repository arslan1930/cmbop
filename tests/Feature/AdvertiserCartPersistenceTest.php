<?php

namespace Tests\Feature;

use App\Models\AdvertiserSavedCartItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\AdvertiserCartPersistence;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserCartPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(string $slug = 'persist'): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Persist '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Cart persist test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_add_to_cart_persists_site_and_quantity_for_logged_in_advertiser(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id, 'homepage_days' => 'none'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('advertiser_saved_cart_items', [
            'user_id' => $this->advertiser->id,
            'site_id' => $site->id,
            'quantity' => 1,
        ]);
    }

    public function test_saving_the_same_cart_does_not_bump_last_activity(): void
    {
        $site = $this->makeSite('same');

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id, 'homepage_days' => 'none'])
            ->assertOk();

        $first = AdvertiserSavedCartItem::query()
            ->where('user_id', $this->advertiser->id)
            ->where('site_id', $site->id)
            ->firstOrFail();
        $this->assertNotNull($first->updated_at);

        $this->travel(2)->hours();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.save'), [
                'cart' => [['id' => $site->id, 'quantity' => 1, 'homepage_days' => 'none']],
            ])
            ->assertOk();

        $again = $first->fresh();
        $this->assertTrue($again->updated_at->equalTo($first->updated_at));
    }

    public function test_clear_cart_removes_saved_items(): void
    {
        $site = $this->makeSite('clear');

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id, 'homepage_days' => 'none'])
            ->assertOk();

        $this->assertSame(1, AdvertiserSavedCartItem::query()->where('user_id', $this->advertiser->id)->count());

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.clear'))
            ->assertOk();

        $this->assertSame(0, AdvertiserSavedCartItem::query()->where('user_id', $this->advertiser->id)->count());
        $this->assertSame([], session('cart', []));
    }

    public function test_saved_items_hydrate_an_empty_session(): void
    {
        $site = $this->makeSite('hydrate');
        AdvertiserSavedCartItem::query()->create([
            'user_id' => $this->advertiser->id,
            'site_id' => $site->id,
            'quantity' => 2,
        ]);

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.cart.get'))
            ->assertOk();

        $cart = session('cart', []);
        $this->assertCount(1, $cart);
        $this->assertSame($site->id, (int) ($cart[0]['id'] ?? 0));
        $this->assertSame(2, (int) ($cart[0]['quantity'] ?? 0));
    }

    public function test_fingerprint_is_stable_for_the_same_quantities(): void
    {
        $persist = app(AdvertiserCartPersistence::class);

        $this->assertSame(
            $persist->fingerprint([12 => 2, 4 => 1]),
            $persist->fingerprint([4 => 1, 12 => 2])
        );
        $this->assertNotSame(
            $persist->fingerprint([4 => 1]),
            $persist->fingerprint([4 => 2])
        );
        $this->assertSame('', $persist->fingerprint([]));
    }
}
