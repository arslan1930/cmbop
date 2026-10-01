<?php

namespace Tests\Feature;

use App\Models\ContentModerationLog;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\AdminContentLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdminContentLibraryDeskTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Desk Site',
            'site_url' => 'https://desk-site.example',
            'domain' => 'desk-site.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Desk site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_results_pagination_links_point_at_index(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        for ($i = 1; $i <= 31; $i++) {
            $this->createApprovedSubmission($advertiser, null, $i)->update([
                'title' => 'Admin Pager '.$i,
            ]);
        }

        $js = file_get_contents(public_path('assets/js/admin-content-library.js'));
        $this->assertNotFalse($js);
        $this->assertStringContainsString('.pagination a', $js);

        $html = $this->actingAs($admin)
            ->get(route('admin.content-library.results', ['q' => 'Admin Pager']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('page=2', $html);
        $this->assertStringContainsString(route('admin.content-library.index', absolute: false), $html);
        $this->assertStringNotContainsString('/content-library/results?', $html);
    }

    public function test_index_uses_live_filter_attr_and_checkout_copy(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.content-library.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-admin-filter-live="1"', $html);
        $this->assertStringContainsString('checkout-ready', $html);
        $this->assertStringContainsString('Policy &amp; scans', $html);
        $this->assertStringContainsString('adminLibrarySelectPage', $html);
        $this->assertStringContainsString((string) AdminContentLibrary::EXPORT_LIMIT, $html);
    }

    public function test_show_back_keeps_list_filters_from_session(): void
    {
        $admin = $this->admin();
        $submission = $this->createApprovedSubmission($this->advertiser());
        $submission->update([
            'title' => 'Filter Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', [
                'availability' => 'needs_fix',
                'q' => 'Filter',
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee(route('admin.content-library.index', [
                'availability' => 'needs_fix',
                'q' => 'Filter',
            ]));
    }

    public function test_expiring_soon_and_unattached_filters(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $soon = $this->createApprovedSubmission($advertiser);
        $soon->update([
            'title' => 'Soon Piece',
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'expires_at' => now()->addDays(5),
        ]);
        $later = $this->createApprovedSubmission($advertiser);
        $later->update([
            'title' => 'Later Piece',
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'expires_at' => now()->addMonths(4),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['expiring' => 'soon']))
            ->assertOk()
            ->assertSee('Soon Piece')
            ->assertDontSee('Later Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['attachment' => 'none']))
            ->assertOk()
            ->assertSee('Soon Piece')
            ->assertSee('Later Piece');
    }

    public function test_attachment_order_hides_unattached(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach(Role::firstOrCreate(['name' => 'publisher'])->id);
        $site = $this->siteFor($publisher);

        $loose = $this->createApprovedSubmission($advertiser);
        $loose->update(['title' => 'Loose Piece']);
        $attached = $this->createApprovedSubmission($advertiser);
        $attached->update(['title' => 'Attached Piece']);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-DESK-1',
            'reference_code' => 'REF-DESK-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_submission_id' => $attached->id,
        ]);
        $attached->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['attachment' => 'order']))
            ->assertOk()
            ->assertSee('Attached Piece')
            ->assertDontSee('Loose Piece');
    }

    public function test_bulk_restore_and_failed_ids(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $archived = $this->createApprovedSubmission($advertiser);
        $archived->update(['title' => 'Archived Desk Piece', 'archived_at' => now()]);

        $this->actingAs($admin)
            ->from(route('admin.content-library.index', ['availability' => 'archived']))
            ->post(route('admin.content-library.bulk-restore'), [
                'ids' => [$archived->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($archived->fresh()->archived_at);
    }

    public function test_export_includes_desk_columns(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser)->update(['title' => 'Csv Desk Piece']);

        $csv = $this->actingAs($admin)
            ->get(route('admin.content-library.export', ['q' => 'Csv Desk']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('moderation_status', $csv);
        $this->assertStringContainsString('file_on_disk', $csv);
        $this->assertStringContainsString('Csv Desk Piece', $csv);
        $this->assertStringContainsString($advertiser->email, $csv);
    }

    public function test_show_lists_scan_history(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $old = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-old',
            'word_count' => 10,
        ]);
        $current = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'passed' => true,
            'scan_token' => 'scan-new',
            'word_count' => 12,
        ]);
        $submission->update(['moderation_log_id' => $current->id]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('Scan history')
            ->assertSee('#'.$old->id, false)
            ->assertSee('#'.$current->id, false)
            ->assertSee(route('admin.moderation.show', $old), false);
    }

    public function test_file_missing_filter_uses_stored_flag(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $ghost = $this->createApprovedSubmission($advertiser);
        $ghost->update([
            'title' => 'Ghost Filter Piece',
            'path' => 'content-uploads/missing-on-disk.docx',
            'file_on_disk' => null,
        ]);
        $present = $this->createApprovedSubmission($advertiser);
        $present->update(['title' => 'Present Filter Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['file' => 'missing']))
            ->assertOk()
            ->assertSee('Ghost Filter Piece')
            ->assertDontSee('Present Filter Piece');
    }

    public function test_archive_writes_staff_activity_on_show(): void
    {
        $admin = $this->admin();
        $submission = $this->createApprovedSubmission($this->advertiser());
        $submission->update(['title' => 'Activity Piece']);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.archive', $submission))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('Staff activity')
            ->assertSee('archived library article #'.$submission->id, false);
    }
}
