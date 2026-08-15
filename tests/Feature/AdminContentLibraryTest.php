<?php

namespace Tests\Feature;

use App\Models\ContentModerationLog;
use App\Models\ContentSubmission;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdminContentLibraryTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Library Staff Site',
            'site_url' => 'https://library-staff.example',
            'domain' => 'library-staff.example',
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
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function orderFor(User $advertiser, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LIB-'.uniqid(),
            'reference_code' => 'REF-LIB-'.uniqid(),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ], $attrs));
    }

    private function attachToOrder(ContentSubmission $submission, Order $order, Site $site, array $itemAttrs = []): OrderItem
    {
        $item = OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ], $itemAttrs));

        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        return $item;
    }

    public function test_unused_expired_approved_is_only_in_expired_chip(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $expired = $this->createApprovedSubmission($advertiser);
        $expired->update(['title' => 'Expired Unused Piece', 'expires_at' => now()->subDay()]);
        $fresh = $this->createApprovedSubmission($advertiser);
        $fresh->update(['title' => 'Fresh Approved Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index'))
            ->assertOk()
            ->assertSee('Fresh Approved Piece')
            ->assertDontSee('Expired Unused Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'available']))
            ->assertOk()
            ->assertSee('Fresh Approved Piece')
            ->assertDontSee('Expired Unused Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'expired']))
            ->assertOk()
            ->assertSee('Expired Unused Piece')
            ->assertDontSee('Fresh Approved Piece');
    }

    public function test_expired_article_on_open_order_is_not_in_expired_chip(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Expired But Owned', 'expires_at' => now()->subDay()]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'expired']))
            ->assertOk()
            ->assertDontSee('Expired But Owned');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Expired But Owned')
            ->assertSee('In progress');
    }

    public function test_rejected_owned_article_is_needs_fix_not_in_progress(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Rejected Owned Piece']);
        $order = $this->orderFor($advertiser);
        $this->attachToOrder($submission, $order, $site);
        $submission->update(['moderation_status' => ContentSubmission::STATUS_REJECTED]);

        $this->assertSame('needs_fix', $submission->fresh()->load(['order', 'orderItems.order'])->libraryAvailability());
        $this->assertFalse(
            ContentSubmission::query()->whereKey($submission->id)->inProgressInLibrary()->exists()
        );

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertDontSee('Rejected Owned Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Rejected Owned Piece');
    }

    public function test_legacy_status_approved_maps_to_available_chip(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Legacy Approved Filter']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Legacy Approved Filter')
            ->assertSee('btn-primary', false);
    }

    public function test_needs_fix_includes_rejected_and_missing_image_rights(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $rejected = $this->createApprovedSubmission($advertiser);
        $rejected->update([
            'title' => 'Rejected Staff Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
        ]);
        $rights = $this->createApprovedSubmission($advertiser);
        $rights->update([
            'title' => 'Missing Rights Piece',
            'preview_html' => '<p>Hello</p><img src="/storage/x.jpg" alt="x">',
            'image_rights' => null,
            'image_rights_source' => null,
        ]);
        $ready = $this->createApprovedSubmission($advertiser);
        $ready->update(['title' => 'Ready Approved Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Rejected Staff Piece')
            ->assertSee('Missing Rights Piece')
            ->assertDontSee('Ready Approved Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'available']))
            ->assertOk()
            ->assertSee('Ready Approved Piece')
            ->assertDontSee('Rejected Staff Piece')
            ->assertDontSee('Missing Rights Piece');
    }

    public function test_completed_chip_lists_live_placements(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Live Library Piece']);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site, [
            'live_url' => 'https://live.example/guest-post',
            'live_url_submitted_at' => now(),
            'publisher_status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('Live Library Piece')
            ->assertSee('Completed/LIVE');
    }

    public function test_user_id_filter_survives_chip_and_view_links(): void
    {
        $admin = $this->admin();
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $owned = $this->createApprovedSubmission($owner);
        $owned->update(['title' => 'Owner Library Piece']);
        $stranger = $this->createApprovedSubmission($other);
        $stranger->update(['title' => 'Other Advertiser Piece']);

        $html = $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['user_id' => $owner->id]))
            ->assertOk()
            ->assertSee('Owner Library Piece')
            ->assertDontSee('Other Advertiser Piece')
            ->assertSee('Advertiser filter')
            ->assertSee($owner->email)
            ->getContent();

        $this->assertStringContainsString('name="user_id"', $html);
        $this->assertStringContainsString('value="'.$owner->id.'"', $html);
        $this->assertStringContainsString('availability=available', $html);
        $this->assertStringContainsString('user_id='.$owner->id, $html);
        $this->assertStringContainsString('/admin/content-library/'.$owned->id, $html);
    }

    public function test_search_requires_every_word_in_title(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $playbook = $this->createApprovedSubmission($advertiser);
        $playbook->update(['title' => 'Growth Playbook']);
        $onlyGrowth = $this->createApprovedSubmission($advertiser);
        $onlyGrowth->update(['title' => 'Growth Only']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['q' => 'growth play']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertDontSee('Growth Only');
    }

    public function test_show_page_links_user_order_and_strips_script_preview(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Support Detail Piece',
            'preview_html' => '<p>Safe intro</p><script>alert(1)</script><img src="/storage/x.jpg" alt="x">',
            'image_rights' => null,
            'anchor_text' => 'click',
            'target_url' => 'javascript:alert(1)',
            'evaluation_report' => [
                'summary' => 'Casino terms found',
                'matched_terms' => ['casino'],
                'blocked_urls' => ['https://bad.example/bet'],
                'checks' => [
                    ['status' => 'fail', 'detail' => 'Blocked gambling language'],
                ],
            ],
        ]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $html = $this->actingAs($admin)
            ->get(route('admin.content-library.show', [
                'submission' => $submission,
                'user_id' => $advertiser->id,
                'availability' => 'in_progress',
            ]))
            ->assertOk()
            ->assertSee('Support Detail Piece')
            ->assertSee($advertiser->email)
            ->assertSee(route('admin.users.index', ['user' => $advertiser->id]), false)
            ->assertSee(route('admin.orders.show', $order), false)
            ->assertSee('Library Staff Site')
            ->assertSee('Images are not covered by a rights claim')
            ->assertSee('Blocked gambling language')
            ->assertSee('casino')
            ->assertSee('https://bad.example/bet')
            ->assertSee('Override approve')
            ->assertDontSee('Override reject')
            ->assertDontSee('>Re-evaluate<', false)
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('href="javascript:alert(1)"', $html);
        $this->assertStringContainsString('availability=in_progress', $html);
        $this->assertStringContainsString('user_id='.$advertiser->id, $html);
    }

    public function test_download_unknown_disk_is_404_not_500(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Bad Disk Piece',
            'disk' => 'not-a-real-disk',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.download', $submission))
            ->assertNotFound();
    }

    public function test_show_hides_download_when_file_missing_on_disk(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Missing File Piece',
            'path' => 'content-uploads/missing-file.docx',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('Original file missing on disk')
            ->assertDontSee(route('admin.content-library.download', $submission), false);
    }

    public function test_override_approve_updates_article_and_linked_scan_log(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-lib-1',
            'word_count' => 20,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'False Positive Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $log->id,
            'scan_token' => 'scan-lib-1',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'False positive on brand name.',
            ])
            ->assertRedirect();

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
        $this->assertTrue((bool) $log->fresh()->admin_override);
        $this->assertTrue((bool) $log->fresh()->passed);
        $this->assertTrue($submission->fresh()->isReadyForCheckout());
    }

    public function test_reject_while_paid_is_forbidden(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'rejected',
                'notes' => 'Trying to reject a paid placement.',
            ])
            ->assertRedirect(route('admin.content-library.show', $submission))
            ->assertSessionHas('error');

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
    }

    public function test_archive_blocked_while_in_progress_and_allowed_when_unused(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $inProgress = $this->createApprovedSubmission($advertiser);
        $inProgress->update(['title' => 'In Progress Archive']);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($inProgress, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $inProgress))
            ->post(route('admin.content-library.archive', $inProgress))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($inProgress->fresh()->archived_at);

        $unused = $this->createApprovedSubmission($advertiser);
        $this->actingAs($admin)
            ->post(route('admin.content-library.archive', $unused))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNotNull($unused->fresh()->archived_at);

        $this->actingAs($admin)
            ->post(route('admin.content-library.restore', $unused))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNull($unused->fresh()->archived_at);
    }

    public function test_retry_on_paid_article_is_forbidden(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
    }

    public function test_override_approve_does_not_claim_unready_article_is_orderable(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Still Unready Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'preview_html' => '<p>Hello</p><img src="/storage/x.jpg" alt="x">',
            'image_rights' => null,
            'evaluation_report' => [
                'summary' => 'Casino terms found',
                'matched_terms' => ['casino'],
                'checks' => [
                    ['status' => 'fail', 'detail' => 'Blocked gambling language'],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Brand name is fine here.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', function ($message) {
                return is_string($message) && str_contains($message, 'still not checkout-ready');
            });

        $fresh = $submission->fresh();
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $fresh->moderation_status);
        $this->assertFalse($fresh->isReadyForCheckout());
        $this->assertSame([], $fresh->evaluationMatchedTerms());
        $this->assertSame([], $fresh->evaluationReasonGroups()['blocking']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertDontSee('Blocked gambling language')
            ->assertSee('Images are not covered by a rights claim');

        $bell = InAppNotification::query()->where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($bell);
        $this->assertStringNotContainsString('You can attach it in the catalog', (string) $bell->message);
        $this->assertStringContainsString('availability=needs_fix', (string) $bell->action_url);
    }

    public function test_moderation_override_does_not_flip_log_when_article_is_archived(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-archived-1',
            'word_count' => 20,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $log->id,
            'archived_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $log))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
        $this->assertFalse((bool) $log->fresh()->admin_override);
        $this->assertSame(ContentModerationLog::STATUS_REJECTED, $log->fresh()->status);
    }

    public function test_moderation_override_does_not_approve_another_users_scan_token(): void
    {
        $admin = $this->admin();
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $owner->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'shared-token',
            'word_count' => 20,
        ]);
        $stranger = $this->createApprovedSubmission($other);
        $stranger->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'scan_token' => 'shared-token',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $log))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $stranger->fresh()->moderation_status);
        $this->assertTrue((bool) $log->fresh()->admin_override);
    }

    public function test_retry_reevaluates_error_article(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Retry Error Piece',
            'moderation_status' => ContentSubmission::STATUS_ERROR,
            'evaluation_status' => 'error',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-library.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotSame(ContentSubmission::STATUS_ERROR, $submission->fresh()->moderation_status);
    }

    public function test_moderation_override_updates_linked_article(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-mod-1',
            'word_count' => 20,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $log->id,
            'scan_token' => 'scan-mod-1',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log))
            ->assertRedirect(route('admin.content-library.show', $submission))
            ->assertSessionHas('success');

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
        $this->assertTrue((bool) $log->fresh()->admin_override);
    }

    public function test_advertiser_cannot_use_staff_library_actions(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('admin.content-library.index'))
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Should not work.',
            ])
            ->assertForbidden();
    }

    public function test_users_and_orders_link_into_content_library(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee(route('admin.content-library.index', ['user_id' => $advertiser->id]), false);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee(route('admin.content-library.show', $submission), false)
            ->assertSee('View in Content Library');
    }

    public function test_dead_preview_json_route_is_gone(): void
    {
        $this->assertFalse(Route::has('admin.content-library.preview'));
    }
}
