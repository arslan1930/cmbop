<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RegisterPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        RateLimiter::clear('register:127.0.0.1');
    }

    public function test_register_page_renders(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create your account', false)
            ->assertSee('Create Account', false);
    }

    public function test_register_succeeds_for_advertiser_with_welcome_bonus(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Alice Advertiser',
            'email' => 'alice-reg@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'advertiser',
            'terms' => '1',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $user = User::where('email', 'alice-reg@example.com')->first();
        $this->assertNotNull($user);

        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $wallet = $user->wallets()->where('role_id', $advertiserRoleId)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
        $this->assertEquals(20.0, (float) $wallet->balance);
    }

    public function test_register_validation_returns_json_errors(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Bob',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'publisher',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'validation')
            ->assertJsonStructure(['errors' => ['email', 'terms']]);
    }
}
