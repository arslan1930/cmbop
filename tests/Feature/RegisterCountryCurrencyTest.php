<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\DisplayCurrencyPreference;
use App\Support\ListingMoney;
use App\Support\SignupCountry;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class RegisterCountryCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        RateLimiter::clear('register:127.0.0.1');
        RateLimiter::clear('register-http:127.0.0.1');
        config([
            'fx.fake_country' => '',
            'fx.force_display' => '',
        ]);
    }

    public function test_register_page_prefills_country_from_ip_geo(): void
    {
        config(['fx.fake_country' => 'GB']);

        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString('name="country"', $html);
        $this->assertStringContainsString('js-slb-search-select', $html);
        $this->assertStringContainsString('searchable-select.js', $html);
        $this->assertStringContainsString('value="uk" selected', $html);
        $this->assertStringContainsString('Your account currency: £ GBP', $html);
        $this->assertStringNotContainsString('you can change it anytime', $html);
        $this->assertStringNotContainsString('cannot be changed', $html);
        $this->assertStringNotContainsString('contact support', $html);
    }

    public function test_register_saves_gb_as_gbp_and_us_as_usd(): void
    {
        Notification::fake();

        $this->postJson('/register', $this->payload('uk-user@example.com', 'uk', 'advertiser'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $uk = User::where('email', 'uk-user@example.com')->first();
        $this->assertSame('uk', $uk->country);
        $this->assertSame('GBP', $uk->display_currency);

        $this->postJson('/register', $this->payload('us-user@example.com', 'us', 'publisher'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $us = User::where('email', 'us-user@example.com')->first();
        $this->assertSame('us', $us->country);
        $this->assertSame('USD', $us->display_currency);

        $this->postJson('/register', $this->payload('ca-user@example.com', 'ca', 'advertiser'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $ca = User::where('email', 'ca-user@example.com')->first();
        $this->assertSame('ca', $ca->country);
        $this->assertSame('EUR', $ca->display_currency);
    }

    public function test_register_rejects_missing_and_invalid_country(): void
    {
        $missing = $this->postJson('/register', $this->payload('no-country@example.com', null));
        $missing->assertStatus(422)
            ->assertJsonPath('status', 'validation')
            ->assertJsonStructure(['errors' => ['country']]);

        $invalid = $this->postJson('/register', $this->payload('bad-country@example.com', 'zz'));
        $invalid->assertStatus(422)
            ->assertJsonPath('status', 'validation')
            ->assertJsonStructure(['errors' => ['country']]);

        $this->assertNull(User::where('email', 'no-country@example.com')->first());
        $this->assertNull(User::where('email', 'bad-country@example.com')->first());
    }

    public function test_existing_users_are_not_rewritten(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'country' => null,
            'display_currency' => null,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get(route('advertiser.dashboard'))->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->country);
        $this->assertTrue($fresh->display_currency === null || $fresh->display_currency === 'EUR');
    }

    public function test_saved_display_currency_still_wins_over_country(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'country' => 'uk',
            'display_currency' => 'EUR',
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user);
        $this->assertSame('EUR', app(DisplayCurrencyPreference::class)->preferred());
    }

    public function test_google_signup_requires_country_completion(): void
    {
        $this->configureGoogle();
        Mail::fake();

        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn('google-country-1');
        $socialUser->shouldReceive('getEmail')->andReturn('google-country@example.com');
        $socialUser->shouldReceive('getName')->andReturn('Google Country');
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'access-token';
        $socialUser->refreshToken = 'refresh-token';

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('auth.google.complete', absolute: false));

        $this->assertNull(User::where('email', 'google-country@example.com')->first());

        $this->get(route('auth.google.complete'))
            ->assertOk()
            ->assertSee('Finish your Google account', false)
            ->assertSee('Country', false)
            ->assertSee('Your account currency:', false)
            ->assertSee('js-slb-search-select', false)
            ->assertSee('searchable-select.js', false);

        $this->post(route('auth.google.complete.store'), [
            'role' => 'advertiser',
            'terms' => '1',
        ])->assertRedirect(route('auth.google.complete', absolute: false))
            ->assertSessionHasErrors('country');

        $this->post(route('auth.google.complete.store'), [
            'country' => 'uk',
            'role' => 'publisher',
            'terms' => '1',
        ])->assertRedirect('/publisher/dashboard');

        $user = User::where('email', 'google-country@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('uk', $user->country);
        $this->assertSame('GBP', $user->display_currency);
        $this->assertSame('publisher', $user->fresh()->activeRole());
    }

    public function test_signup_country_mapping_is_gb_us_else_eur(): void
    {
        $listing = app(ListingMoney::class);
        $this->assertSame('GBP', $listing->displayCurrencyForCountry('GB'));
        $this->assertSame('GBP', $listing->displayCurrencyForCountry('uk'));
        $this->assertSame('USD', $listing->displayCurrencyForCountry('US'));
        $this->assertSame('EUR', $listing->displayCurrencyForCountry('CA'));
        $this->assertSame('EUR', $listing->displayCurrencyForCountry('DE'));
        $this->assertSame('GBP', SignupCountry::displayCurrency('uk'));
        $this->assertSame('USD', SignupCountry::displayCurrency('us'));
        $this->assertSame('EUR', SignupCountry::displayCurrency('fr'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $email, ?string $country, string $role = 'advertiser'): array
    {
        $data = [
            'name' => 'Country User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => $role,
            'terms' => '1',
        ];
        if ($country !== null) {
            $data['country'] = $country;
        }

        return $data;
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
        ]);
    }
}
