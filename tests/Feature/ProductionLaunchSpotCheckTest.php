<?php

namespace Tests\Feature;

use App\Mail\NewChatMessageNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * The Hostinger launch path: register → verify email → catalog image →
 * wallet order → chat mail. Each step has its own suite; this one walks
 * them in order so a broken hand-off cannot hide behind a green unit test.
 */
class ProductionLaunchSpotCheckTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        RateLimiter::clear('register:127.0.0.1');
        RateLimiter::clear('register-http:127.0.0.1');
        config(['content_moderation.enabled' => false]);
    }

    public function test_register_verify_catalog_image_wallet_order_and_chat_mail(): void
    {
        Notification::fake();
        Mail::fake();

        $this->postJson('/register', [
            'name' => 'Spot Check Advertiser',
            'email' => 'spot-check@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'advertiser',
            'terms' => '1',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('verification_sent', true);

        $advertiser = User::where('email', 'spot-check@example.com')->first();
        $this->assertNotNull($advertiser);
        $this->assertNull($advertiser->email_verified_at);

        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $wallet = $advertiser->wallets()->where('role_id', $advertiserRoleId)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->bonus_balance);

        Notification::assertSentTo($advertiser, VerifyEmail::class);

        $verifyUrl = VerifyEmail::signedUrlFor($advertiser);
        $this->assertStringContainsString('/email/verify/'.$advertiser->id.'/', $verifyUrl);

        $this->get($verifyUrl)
            ->assertRedirect('/login')
            ->assertSessionHas('message');
        $this->assertNotNull($advertiser->fresh()->email_verified_at);
        $this->assertGuest();

        RateLimiter::clear('login:127.0.0.1|spot-check@example.com');
        RateLimiter::clear('login-ip:127.0.0.1');

        $this->postJson('/login', [
            'email' => 'spot-check@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('status', 'success');
        $this->assertAuthenticatedAs($advertiser->fresh());

        $wallet->refresh();
        $wallet->update(['balance' => 50]);

        $publisher = $this->userWithRole('publisher');
        Storage::disk('public')->put('sites/spot-check-cover.webp', 'fake-webp-body');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Spot Check Journal',
            'site_url' => 'https://spot-check.example',
            'domain' => 'spot-check.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 2000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 15,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Launch-path catalog listing.',
            'verified' => true,
            'active' => true,
            'site_image' => 'sites/spot-check-cover.webp',
        ]);

        $catalog = $this->actingAs($advertiser->fresh())
            ->get(route('advertiser.catalog'))
            ->assertOk();
        $html = $catalog->getContent();
        $this->assertStringContainsString('Spot Check Journal', $html);
        $this->assertStringContainsString('media/sites/spot-check-cover.webp', $html);

        $this->get('/media/sites/spot-check-cover.webp')
            ->assertOk()
            ->assertHeader('Cache-Control');

        $submission = $this->createApprovedSubmission($advertiser->fresh());

        $this->actingAs($advertiser->fresh())
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                ]],
                'checkout_content_submission_id' => $submission->id,
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'SPOT1',
                'publication_mode' => 'immediate',
                'use_bonus' => '0',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $order = Order::where('reference_code', 'SPOT1')->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($advertiser->id, $order->user_id);

        $this->actingAs($advertiser->fresh())
            ->postJson('/chat/send/'.$order->id, ['message' => 'Brief is in the library — please confirm the angle.'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertQueued(
            NewChatMessageNotification::class,
            fn (NewChatMessageNotification $mail) => $mail->hasTo($publisher->email)
        );
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
