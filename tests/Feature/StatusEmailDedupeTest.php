<?php

namespace Tests\Feature;

use App\Mail\SiteStatusNotification;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Platform mail is deduped for ten minutes so a retry cannot double-send. The
 * key identified the type, the record and the recipient but not the transition —
 * and approving a site is two clicks seconds apart (verify, then activate), so
 * the second email was dropped as a duplicate of the first. The publisher saw
 * one message, or none, for a decision made of two steps.
 */
class StatusEmailDedupeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The dedupe key is internal, but it is the thing that decides whether a
     * publisher hears about a decision — so it is worth asserting directly
     * rather than inferring from delivery.
     */
    private function keyFor(SiteStatusNotification $mail): string
    {
        $method = new \ReflectionMethod($mail, 'defaultDedupeKey');
        $method->setAccessible(true);

        return (string) $method->invoke($mail, 'site_status', $mail->site->publisher);
    }

    private function wouldBeSuppressed(SiteStatusNotification $mail): bool
    {
        $method = new \ReflectionMethod($mail, 'isDuplicate');
        $method->setAccessible(true);

        return (bool) $method->invoke($mail, $this->keyFor($mail));
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $u->roles()->attach($role->id);

        return $u->fresh();
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dedupe Site',
            'site_url' => 'https://dedupe.example',
            'domain' => 'dedupe.example',
            'example_url' => 'https://dedupe.example/sample',
            'da' => 30, 'dr' => 30, 'traffic' => 2000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 60,
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'Test site', 'verified' => false, 'active' => false,
        ]);
    }

    public function test_verify_and_activate_are_two_different_emails(): void
    {
        $publisher = $this->publisher();
        $site = $this->site($publisher);

        $verified = $this->keyFor(new SiteStatusNotification($site, 'verified'));
        $activated = $this->keyFor(new SiteStatusNotification($site, 'activated'));

        $this->assertNotSame(
            $verified,
            $activated,
            'Approving a site is verify plus activate; identical keys drop the second email.'
        );
    }

    public function test_a_genuine_repeat_of_the_same_decision_is_still_deduped(): void
    {
        $publisher = $this->publisher();
        $site = $this->site($publisher);

        $first = $this->keyFor(new SiteStatusNotification($site, 'verified'));
        $second = $this->keyFor(new SiteStatusNotification($site, 'verified'));

        $this->assertSame($first, $second);
    }

    public function test_two_sites_do_not_share_a_key(): void
    {
        $publisher = $this->publisher();
        $one = $this->site($publisher);
        $two = Site::create(array_merge($one->only([
            'publisher_id', 'da', 'dr', 'traffic', 'country', 'language',
            'category', 'price', 'publication_time', 'link_type', 'description',
        ]), [
            'site_name' => 'Second Site',
            'site_url' => 'https://second.example',
            'domain' => 'second.example',
            'countries' => ['us'], 'languages' => ['en'],
            'verified' => false, 'active' => false,
        ]));

        $this->assertNotSame(
            $this->keyFor(new SiteStatusNotification($one, 'verified')),
            $this->keyFor(new SiteStatusNotification($two, 'verified'))
        );
    }

    public function test_the_publisher_hears_about_both_steps_of_an_approval(): void
    {
        Mail::fake();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $adminRole->id]);
        $admin->roles()->attach($adminRole->id);

        $publisher = $this->publisher();
        $site = $this->site($publisher);

        $this->actingAs($admin->fresh())
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

        $this->actingAs($admin->fresh())
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk();

        $sent = collect(Mail::queued(SiteStatusNotification::class))
            ->map(fn ($mail) => $mail->action)
            ->all();

        $this->assertContains('verified', $sent);
        $this->assertContains('activated', $sent);
    }

    public function test_a_second_decision_is_not_suppressed_by_the_email_log(): void
    {
        $publisher = $this->publisher();
        $site = $this->site($publisher);

        // Simulate the verify email having just been delivered.
        EmailLog::create([
            'to_email' => $publisher->email,
            'subject' => 'Your Site Has Been Verified',
            'notification_type' => 'site_status',
            'dedupe_key' => $this->keyFor(new SiteStatusNotification($site, 'verified')),
            'status' => EmailLog::STATUS_DELIVERED,
        ]);

        $this->assertFalse(
            $this->wouldBeSuppressed(new SiteStatusNotification($site, 'activated')),
            'The activate email was suppressed by the verify email that preceded it.'
        );
    }
}
