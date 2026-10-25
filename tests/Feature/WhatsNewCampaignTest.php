<?php

namespace Tests\Feature;

use App\Jobs\SendEmailCampaignJob;
use App\Mail\AudienceCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailNotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Support\FeatureUpdateHtml;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WhatsNewCampaignTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function featurePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'October what’s new',
            'subject' => 'What’s new this week',
            'template_key' => FeatureUpdateHtml::TEMPLATE_KEY,
            'feature_title' => 'Pack discounts are live',
            'feature_intro' => 'Publishers can set 3, 5, and 10-article pack prices.',
            'features' => [
                ['text' => 'Publishers set pack tiers on their sites', 'url' => 'https://example.com/publishers', 'image' => ''],
                ['text' => 'Advertisers see volume discounts on selected sites', 'url' => '', 'image' => ''],
            ],
            'cta_label' => 'Open the catalog',
            'cta_url' => 'https://example.com/advertiser/catalog',
            'audience' => 'advertisers',
            'respect_preferences' => '1',
        ], $overrides);
    }

    public function test_compose_page_offers_feature_update_template(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('Feature update / What’s new', false)
            ->assertSee('System Updates', false)
            ->assertSee('id="featureUpdateFields"', false);
    }

    public function test_preview_builds_feature_update_html(): void
    {
        $html = $this->actingAs($this->makeUser('admin'))
            ->post(route('admin.campaigns.preview'), $this->featurePayload())
            ->assertOk()
            ->assertSee('Pack discounts are live', false)
            ->assertSee('Publishers set pack tiers on their sites', false)
            ->assertSee('https://example.com/publishers', false)
            ->getContent();

        $this->assertStringContainsString('Advertisers see volume discounts on selected sites', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_send_test_uses_feature_update_body_without_audience_rows(): void
    {
        Mail::fake();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->from(route('admin.campaigns.index'))
            ->post(route('admin.campaigns.test'), $this->featurePayload([
                'email' => $admin->email,
                'respect_preferences' => '0',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, EmailCampaign::query()->count());
        Mail::assertSent(AudienceCampaignMail::class, function (AudienceCampaignMail $mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && str_contains((string) $mail->campaign->body_html, 'Pack discounts are live');
        });
    }

    public function test_send_stores_system_updates_preference_and_skips_opt_outs(): void
    {
        Mail::fake();
        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        $optedIn = $this->makeUser('advertiser');
        $marketingOff = $this->makeUser('advertiser');

        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'system_updates',
            'enabled' => false,
        ]);
        EmailNotificationPreference::create([
            'user_id' => $marketingOff->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->featurePayload())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'Campaign queued for 3 recipient'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame(FeatureUpdateHtml::TEMPLATE_KEY, $campaign->template_key);
        $this->assertSame(FeatureUpdateHtml::PREFERENCE_KEY, $campaign->preference_key);
        $this->assertSame('system_updates', $campaign->preferenceKey());
        $this->assertSame(2, $campaign->sent_count);
        $this->assertSame(1, $campaign->skipped_count);
        $this->assertSame(
            EmailCampaignRecipient::SKIP_PREFERENCE,
            $campaign->recipients()->where('user_id', $optedOut->id)->value('skip_reason')
        );

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedIn->email));
        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($marketingOff->email));
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedOut->email));
        $this->assertTrue(class_exists(SendEmailCampaignJob::class));
    }
}
