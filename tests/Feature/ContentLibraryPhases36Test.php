<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Content Library phases 3–6: expiry UX, score clarity, preview fetch, language guard.
 */
class ContentLibraryPhases36Test extends TestCase
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

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug = 'pub'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser): Order
    {
        return Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 46,
            'tax' => 0,
            'total_amount' => 46,
            'payment_method' => 'wallet',
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ]);
    }

    public function test_near_expiry_banner_and_row_hint(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Expiring Soon Piece',
            'expires_at' => now()->addDays(3),
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Expiring Soon Piece')
            ->assertSee('expire', false)
            ->assertSee('within 7 days', false)
            ->assertSee('keep the original file', false)
            ->getContent();

        $this->assertStringContainsString('Expires in', $html);
        $this->assertStringContainsString('library-expiry-hint', $html);
        $this->assertStringContainsString('library-expiry-hint--urgent', $html);
        $this->assertTrue($submission->fresh()->isNearExpiry(7));
    }

    public function test_far_expiry_row_hint_is_not_styled_as_urgent(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Plenty Of Time Piece',
            'expires_at' => now()->addMonths(6),
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Plenty Of Time Piece')
            ->assertDontSee('within 7 days', false)
            ->getContent();

        $this->assertStringContainsString('Expires in', $html);
        $this->assertStringContainsString('library-expiry-hint', $html);
        $this->assertStringNotContainsString('library-expiry-hint--urgent', $html);
        $this->assertFalse($submission->fresh()->isNearExpiry(7));
    }

    public function test_purge_expired_skips_articles_linked_to_orders(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'purge');

        $unused = $this->createApprovedSubmission($advertiser);
        $unused->update([
            'title' => 'Unused Expired',
            'expires_at' => now()->subDay(),
        ]);

        $linked = $this->createApprovedSubmission($advertiser, $site->id);
        $order = $this->makeOrder($advertiser);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/a.docx',
            'content_submission_id' => $linked->id,
        ]);
        $linked->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'expires_at' => now()->subDay(),
            'title' => 'Linked Expired',
        ]);

        $unusedPath = $unused->path;
        $unusedDisk = $unused->disk ?: 'local';
        $this->assertTrue(Storage::disk($unusedDisk)->exists($unusedPath));
        $preview = (string) $unused->preview_html;

        $exit = Artisan::call('content:purge-expired');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('unused expired', Artisan::output());

        $this->assertDatabaseHas('content_submissions', ['id' => $unused->id]);
        $stripped = $unused->fresh();
        $this->assertSame('', (string) $stripped->path);
        $this->assertSame(0, (int) $stripped->size_bytes);
        $this->assertSame($preview, (string) $stripped->preview_html);
        $this->assertFalse($stripped->hasStoredFile());
        $this->assertFalse($stripped->canDownloadOriginal());
        $this->assertFalse($stripped->canEditArticle());
        $this->assertFalse($stripped->canBeOrdered());
        $this->assertFalse(Storage::disk($unusedDisk)->exists($unusedPath));

        $this->assertDatabaseHas('content_submissions', ['id' => $linked->id]);
        $this->assertTrue($linked->fresh()->hasStoredFile());
        $this->assertTrue($linked->fresh()->canDownloadOriginal());

        $this->actingAs($publisher)
            ->get(route('publisher.content.download', $linked))
            ->assertForbidden();

        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($publisher)
            ->get(route('publisher.content.download', $linked))
            ->assertOk();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-submissions.download', $linked))
            ->assertOk();
    }

    public function test_expired_filter_and_purge_ignore_cancelled_owner_order_id(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Ghost Expired Piece',
            'expires_at' => now()->subDay(),
        ]);
        $cancelled = $this->makeOrder($advertiser);
        $cancelled->update(['status' => 'cancelled', 'payment_status' => 'failed']);
        $submission->update(['order_id' => $cancelled->id]);

        $fresh = $submission->fresh();
        $this->assertFalse($fresh->isInUse());
        $this->assertSame('expired', $fresh->libraryAvailability());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->expiredUnused()->exists()
        );

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'expired']))
            ->assertOk()
            ->assertSee('Ghost Expired Piece');

        $path = $fresh->path;
        $disk = $fresh->disk ?: 'local';
        $this->assertTrue(Storage::disk($disk)->exists($path));

        $this->assertSame(0, Artisan::call('content:purge-expired'));
        $stripped = $submission->fresh();
        $this->assertSame('', (string) $stripped->path);
        $this->assertFalse($stripped->hasStoredFile());
        $this->assertFalse(Storage::disk($disk)->exists($path));
    }

    public function test_purge_expired_strips_article_still_pointed_at_by_cancelled_leftover(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'purge-leftover');
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = $this->makeOrder($advertiser);
        $leftover->update(['status' => 'cancelled', 'payment_status' => 'failed']);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/leftover.docx',
            'content_submission_id' => $submission->id,
        ]);
        $submission->update([
            'order_id' => null,
            'order_item_id' => null,
            'expires_at' => now()->subDay(),
            'title' => 'Released Leftover Expired',
        ]);

        $this->assertSame($submission->id, (int) $item->fresh()->content_submission_id);
        $this->assertFalse($submission->fresh()->isInUse());
        $this->assertFalse($submission->fresh()->isLinkedToOpenOrderItem());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->expiredUnused()->withoutOpenOrderItemLink()->exists()
        );

        $path = $submission->path;
        $disk = $submission->disk ?: 'local';
        $this->assertTrue(Storage::disk($disk)->exists($path));

        $this->assertSame(0, Artisan::call('content:purge-expired'));
        $stripped = $submission->fresh();
        $this->assertSame('', (string) $stripped->path);
        $this->assertFalse($stripped->hasStoredFile());
        $this->assertFalse(Storage::disk($disk)->exists($path));
        $this->assertSame($submission->id, (int) $item->fresh()->content_submission_id);
    }

    public function test_dual_role_publisher_cannot_download_unpaid_article_via_advertiser_route(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $publisher->roles()->syncWithoutDetaching([
            Role::firstOrCreate(['name' => 'advertiser'])->id,
        ]);
        $site = $this->activeSite($publisher, 'dual-dl');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $order = $this->makeOrder($advertiser);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/unpaid.docx',
            'content_submission_id' => $submission->id,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($publisher)
            ->get(route('advertiser.content-submissions.download', $submission))
            ->assertForbidden();

        $this->actingAs($publisher)
            ->get(route('publisher.content.download', $submission))
            ->assertForbidden();

        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($publisher)
            ->get(route('advertiser.content-submissions.download', $submission))
            ->assertOk();
    }

    public function test_site_id_alone_does_not_let_publisher_download_library_article(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $publisher->roles()->syncWithoutDetaching([
            Role::firstOrCreate(['name' => 'advertiser'])->id,
        ]);
        $site = $this->activeSite($publisher, 'site-only-dl');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($publisher)
            ->get(route('advertiser.content-submissions.download', $submission))
            ->assertForbidden();
    }

    public function test_completed_row_keeps_live_url_clickable_without_pointer_events_none(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'live');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $submission->update(['title' => 'Live Article']);
        $order = $this->makeOrder($advertiser);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
            'live_url' => 'https://live.example/post-36',
            'live_url_submitted_at' => now(),
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('https://live.example/post-36')
            ->assertSee('library-live-url', false)
            ->assertSee('copyLibraryLiveUrl', false)
            ->assertSee('js-open-preview', false)
            ->getContent();

        $this->assertStringContainsString('library-row--completed', $html);
        $this->assertStringNotContainsString('pointer-events: none', $html);
        $this->assertStringNotContainsString('data-preview-payload=', $html);
        $this->assertStringNotContainsString('data-editor-payload=', $html);
        $this->assertStringContainsString('data-submission-id="'.$submission->id.'"', $html);
        $this->assertStringContainsString('Advisory scores', $html);
        $this->assertStringContainsString('still be ordered', $html);
    }

    public function test_preview_endpoint_returns_payload_without_huge_blade_attributes(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Preview Fetch Article',
            'preview_html' => '<p>Hello <a href="https://example.com/x">world</a></p>',
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.preview', $submission))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('id', $submission->id)
            ->assertJsonPath('title', 'Preview Fetch Article')
            ->assertJsonPath('editable', true)
            ->assertJsonPath('can_order', true)
            ->assertJsonPath('preview_html', '<p>Hello <a href="https://example.com/x">world</a></p>')
            ->assertJsonPath('has_images', false)
            ->assertJsonPath('needs_image_rights', false)
            ->assertJsonPath('image_rights_covers', true);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-preview-payload=', $html);
        $this->assertStringNotContainsString('data-editor-payload=', $html);
        $this->assertStringContainsString('js-open-editor', $html);
        $this->assertStringContainsString('fetchSubmissionPayload', file_get_contents(public_path('assets/js/content-library.js')));
        $this->assertStringContainsString('js-open-editor', $html);
    }

    public function test_needs_fix_shows_blocking_vs_advisory_groups(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Needs Fix Article',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => 'rejected',
            'evaluation_report' => [
                'summary' => 'Restricted content detected.',
                'checks' => [
                    ['key' => 'restricted_content', 'label' => 'Policy', 'status' => 'fail', 'detail' => 'Found: casino'],
                    ['key' => 'uniqueness', 'label' => 'Uniqueness', 'status' => 'warn', 'detail' => '40% unique (advisory)'],
                ],
                'matched_terms' => ['casino'],
            ],
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertSee('Needs Fix Article')
            ->assertSee('Blocking')
            ->assertSee('Found: casino')
            ->assertSee('Advisory')
            ->assertSee('40% unique', false)
            ->getContent();

        $this->assertStringContainsString('library-reason-list--blocking', $html);
        $this->assertStringContainsString('library-reason-list--advisory', $html);
    }

    public function test_needs_fix_with_null_evaluation_report_does_not_500(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Scan Error Piece',
            'moderation_status' => ContentSubmission::STATUS_ERROR,
            'evaluation_status' => 'error',
            'evaluation_report' => null,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertSee('Scan Error Piece')
            ->assertSee('Fix issues and resubmit.')
            ->assertDontSee('Trying to access array offset', false);
    }

    public function test_needs_fix_with_nested_evaluation_report_does_not_500(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Nested Report Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => 'rejected',
            'evaluation_report' => [
                'summary' => ['Restricted content detected.'],
                'checks' => 'not-a-list',
                'matched_terms' => [['term' => 'casino'], 'poker'],
                'blocked_urls' => [['url' => 'https://bet.example/x']],
            ],
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertSee('Nested Report Piece')
            ->assertSee('Restricted content detected.')
            ->assertSee('casino')
            ->assertSee('poker')
            ->assertSee('https://bet.example/x')
            ->assertDontSee('Array to string conversion', false)
            ->assertDontSee('must be of type string', false)
            ->getContent();

        $this->assertStringContainsString('Remove/rewrite:', $html);
        $this->assertStringContainsString('Blocked links:', $html);
    }

    public function test_low_score_approved_row_shows_advisory_orderable_note(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Low Score Still Ok',
            'uniqueness_score' => 20,
            'quality_score' => 30,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Low Score Still Ok')
            ->assertSee('Advisory — still orderable');
    }

    public function test_low_score_approved_row_does_not_say_orderable_when_rights_are_missing(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Low Score Needs Rights',
            'preview_html' => '<p>Body</p><img src="/storage/content-articles/1/x.png" alt="">',
            'image_rights' => null,
            'uniqueness_score' => 20,
            'quality_score' => 30,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertSee('Low Score Needs Rights')
            ->assertDontSee('Advisory — still orderable');
    }
}
