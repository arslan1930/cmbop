<?php

namespace Tests\Feature;

use App\Models\ContentModerationLog;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\ContentLibrarySearchQuery;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryImprovementsPlanTest extends TestCase
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
            'site_name' => 'Plan Site',
            'site_url' => 'https://plan-site.example',
            'domain' => 'plan-site.example',
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

    private function paidOrder(User $advertiser, ContentSubmission $submission, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-PLAN-'.uniqid(),
            'reference_code' => 'REF-PLAN-'.uniqid(),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ]);

        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        return $order;
    }

    public function test_advertiser_library_leftover_query_flashes_instead_of_500(): void
    {
        $advertiser = $this->advertiser();

        $this->mock(ContentLibrarySearchQuery::class, function ($mock) {
            $mock->shouldReceive('apply')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: leftover boom'));
        });

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'leftover']))
            ->assertOk()
            ->assertDontSee('SQLSTATE')
            ->assertSee('We could not load your content library');
    }

    public function test_advertiser_library_survives_dropped_submissions_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('content_submissions');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertDontSee('SQLSTATE')
            ->assertSee('We could not load your content library');
    }

    public function test_admin_library_leftover_query_flashes_instead_of_500(): void
    {
        $admin = $this->admin();
        $this->createApprovedSubmission($this->advertiser())->update(['title' => 'Safe Piece']);

        $this->mock(ContentLibrarySearchQuery::class, function ($mock) {
            $mock->shouldReceive('apply')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: leftover boom'));
        });

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['q' => 'leftover']))
            ->assertOk()
            ->assertDontSee('SQLSTATE')
            ->assertSee('We could not load the content library')
            ->assertDontSee('Safe Piece');
    }

    public function test_admin_library_survives_dropped_submissions_table(): void
    {
        $admin = $this->admin();
        Schema::dropIfExists('content_submissions');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index'))
            ->assertOk()
            ->assertDontSee('SQLSTATE');
    }

    public function test_admin_live_search_fragment_and_kill_switch(): void
    {
        $admin = $this->admin();
        $article = $this->createApprovedSubmission($this->advertiser());
        $article->update(['title' => 'Fragment Playbook']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.results', ['q' => 'Fragment']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Fragment Playbook')
            ->assertSee('Evaluating')
            ->assertDontSee('<html', false);

        Config::set('content_library.live_search.enabled', false);

        $this->actingAs($admin)
            ->get(route('admin.content-library.results', ['q' => 'Fragment']))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['q' => 'Fragment']))
            ->assertOk()
            ->assertSee('Fragment Playbook')
            ->assertDontSee('admin-content-library.js', false);
    }

    public function test_admin_show_uses_single_staff_actions_card(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => 'https://example.com/staff-card.docx',
            'status' => ContentModerationLog::STATUS_APPROVED,
            'passed' => true,
            'scan_token' => 'scan-staff-card',
            'word_count' => 20,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Staff Card Piece',
            'moderation_log_id' => $log->id,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('Staff actions')
            ->assertSee(route('admin.content-library.override', $submission), false)
            ->assertSee(route('admin.moderation.show', $log), false)
            ->getContent();

        $this->assertStringNotContainsString(route('admin.moderation.override', $log, false), $html);
        $this->assertSame(1, substr_count($html, 'id="adminLibraryOverrideNotes"'));
    }

    public function test_admin_index_shows_file_missing_badge(): void
    {
        $admin = $this->admin();
        $submission = $this->createApprovedSubmission($this->advertiser());
        $submission->update([
            'title' => 'Ghost File Piece',
            'path' => 'content-uploads/missing-on-disk.docx',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index'))
            ->assertOk()
            ->assertSee('Ghost File Piece')
            ->assertSee('File missing on disk');
    }

    public function test_admin_advertiser_picker_matches_email(): void
    {
        $admin = $this->admin();
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $owned = $this->createApprovedSubmission($owner);
        $owned->update(['title' => 'Owner Picker Piece']);
        $stranger = $this->createApprovedSubmission($other);
        $stranger->update(['title' => 'Other Picker Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['advertiser' => $owner->email]))
            ->assertOk()
            ->assertSee('Owner Picker Piece')
            ->assertDontSee('Other Picker Piece')
            ->assertSee('Advertiser filter')
            ->assertSee($owner->email);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['advertiser' => 'nobody-matched@example.test']))
            ->assertOk()
            ->assertSee('No advertiser matched')
            ->assertDontSee('Owner Picker Piece')
            ->assertDontSee('Other Picker Piece');
    }

    public function test_needs_corrections_banner_and_fix_deep_link(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Fix Me Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => 'rejected',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertSee('Fix Me Piece')
            ->assertSee('fix=1', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'edit' => $submission->id,
                'fix' => 1,
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertSee('Needs corrections.')
            ->assertSee('libraryFixBanner', false);
    }

    public function test_evaluating_chip_is_not_processing(): void
    {
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $evaluating = $this->createApprovedSubmission($advertiser);
        $evaluating->update([
            'title' => 'Still Scanning Piece',
            'moderation_status' => ContentSubmission::STATUS_PROCESSING,
            'evaluation_status' => 'processing',
            'evaluated_at' => null,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'evaluating',
            ]))
            ->assertOk()
            ->assertSee('Still Scanning Piece')
            ->assertSee('Evaluating');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'in_progress',
            ]))
            ->assertOk()
            ->assertDontSee('Still Scanning Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'evaluating']))
            ->assertOk()
            ->assertSee('Still Scanning Piece')
            ->assertSee('Evaluating');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertDontSee('Still Scanning Piece');
    }

    public function test_advertiser_and_admin_sort_by_title(): void
    {
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $zebra = $this->createApprovedSubmission($advertiser);
        $zebra->update(['title' => 'Zebra Sort Piece']);
        $alpha = $this->createApprovedSubmission($advertiser);
        $alpha->update(['title' => 'Alpha Sort Piece']);

        $advertiserHtml = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['sort' => 'title']))
            ->assertOk()
            ->assertSee('Alpha Sort Piece')
            ->assertSee('Zebra Sort Piece')
            ->getContent();
        $this->assertLessThan(
            strpos($advertiserHtml, 'Zebra Sort Piece'),
            strpos($advertiserHtml, 'Alpha Sort Piece')
        );

        $adminHtml = $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['sort' => 'title']))
            ->assertOk()
            ->getContent();
        $this->assertLessThan(
            strpos($adminHtml, 'Zebra Sort Piece'),
            strpos($adminHtml, 'Alpha Sort Piece')
        );
    }

    public function test_advertiser_bulk_archive_and_delete_skip_order_linked(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $unused = $this->createApprovedSubmission($advertiser);
        $unused->update(['title' => 'Bulk Unused']);
        $linked = $this->createApprovedSubmission($advertiser);
        $linked->update(['title' => 'Bulk Linked']);
        $this->paidOrder($advertiser, $linked, $site);
        $deletable = $this->createApprovedSubmission($advertiser);
        $deletable->update(['title' => 'Bulk Delete Me']);

        $this->actingAs($advertiser)
            ->from(route('advertiser.content-library'))
            ->post(route('advertiser.content-submissions.bulk-archive'), [
                'ids' => [$unused->id, $linked->id],
            ])
            ->assertRedirect(route('advertiser.content-library'));

        $this->assertNotNull($unused->fresh()->archived_at);
        $this->assertNull($linked->fresh()->archived_at);

        $this->actingAs($advertiser)
            ->from(route('advertiser.content-library'))
            ->post(route('advertiser.content-submissions.bulk-destroy'), [
                'ids' => [$deletable->id, $linked->id],
            ])
            ->assertRedirect(route('advertiser.content-library'));

        $this->assertNull(ContentSubmission::query()->find($deletable->id));
        $this->assertNotNull($linked->fresh());
    }

    public function test_admin_bulk_archive_and_csv_export(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $unused = $this->createApprovedSubmission($advertiser);
        $unused->update(['title' => 'Admin Bulk Unused']);
        $other = $this->createApprovedSubmission($advertiser);
        $other->update(['title' => 'Admin Export Piece']);

        $this->actingAs($admin)
            ->from(route('admin.content-library.index'))
            ->post(route('admin.content-library.bulk-archive'), [
                'ids' => [$unused->id],
            ])
            ->assertRedirect();

        $this->assertNotNull($unused->fresh()->archived_at);

        $export = $this->actingAs($admin)
            ->get(route('admin.content-library.export', ['q' => 'Export']))
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('content-type'));
        $csv = $export->streamedContent();

        $this->assertStringContainsString('Admin Export Piece', $csv);
        $this->assertStringContainsString($advertiser->email, $csv);
    }

    public function test_admin_export_leftover_redirects_with_flash(): void
    {
        $admin = $this->admin();

        $this->mock(ContentLibrarySearchQuery::class, function ($mock) {
            $mock->shouldReceive('apply')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: leftover boom'));
        });

        $this->actingAs($admin)
            ->get(route('admin.content-library.export', ['q' => 'leftover']))
            ->assertRedirect(route('admin.content-library.index', ['q' => 'leftover']));

        $this->assertStringContainsString('could not export', (string) session('error'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_change_market_reevaluates_unused_and_blocks_invalid_or_paid(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $unused = $this->createApprovedSubmission($advertiser);
        $unused->update(['title' => 'Market Change Piece', 'country' => 'us', 'language' => 'en']);
        $paid = $this->createApprovedSubmission($advertiser);
        $paid->update(['title' => 'Paid Market Piece']);
        $this->paidOrder($advertiser, $paid, $site);

        $this->partialMock(ContentUploadService::class, function ($mock) {
            $mock->shouldReceive('reEvaluateSubmission')->once()->andReturn([
                'approved' => true,
                'submission' => new ContentSubmission,
                'title' => 'Market Change Piece',
                'message' => 'ok',
                'report' => [],
                'moderation_status' => ContentSubmission::STATUS_APPROVED,
            ]);
        });

        $this->actingAs($advertiser)
            ->from(route('advertiser.content-library'))
            ->post(route('advertiser.content-submissions.market', $unused), [
                'country' => 'uk',
                'language' => 'en',
            ])
            ->assertRedirect(route('advertiser.content-library'));

        $this->assertSame('uk', $unused->fresh()->country);
        $this->assertSame('en', $unused->fresh()->language);

        $this->actingAs($advertiser)
            ->from(route('advertiser.content-library'))
            ->post(route('advertiser.content-submissions.market', $unused), [
                'country' => 'de',
                'language' => 'en',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('language');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.market', $paid), [
                'country' => 'uk',
                'language' => 'en',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_drafts_json_is_safe_when_submissions_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('content_submissions');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.drafts'))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_advertiser_download_unknown_disk_is_404(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['disk' => 'not-a-real-disk']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-submissions.download', $submission))
            ->assertNotFound();
    }

    public function test_admin_show_survives_dropped_order_items_table(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Show Leftover Piece']);
        $this->paidOrder($advertiser, $submission, $this->siteFor($this->publisher()));

        Schema::dropIfExists('order_items');

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('Show Leftover Piece')
            ->assertDontSee('SQLSTATE');
    }

    public function test_admin_and_advertiser_lists_survive_dropped_order_items_table(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'List Leftover Piece']);
        $this->paidOrder($advertiser, $submission, $this->siteFor($this->publisher()));

        Schema::dropIfExists('order_items');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'all']))
            ->assertOk()
            ->assertSee('List Leftover Piece')
            ->assertDontSee('SQLSTATE');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'all', 'availability' => 'all']))
            ->assertOk()
            ->assertSee('List Leftover Piece')
            ->assertDontSee('SQLSTATE');
    }

    public function test_upload_config_json_is_safe_when_settings_throw(): void
    {
        $advertiser = $this->advertiser();

        $this->mock(ContentUploadService::class, function ($mock) {
            $mock->shouldReceive('effectiveConfig')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: leftover boom'));
        });

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.config'))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_editor_image_json_is_safe_when_submissions_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('content_submissions');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image'), [
                'image' => UploadedFile::fake()->image('pic.png'),
                'content_submission_id' => 1,
                'current_image_count' => 0,
            ])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }
}
