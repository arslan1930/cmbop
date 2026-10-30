<?php

namespace Tests\Feature;

use App\Mail\AbandonedCartReminderMail;
use App\Models\AbandonedCartReminderSend;
use App\Models\AbandonedCartSetting;
use App\Models\AdvertiserSavedCartItem;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\AdvertiserCartPersistence;
use App\Services\Reminders\AbandonedCartReminderService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AbandonedCartReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Cart reminder site',
            'site_url' => 'https://cart-reminder.example',
            'domain' => 'cart-reminder.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Abandoned cart reminder test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function persistCart(int $quantity = 1): void
    {
        app(AdvertiserCartPersistence::class)->replaceItems($this->advertiser, [
            $this->site->id => $quantity,
        ]);
    }

    private function enableReminders(): void
    {
        AbandonedCartSetting::ensureTable();
        AbandonedCartSetting::setEnabled(true);
        $this->assertTrue(app(AbandonedCartReminderService::class)->isEnabled());
    }

    public function test_switch_off_sends_nothing(): void
    {
        Mail::fake();
        AbandonedCartSetting::ensureTable();
        AbandonedCartSetting::setEnabled(false);
        $this->persistCart();
        $this->travel(30)->hours();

        Artisan::call('emails:send-abandoned-cart-reminders');

        Mail::assertNothingOutgoing();
        $this->assertSame(0, AbandonedCartReminderSend::query()->count());
    }

    public function test_stage_1_sends_after_configured_hours_and_not_before(): void
    {
        Mail::fake();
        $this->enableReminders();
        $this->persistCart();

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertNothingOutgoing();

        $this->travel(23)->hours();
        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertNothingOutgoing();

        $this->travel(2)->hours();
        Artisan::call('emails:send-abandoned-cart-reminders');

        Mail::assertQueued(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail) {
            return $mail->hasTo($this->advertiser->email)
                && $mail->stage === AbandonedCartReminderMail::STAGE_1
                && collect($mail->lines)->contains(fn (array $line) => $line['domain'] === 'cart-reminder.example');
        });
        $this->assertTrue(AbandonedCartReminderSend::alreadySent(
            (int) $this->advertiser->id,
            app(AdvertiserCartPersistence::class)->fingerprint([$this->site->id => 1]),
            AbandonedCartReminderMail::STAGE_1
        ));
    }

    public function test_stage_2_sends_after_72_hours_and_does_not_duplicate_a_stage(): void
    {
        Mail::fake();
        $this->enableReminders();
        $this->persistCart();

        $this->travel(25)->hours();
        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertQueued(AbandonedCartReminderMail::class, 1);

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertQueued(AbandonedCartReminderMail::class, 1);

        $this->travel(48)->hours();
        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertQueued(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail) {
            return $mail->stage === AbandonedCartReminderMail::STAGE_2
                && $mail->hasTo($this->advertiser->email);
        });
        Mail::assertQueued(AbandonedCartReminderMail::class, 2);

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertQueued(AbandonedCartReminderMail::class, 2);
    }

    public function test_empty_cart_stops_reminders(): void
    {
        Mail::fake();
        $this->enableReminders();
        $this->persistCart();
        $this->travel(30)->hours();

        AdvertiserSavedCartItem::query()->where('user_id', $this->advertiser->id)->delete();

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertNothingOutgoing();
    }

    public function test_paid_order_since_cart_activity_stops_reminders(): void
    {
        Mail::fake();
        $this->enableReminders();
        $this->persistCart();
        $this->travel(30)->hours();

        Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-CART-1',
            'reference_code' => 'ORDCART1',
            'status' => 'processing',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'total_amount' => 40,
            'subtotal' => 40,
            'paid_at' => now(),
        ]);

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertNotQueued(AbandonedCartReminderMail::class);
        $this->assertSame(0, AbandonedCartReminderSend::query()->count());
    }

    public function test_marketing_opt_out_and_daily_cap_block_sends(): void
    {
        Mail::fake();
        $this->enableReminders();
        $this->persistCart();
        $this->travel(30)->hours();

        EmailNotificationPreference::create([
            'user_id' => $this->advertiser->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertNothingOutgoing();

        EmailNotificationPreference::query()
            ->where('user_id', $this->advertiser->id)
            ->where('preference_key', 'marketing_emails')
            ->update(['enabled' => true]);

        config(['reminders.daily_cap_per_user' => 1]);
        EmailLog::create([
            'to_email' => $this->advertiser->email,
            'notification_type' => 'deposit_reminder',
            'status' => EmailLog::STATUS_DELIVERED,
            'subject' => 'Earlier nudge',
        ]);

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertNothingOutgoing();
    }

    public function test_unverified_advertiser_is_skipped(): void
    {
        Mail::fake();
        $this->enableReminders();
        $this->advertiser->forceFill(['email_verified_at' => null])->save();
        $this->persistCart();
        $this->travel(30)->hours();

        Artisan::call('emails:send-abandoned-cart-reminders');
        Mail::assertNothingOutgoing();
    }

    public function test_admin_page_lists_saved_carts_and_toggle(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->persistCart(2);

        $this->actingAs($admin)
            ->get(route('admin.abandoned-carts.index'))
            ->assertOk()
            ->assertSee('Abandoned carts', false)
            ->assertSee($this->advertiser->email, false)
            ->assertSee('Master switch', false);

        $this->actingAs($admin)
            ->post(route('admin.abandoned-carts.toggle'), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue(AbandonedCartSetting::isEnabled());
    }
}
