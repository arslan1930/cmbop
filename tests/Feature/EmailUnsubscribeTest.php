<?php

namespace Tests\Feature;

use App\Mail\AudienceCampaignMail;
use App\Mail\OrderPaymentConfirmed;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailNotificationPreference;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Support\EmailCatalog;
use App\Support\EmailUnsubscribeLink;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function relativeSignedUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    public function test_signed_get_shows_confirm_without_opting_out(): void
    {
        $user = $this->makeUser('advertiser');
        $url = $this->relativeSignedUrl(EmailUnsubscribeLink::url($user));

        $html = $this->get($url)
            ->assertOk()
            ->assertSee('Unsubscribe from marketing emails', false)
            ->assertSee($user->email, false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]+action="\/email\/unsubscribe\/'.$user->id.'\?/',
            $html
        );
        $this->assertStringNotContainsString('action="http', $html);

        $this->assertTrue(EmailNotificationPreference::allows($user, 'marketing_emails'));
    }

    public function test_signed_post_opts_out_of_marketing_only(): void
    {
        $user = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $user->id,
            'preference_key' => 'order_emails',
            'enabled' => true,
        ]);
        $url = $this->relativeSignedUrl(EmailUnsubscribeLink::url($user));

        $this->post($url)
            ->assertOk()
            ->assertSee('You’ve been unsubscribed', false);

        $this->assertFalse(EmailNotificationPreference::allows($user, 'marketing_emails'));
        $this->assertTrue(EmailNotificationPreference::allows($user, 'order_emails'));
        $this->assertTrue(EmailNotificationPreference::allows($user, 'payment_emails'));
        $this->assertTrue(EmailNotificationPreference::allows($user, 'security_alerts'));
    }

    public function test_one_click_post_returns_empty_200(): void
    {
        $user = $this->makeUser('advertiser');
        $url = $this->relativeSignedUrl(EmailUnsubscribeLink::url($user));

        $this->post($url, [
            '_token' => 'invalid',
            'List-Unsubscribe' => 'One-Click',
        ])
            ->assertOk()
            ->assertSee('');

        $this->assertFalse(EmailNotificationPreference::allows($user, 'marketing_emails'));
    }

    public function test_unsigned_get_and_post_are_forbidden(): void
    {
        $user = $this->makeUser('advertiser');

        $this->get('/email/unsubscribe/'.$user->id)->assertForbidden();
        $this->post('/email/unsubscribe/'.$user->id, [
            '_token' => 'invalid',
        ])->assertForbidden();
        $this->get('/email/unsubscribe/999999')->assertForbidden();
        $this->post('/email/unsubscribe/999999', [
            '_token' => 'invalid',
        ])->assertForbidden();

        $this->assertTrue(EmailNotificationPreference::allows($user, 'marketing_emails'));
    }

    public function test_preview_placeholder_path_does_not_resolve(): void
    {
        $this->get('/email/unsubscribe/preview-id')->assertNotFound();
        $this->post('/email/unsubscribe/preview-id')->assertNotFound();
    }

    public function test_opt_out_is_skipped_on_the_next_preference_respecting_campaign(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('advertiser');
        $url = $this->relativeSignedUrl(EmailUnsubscribeLink::url($user));

        $this->post($url)->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), [
                'name' => 'After opt-out',
                'subject' => 'Still marketing',
                'body_html' => '<p>Hello</p>',
                'audience' => 'advertisers',
                'respect_preferences' => '1',
            ])
            ->assertRedirect(route('admin.campaigns.index'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $campaign->status);
        $this->assertSame(
            EmailCampaignRecipient::SKIP_PREFERENCE,
            $campaign->recipients()->where('user_id', $user->id)->value('skip_reason')
        );
        Mail::assertNothingQueued();
    }

    public function test_campaign_html_includes_unsubscribe_url_and_headers(): void
    {
        $user = $this->makeUser('advertiser');
        $campaign = new EmailCampaign([
            'subject' => 'Spring update',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
        ]);

        $mailable = new AudienceCampaignMail($campaign, $user);
        $html = $mailable->render();
        $url = $mailable->unsubscribeUrl();

        $this->assertStringContainsString('/email/unsubscribe/'.$user->id, $html);
        $this->assertStringContainsString('Unsubscribe from marketing emails', $html);
        $this->assertStringContainsString(e($url), $html);

        $headers = $mailable->headers();
        $this->assertSame('<'.$url.'>', $headers->text['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
        $this->assertSame($url, $mailable->unsubscribeUrl());
    }

    public function test_preview_user_gets_placeholder_unsubscribe_url(): void
    {
        $user = EmailCatalog::previewUser();
        $url = EmailUnsubscribeLink::url($user);

        $this->assertSame(EmailUnsubscribeLink::previewUrl(), $url);
        $this->assertStringContainsString('/email/unsubscribe/preview-id', $url);
        $this->assertStringNotContainsString('signature=', $url);

        $mailable = new AudienceCampaignMail(new EmailCampaign([
            'subject' => 'Preview',
            'body_html' => '<p>Hi</p>',
        ]), $user);
        $html = $mailable->render();
        $this->assertStringContainsString('/email/unsubscribe/preview-id', $html);
        $this->assertSame('<'.$url.'>', $mailable->headers()->text['List-Unsubscribe']);
    }

    public function test_order_payment_mail_has_no_unsubscribe_footer(): void
    {
        $user = $this->makeUser('advertiser');
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-UNSUB-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $order->setRelation('user', $user);
        $order->setRelation('items', collect());

        $html = (new OrderPaymentConfirmed($order))->render();

        $this->assertStringNotContainsString('/email/unsubscribe/', $html);
        $this->assertStringNotContainsString('Unsubscribe from marketing emails', $html);
    }
}
