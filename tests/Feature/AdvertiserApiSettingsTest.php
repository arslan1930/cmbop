<?php

namespace Tests\Feature;

use App\Models\AdvertiserApiToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserApiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this suite.');
        }

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

    public function test_profile_stays_at_shared_root_path(): void
    {
        $this->assertSame(url('/profile'), route('profile'));

        $this->actingAs($this->advertiser)
            ->get('/profile')
            ->assertOk();

        $this->actingAs($this->publisher)
            ->get('/profile')
            ->assertOk();
    }

    public function test_advertiser_can_issue_and_revoke_a_key(): void
    {
        $this->actingAs($this->advertiser)
            ->get(route('advertiser.api.index'))
            ->assertOk()
            ->assertSee('API & MCP')
            ->assertSee(route('profile'))
            ->assertSee('/api/mcp/customer');

        $this->actingAs($this->advertiser)
            ->post(route('advertiser.api.store'), ['name' => 'Claude'])
            ->assertRedirect(route('advertiser.api.index'));

        $this->assertDatabaseHas('advertiser_api_tokens', [
            'user_id' => $this->advertiser->id,
            'name' => 'Claude',
        ]);

        $token = AdvertiserApiToken::query()->firstOrFail();

        $this->actingAs($this->advertiser)
            ->delete(route('advertiser.api.destroy', $token))
            ->assertRedirect(route('advertiser.api.index'));

        $this->assertDatabaseMissing('advertiser_api_tokens', ['id' => $token->id]);
    }

    public function test_publisher_cannot_open_advertiser_api_settings(): void
    {
        $this->actingAs($this->publisher)
            ->get('/advertiser/api')
            ->assertForbidden();
    }

    public function test_advertiser_cannot_revoke_someone_elses_key(): void
    {
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $this->advertiser->active_role_id,
        ]);
        $other->roles()->attach($this->advertiser->active_role_id);
        $issued = AdvertiserApiToken::issue($other, 'Other');

        $this->actingAs($this->advertiser)
            ->delete(route('advertiser.api.destroy', $issued['token']))
            ->assertNotFound();
    }
}
