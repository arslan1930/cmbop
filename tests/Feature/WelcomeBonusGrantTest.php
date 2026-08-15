<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use App\Services\Wallet\WelcomeBonusService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class WelcomeBonusGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        foreach ([
            '1.2.3.4', '9.9.9.9', '10.0.0.2', '127.0.0.1',
            '8.8.8.8', '11.11.11.11', '203.0.113.80',
            '203.0.113.90', '203.0.113.91', '203.0.113.92',
        ] as $ip) {
            RateLimiter::clear('register:'.$ip);
            RateLimiter::clear('register-http:'.$ip);
        }
    }

    public function test_first_advertiser_from_ip_receives_bonus_and_claim(): void
    {
        Notification::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->postJson('/register', $this->registerPayload('first-bonus@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user = User::where('email', 'first-bonus@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAdvertiserBonus($user, 20.0);
        $this->assertSame(1, WelcomeBonusClaim::query()->where('user_id', $user->id)->count());
        $this->assertSame('1.2.3.4', WelcomeBonusClaim::query()->where('user_id', $user->id)->value('ip_address'));
    }

    public function test_forwarded_for_spoof_does_not_unlock_a_second_bonus(): void
    {
        Notification::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->withHeaders(['X-Forwarded-For' => '9.9.9.9'])
            ->postJson('/register', $this->registerPayload('xff-first@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        RateLimiter::clear('register:9.9.9.9');
        RateLimiter::clear('register:8.8.8.8');
        RateLimiter::clear('register:1.2.3.4');
        RateLimiter::clear('register-http:1.2.3.4');
        RateLimiter::clear('register-http:9.9.9.9');
        RateLimiter::clear('register-http:8.8.8.8');

        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->withHeaders(['X-Forwarded-For' => '8.8.8.8'])
            ->postJson('/register', $this->registerPayload('xff-second@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $first = User::where('email', 'xff-first@example.com')->first();
        $second = User::where('email', 'xff-second@example.com')->first();
        $this->assertAdvertiserBonus($first, 20.0);
        $this->assertAdvertiserBonus($second, 0.0);
        $this->assertSame('1.2.3.4', WelcomeBonusClaim::query()->where('user_id', $first->id)->value('ip_address'));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_second_advertiser_from_same_ip_gets_no_bonus(): void
    {
        Notification::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->postJson('/register', $this->registerPayload('first-ip@example.com'))
            ->assertOk();

        RateLimiter::clear('register:1.2.3.4');
        RateLimiter::clear('register-http:1.2.3.4');

        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->postJson('/register', $this->registerPayload('second-ip@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $second = User::where('email', 'second-ip@example.com')->first();
        $this->assertNotNull($second);
        $this->assertAdvertiserBonus($second, 0.0);
        $this->assertSame(0, WelcomeBonusClaim::query()->where('user_id', $second->id)->count());
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_register_succeeds_without_bonus_when_claims_table_is_missing(): void
    {
        Notification::fake();
        Schema::dropIfExists('welcome_bonus_claims');
        $this->assertFalse(Schema::hasTable('welcome_bonus_claims'));

        $this->withServerVariables(['REMOTE_ADDR' => '11.11.11.11'])
            ->postJson('/register', $this->registerPayload('no-claims-table@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user = User::where('email', 'no-claims-table@example.com')->first();
        $this->assertAdvertiserBonus($user, 0.0);
    }

    public function test_register_does_not_grant_withdrawable_cash_when_bonus_columns_are_missing(): void
    {
        Notification::fake();
        Schema::table('wallets', function ($table) {
            if (Schema::hasColumn('wallets', 'bonus_balance')) {
                $table->dropColumn(['bonus_balance', 'bonus_reserved']);
            }
        });
        $this->assertFalse(Schema::hasColumn('wallets', 'bonus_balance'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.92'])
            ->postJson('/register', $this->registerPayload('no-bonus-cols@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user = User::where('email', 'no-bonus-cols@example.com')->first();
        $this->assertNotNull($user);
        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $wallet = Wallet::where('user_id', $user->id)->where('role_id', $advertiserRoleId)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->balance);
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_register_page_hides_bonus_copy_when_bonus_columns_are_missing(): void
    {
        Schema::table('wallets', function ($table) {
            if (Schema::hasColumn('wallets', 'bonus_balance')) {
                $table->dropColumn(['bonus_balance', 'bonus_reserved']);
            }
        });

        $this->get(route('register'))
            ->assertOk()
            ->assertDontSee('€20 welcome credit', false)
            ->assertSee('const welcomeBonusEnabled = false', false);
    }

    public function test_register_page_hides_bonus_copy_when_claims_table_is_missing(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');

        $this->get(route('register'))
            ->assertOk()
            ->assertDontSee('€20 welcome credit', false)
            ->assertSee('const welcomeBonusEnabled = false', false);
    }

    public function test_disabled_bonus_skips_credit_on_new_ip(): void
    {
        Notification::fake();
        app(WelcomeBonusService::class)->setEnabled(false);

        $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])
            ->postJson('/register', $this->registerPayload('disabled-bonus@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user = User::where('email', 'disabled-bonus@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAdvertiserBonus($user, 0.0);
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_reenabled_bonus_grants_on_new_ip(): void
    {
        Notification::fake();
        $service = app(WelcomeBonusService::class);
        $service->setEnabled(false);
        $service->setEnabled(true);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/register', $this->registerPayload('reenabled@example.com'))
            ->assertOk();

        $user = User::where('email', 'reenabled@example.com')->first();
        $this->assertAdvertiserBonus($user, 20.0);
    }

    public function test_publisher_register_never_receives_bonus(): void
    {
        Notification::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->postJson('/register', $this->registerPayload('pub-bonus@example.com', 'publisher'))
            ->assertOk();

        $user = User::where('email', 'pub-bonus@example.com')->first();
        $this->assertAdvertiserBonus($user, 0.0);
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_google_signup_skips_bonus_when_disabled(): void
    {
        Mail::fake();
        $this->configureGoogle();
        app(WelcomeBonusService::class)->setEnabled(false);

        $this->mockGoogleCallback('google-disabled', 'google-disabled@example.com');
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4'])
            ->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $user = User::where('email', 'google-disabled@example.com')->first();
        $this->assertAdvertiserBonus($user, 0.0);
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_google_signup_respects_ip_claim(): void
    {
        Mail::fake();
        $this->configureGoogle();

        $this->mockGoogleCallback('google-first', 'google-first@example.com');
        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $first = User::where('email', 'google-first@example.com')->first();
        $this->assertAdvertiserBonus($first, 20.0);

        Auth::logout();
        $this->flushSession();

        $this->mockGoogleCallback('google-second', 'google-second@example.com');
        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $second = User::where('email', 'google-second@example.com')->first();
        $this->assertNotNull($second);
        $this->assertAdvertiserBonus($second, 0.0);
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_register_rate_limit_ignores_forwarded_for_spoof(): void
    {
        Notification::fake();

        $remote = '203.0.113.80';
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $remote])
                ->withHeaders(['X-Forwarded-For' => '198.51.100.'.$i])
                ->postJson('/register', $this->registerPayload("xff-limit-{$i}@example.com"))
                ->assertOk()
                ->assertJsonPath('status', 'success');
        }

        $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.99'])
            ->postJson('/register', $this->registerPayload('xff-limit-blocked@example.com'))
            ->assertStatus(429);

        $this->assertNull(User::where('email', 'xff-limit-blocked@example.com')->first());
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_deleting_the_claimant_does_not_free_the_place_for_another_bonus(): void
    {
        Notification::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->postJson('/register', $this->registerPayload('doomed-claimant@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user = User::where('email', 'doomed-claimant@example.com')->first();
        $this->assertAdvertiserBonus($user, 20.0);
        $this->assertSame(1, WelcomeBonusClaim::query()->count());

        $user->delete();

        $this->assertNull(User::where('email', 'doomed-claimant@example.com')->first());
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
        $this->assertNull(WelcomeBonusClaim::query()->value('user_id'));
        $this->assertSame('203.0.113.90', WelcomeBonusClaim::query()->value('ip_address'));

        RateLimiter::clear('register:203.0.113.90');
        RateLimiter::clear('register-http:203.0.113.90');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->postJson('/register', $this->registerPayload('after-delete@example.com'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $second = User::where('email', 'after-delete@example.com')->first();
        $this->assertAdvertiserBonus($second, 0.0);
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_google_signup_shares_the_register_place_rate_limit(): void
    {
        Mail::fake();
        $this->configureGoogle();

        $request = Request::create('/register', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.91']);
        $key = app(WelcomeBonusService::class)->registerRateLimitKey($request);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 600);
        }

        $this->mockGoogleCallback('google-limited', 'google-limited@example.com');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.91'])
            ->get(route('auth.google.callback'))
            ->assertRedirect('/login');

        $this->assertNull(User::where('email', 'google-limited@example.com')->first());
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_google_login_ignores_register_place_rate_limit(): void
    {
        Mail::fake();
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'google-existing@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('ExistingPass1!'),
            'active_role_id' => $role->id,
        ]);
        $existing->roles()->attach($role->id);

        $request = Request::create('/register', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.91']);
        $key = app(WelcomeBonusService::class)->registerRateLimitKey($request);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 600);
        }

        $this->mockGoogleCallback('google-existing', 'google-existing@example.com');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.91'])
            ->get(route('auth.google.callback'))
            ->assertRedirect('/advertiser/dashboard');

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_registration_pair_does_not_credit_without_a_claim(): void
    {
        $user = User::factory()->create();
        $advertiserRoleId = (int) Role::where('name', 'advertiser')->value('id');
        $publisherRoleId = (int) Role::where('name', 'publisher')->value('id');

        $credited = Wallet::insertRegistrationPair($user->id, $advertiserRoleId, $publisherRoleId, 20.0);

        $this->assertSame(0.0, $credited);
        $this->assertAdvertiserBonus($user, 0.0);
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_registration_pair_caps_credit_to_the_recorded_claim(): void
    {
        $user = User::factory()->create();
        WelcomeBonusClaim::query()->create([
            'user_id' => $user->id,
            'ip_address' => '203.0.113.93',
            'source' => 'registration',
            'amount' => 20,
        ]);
        $advertiserRoleId = (int) Role::where('name', 'advertiser')->value('id');
        $publisherRoleId = (int) Role::where('name', 'publisher')->value('id');

        $credited = Wallet::insertRegistrationPair($user->id, $advertiserRoleId, $publisherRoleId, 2000.0);

        $this->assertSame(20.0, $credited);
        $this->assertAdvertiserBonus($user, 20.0);
    }

    public function test_registration_pair_credits_the_admin_set_amount_up_to_the_hard_max(): void
    {
        app(WelcomeBonusService::class)->setAmount(100);
        $user = User::factory()->create();
        WelcomeBonusClaim::query()->create([
            'user_id' => $user->id,
            'ip_address' => '203.0.113.94',
            'source' => 'registration',
            'amount' => 100,
        ]);
        $advertiserRoleId = (int) Role::where('name', 'advertiser')->value('id');
        $publisherRoleId = (int) Role::where('name', 'publisher')->value('id');

        $credited = Wallet::insertRegistrationPair($user->id, $advertiserRoleId, $publisherRoleId, 100.0);

        $this->assertSame(100.0, $credited);
        $this->assertAdvertiserBonus($user, 100.0);

        WelcomeBonusSetting::setValue('config', ['enabled' => true, 'amount' => 99999]);
        $cappedUser = User::factory()->create();
        WelcomeBonusClaim::query()->create([
            'user_id' => $cappedUser->id,
            'ip_address' => '203.0.113.95',
            'source' => 'registration',
            'amount' => 99999,
        ]);

        $capped = Wallet::insertRegistrationPair($cappedUser->id, $advertiserRoleId, $publisherRoleId, 99999.0);

        $this->assertSame(500.0, $capped);
        $this->assertAdvertiserBonus($cappedUser, 500.0);
    }

    public function test_register_page_hides_bonus_copy_when_disabled(): void
    {
        app(WelcomeBonusService::class)->setEnabled(false);

        $html = $this->get(route('register'))
            ->assertOk()
            ->assertDontSee('Spend on your first orders — not withdrawable', false)
            ->assertDontSee('Welcome bonus for first orders', false)
            ->assertDontSee('Start with €20 free credit', false)
            ->assertDontSee('€20 Welcome Credit', false)
            ->assertDontSee('New advertisers get €20 welcome credit', false)
            ->assertSee('Create Account | SEOLinkBuildings', false)
            ->assertSee('Free to start — no card required', false)
            ->assertSee('const welcomeBonusEnabled = false', false)
            ->getContent();

        $this->assertStringNotContainsString('<strong>€20 welcome credit</strong>', $html);
    }

    private function registerPayload(string $email, string $role = 'advertiser'): array
    {
        return [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => $role,
            'terms' => '1',
        ];
    }

    private function assertAdvertiserBonus(?User $user, float $expected): void
    {
        $this->assertNotNull($user);
        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $wallet = Wallet::where('user_id', $user->id)->where('role_id', $advertiserRoleId)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals($expected, (float) $wallet->bonus_balance);
        $this->assertEquals($expected, (float) $wallet->balance);
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
        ]);
    }

    private function mockGoogleCallback(string $id, string $email): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($id);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn('Google User');
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'access-token';
        $socialUser->refreshToken = 'refresh-token';

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
    }
}
