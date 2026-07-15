<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::create(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    public function test_guest_is_redirected_from_advertiser_catalog(): void
    {
        $this->get(route('advertiser.catalog'))
            ->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_publisher_dashboard(): void
    {
        $this->get(route('publisher.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_publisher_cannot_access_advertiser_catalog(): void
    {
        $user = $this->userWithRole('publisher');

        $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertForbidden();
    }

    public function test_advertiser_cannot_access_publisher_dashboard(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->actingAs($user)
            ->get(route('publisher.dashboard'))
            ->assertForbidden();
    }

    public function test_advertiser_can_access_advertiser_catalog(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk();
    }
}
