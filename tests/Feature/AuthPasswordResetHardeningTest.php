<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Models\Role;
use App\Models\User;
use App\Support\UserMessages;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthPasswordResetHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_forgot_and_reset_forms_ask_for_json(): void
    {
        foreach ([
            resource_path('views/auth/forgot-password.blade.php'),
            resource_path('views/auth/reset-password.blade.php'),
        ] as $path) {
            $this->assertFileExists($path);
            $markup = file_get_contents($path);
            $this->assertStringContainsString("'Accept': 'application/json'", $markup);
        }
    }

    public function test_forgot_and_reset_posts_are_rate_limited(): void
    {
        foreach (['password.email', 'password.update'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);

            $hasThrottle = collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

            $this->assertTrue($hasThrottle, $name.' must be throttled');
        }
    }

    public function test_weak_reset_password_is_rejected(): void
    {
        $this->postJson(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => 'nobody@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_sixth_forgot_password_is_too_many_attempts(): void
    {
        $payload = ['email' => 'nobody@example.com'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('password.email'), $payload)
                ->assertOk()
                ->assertJsonPath('status', 'success');
        }

        $this->postJson(route('password.email'), $payload)
            ->assertStatus(429)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', UserMessages::get('password.throttled'))
            ->assertHeader('Retry-After');
    }

    public function test_reset_assigns_plain_password_without_double_hash(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'reset-once@example.com',
            'email_verified_at' => now(),
            'password' => 'old-password',
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $token = Password::broker()->createToken($user);

        $this->postJson(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newpass99',
            'password_confirmation' => 'newpass99',
        ])->assertOk()->assertJsonPath('status', 'success');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpass99', $fresh->password));
        $this->assertFalse(Hash::check('old-password', $fresh->password));
    }

    public function test_reset_form_has_show_hide_toggles(): void
    {
        $this->get(route('password.reset', ['token' => 'preview-token']))
            ->assertOk()
            ->assertSee('togglePassword', false)
            ->assertSee('id="password"', false)
            ->assertSee('id="password_confirmation"', false)
            ->assertSee('Show or hide new password', false)
            ->assertSee("credentials: 'same-origin'", false);
    }

    public function test_successful_reset_queues_password_changed_mail(): void
    {
        Mail::fake();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'reset-mail@example.com',
            'email_verified_at' => now(),
            'password' => 'old-password',
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $token = Password::broker()->createToken($user);

        $this->postJson(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newpass99',
            'password_confirmation' => 'newpass99',
        ])->assertOk()->assertJsonPath('status', 'success');

        Mail::assertQueued(PasswordChangedMail::class, 1);
    }

    public function test_invalid_reset_does_not_queue_password_changed_mail(): void
    {
        Mail::fake();

        $this->postJson(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => 'nobody@example.com',
            'password' => 'newpass99',
            'password_confirmation' => 'newpass99',
        ])->assertOk()->assertJsonPath('status', 'error');

        Mail::assertNothingQueued();
    }

    public function test_empty_forgot_password_returns_json_validation(): void
    {
        $this->postJson(route('password.email'), [])
            ->assertStatus(422)
            ->assertJsonPath('status', 'validation')
            ->assertJsonValidationErrors('email');
    }

    public function test_password_field_errors_sit_outside_input_groups(): void
    {
        foreach ([
            resource_path('views/auth/login.blade.php'),
            resource_path('views/auth/register.blade.php'),
            resource_path('views/auth/reset-password.blade.php'),
        ] as $path) {
            $markup = file_get_contents($path);
            $this->assertStringContainsString('id="passwordError"', $markup, basename($path));
            $this->assertStringContainsString("classList.add('d-block')", $markup, basename($path));

            preg_match_all('/<div class="input-group">.*?<\/div>/s', $markup, $groups);
            foreach ($groups[0] as $group) {
                $this->assertStringNotContainsString(
                    'invalid-feedback',
                    $group,
                    basename($path).' must not nest invalid-feedback inside input-group'
                );
            }
        }
    }
}
