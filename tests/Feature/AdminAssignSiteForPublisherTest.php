<?php

namespace Tests\Feature;

use App\Mail\AdminAssignedSiteNotification;
use App\Mail\WebsiteSuggestionReviewed;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Services\InAppNotificationService;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAssignSiteForPublisherTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    public function test_admin_can_create_site_for_publisher_pending_acceptance(): void
    {
        Mail::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $response = $this->actingAs($this->admin)->post(route('admin.sites.store'), [
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Staff Added News',
            'site_url' => 'https://staff-added-news.example',
            'example_url' => 'https://staff-added-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
            'written_request' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $site = Site::where('domain', 'staff-added-news.example')->first();
        $this->assertNotNull($site);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('publisher='.$this->publisher->id, $location);
        $this->assertStringContainsString('site='.$site->id, $location);
        $this->assertSame((int) $this->publisher->id, (int) $site->publisher_id);
        $this->assertSame((int) $this->admin->id, (int) $site->assigned_by_user_id);
        $this->assertNull($site->publisher_accepted_at);
        $this->assertSame(40, (int) $site->da);
        $this->assertSame(45, (int) $site->dr);
        $this->assertSame(12000, (int) $site->traffic);
        $this->assertFalse((bool) $site->active);
        $this->assertFalse((bool) $site->verified);
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertFalse($site->needsAdminReview());
        $this->assertStringContainsString('Invites', (string) session('success'));

        Mail::assertQueued(AdminAssignedSiteNotification::class, function ($mail) {
            if (! $mail->hasTo($this->publisher->email)) {
                return false;
            }
            $mail->build();

            $html = $mail->render();

            return str_contains((string) ($mail->viewData['acceptUrl'] ?? ''), 'status=invites')
                && str_contains($html, 'Catalog Activate is not automatic')
                && ! str_contains($html, 'Our team can activate it for the catalog when ready');
        });

        $bell = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Please accept a website we added for you')
            ->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('status=invites', (string) $bell->action_url);
        $this->assertStringContainsString('staff review', (string) $bell->message);
        $this->assertStringNotContainsString('You can still verify ownership with the TXT file', (string) $bell->message);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->assertDontSee('staff-added-news.example', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->assertSee('staff-added-news.example', false)
            ->assertSee('Accept', false);
    }

    public function test_admin_sponsored_tag_is_exclusive_and_create_form_uses_glossary(): void
    {
        Mail::fake();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Partner article', $html);
        $this->assertStringNotContainsString('Partner material', $html);

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');

        $this->actingAs($this->admin)->post(route('admin.sites.store'), [
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Staff Sponsored News',
            'site_url' => 'https://staff-sponsored.example',
            'example_url' => 'https://staff-sponsored.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'sponsored',
            'written_request' => 1,
        ])->assertRedirect()->assertSessionHas('success');

        $site = Site::where('domain', 'staff-sponsored.example')->firstOrFail();
        $this->assertTrue((bool) $site->sponsored);
        $this->assertFalse((bool) $site->partner_material);
        $this->assertFalse((bool) $site->as_you_prefer);
        $this->assertSame('sponsored', $site->tagValue());
    }

    public function test_publisher_accept_moves_site_into_my_sites_pending(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Invite Site',
            'site_url' => 'https://invite-site.example',
            'domain' => 'invite-site.example',
            'example_url' => 'https://invite-site.example/post',
            'da' => 30,
            'dr' => 30,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Invite site description for acceptance. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.accept-assignment', $site->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $site->refresh();
        $this->assertNotNull($site->publisher_accepted_at);
        $inviteBell = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Please accept a website we added for you')
            ->where('related_id', $site->id)
            ->first();
        $this->assertNotNull($inviteBell);
        $this->assertTrue($inviteBell->isArchived());
        $this->assertFalse($site->isPendingPublisherAcceptance());
        $this->assertTrue($site->needsAdminReview());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'site.assignment_accepted',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
        ]);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('invite-site.example', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->assertDontSee('invite-site.example', false);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_publisher_reject_deletes_pending_invite(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/decline-cover.jpg', 'cover');

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Decline Site',
            'site_url' => 'https://decline-site.example',
            'domain' => 'decline-site.example',
            'example_url' => 'https://decline-site.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Decline this invite site description. ', 3),
            'verified' => false,
            'active' => false,
            'site_image' => 'sites/decline-cover.jpg',
        ]);

        app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.reject-assignment', $site->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertFalse(Storage::disk('public')->exists('sites/decline-cover.jpg'));
        $inviteBell = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Please accept a website we added for you')
            ->where('related_id', $site->id)
            ->first();
        $this->assertNotNull($inviteBell);
        $this->assertTrue($inviteBell->isArchived());
    }

    public function test_staff_delete_of_pending_invite_archives_accept_bell(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Staff Removed Invite',
            'site_url' => 'https://staff-removed-invite.example',
            'domain' => 'staff-removed-invite.example',
            'example_url' => 'https://staff-removed-invite.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Staff removed this invite site description. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site);

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => 'Publisher asked us to withdraw the invite.',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $inviteBell = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Please accept a website we added for you')
            ->where('related_id', $site->id)
            ->first();
        $this->assertNotNull($inviteBell);
        $this->assertTrue($inviteBell->isArchived());
    }

    public function test_publisher_delete_of_accepted_pending_site_removes_cover(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/accepted-cover.jpg', 'cover');

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Accepted Then Deleted',
            'site_url' => 'https://accepted-then-deleted.example',
            'domain' => 'accepted-then-deleted.example',
            'example_url' => 'https://accepted-then-deleted.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Accepted then deleted site description. ', 3),
            'verified' => false,
            'active' => false,
            'site_image' => 'sites/accepted-cover.jpg',
        ]);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites', ['status' => 'pending']))
            ->delete(route('publisher.sites.destroy', $site->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertFalse(Storage::disk('public')->exists('sites/accepted-cover.jpg'));
    }

    public function test_publisher_cannot_edit_pending_invite(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Invite No Edit',
            'site_url' => 'https://invite-no-edit.example',
            'domain' => 'invite-no-edit.example',
            'example_url' => 'https://invite-no-edit.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Invite must be accepted before edit. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.edit-data', $site->id))
            ->assertStatus(422);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites', ['status' => 'invites']))
            ->put(route('publisher.sites.update', $site->id), [
                'price' => 1,
            ])
            ->assertRedirect(route('publisher.websites', ['status' => 'invites']));

        $this->assertSame(40.0, (float) $site->fresh()->price);
        $this->assertTrue($site->fresh()->isPendingPublisherAcceptance());
    }

    public function test_reject_after_accept_does_not_delete_listing(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Accept Then Decline',
            'site_url' => 'https://accept-then-decline.example',
            'domain' => 'accept-then-decline.example',
            'example_url' => 'https://accept-then-decline.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Accept then decline must keep the row. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.accept-assignment', $site->id))
            ->assertOk();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.reject-assignment', $site->id))
            ->assertStatus(422);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertNotNull($site->fresh()->publisher_accepted_at);
    }

    public function test_staff_can_still_delete_invite_after_publisher_accepts(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => now(),
            'site_name' => 'Accepted Then Staff Rejected',
            'site_url' => 'https://accepted-staff-reject.example',
            'domain' => 'accepted-staff-reject.example',
            'example_url' => 'https://accepted-staff-reject.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Staff may still reject after accept. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => 'Publisher asked us to withdraw after accepting.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('archived', false);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_decline_does_not_archive_sibling_site_id_prefix_bell(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Prefix Invite',
            'site_url' => 'https://prefix-invite.example',
            'domain' => 'prefix-invite.example',
            'example_url' => 'https://prefix-invite.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Prefix invite site description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site);

        $siblingId = (int) ($site->id.'0');
        $sibling = InAppNotification::create([
            'user_id' => $this->publisher->id,
            'audience' => InAppNotification::AUDIENCE_PUBLISHER,
            'type' => InAppNotificationService::TYPE_SITE_STATUS,
            'category' => 'account',
            'title' => 'Please accept a website we added for you',
            'message' => 'Sibling prefix bell',
            'status' => InAppNotification::STATUS_UNREAD,
            'related_type' => Site::class,
            'related_id' => $siblingId,
            'meta' => ['site_id' => $siblingId],
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.reject-assignment', $site->id))
            ->assertOk();

        $inviteBell = InAppNotification::query()
            ->where('related_id', $site->id)
            ->where('title', 'Please accept a website we added for you')
            ->first();
        $this->assertNotNull($inviteBell);
        $this->assertTrue($inviteBell->isArchived());
        $this->assertFalse($sibling->fresh()->isArchived());
    }

    public function test_decline_deletes_site_screenshot_not_shared_placeholder(): void
    {
        Storage::fake('public');

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Screenshot Invite',
            'site_url' => 'https://screenshot-invite.example',
            'domain' => 'screenshot-invite.example',
            'example_url' => 'https://screenshot-invite.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Screenshot invite site description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $shot = 'site-screenshots/site-'.$site->id.'-20260101120000.webp';
        $thumb = 'site-screenshots/site-'.$site->id.'-20260101120000-thumb.webp';
        $shared = 'site-screenshots/home-placeholder.webp';
        $other = 'site-screenshots/site-999999-20260101120000.webp';
        Storage::disk('public')->put($shot, 'shot');
        Storage::disk('public')->put($thumb, 'thumb');
        Storage::disk('public')->put($shared, 'shared');
        Storage::disk('public')->put($other, 'other');
        $site->forceFill([
            'screenshot_path' => $shot,
            'screenshot_thumb_path' => $thumb,
        ])->save();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.reject-assignment', $site->id))
            ->assertOk();

        $this->assertFalse(Storage::disk('public')->exists($shot));
        $this->assertFalse(Storage::disk('public')->exists($thumb));
        $this->assertTrue(Storage::disk('public')->exists($shared));
        $this->assertTrue(Storage::disk('public')->exists($other));
    }

    public function test_publisher_reject_does_not_delete_invite_with_order_items(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Ordered Invite',
            'site_url' => 'https://ordered-invite.example',
            'domain' => 'ordered-invite.example',
            'example_url' => 'https://ordered-invite.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Invite that already has an order item. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-INVITE-ORD',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 40,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.reject-assignment', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('order_items', ['site_id' => $site->id]);
    }

    public function test_publisher_self_created_sites_are_accepted_immediately(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');

        $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Self Added',
            'siteUrl' => 'self-added.example',
            'exampleUrl' => 'https://self-added.example/post',
            'da' => 33,
            'dr' => 34,
            'traffic' => 8000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 70,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Self created site description here. ', 4),
            'site_tag' => 'as_you_prefer',
        ])->assertRedirect();

        $site = Site::where('domain', 'self-added.example')->first();
        $this->assertNotNull($site);
        $this->assertNotNull($site->publisher_accepted_at);
        $this->assertFalse($site->isPendingPublisherAcceptance());
    }

    public function test_publisher_store_rejects_traffic_that_would_overflow_unsigned_int(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Overflow Traffic',
                'siteUrl' => 'overflow-traffic.example',
                'exampleUrl' => 'https://overflow-traffic.example/post',
                'da' => 33,
                'dr' => 34,
                'traffic' => '5000000000',
                'country' => strtolower((string) $country->code),
                'language' => strtolower((string) $language->code),
                'categories' => $niche,
                'price' => 70,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Overflow traffic leftover store guard. ', 4),
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('traffic')
            ->assertSessionDoesntHaveErrors('siteUrl');

        $this->assertNull(Site::where('domain', 'overflow-traffic.example')->first());
    }

    public function test_admin_create_coerces_da_dr_traffic_from_noisy_input(): void
    {
        Mail::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->admin)->post(route('admin.sites.store'), [
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Metrics Coerce News',
            'site_url' => 'https://metrics-coerce.example',
            'example_url' => 'https://metrics-coerce.example/sample',
            'da' => ' 52 ',
            'dr' => '48.0',
            'traffic' => '15,000',
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 90,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Metrics coerce site description text. ', 4),
            'site_tag' => 'as_you_prefer',
            'written_request' => 1,
        ])->assertRedirect();

        $site = Site::where('domain', 'metrics-coerce.example')->first();
        $this->assertNotNull($site);
        $this->assertSame(52, (int) $site->da);
        $this->assertSame(48, (int) $site->dr);
        $this->assertSame(15000, (int) $site->traffic);
        $this->assertTrue($site->isPendingPublisherAcceptance());
    }

    public function test_admin_create_rejects_array_category_without_500(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        $this->actingAs($this->admin)
            ->from(route('admin.sites.create'))
            ->post(route('admin.sites.store'), [
                'publisher_id' => $this->publisher->id,
                'site_name' => 'Array Category News',
                'site_url' => 'https://array-category-news.example',
                'example_url' => 'https://array-category-news.example/sample',
                'da' => 40,
                'dr' => 45,
                'traffic' => 12000,
                'country' => strtolower($country->code),
                'language' => strtolower($language->code),
                'category' => ['NotARealNiche'],
                'price' => 99,
                'turnaround_time' => '3days',
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => str_repeat('Array category create description text. ', 4),
                'site_tag' => 'as_you_prefer',
                'written_request' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('categories');

        $this->assertNull(Site::where('domain', 'array-category-news.example')->first());
    }

    public function test_heal_migration_reopens_staff_invites_wiped_by_backfill(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Wiped Invite',
            'site_url' => 'https://wiped-invite.example',
            'domain' => 'wiped-invite.example',
            'example_url' => 'https://wiped-invite.example/post',
            'da' => 25,
            'dr' => 25,
            'traffic' => 2000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Wiped invite site description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        // Simulate the old migration backfill that stamped acceptance = created_at.
        DB::table('sites')->where('id', $site->id)->update([
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => DB::raw('created_at'),
        ]);

        $site->refresh();
        $this->assertFalse($site->isPendingPublisherAcceptance());

        $migration = require database_path('migrations/2026_08_08_111500_heal_staff_assigned_site_invites.php');
        $migration->up();

        $site->refresh();
        $this->assertNull($site->publisher_accepted_at);
        $this->assertSame((int) $this->admin->id, (int) $site->assigned_by_user_id);
        $this->assertTrue($site->isPendingPublisherAcceptance());
    }

    public function test_invites_ajax_empty_state_mentions_accept(): void
    {
        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->assertSee('No site invites waiting', false)
            ->assertSee('Accept / Decline', false);
    }

    public function test_admin_create_page_says_activate_also_verifies_and_posts_language(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->assertSee('Add site for publisher', false)
            ->assertSee('catalog Activate is not automatic', false)
            ->assertSee('Accept ≠ Verified', false)
            ->assertDontSee('Activate / Deactivate as usual', false)
            ->assertSee('id="selectedLanguage"', false)
            ->assertSee('name="language"', false)
            ->assertSee('Select a language', false)
            ->assertSee('data-max-kb', false)
            ->assertSee('Site image must be under', false)
            ->assertSee('id="publisherFilter"', false)
            ->assertSee('written_request', false)
            ->assertSee('This emails and bells the publisher', false)
            ->assertSee('Click to toggle; type to search; Enter adds the highlighted match. Max 7.', false)
            ->assertSee('data-site-description-editor', false)
            ->assertSee('name="description"', false)
            ->assertSee('name="price_homepage[7]"', false)
            ->assertSee('name="sensitive[crypto]"', false)
            ->assertSee('optional homepage, social, and sensitive-topic prices', false)
            ->assertSee('Must be on the same domain as the site URL.', false)
            ->getContent();

        $this->assertStringNotContainsString('required disabled', $html);
        $this->assertMatchesRegularExpression('/<select[^>]+id="language"[^>]*required/', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]+id="language"[^>]*disabled/', $html);
    }

    public function test_admin_create_page_survives_array_old_description(): void
    {
        $this->actingAs($this->admin)
            ->withSession([
                '_old_input' => [
                    'description' => ['<p>Poisoned description</p>'],
                    'site_name' => ['Poisoned Name'],
                ],
            ])
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->assertSee('data-site-description-editor', false)
            ->assertDontSee('htmlspecialchars(): Argument #1', false)
            ->assertDontSee('TypeError', false);
    }

    public function test_admin_create_picker_excludes_leftover_unverified_publishers(): void
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $leftover = User::factory()->create([
            'email' => 'leftover-unverified-pub@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $leftover->roles()->attach($publisherRole->id);
        DB::table('users')->where('id', $leftover->id)->update([
            'email_verified_at' => 'not-a-date',
        ]);
        $this->assertFalse($leftover->fresh()->hasVerifiedEmail());

        $this->actingAs($this->admin)
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->assertSee($this->publisher->email, false)
            ->assertDontSee($leftover->email, false)
            ->assertDontSee('Something went wrong');
    }

    public function test_admin_create_with_suggestion_id_marks_the_website_suggestion_accepted(): void
    {
        Mail::fake();

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $suggestion = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Staff Added News',
            'website_url' => 'https://staff-added-news.example',
            'domain' => 'staff-added-news.example',
            'status' => 'pending',
        ]);

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->admin)->post(route('admin.sites.store'), [
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Staff Added News',
            'site_url' => 'https://staff-added-news.example',
            'example_url' => 'https://staff-added-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
            'written_request' => 1,
            'suggestion_id' => $suggestion->id,
        ])->assertRedirect()->assertSessionHas('success');

        $suggestion->refresh();
        $this->assertSame('accepted', $suggestion->status);
        $this->assertSame($this->admin->id, (int) $suggestion->reviewed_by);
        $this->assertStringContainsString('Listing created: staff-added-news.example', (string) $suggestion->admin_notes);

        Mail::assertQueued(WebsiteSuggestionReviewed::class, function (WebsiteSuggestionReviewed $mail) use ($advertiser) {
            return $mail->hasTo($advertiser->email) && $mail->suggestion->status === 'accepted';
        });
    }

    public function test_admin_create_with_stale_suggestion_id_still_saves_the_listing(): void
    {
        Mail::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->admin)->post(route('admin.sites.store'), [
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Staff Added News',
            'site_url' => 'https://staff-added-news.example',
            'example_url' => 'https://staff-added-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
            'written_request' => 1,
            'suggestion_id' => 99999,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertNotNull(Site::where('domain', 'staff-added-news.example')->first());
    }
}
