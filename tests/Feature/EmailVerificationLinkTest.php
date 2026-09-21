<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'app.public_url' => 'https://seolinkbuildings.com',
        ]);
        URL::forceRootUrl('http://127.0.0.1:8000');
    }

    public function test_signed_verification_link_uses_local_app_url_in_local_env(): void
    {
        // PHPUnit runs in console; local APP_URL must stay local so localhost
        // testing works (do not rewrite to production PUBLIC_APP_URL).
        $user = User::factory()->create([
            'email' => 'local-host@example.com',
            'email_verified_at' => null,
        ]);

        $url = VerifyEmail::signedUrlFor($user);

        $this->assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST));
        $this->assertSame('http', parse_url($url, PHP_URL_SCHEME));
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_signed_verification_link_uses_public_fallback_in_production_when_app_url_loopback(): void
    {
        app()['env'] = 'production';
        config(['app.env' => 'production']);
        // No live request — production + loopback APP_URL should use PUBLIC_APP_URL.
        app()->forgetInstance('request');

        $user = User::factory()->create([
            'email' => 'public-host@example.com',
            'email_verified_at' => null,
        ]);

        $url = VerifyEmail::signedUrlFor($user);

        $this->assertSame('seolinkbuildings.com', parse_url($url, PHP_URL_HOST));
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_signed_verification_link_keeps_configured_public_app_url(): void
    {
        config(['app.url' => 'https://staging.example.com']);
        URL::forceRootUrl('https://staging.example.com');
        app()->instance(
            'request',
            Request::create('https://staging.example.com/register', 'POST')
        );

        $user = User::factory()->create([
            'email' => 'staging-host@example.com',
            'email_verified_at' => null,
        ]);

        $url = VerifyEmail::signedUrlFor($user);

        $this->assertSame('staging.example.com', parse_url($url, PHP_URL_HOST));
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $url);
    }

    public function test_signed_verification_link_redirects_to_login_without_auto_login(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'verify-me@example.com',
            'email_verified_at' => null,
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $url = VerifyEmail::signedUrlFor($user);

        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $url);
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)
            ->assertRedirect('/login')
            ->assertSessionHas('message');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_signed_verification_link_redirects_publisher_to_login(): void
    {
        $role = Role::where('name', 'publisher')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'verify-pub@example.com',
            'email_verified_at' => null,
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $url = VerifyEmail::signedUrlFor($user);

        $this->get($url)
            ->assertRedirect('/login')
            ->assertSessionHas('message');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_signed_verification_link_works_when_request_host_differs_from_email_host(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'host-mismatch@example.com',
            'email_verified_at' => null,
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $url = VerifyEmail::signedUrlFor($user);
        $this->assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST));

        // Hit the path on a different host than the email CTA — relative HMAC must still pass.
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($path);
        $this->assertIsString($query);

        $this->get($path.'?'.$query)
            ->assertRedirect('/login')
            ->assertSessionHas('message');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_signed_verification_link_survives_email_tracker_query_params(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'utm-verify@example.com',
            'email_verified_at' => null,
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $url = VerifyEmail::signedUrlFor($user);
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($path);
        $this->assertIsString($query);

        $this->get($path.'?'.$query.'&utm_source=gmail&utm_medium=email&fbclid=abc123')
            ->assertRedirect('/login')
            ->assertSessionHas('message');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_unsigned_verification_link_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'unsigned@example.com',
            'email_verified_at' => null,
        ]);

        $hash = sha1($user->email);
        $this->get('/email/verify/'.$user->id.'/'.$hash)
            ->assertRedirect('/login')
            ->assertSessionHas('error');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_welcome_email_cta_uses_signed_verify_url_not_notice_page(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'welcome-verify@example.com',
            'email_verified_at' => null,
        ]);

        $mailable = new WelcomeEmail($user);
        $built = $mailable->build();

        $ctaUrl = $built->viewData['ctaUrl'] ?? null;
        $this->assertIsString($ctaUrl);
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $ctaUrl);
        $this->assertStringNotContainsString('/email/verify?', $ctaUrl);
        $this->assertSame('Click to verify', $built->viewData['ctaLabel'] ?? null);
        $this->assertStringContainsString('signature=', $ctaUrl);
        $this->assertSame('127.0.0.1', parse_url($ctaUrl, PHP_URL_HOST));
    }

    public function test_verify_email_notification_action_uses_signed_url(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'notify-verify@example.com',
            'email_verified_at' => null,
        ]);

        $user->notify(new VerifyEmail);

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $mail = $notification->toMail($user);
            $actionUrl = $mail->actionUrl ?? null;
            $this->assertIsString($actionUrl);
            $this->assertStringContainsString('/email/verify/'.$user->id.'/', $actionUrl);
            $this->assertStringContainsString('signature=', $actionUrl);
            $this->assertSame('127.0.0.1', parse_url($actionUrl, PHP_URL_HOST));

            return true;
        });
    }

    public function test_verify_email_to_mail_builds_without_missing_helpers(): void
    {
        $user = User::factory()->create([
            'email' => 'build-verify@example.com',
            'email_verified_at' => null,
        ]);

        $mail = (new VerifyEmail)->toMail($user);

        $this->assertIsString($mail->actionUrl);
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $mail->actionUrl);
        $this->assertStringContainsString('signature=', $mail->actionUrl);
        $this->assertSame('Click to verify', $mail->actionText);
        $this->assertTrue(function_exists('app_public_url'));
        $this->assertTrue(function_exists('role_home_path'));
    }

    public function test_verification_resend_does_not_reveal_whether_email_exists(): void
    {
        Notification::fake();

        $unknown = $this->postJson(route('verification.resend'), [
            'email' => 'nobody-here@example.com',
        ]);
        $unknown->assertOk()->assertJson([
            'status' => 'success',
            'message' => 'Verification email resent successfully.',
        ]);

        $verified = User::factory()->create([
            'email' => 'already-verified@example.com',
            'email_verified_at' => now(),
        ]);
        $this->postJson(route('verification.resend'), [
            'email' => $verified->email,
        ])->assertOk()->assertJson([
            'status' => 'success',
            'message' => 'Verification email resent successfully.',
        ]);

        Notification::assertNothingSent();

        $unverified = User::factory()->create([
            'email' => 'still-unverified@example.com',
            'email_verified_at' => null,
        ]);
        $this->postJson(route('verification.resend'), [
            'email' => $unverified->email,
        ])->assertOk()->assertJson([
            'status' => 'success',
            'message' => 'Verification email resent successfully.',
        ]);

        Notification::assertSentTo($unverified, VerifyEmail::class);
    }

    public function test_verification_resend_empty_email_returns_json_validation(): void
    {
        $this->postJson(route('verification.resend'), [])
            ->assertStatus(422)
            ->assertJsonPath('status', 'validation')
            ->assertJsonValidationErrors('email');
    }

    public function test_send_email_verification_notification_does_not_throw(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'send-verify@example.com',
            'email_verified_at' => null,
        ]);

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
