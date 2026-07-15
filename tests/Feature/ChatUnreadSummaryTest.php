<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatUnreadSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_unread_summary(): void
    {
        $this->getJson(route('chat.unread-summary'))
            ->assertStatus(401);
    }

    public function test_authenticated_advertiser_receives_unread_summary(): void
    {
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'unread_chat' => 0,
                'needs_action' => 0,
            ]);
    }
}
