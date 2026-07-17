<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
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
            ->assertSee('Create Account', false)
            ->assertSee('Continue with Google', false)
            ->assertDontSee('Continue with Apple', false);
    }

    public function test_login_page_does_not_offer_apple_sign_in(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google', false)
            ->assertDontSee('Continue with Apple', false);
    }

    public function test_register_succeeds_for_advertiser_with_welcome_bonus(): void
    {
        Notification::fake();

        $response = $this->postJson('/register', [
            'name' => 'Alice Advertiser',
            'email' => 'alice-reg@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'advertiser',
            'terms' => '1',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('verification_sent', true);

        $user = User::where('email', 'alice-reg@example.com')->first();
        $this->assertNotNull($user);

        Notification::assertSentTo($user, VerifyEmail::class);

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

    public function test_register_succeeds_when_wallet_bonus_columns_are_missing(): void
    {
        Notification::fake();

        Schema::table('wallets', function ($table) {
            if (Schema::hasColumn('wallets', 'bonus_balance')) {
                $table->dropColumn(['bonus_balance', 'bonus_reserved']);
            }
        });

        $this->assertFalse(Schema::hasColumn('wallets', 'bonus_balance'));

        $response = $this->postJson('/register', [
            'name' => 'Carol Advertiser',
            'email' => 'carol-reg@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'advertiser',
            'terms' => '1',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $user = User::where('email', 'carol-reg@example.com')->first();
        $this->assertNotNull($user);

        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $wallet = $user->wallets()->where('role_id', $advertiserRoleId)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(20.0, (float) $wallet->balance);
    }
}
