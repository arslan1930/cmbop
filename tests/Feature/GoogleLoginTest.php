<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
        ]);
    }

    private function mockSocialUser(string $id, string $email, string $name = 'Google User'): SocialiteUser
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($id);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'access-token';
        $socialUser->refreshToken = 'refresh-token';

        return $socialUser;
    }

    private function mockGoogleProvider(?SocialiteUser $socialUser = null, ?\Throwable $userException = null): Provider
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();

        if ($userException) {
            $provider->shouldReceive('user')->andThrow($userException);
        } elseif ($socialUser) {
            $provider->shouldReceive('user')->andReturn($socialUser);
        }

        return $provider;
    }

    public function test_unconfigured_google_redirect_returns_to_login_with_error(): void
    {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
        ]);

        $this->followingRedirects()
            ->get(route('auth.google'))
            ->assertOk()
            ->assertSee('Google sign-in is not configured', false);
    }

    public function test_placeholder_google_credentials_are_treated_as_unconfigured(): void
    {
        config([
            'services.google.client_id' => 'your-id',
            'services.google.client_secret' => 'your-secret',
        ]);

        $this->assertFalse(google_oauth_configured());

        $this->followingRedirects()
            ->get(route('auth.google'))
            ->assertOk()
            ->assertSee('Google sign-in is not configured', false);
    }

    public function test_login_always_shows_google_button(): void
    {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google', false)
            ->assertSee(route('auth.google', absolute: false), false);
    }

    public function test_login_shows_google_button_when_configured(): void
    {
        $this->configureGoogle();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google', false)
            ->assertSee(route('auth.google', absolute: false), false);
    }

    public function test_google_callback_access_denied_returns_friendly_error(): void
    {
        $this->configureGoogle();

        $this->get(route('auth.google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_google_callback_logs_in_existing_user_to_dashboard(): void
    {
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'google-user@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $provider = $this->mockGoogleProvider($this->mockSocialUser('google-oid-99', 'google-user@example.com'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-oid-99', $user->fresh()->google_id);
    }

    public function test_google_callback_creates_new_user_and_redirects_to_dashboard(): void
    {
        $this->configureGoogle();

        $socialUser = $this->mockSocialUser('google-new-42', 'new-google@example.com', 'New Google');
        $socialUser->refreshToken = null;

        $provider = $this->mockGoogleProvider($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $user = User::where('email', 'new-google@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-new-42', $user->google_id);
        $this->assertNotNull($user->email_verified_at);

        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $wallet = $user->wallets()->where('role_id', $advertiserRoleId)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
    }

    public function test_google_callback_ignores_login_as_intended_url(): void
    {
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'intended-login@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $provider = $this->mockGoogleProvider($this->mockSocialUser('google-intended-1', 'intended-login@example.com'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->withSession(['url.intended' => url('/login')])
            ->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_google_callback_retries_stateless_after_invalid_state(): void
    {
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'stateless-google@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $socialUser = $this->mockSocialUser('google-stateless-7', 'stateless-google@example.com');

        $statelessProvider = Mockery::mock(Provider::class);
        $statelessProvider->shouldReceive('user')->once()->andReturn($socialUser);

        $driver = $this->mockGoogleProvider(userException: new InvalidStateException);
        $driver->shouldReceive('stateless')->once()->andReturn($statelessProvider);

        Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($driver);

        $this->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_authenticated_user_visiting_login_goes_to_dashboard(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect('/advertiser/dashboard');
    }

    public function test_dashboard_route_helper_is_host_relative(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        config(['app.url' => 'http://127.0.0.1:8000']);

        $this->assertSame('/advertiser/dashboard', $user->fresh()->getDashboardRoute());
        $this->assertStringNotContainsString('127.0.0.1', $user->fresh()->getDashboardRoute());
    }

    public function test_google_oauth_uses_request_host_when_config_points_at_localhost(): void
    {
        $this->configureGoogle();
        config(['app.url' => 'http://127.0.0.1:8000']);

        $seenRedirect = null;
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->once()->andReturnSelf();
        $provider->shouldReceive('redirectUrl')
            ->once()
            ->andReturnUsing(function ($url) use (&$seenRedirect, $provider) {
                $seenRedirect = $url;

                return $provider;
            });
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('http://seolinkbuildings.test/auth/google')
            ->assertRedirect();

        // Non-local hosts always send https:// to Google (avoids proxy http drift).
        $this->assertSame('https://seolinkbuildings.test/auth/google/callback', $seenRedirect);
    }

    public function test_google_oauth_uses_https_request_even_when_config_is_http(): void
    {
        $this->configureGoogle();
        config([
            'app.url' => 'http://seolinkbuildings.test',
            'services.google.redirect' => 'http://seolinkbuildings.test/auth/google/callback',
        ]);

        $seenRedirect = null;
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->once()->andReturnSelf();
        $provider->shouldReceive('redirectUrl')
            ->once()
            ->andReturnUsing(function ($url) use (&$seenRedirect, $provider) {
                $seenRedirect = $url;

                return $provider;
            });
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        // Same host as config, but HTTPS — ignore http:// GOOGLE_REDIRECT_URI on public hosts.
        $this->get('https://seolinkbuildings.test/auth/google')
            ->assertRedirect();

        $this->assertSame('https://seolinkbuildings.test/auth/google/callback', $seenRedirect);
    }

    public function test_google_oauth_keeps_local_http_callback_with_port(): void
    {
        $this->configureGoogle();
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
        ]);

        $seenRedirect = null;
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->once()->andReturnSelf();
        $provider->shouldReceive('redirectUrl')
            ->once()
            ->andReturnUsing(function ($url) use (&$seenRedirect, $provider) {
                $seenRedirect = $url;

                return $provider;
            });
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('http://127.0.0.1:8000/auth/google')
            ->assertRedirect();

        $this->assertSame('http://127.0.0.1:8000/auth/google/callback', $seenRedirect);
    }

    public function test_google_oauth_uses_request_host_when_app_url_is_different_public_host(): void
    {
        $this->configureGoogle();
        config([
            'app.url' => 'https://wrong-domain.example',
            'services.google.redirect' => 'https://wrong-domain.example/auth/google/callback',
        ]);

        $seenRedirect = null;
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->once()->andReturnSelf();
        $provider->shouldReceive('redirectUrl')
            ->once()
            ->andReturnUsing(function ($url) use (&$seenRedirect, $provider) {
                $seenRedirect = $url;

                return $provider;
            });
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('https://seolinkbuildings.test/auth/google')
            ->assertRedirect();

        $this->assertSame('https://seolinkbuildings.test/auth/google/callback', $seenRedirect);
    }

    public function test_login_page_google_href_is_host_relative_when_configured(): void
    {
        $this->configureGoogle();
        config(['app.url' => 'http://127.0.0.1:8000']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('href="/auth/google"', false)
            ->assertDontSee('href="http://127.0.0.1:8000/auth/google"', false);
    }

    public function test_password_login_json_redirect_is_relative(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'relative-login@example.com',
            'password' => bcrypt('Password1!'),
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        config(['app.url' => 'http://127.0.0.1:8000']);

        $this->postJson(route('login.post'), [
            'email' => 'relative-login@example.com',
            'password' => 'Password1!',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('redirect', '/advertiser/dashboard');
    }
}
