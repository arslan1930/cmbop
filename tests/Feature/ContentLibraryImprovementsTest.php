<?php

namespace Tests\Feature;

use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ArticleEvaluationService;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryImprovementsTest extends TestCase
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

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug, float $price = 40, string $country = 'us', string $language = 'en'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => $country,
            'language' => $language,
            'countries' => [$country],
            'languages' => [$language],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_library_compact_table_filters_and_status_labels(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'alpha');

        $available = $this->createApprovedSubmission($advertiser, null, 0, 'anchor a', 'https://example.com/a');
        $available->update(['title' => 'Growth Playbook']);

        $ordered = $this->createApprovedSubmission($advertiser, $site->id, 0, 'anchor b', 'https://example.com/b');
        $ordered->update(['title' => 'Ordered Piece']);
        $order = $this->makeOrder($advertiser);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $ordered->id,
        ]);
        $ordered->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $uk = $this->createApprovedSubmission($advertiser, null, 0, 'anchor c', 'https://example.com/c', 'gb', 'en');
        $uk->update(['title' => 'UK Guide']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Title')
            ->assertSee('Market')
            ->assertSee('Scores')
            ->assertSee('Growth Playbook')
            ->assertSee('Approved');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'available']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertSee('UK Guide')
            ->assertDontSee('Ordered Piece');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Ordered Piece')
            ->assertDontSee('Growth Playbook')
            ->assertSee('Approved')
            ->assertSee('Processing')
            ->assertSee('library-status--processing', false)
            ->assertSee('library-status-sweep', false)
            ->assertSee('Uploaded', false)
            ->assertDontSee('>In progress</span>', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'Growth']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertDontSee('UK Guide');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['country' => 'gb']))
            ->assertOk()
            ->assertSee('UK Guide')
            ->assertDontSee('Growth Playbook')
            ->assertSee('Reset', false)
            ->assertDontSee('>Apply<', false);
    }

    public function test_library_shows_published_live_link(): void
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
            'live_url' => 'https://live.example/post',
            'live_url_submitted_at' => now(),
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('Live Article')
            ->assertSee('Completed/LIVE')
            ->assertSee('Published', false)
            ->assertSee('library-status-time', false)
            ->assertSee('https://live.example/post')
            ->assertSee('Published on:')
            ->assertSee($site->site_name)
            ->assertDontSee('Open live URL')
            ->assertDontSee('>Copy<', false)
            ->assertDontSee(route('advertiser.content-library.order', $submission), false)
            ->getContent();

        $this->assertStringContainsString('library-status--completed', $html);
        $this->assertStringContainsString('library-row--completed', $html);
        $this->assertStringContainsString('copyLibraryLiveUrl', $html);
        $this->assertStringContainsString('fa-copy', $html);
        $this->assertStringContainsString('library-live-url', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/library-row-'.$submission->id.'[\s\S]*?href="[^"]*content-library\/'.$submission->id.'\/order"/',
            $html
        );

        // Legacy published query still works and maps to Completed UI.
        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'published']))
            ->assertOk()
            ->assertSee('Live Article')
            ->assertSee('Completed/LIVE');
    }

    public function test_approved_chip_excludes_completed_and_in_progress(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'ready-only');

        $available = $this->createApprovedSubmission($advertiser, null, 0, 'a', 'https://example.com/a');
        $available->update(['title' => 'Ready To Order']);

        $live = $this->createApprovedSubmission($advertiser, $site->id, 0, 'b', 'https://example.com/b');
        $live->update(['title' => 'Already Live']);
        $order = $this->makeOrder($advertiser);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $live->id,
            'live_url' => 'https://live.example/done',
            'live_url_submitted_at' => now(),
        ]);
        $live->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'approved',
                'availability' => 'available',
            ]))
            ->assertOk()
            ->assertSee('Ready To Order')
            ->assertDontSee('Already Live');
    }

    public function test_library_exposes_single_status_filter_row(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Library status filter', false)
            ->assertSee('library-status-box--completed', false)
            ->assertSee('library-status-box--approved', false)
            ->assertSee('library-status-box--needs_fix', false)
            ->assertDontSee('Availability filter', false)
            ->assertDontSee('Moderation filter', false)
            ->assertDontSee('library-availability-row', false)
            ->assertDontSee('library-moderation-row', false)
            ->getContent();

        $this->assertStringContainsString('availability=completed', $html);
        $this->assertStringContainsString('availability=available', $html);
        $this->assertStringContainsString('availability=in_progress', $html);
        $this->assertStringContainsString('availability=archived', $html);
        $this->assertStringContainsString('availability=expired', $html);
        $this->assertStringNotContainsString('availability=evaluating', $html);
        $this->assertStringContainsString('Completed/LIVE', $html);
        $this->assertStringContainsString('>Approved</span>', $html);
        $this->assertStringContainsString('>Processing</span>', $html);
        $this->assertStringNotContainsString('>In progress</span>', $html);
        $this->assertStringContainsString('>Needs corrections</span>', $html);
        $this->assertStringContainsString('>Archived</span>', $html);
        $this->assertStringContainsString('>Expired</span>', $html);
        $this->assertStringContainsString('library-status-box--processing', $html);
        $this->assertStringNotContainsString('library-status-box--in_progress', $html);
        $this->assertStringContainsString('library-status-box--archived', $html);
        $this->assertStringContainsString('library-status-box--expired', $html);
        $this->assertStringNotContainsString('>All</span>', $html);
        $this->assertStringNotContainsString('library-status-box--all', $html);
        // Exactly one status strip markup block (CSS rule also mentions the class).
        $this->assertSame(1, substr_count($html, 'class="library-status-row"'));
        $this->assertMatchesRegularExpression(
            '/library-status-box--approved\s+is-active/',
            $html
        );
        $this->assertStringContainsString('<nav class="library-status-row"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertMatchesRegularExpression(
            '/<nav class="library-status-row"[^>]*>[\s\S]*?<\/nav>/',
            $html
        );
        preg_match('/<nav class="library-status-row"[^>]*>[\s\S]*?<\/nav>/', $html, $statusNav);
        $this->assertStringNotContainsString('role="tab', $statusNav[0]);
        $this->assertStringContainsString('mod-count is-zero', $html);
        $this->assertStringContainsString('id="libraryCountryFilter"', $html);
        $this->assertStringContainsString('id="libraryLanguageFilter"', $html);
        $this->assertStringContainsString('class="library-filter-bar mb-3"', $html);
        $this->assertStringContainsString('for="librarySearchInput">Search</label>', $html);
        $this->assertStringContainsString('id="librarySearchClear"', $html);
        $this->assertStringContainsString('id="librarySearchStatus"', $html);
        $this->assertStringContainsString('visually-hidden">Search</button>', $html);
        $this->assertStringContainsString('visually-hidden" for="libraryCountryFilter"', $html);
        $this->assertStringContainsString('visually-hidden" for="libraryLanguageFilter"', $html);
        $this->assertStringContainsString('Search title or filename', $html);
        $this->assertStringContainsString('All countries', $html);
        $this->assertStringContainsString('All languages', $html);
        $this->assertStringNotContainsString('>Apply<', $html);
        $this->assertStringContainsString('form-label fw-semibold small text-muted mb-1" for="librarySearchInput"', $html);
        $this->assertStringContainsString('id="libraryFilterReset"', $html);
        $this->assertStringContainsString('library-filter-bar__actions d-none', $html);

        $css = (string) file_get_contents(public_path('assets/css/content-library.css'));
        $this->assertStringContainsString('.library-status-row', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);
        $this->assertStringNotContainsString('library-status-dot', $html);
        $this->assertStringNotContainsString('library-eval-badge', $html);
        $this->assertStringContainsString('.library-status-sweep', $css);
        $this->assertStringContainsString('library-status-sweep', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('.library-status-box.is-active', $css);
        $this->assertStringContainsString('.mod-count.is-zero', $css);
        $this->assertStringContainsString('.library-status-box.is-active .mod-count:not(.is-zero)', $css);
        $this->assertStringNotContainsString('.library-status-box.is-active .mod-count {', $css);
        $this->assertStringContainsString('.library-browse-link', $css);
        $this->assertStringNotContainsString('.library-page-actions.upload-zone', $css);
        $this->assertStringContainsString('.library-filter-bar__row', $css);
        $this->assertStringContainsString(".library-filter-bar__row {\n        display: flex;\n        flex-wrap: wrap;\n        align-items: center;", $css);
        $this->assertStringContainsString('class="library-filter-bar__row"', $html);
        $this->assertStringContainsString(".library-browse-link {\n        display: inline-flex;\n        align-items: center;", $css);
        $this->assertStringNotContainsString('align-items: flex-end;', $css);
        $boxPos = strpos($css, '.library-status-box {');
        $mediaPos = strpos($css, '@media (max-width: 575.98px)');
        $this->assertNotFalse($boxPos);
        $this->assertNotFalse($mediaPos);
        $this->assertGreaterThan($boxPos, $mediaPos);
    }

    public function test_completed_filter_empty_state(): void
    {
        $advertiser = $this->advertiser();

        // An article has to exist, or the library is empty in the plain sense and
        // shows the "No articles yet" upload prompt instead — that prompt is the
        // more useful thing to show someone with nothing in the library at all.
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('No completed articles yet')
            ->assertSee('live URL')
            ->assertDontSee('No articles yet');
    }

    public function test_processing_filter_empty_state(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('No articles processing')
            ->assertSee('library-status-box--processing', false)
            ->assertDontSee('No articles in progress')
            ->assertDontSee('No articles yet');
    }

    public function test_low_uniqueness_approved_article_stays_orderable_and_listed(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Low Uniqueness Approved',
            'uniqueness_score' => 20, // below advisory threshold (50)
        ]);

        $this->assertTrue($submission->fresh()->canBeOrdered());
        $this->assertSame('available', $submission->fresh()->libraryAvailability());

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'approved',
                'availability' => 'available',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Low Uniqueness Approved', $html);
        $this->assertStringContainsString('library-status-box--approved', $html);
        $this->assertMatchesRegularExpression(
            '/library-status-box--approved[\s\S]*?>\s*1\s*</',
            $html
        );
    }

    public function test_advertiser_can_archive_and_restore_article(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Archive Me']);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.archive', $submission))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($submission->fresh()->archived_at);
        $this->assertFalse($submission->fresh()->canBeOrdered());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertDontSee('Archive Me');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'archived']))
            ->assertOk()
            ->assertSee('Archive Me');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.restore', $submission))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($submission->fresh()->archived_at);
        $this->assertTrue($submission->fresh()->canBeOrdered());
    }

    public function test_cart_picker_excludes_archived_approved_articles(): void
    {
        $advertiser = $this->advertiser();

        $ready = $this->createApprovedSubmission($advertiser);
        $ready->update(['title' => 'Ready For Checkout']);

        $archived = $this->createApprovedSubmission($advertiser);
        $archived->update(['title' => 'Archived Approved Piece']);
        $archived->archive();

        // Live assign path is the cart drawer (orderable gate), not checkout HTML.
        $cart = $this->actingAs($advertiser)
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $articleIds = collect($cart['approved_articles'] ?? [])->pluck('id')->all();
        $this->assertContains($ready->id, $articleIds);
        $this->assertNotContains($archived->id, $articleIds);
    }

    public function test_cart_assign_rejects_archived_approved_article(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'assign-arch');

        $archived = $this->createApprovedSubmission($advertiser);
        $archived->archive();

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => null,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $site->id,
                'content_submission_id' => $archived->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_cart_assign_rejects_an_article_with_an_incomplete_link(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'assign-link');
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['target_url' => null]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => null,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $site->id,
                'content_submission_id' => $submission->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_library_order_rejects_an_article_with_an_incomplete_link(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['anchor_text' => 'only the label', 'target_url' => null]);

        $this->actingAs($advertiser)
            ->from(route('advertiser.content-library'))
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.content-library'))
            ->assertSessionHas('error');

        $this->assertTrue(session()->missing('checkout_content_submission_id'));
    }

    public function test_library_order_button_links_to_catalog_flow(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Ready Article']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Order')
            ->assertSee(route('advertiser.content-library.order', $submission, false), false)
            ->assertDontSee('id="orderContentModal"', false);
    }

    public function test_advertiser_can_rename_and_delete_unlinked_library_article(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Old Title']);

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'title' => 'New Title',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.title', 'New Title');

        $this->assertSame('New Title', $submission->fresh()->title);

        $this->actingAs($advertiser)
            ->deleteJson(route('advertiser.content-submissions.destroy', $submission))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('content_submissions', ['id' => $submission->id]);
    }

    public function test_cannot_delete_article_linked_to_order(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'linked');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $order = $this->makeOrder($advertiser);
        $submission->update(['order_id' => $order->id]);

        $this->actingAs($advertiser)
            ->deleteJson(route('advertiser.content-submissions.destroy', $submission))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('content_submissions', ['id' => $submission->id]);
    }

    public function test_library_availability_helper_on_model(): void
    {
        $advertiser = $this->advertiser();
        $available = $this->createApprovedSubmission($advertiser);
        $this->assertSame('available', $available->libraryAvailability());

        $inProgress = $this->createApprovedSubmission($advertiser);
        $order = $this->makeOrder($advertiser);
        $inProgress->update(['order_id' => $order->id]);
        $this->assertSame('in_progress', $inProgress->fresh()->libraryAvailability());

        $expired = $this->createApprovedSubmission($advertiser);
        $expired->update(['expires_at' => now()->subDay()]);
        $this->assertSame('expired', $expired->fresh()->libraryAvailability());

        $archived = $this->createApprovedSubmission($advertiser);
        $archived->archive();
        $this->assertSame('archived', $archived->fresh()->libraryAvailability());
    }

    public function test_just_approved_marks_recent_orderable_articles(): void
    {
        $this->freezeTime();
        $advertiser = $this->advertiser();

        $fresh = $this->createApprovedSubmission($advertiser);
        $fresh->update(['title' => 'Fresh Approved Piece']);
        $this->assertTrue($fresh->isJustApproved());
        $this->assertTrue($fresh->showJustApprovedBadge());
        $this->assertSame('Approved today', $fresh->justApprovedLabel());

        $onCutoff = $this->createApprovedSubmission($advertiser);
        $onCutoff->update([
            'title' => 'Cutoff Approved Piece',
            'evaluated_at' => now()->subDays(ContentSubmission::JUST_APPROVED_DAYS)->startOfDay()->addHour(),
        ]);
        $this->assertTrue($onCutoff->fresh()->isJustApproved());

        $stale = $this->createApprovedSubmission($advertiser);
        $stale->update([
            'title' => 'Stale Approved Piece',
            'evaluated_at' => now()->subDays(ContentSubmission::JUST_APPROVED_DAYS + 1)->startOfDay(),
        ]);
        $this->assertFalse($stale->fresh()->isJustApproved());
        $this->assertNull($stale->fresh()->justApprovedLabel());

        $yesterday = $this->createApprovedSubmission($advertiser);
        $yesterday->update([
            'title' => 'Yesterday Approved Piece',
            'evaluated_at' => now()->subDay(),
        ]);
        $this->assertTrue($yesterday->fresh()->isJustApproved());
        $this->assertFalse($yesterday->fresh()->showJustApprovedBadge());
        $this->assertSame('Approved yesterday', $yesterday->fresh()->justApprovedLabel());

        $threeDays = $this->createApprovedSubmission($advertiser);
        $threeDays->update([
            'title' => 'Three Day Approved Piece',
            'evaluated_at' => now()->subDays(3),
        ]);
        $this->assertFalse($threeDays->fresh()->showJustApprovedBadge());
        $this->assertSame('Approved 3 days ago', $threeDays->fresh()->justApprovedLabel());

        $needsFix = $this->createApprovedSubmission($advertiser);
        $needsFix->update([
            'title' => 'Needs Fix Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluated_at' => now(),
        ]);
        $this->assertFalse($needsFix->fresh()->isJustApproved());

        $expired = $this->createApprovedSubmission($advertiser);
        $expired->update([
            'title' => 'Expired Approved Piece',
            'expires_at' => now()->subDay(),
            'evaluated_at' => now(),
        ]);
        $this->assertFalse($expired->fresh()->isJustApproved());

        $ordered = $this->createApprovedSubmission($advertiser);
        $order = $this->makeOrder($advertiser);
        $ordered->update([
            'title' => 'Already Ordered Piece',
            'order_id' => $order->id,
            'evaluated_at' => now(),
        ]);
        $this->assertFalse($ordered->fresh()->isJustApproved());

        $archived = $this->createApprovedSubmission($advertiser);
        $archived->update(['title' => 'Archived Just Approved Piece', 'evaluated_at' => now()]);
        $archived->archive();
        $this->assertFalse($archived->fresh()->isJustApproved());

        $evaluating = $this->createApprovedSubmission($advertiser);
        $evaluating->update([
            'title' => 'Still Evaluating Piece',
            'moderation_status' => ContentSubmission::STATUS_PROCESSING,
            'evaluated_at' => now(),
        ]);
        $this->assertFalse($evaluating->fresh()->isJustApproved());

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'approved', 'availability' => 'available']))
            ->assertOk()
            ->assertSee('Fresh Approved Piece')
            ->assertSee('Just approved')
            ->assertSee('Approved today')
            ->assertSee('Yesterday Approved Piece')
            ->assertSee('Approved yesterday')
            ->assertSee('Cutoff Approved Piece')
            ->assertSee('Stale Approved Piece')
            ->assertDontSee('Needs Fix Piece')
            ->assertDontSee('Expired Approved Piece')
            ->getContent();

        $this->assertStringContainsString('class="library-just-approved"', $html);
        $this->assertStringContainsString('class="library-just-approved-hint"', $html);
        $this->assertStringNotContainsString('site-badge-new', $html);

        $staleStart = strpos($html, 'Stale Approved Piece');
        $this->assertNotFalse($staleStart);
        $staleEnd = strpos($html, '</tr>', $staleStart);
        $this->assertNotFalse($staleEnd);
        $staleRow = substr($html, $staleStart, $staleEnd - $staleStart);
        $this->assertStringNotContainsString('Just approved', $staleRow);
        $this->assertStringNotContainsString('library-just-approved', $staleRow);

        $freshStart = strpos($html, 'Fresh Approved Piece');
        $this->assertNotFalse($freshStart);
        $freshEnd = strpos($html, '</tr>', $freshStart);
        $this->assertNotFalse($freshEnd);
        $freshRow = substr($html, $freshStart, $freshEnd - $freshStart);
        $this->assertStringContainsString('Just approved', $freshRow);
        $this->assertStringContainsString('Approved today', $freshRow);

        $yesterdayStart = strpos($html, 'Yesterday Approved Piece');
        $this->assertNotFalse($yesterdayStart);
        $yesterdayEnd = strpos($html, '</tr>', $yesterdayStart);
        $this->assertNotFalse($yesterdayEnd);
        $yesterdayRow = substr($html, $yesterdayStart, $yesterdayEnd - $yesterdayStart);
        $this->assertStringContainsString('Approved yesterday', $yesterdayRow);
        $this->assertStringNotContainsString('Just approved', $yesterdayRow);

        $css = (string) file_get_contents(public_path('assets/css/content-library.css'));
        $this->assertMatchesRegularExpression(
            '/\.library-just-approved \{[^}]*background:/s',
            $css
        );
        $this->assertStringContainsString('.library-just-approved-hint {', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.library-just-approved \{[^}]*animation:/s',
            $css
        );
    }

    public function test_upload_button_sits_under_content_library_heading(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $headingPos = strpos($html, 'Content Library');
        $uploadPos = strpos($html, 'id="openUploadModalBtn"');
        $this->assertNotFalse($headingPos);
        $this->assertNotFalse($uploadPos);
        $this->assertGreaterThan($headingPos, $uploadPos);
        $this->assertMatchesRegularExpression(
            '/<button type="button"\s+class="btn btn-upload"[\s\S]*?id="openUploadModalBtn"[\s\S]*?btn-upload__label">Upload article<\/span>/',
            $html
        );
        $this->assertStringContainsString('articleQuillEditor', $html);
        $this->assertStringContainsString('Edit article', $html);
        $this->assertStringContainsString('article-preview-tools.js', $html);
        $this->assertStringContainsString('articleCopyHeadingBtn', $html);
        $this->assertStringContainsString('articleCopyContentBtn', $html);
        $this->assertStringContainsString('articlePreviewLinksList', $html);
        $this->assertStringContainsString('id="libraryBrowsePublishersBtn"', $html);
        $this->assertStringContainsString('Browse publishers', $html);
        $this->assertStringContainsString('btn-upload__hint', $html);
        $this->assertSame(1, substr_count($html, 'id="openUploadModalBtn"'));
        $this->assertStringContainsString('class="library-page-actions"', $html);
        $this->assertStringNotContainsString('library-page-actions upload-zone', $html);
        $this->assertStringNotContainsString('btn-outline-primary btn-sm" id="libraryBrowsePublishersBtn"', $html);
        $this->assertStringNotContainsString('btn-sm btn-outline-secondary">Browse publishers', $html);
        $this->assertStringNotContainsString('One job here: upload and approve articles', $html);
        $this->assertStringNotContainsString('use Order on a row to place an approved article', $html);
        $this->assertStringContainsString('browse publishers first and upload when you pick a site', $html);
        $this->assertStringNotContainsString('library-order-soon', $html);
        $this->assertStringNotContainsString('Order your article', $html);
        $this->assertStringNotContainsString('Coming soon', $html);
    }

    public function test_library_browse_publishers_is_secondary_not_in_stepper(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="libraryBrowsePublishersBtn"', $html);
        $this->assertStringContainsString('href="'.route('advertiser.catalog').'"', $html);

        $chrome = $this->extractHtmlBetween($html, 'class="wizard-chrome"', 'class="library-page-actions"');
        $this->assertNotSame('', $chrome);
        $this->assertStringNotContainsString('id="libraryBrowsePublishersBtn"', $chrome);
        $this->assertStringNotContainsString('id="openUploadModalBtn"', $chrome);
        $this->assertDoesNotMatchRegularExpression('/>\s*Browse publishers\s*</', $chrome);

        $actions = $this->extractHtmlBetween($html, 'class="library-page-actions"', 'id="libraryFlash"');
        $this->assertNotSame('', $actions);
        $this->assertStringContainsString('id="openUploadModalBtn"', $actions);
        $this->assertStringContainsString('class="btn btn-upload"', $actions);
        $this->assertStringContainsString('btn-upload__label">Upload article</span>', $actions);
        $this->assertStringContainsString('id="libraryBrowsePublishersBtn"', $actions);
        $this->assertStringContainsString('library-browse-link', $actions);
        $this->assertStringContainsString('btn btn-link', $actions);
    }

    public function test_empty_library_offers_upload_and_catalog_path(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('No articles yet', false)
            ->assertSee('Upload a .docx to get your first approved article', false)
            ->assertSee('browse publishers now and upload when you pick a site', false)
            ->assertSee('Upload article', false)
            ->assertSee('Browse publishers', false)
            ->assertSee(route('advertiser.catalog'), false)
            ->assertDontSee('Upload a .docx here. After approval, assign it in your cart and checkout.', false)
            ->getContent();

        $this->assertStringContainsString('id="libraryBrowsePublishersBtn"', $html);
        $this->assertStringContainsString('library-browse-link', $html);
        $this->assertSame(1, substr_count($html, 'id="openUploadModalBtn"'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Browse publishers'));
        $this->assertStringContainsString('Guided placement', $html);
        $this->assertStringNotContainsString('btn btn-outline-secondary">Guided placement', $html);
    }

    public function test_advertiser_can_save_multiple_detected_links_from_preview(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $html = '<p>Teams use <a href="https://example.com/a">tool A</a> and '
            .'<a href="https://example.com/b">tool B</a> for productivity across digital projects worldwide.</p>';

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'links' => [
                    ['anchor' => 'tool A edited', 'url' => 'https://example.com/a-edited'],
                    ['anchor' => 'tool B edited', 'url' => 'https://example.com/b-edited'],
                ],
                'preview_html' => $html,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.detected_links.0.anchor', 'tool A edited')
            ->assertJsonPath('submission.detected_links.0.url', 'https://example.com/a-edited')
            ->assertJsonPath('submission.detected_links.1.anchor', 'tool B edited')
            ->assertJsonPath('submission.detected_links.1.url', 'https://example.com/b-edited');

        $fresh = $submission->fresh();
        $this->assertSame('tool A edited', $fresh->anchor_text);
        $this->assertSame('https://example.com/a-edited', $fresh->target_url);
        $this->assertCount(2, $fresh->detectedLinks());
        $this->assertStringContainsString('tool A edited', (string) $fresh->preview_html);
        $this->assertStringContainsString('https://example.com/a-edited', (string) $fresh->preview_html);
        $this->assertStringContainsString('tool B edited', (string) $fresh->preview_html);
    }

    public function test_preview_link_save_clears_anchors_from_html_when_all_links_are_removed(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $html = '<p>Teams use <a href="https://example.com/a">tool A</a> for productivity across digital projects worldwide.</p>';
        $submission->update(['preview_html' => $html]);

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'links' => [],
                'preview_html' => $html,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.detected_links', []);

        $fresh = $submission->fresh();
        $this->assertNull($fresh->anchor_text);
        $this->assertNull($fresh->target_url);
        $this->assertSame([], $fresh->detectedLinks());
        $this->assertStringNotContainsString('<a ', (string) $fresh->preview_html);
        $this->assertStringContainsString('tool A', (string) $fresh->preview_html);
    }

    public function test_preview_html_patch_without_links_resyncs_checkout_fields(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'preview_html' => '<p>Updated article body with no outbound links for digital teams worldwide.</p>',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.detected_links', []);

        $fresh = $submission->fresh();
        $this->assertNull($fresh->anchor_text);
        $this->assertNull($fresh->target_url);
        $this->assertSame([], $fresh->detectedLinks());
    }

    public function test_preview_html_patch_rejects_an_empty_body(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $original = (string) $submission->preview_html;

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'preview_html' => '<p></p>',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $fresh = $submission->fresh();
        $this->assertSame($original, (string) $fresh->preview_html);
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $fresh->moderation_status);
        $this->assertTrue($fresh->canBeOrdered());
    }

    public function test_advertiser_can_edit_article_html_with_links_and_images(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $html = '<p>Updated article body with a <a href="https://example.com/new-guide">helpful guide</a> for marketers.</p>'
            .'<p><img src="/storage/content-articles/demo.png" alt="Chart"></p>'
            .'<p>More compliant content about software tools and productivity for digital teams worldwide.</p>';

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'title' => 'Edited Doc Title',
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.title', 'Edited Doc Title');

        $fresh = $submission->fresh();
        $this->assertSame('Edited Doc Title', $fresh->title);
        $this->assertStringContainsString('helpful guide', (string) $fresh->preview_html);
        $this->assertStringContainsString('<img', (string) $fresh->preview_html);
        $this->assertStringContainsString('src="/storage/content-articles/demo.png"', (string) $fresh->preview_html);
        $this->assertStringContainsString('https://example.com/new-guide', (string) $fresh->target_url);
        $this->assertNotEmpty($fresh->draft_payload['content_history'] ?? null);
        $this->assertNotEmpty($fresh->articleHistory());
    }

    public function test_editor_rejects_embedded_data_images_instead_of_saving_them(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $original = (string) $submission->preview_html;

        $response = $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Updated body</p><p><img src="data:image/png;base64,iVBORw0KGgo=" alt=""></p>',
                'title' => $submission->title,
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('image button', (string) $response->json('message'));
        $this->assertSame($original, (string) $submission->fresh()->preview_html);
    }

    public function test_editor_media_image_src_is_persisted_as_storage_path(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $html = '<p>Updated article body with a <a href="https://example.com/new-guide">helpful guide</a> for marketers.</p>'
            .'<p><img src="/media/content-articles/demo.png" alt="Chart"></p>'
            .'<p>More compliant content about software tools and productivity for digital teams worldwide.</p>';

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'title' => 'Media Path Title',
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $submission->fresh();
        $this->assertStringContainsString('src="/storage/content-articles/demo.png"', (string) $fresh->preview_html);
        $this->assertStringNotContainsString('src="/media/content-articles/demo.png"', (string) $fresh->preview_html);
    }

    public function test_preview_rewrites_absolute_storage_urls_to_relative(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $html = '<p>Guide with an embedded figure for marketers.</p>'
            .'<p><img src="http://127.0.0.1:8000/storage/content-articles/'.$advertiser->id.'/fig.png" alt="Fig"></p>'
            .'<p>More compliant content about software tools and productivity for digital teams worldwide.</p>';

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $submission->fresh();
        $this->assertStringContainsString(
            'src="/storage/content-articles/'.$advertiser->id.'/fig.png"',
            (string) $fresh->preview_html
        );
        $this->assertStringNotContainsString('127.0.0.1', (string) $fresh->preview_html);
    }

    public function test_advertiser_can_upload_editor_image(): void
    {
        Storage::fake('public');
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $file = UploadedFile::fake()->image('figure.png', 40, 40);

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image'), [
                'image' => $file,
                'content_submission_id' => $submission->id,
                'current_image_count' => 9,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['url']);

        $url = (string) $response->json('url');
        $this->assertStringStartsWith('/storage/content-articles/', $url);
    }

    public function test_editor_image_upload_is_rejected_at_the_ten_image_cap(): void
    {
        Storage::fake('public');
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $file = UploadedFile::fake()->image('figure.png', 40, 40);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image'), [
                'image' => $file,
                'content_submission_id' => $submission->id,
                'current_image_count' => ContentUploadService::IMAGE_MAX_PER_ARTICLE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', ContentUploadService::tooManyImagesMessage());

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_editor_save_accepts_ten_images_and_rejects_eleven(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $original = (string) $submission->preview_html;

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $this->articleHtmlWithImages(ContentUploadService::IMAGE_MAX_PER_ARTICLE),
                'title' => $submission->title,
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            ContentUploadService::IMAGE_MAX_PER_ARTICLE,
            (new ArticleHtmlSanitizer)->countImages((string) $submission->fresh()->preview_html)
        );

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $this->articleHtmlWithImages(ContentUploadService::IMAGE_MAX_PER_ARTICLE + 1),
                'title' => $submission->title,
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', ContentUploadService::tooManyImagesMessage());

        $this->assertSame(
            ContentUploadService::IMAGE_MAX_PER_ARTICLE,
            (new ArticleHtmlSanitizer)->countImages((string) $submission->fresh()->preview_html)
        );
        $this->assertNotSame($original, (string) $submission->fresh()->preview_html);
    }

    public function test_evaluation_rejects_articles_that_already_have_more_than_ten_images(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $html = $this->articleHtmlWithImages(11);
        $submission->update([
            'preview_html' => $html,
            'extracted_text' => (new ArticleHtmlSanitizer)->htmlToPlainText($html),
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            'image_rights_declared_at' => now(),
        ]);

        $result = app(ContentUploadService::class)->reEvaluateSubmission($submission->fresh(), false);

        $this->assertFalse($result['approved']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $result['submission']->moderation_status);
        $this->assertSame(ContentUploadService::tooManyImagesMessage(), $result['message']);
        $this->assertSame(
            11,
            (new ArticleHtmlSanitizer)->countImages((string) $result['submission']->preview_html)
        );
        $this->assertSame(ContentUploadService::tooManyImagesMessage(), $result['submission']->editorNotice());
    }

    public function test_preview_link_save_does_not_persist_download_chrome(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'preview_html' => '<p>Body with a figure.</p><p><img src="/storage/content-articles/demo.png" alt="Chart"></p>',
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            'image_rights_declared_at' => now(),
        ]);

        $polluted = '<p>Body with a figure.</p><div class="article-img-wrap">'
            .'<img src="/storage/content-articles/demo.png" alt="Chart">'
            .'<button type="button" class="article-img-download btn btn-sm btn-dark">'
            .'<i class="fa fa-download me-1"></i>Download</button></div>';

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'links' => [
                    ['anchor' => 'tool A', 'url' => 'https://example.com/a'],
                ],
                'preview_html' => $polluted,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $html = (string) $submission->fresh()->preview_html;
        $this->assertStringContainsString('src="/storage/content-articles/demo.png"', $html);
        $this->assertStringNotContainsString('article-img-wrap', $html);
        $this->assertStringNotContainsString('Download', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function test_available_filter_excludes_approved_articles_that_still_need_image_rights(): void
    {
        $advertiser = $this->advertiser();
        $ready = $this->createApprovedSubmission($advertiser);
        $ready->update(['title' => 'Ready To Order']);

        $needsRights = $this->createApprovedSubmission($advertiser);
        $needsRights->update([
            'title' => 'Needs Image Rights',
            'preview_html' => '<p>Body</p><p><img src="/storage/content-articles/1/x.png" alt=""></p>',
            'image_rights' => null,
            'image_rights_declared_at' => null,
        ]);

        $this->assertSame('needs_fix', $needsRights->fresh()->libraryAvailability());
        $this->assertSame('available', $ready->fresh()->libraryAvailability());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'available']))
            ->assertOk()
            ->assertSee('Ready To Order')
            ->assertDontSee('Needs Image Rights');

        $needsFix = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'all', 'availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Needs Image Rights')
            ->assertDontSee('Ready To Order')
            ->assertSee('This article contains images. Confirm you own them')
            ->assertDontSee('You can now select websites and place an order')
            ->assertSee('Edit article')
            ->assertDontSee('>Resubmit<')
            ->getContent();

        $this->assertStringContainsString('js-open-editor', $needsFix);
        $this->assertStringContainsString('data-submission-id="'.$needsRights->id.'"', $needsFix);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'edit' => $needsRights->id,
                'upload' => 1,
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertSee('name="replace_id"', false)
            ->assertSee('value="'.$needsRights->id.'"', false);
    }

    public function test_preview_link_save_rejects_eleven_images_and_keeps_approval(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $original = (string) $submission->preview_html;

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'links' => [
                    ['anchor' => 'tool A', 'url' => 'https://example.com/a'],
                ],
                'preview_html' => $this->articleHtmlWithImages(11),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', ContentUploadService::tooManyImagesMessage());

        $fresh = $submission->fresh();
        $this->assertSame($original, (string) $fresh->preview_html);
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $fresh->moderation_status);
        $this->assertSame('https://example.com/tools', $fresh->target_url);
    }

    public function test_preview_html_patch_does_not_leave_approved_article_processing(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'preview_html' => $this->articleHtmlWithImages(11),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', ContentUploadService::tooManyImagesMessage());

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
    }

    public function test_preview_link_save_requires_image_rights_when_adding_pictures(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $original = (string) $submission->preview_html;

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'links' => [
                    ['anchor' => 'helpful guide', 'url' => 'https://example.com/new-guide'],
                ],
                'preview_html' => $this->articleHtmlWithImages(1),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('needs_image_rights', true);

        $fresh = $submission->fresh();
        $this->assertSame($original, (string) $fresh->preview_html);
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $fresh->moderation_status);
    }

    public function test_editor_save_does_not_keep_a_rights_declaration_when_eleven_images_are_rejected(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $this->assertNull($submission->image_rights);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $this->articleHtmlWithImages(11),
                'title' => $submission->title,
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', ContentUploadService::tooManyImagesMessage());

        $fresh = $submission->fresh();
        $this->assertNull($fresh->image_rights);
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $fresh->moderation_status);
    }

    public function test_editor_image_php_reject_does_not_blame_article_docx_cap(): void
    {
        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/editor-img-'.uniqid('', true).'.png';
        file_put_contents($path, 'fake-png');

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-submissions.editor-image'), [
            'image' => new UploadedFile($path, 'figure.png', 'image/png', UPLOAD_ERR_INI_SIZE, true),
        ]);

        @unlink($path);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('image could not be uploaded', $message);
        $this->assertStringNotContainsString('.docx', $message);
        $this->assertStringNotContainsString('over the 10 MB limit', $message);
        $this->assertStringNotContainsString('under 5 MB', $message);
        $this->assertStringNotContainsString('upload_max_filesize', $message);
    }

    public function test_editor_image_over_five_mb_is_blamed_on_the_image_cap(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image').'?client_bytes='.(6 * 1024 * 1024), [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'The image could not be uploaded. Use a JPG, PNG, GIF, or WebP under 5 MB and try again.'
            );
    }

    public function test_content_library_preview_modal_exposes_external_link_rows(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="articlePreviewLinkMeta"', $html);
        $this->assertStringContainsString('id="articlePreviewLinksList"', $html);
        $this->assertStringContainsString('id="articleLinksSaveBtn"', $html);
        $this->assertStringContainsString('assets/js/content-library.js', $html);
        $js = (string) file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString(
            'function openPreviewModal(title, html, links, submissionId, editable)',
            $js
        );
        $this->assertStringContainsString('preview_html: previewModalState.html || \'\'', $js);
        $this->assertStringContainsString('const html = previewModalState.html || \'\'', $js);
        $this->assertStringContainsString('let previewOpenedFromEditor = false', $js);
        $this->assertStringContainsString('data.approved === true && !sub.needs_image_rights', $js);
        $this->assertStringNotContainsString('data.approved !== false', $js);
        $this->assertStringNotContainsString(
            "preview_html: document.getElementById('articlePreviewBody').innerHTML",
            $js
        );
        $this->assertStringNotContainsString(
            'await tools.copyHtml(body.innerHTML, body.innerText)',
            $js
        );
    }

    public function test_article_editor_loads_html_as_quill_blots_with_undo_and_preview_edit(): void
    {
        $advertiser = $this->advertiser();

        $library = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="articlePreviewEditBtn"', $library);
        $this->assertStringContainsString('id="articleEditorImageCount"', $library);
        $this->assertStringContainsString('id="articleImageRemoveBtn"', $library);
        $this->assertStringContainsString('article-img-remove', $library);
        $this->assertStringContainsString('aria-label="Remove image"', $library);
        $this->assertStringNotContainsString('id="articlePreviewBody" contenteditable', $library);
        $this->assertDoesNotMatchRegularExpression('/id="articlePreviewBody"[^>]*contenteditable/', $library);

        $previewHeader = $this->extractHtmlBetween(
            $library,
            'id="articlePreviewModal"',
            'id="articlePreviewBody"'
        );
        $this->assertNotSame('', $previewHeader);
        $this->assertStringContainsString('id="articlePreviewEditBtn"', $previewHeader);
        $this->assertMatchesRegularExpression('/>\s*Edit article\s*<\/button>/', $previewHeader);

        $js = file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('dangerouslyPasteHTML', $js);
        $this->assertStringContainsString('history.clear', $js);
        $this->assertStringContainsString('deleteText(', $js);
        $this->assertStringContainsString("['undo', 'redo']", $js);
        $this->assertStringContainsString('function returnToEditorFromPreview', $js);
        $this->assertStringContainsString('function loadArticleHtml', $js);
        $this->assertStringNotContainsString(
            'articleQuill.root.innerHTML = submission.preview_html',
            $js
        );

        $css = file_get_contents(public_path('assets/css/content-library.css'));
        $this->assertStringContainsString('.article-img-remove', $css);
        $this->assertStringContainsString('width: 1.85rem', $css);
        $this->assertStringContainsString('img.is-selected', $css);
        $this->assertStringContainsString('img.is-broken', $css);
        $this->assertStringContainsString('function patchQuillImageSanitize', $js);
        $this->assertStringContainsString("value.startsWith('/storage/')", $js);
        $this->assertStringContainsString("value.startsWith('/media/')", $js);
        $this->assertStringContainsString('function publicDiskTwinSrc', $js);
        $this->assertStringContainsString('function recoverPublicDiskImage', $js);
        $this->assertStringContainsString('$1/media/', $js);
        $this->assertStringContainsString('function parseLibraryJson', $js);
        $this->assertStringContainsString('function hideBootstrapModal', $js);
        $this->assertStringContainsString('function bindLibraryModalA11y', $js);
        $this->assertStringContainsString('data-no-tip', $js);
        $this->assertStringContainsString('imgRect.top - shellRect.top + 8', $js);
        $this->assertStringContainsString('offsetWidth || 30', $js);
        $this->assertStringNotContainsString('imgRect.bottom - shellRect.top - 36', $js);
        $this->assertStringContainsString('handlers: {', $js);
        $this->assertStringContainsString('undo: function () {', $js);
        $this->assertStringContainsString('redo: function () {', $js);
        $this->assertStringNotContainsString("toolbar.addHandler('undo'", $js);
        $this->assertStringNotContainsString("toolbar.addHandler('redo'", $js);
        $this->assertDoesNotMatchRegularExpression(
            '/\.library-expiry-hint \{\s*font-size:[^}]*color: #b45309/',
            $css
        );
        $this->assertStringContainsString('.library-expiry-hint--urgent', $css);
        $this->assertStringContainsString('#articleEditorModal .modal-dialog', $css);
        $this->assertStringContainsString('#articleEditorModal .article-docs-shell #articleQuillEditor.ql-container', $css);
        $this->assertStringContainsString('#articleEditorModal .article-docs-shell .article-editor-scroll .ql-editor', $css);
        $this->assertStringContainsString('flex: 1 1 0%', $css);
        $this->assertStringContainsString('min-height: min(52vh, 28rem)', $css);
        $this->assertStringNotContainsString('min-height: 12rem', $css);
        $this->assertStringNotContainsString('max-height: 28vh', $css);
        $this->assertStringContainsString('#articleEditorImageRights.article-editor-rights', $css);
        $this->assertStringContainsString('max-height: 9rem', $css);
        $this->assertStringContainsString('#articleEditorModal .modal-body > #articleEditorFeedback', $css);
        $this->assertStringContainsString('#articleEditorFeedback:empty', $css);
        $this->assertStringContainsString('#articleEditorFeedback:not(:empty):has(.text-danger)', $css);
        $this->assertStringContainsString('#articleEditorModal .modal-body > #articleEditorImageRights.article-editor-rights', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/id="articleEditorImageRights"[\s\S]*id="articleEditorFeedback"/',
            $library
        );
        $this->assertMatchesRegularExpression(
            '/id="articleEditorFeedback"[\s\S]*id="articleEditorImageRights"/',
            $library
        );
        $this->assertStringContainsString('max-width: min(100%, 720px)', $css);
        $this->assertStringContainsString('function applyArticleEditorScrollport', $js);
        $this->assertStringContainsString('function bindArticleEditorScrollport', $js);
        $this->assertStringContainsString('function bindArticleEditorWheel', $js);
        $this->assertStringContainsString('function articleEditorScrollport', $js);
        $this->assertStringContainsString('function ensureArticleEditorScrollWrap', $js);
        $this->assertStringContainsString('function silenceArticleQuillSelectionScroll', $js);
        $this->assertStringContainsString('scrollSelectionIntoView', $js);
        $this->assertStringContainsString('scrollRectIntoView', $js);
        $this->assertStringContainsString('article-editor-scroll', $css);
        $this->assertStringContainsString('articleQuill.scrollingContainer', $js);
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.article-docs-shell #articleQuillEditor\.ql-container \{[^}]*overflow:\s*hidden/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/#articleEditorModal \.article-docs-shell #articleQuillEditor\.ql-container \{[^}]*height:\s*auto/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.article-docs-shell \{[^}]*flex:\s*1 1 0%/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/#articleEditorModal \.article-docs-shell \{[^}]*height:\s*auto/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.article-docs-shell \.article-editor-scroll \{[^}]*overflow-y:\s*scroll/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.article-docs-shell \.article-editor-scroll \.ql-editor \{[^}]*height:\s*auto/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.article-docs-shell \.article-editor-scroll \.ql-editor \{[^}]*overflow:\s*visible/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.article-docs-shell \.article-editor-scroll \.ql-editor \{[^}]*padding-bottom:\s*2\.75rem/s',
            $css
        );
        $this->assertStringContainsString('scrollbar-gutter: stable', $css);
        $this->assertStringContainsString('scrollbar-width: thin', $css);
        $this->assertStringContainsString('.article-editor-scroll::-webkit-scrollbar', $css);
        $this->assertStringContainsString('overflow-anchor: none', $css);
        $this->assertStringContainsString('overscroll-behavior: contain', $css);
        $this->assertStringContainsString('overscroll-behavior: none', $css);
        $this->assertStringContainsString('#articleEditorModal .modal-body', $css);
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.modal-body \{[^}]*overflow:\s*hidden/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="articleEditorModal"[\s\S]*?modal-dialog-scrollable[\s\S]*?id="articleQuillEditor"/',
            $library
        );
        $this->assertStringNotContainsString('function syncEditorActions', $js);
        $this->assertStringNotContainsString('id="articleEditorOrderBtn"', $library);
        $this->assertStringNotContainsString('id="articleEditorOrderBtn"', $js);
        $this->assertStringNotContainsString('libraryOrderUrlBase', $js);
        $this->assertStringNotContainsString("saveBtn.classList.toggle('btn-outline-primary', canOrder)", $js);
        $this->assertMatchesRegularExpression(
            '/id="articleEditorModal"[\s\S]*?modal-dialog modal-fullscreen[\s\S]*?id="articleQuillEditor"/',
            $library
        );
        $editorModal = $this->extractHtmlBetween(
            $library,
            'id="articleEditorModal"',
            'id="articlePreviewModal"'
        );
        $this->assertStringNotContainsString('id="articleEditorOrderBtn"', $editorModal);
        $this->assertStringNotContainsString('>Order</a>', $editorModal);
        $this->assertStringContainsString('id="articleEditorSaveBtn"', $editorModal);
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.modal-dialog \{[^}]*height:\s*100%/s',
            $css
        );
        $this->assertStringContainsString('overscroll-behavior: none', $css);
        $this->assertStringContainsString('#articleEditorModal .modal-body', $css);
        $this->assertMatchesRegularExpression(
            '/#articleEditorModal \.modal-body \{[^}]*overflow:\s*hidden/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="articleEditorModal"[\s\S]*?modal-dialog-scrollable[\s\S]*?id="articleQuillEditor"/',
            $library
        );
        $this->assertStringContainsString('.library-row--focus', $css);
        $this->assertStringContainsString('.library-row--focus > td', $css);
        $this->assertStringContainsString('function goToLibraryResult', $js);
        $this->assertStringContainsString('function libraryDestinationUrl', $js);
        $this->assertStringContainsString("availability: 'needs_fix'", $js);
        $this->assertStringContainsString('submission.needs_image_rights', $js);
        $this->assertStringContainsString('function dismissLibraryUploadByUser', $js);
        $this->assertStringContainsString('goToLibraryResult(saved, \'\', !!saved.can_order)', $js);
        $this->assertStringContainsString('libraryResultFlash', $js);
        $this->assertStringContainsString('function applyLibraryResultFocus', $js);
        $this->assertStringNotContainsString('window.location.reload()', $js);
    }

    public function test_replace_upload_clears_stale_feature_image_and_schedule(): void
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'feature_image_url' => 'https://cdn.example.test/old-hero.jpg',
            'publication_mode' => ContentSubmission::MODE_SCHEDULED,
            'scheduled_publish_at' => now()->addWeek(),
            'timezone' => 'America/New_York',
        ]);

        $path = sys_get_temp_dir().'/replace-stale-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 60));

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.upload'), [
                'file' => new UploadedFile(
                    $path,
                    'revised.docx',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    null,
                    true
                ),
                'title' => 'Revised article',
                'country' => 'us',
                'language' => 'en',
                'replace_id' => $submission->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        @unlink($path);

        $fresh = $submission->fresh();
        $this->assertNull($fresh->feature_image_url);
        $this->assertSame(ContentSubmission::MODE_IMMEDIATE, $fresh->publication_mode);
        $this->assertNull($fresh->scheduled_publish_at);
        $this->assertSame(1, ContentSubmission::query()->where('user_id', $advertiser->id)->count());
    }

    public function test_php_upload_error_explains_the_docx_limit_instead_of_failed_to_upload(): void
    {
        Storage::fake('local');
        Mail::fake();
        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/oversized-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path);

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-library.upload'), [
            'file' => new UploadedFile(
                $path,
                'article.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                UPLOAD_ERR_INI_SIZE,
                true
            ),
            'country' => 'us',
            'language' => 'en',
        ]);

        @unlink($path);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('The file failed to upload', $message);
        $this->assertStringNotContainsString('upload_max_filesize', $message);
        $this->assertStringNotContainsString('hosting PHP settings', $message);
        $this->assertStringContainsString('The article could not be uploaded', $message);
        $this->assertStringContainsString('Please try again', $message);
        $this->assertStringNotContainsString('under 10 MB', $message);
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
    }

    public function test_library_upload_accepts_docx_sniffed_as_zip(): void
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/zip-sniff-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 60));

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-library.upload'), [
            'file' => new UploadedFile($path, 'article.docx', 'application/zip', null, true),
            'title' => 'Zip sniffed article',
            'country' => 'us',
            'language' => 'en',
        ]);

        @unlink($path);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringNotContainsString('The file failed to upload', (string) $response->json('message'));
        $this->assertStringNotContainsString('must be a file of type: docx', (string) $response->json('message'));
    }

    public function test_upload_form_posts_the_chosen_docx_as_multipart(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="libraryUploadForm"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);

        $js = (string) file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('function postLibraryUpload', $js);
        $this->assertStringContainsString('function librarySafeDocxFilename', $js);
        $this->assertStringContainsString('LIBRARY_UPLOAD_CHUNK_BYTES', $js);
        $this->assertStringContainsString('function libraryUploadPartData', $js);
        $this->assertStringContainsString("fd.set('file', filePart, LIBRARY_UPLOAD_PART_NAME)", $js);
        $this->assertStringContainsString('function libraryUploadFields', $js);
        $this->assertStringContainsString('function librarySizeAwareUploadMessage', $js);
        $this->assertStringContainsString('function firstErrorMessage', $js);
        $this->assertStringContainsString('function dismissLibraryUploadByUser', $js);
        $this->assertStringContainsString('function resetLibraryUploadUi', $js);
        $this->assertStringContainsString('function bindLibraryUploadCancel', $js);
        $this->assertStringContainsString('function abortLibraryUpload', $js);
        $this->assertStringContainsString('function cancelLibraryUploadHandoffState', $js);
        $this->assertStringContainsString('pendingLibraryLanding = null', $js);
        $this->assertStringContainsString('libraryUploadDismissGen', $js);
        $this->assertStringContainsString('AbortController', $js);
        $this->assertStringContainsString('isLibraryUploadAbortError', $js);
        $this->assertStringContainsString('if (!libraryUploadHandoff)', $js);
        $this->assertStringContainsString('libraryUploadSavedSubmission', $js);
        $this->assertStringContainsString('id="libraryUploadCancelBtn"', $html);
        $this->assertStringContainsString('Could not open the editor. Try again.', $js);
        $this->assertStringContainsString('Article uploaded. It is in your library.', $js);
    }

    public function test_library_upload_allows_ten_megabyte_docx(): void
    {
        $advertiser = $this->advertiser();

        ContentModerationSetting::setValue('upload_config', [
            'max_kilobytes' => 2048,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Max 10 MB', $html);
        $this->assertStringContainsString('-c512', $html);
        $this->assertSame(512, ContentUploadService::CHUNK_KILOBYTES);
        $this->assertSame(1536, ContentUploadService::MAX_RECEIVE_CHUNK_KILOBYTES);
        $this->assertSame(32, ContentUploadService::MAX_CHUNKS);
        $this->assertStringNotContainsString('Max 2 MB', $html);
        $this->assertStringNotContainsString('Max 5 MB', $html);
        $this->assertStringNotContainsString('server PHP still allows only', $html);
        $this->assertStringNotContainsString('libraryPhpUploadLimitWarn', $html);
        $this->assertStringNotContainsString('hosting PHP settings', $html);
        $this->assertStringNotContainsString('article cap is 10 MB', $html);
        $this->assertMatchesRegularExpression('/maxKilobytes:\s*10240/', $html);
        $this->assertMatchesRegularExpression('/phpMaxKilobytes:\s*\d+/', $html);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.config'))
            ->assertOk()
            ->assertJsonPath('config.max_kilobytes', 10240)
            ->assertJsonStructure(['config' => ['php_max_kilobytes']]);

        ContentModerationSetting::setValue('upload_config', [
            'max_kilobytes' => 5120,
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.config'))
            ->assertOk()
            ->assertJsonPath('config.max_kilobytes', 10240);

        $service = app(ContentUploadService::class);
        $this->assertSame(10240, $service->effectiveMaxKilobytes(['max_kilobytes' => 2048]));
        $this->assertSame(10240, $service->effectiveMaxKilobytes(['max_kilobytes' => 5120]));
        $this->assertSame(10240, $service->effectiveMaxKilobytes(['max_kilobytes' => 20480]));
        $this->assertSame(10240, $service->effectiveMaxKilobytes(['max_kilobytes' => 51200]));

        $htaccess = (string) file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('lsapi_module', $htaccess);
        $this->assertStringContainsString('php_value upload_max_filesize 64M', $htaccess);
        $this->assertStringContainsString('php_value max_input_time 120', $htaccess);
        $this->assertStringNotContainsString('memory_limit', $htaccess);
        $this->assertStringNotContainsString('max_execution_time', $htaccess);
        $this->assertStringContainsString('LimitRequestBody 67108864', $htaccess);
        $this->assertStringContainsString('content-library\\.js$', $htaccess);
        $this->assertStringContainsString('Cache-Control', $htaccess);
        $this->assertDoesNotMatchRegularExpression(
            '/^php_value /m',
            $htaccess,
            'Bare php_value outside IfModule 500s Hostinger when PHP is not an Apache module.'
        );
        $this->assertStringNotContainsString('<IfModule LiteSpeed>', $htaccess);
        $this->assertStringContainsString('RewriteCond %{REQUEST_METHOD} GET', $htaccess);
        $this->assertStringContainsString('RewriteRule ^ index.php [L,QSA]', $htaccess);
        $this->assertStringNotContainsString('Content-Security-Policy', $htaccess);

        $userIni = (string) file_get_contents(public_path('.user.ini'));
        $this->assertStringContainsString('upload_max_filesize = 64M', $userIni);
        $this->assertStringContainsString('post_max_size = 64M', $userIni);
        $this->assertStringContainsString('max_input_time = 120', $userIni);
        $this->assertStringContainsString('pcre.backtrack_limit = 10000000', $userIni);
        $this->assertStringNotContainsString('memory_limit =', $userIni);
        $this->assertStringNotContainsString('max_execution_time =', $userIni);
        $rootIni = (string) file_get_contents(base_path('.user.ini'));
        $this->assertStringContainsString('upload_max_filesize = 64M', $rootIni);
        $publicPhpIni = (string) file_get_contents(public_path('php.ini'));
        $this->assertStringContainsString('upload_max_filesize = 64M', $publicPhpIni);

        $js = (string) file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('function libraryFileTooLargeMessage', $js);
        $this->assertStringContainsString('function libraryUploadTransportMessage', $js);
        $this->assertStringContainsString('function postLibraryUpload', $js);
        $this->assertStringContainsString('function librarySafeDocxFilename', $js);
        $this->assertStringContainsString('LIBRARY_UPLOAD_CHUNK_BYTES', $js);
        $this->assertStringContainsString('512 * 1024', $js);
        $this->assertStringContainsString("LIBRARY_UPLOAD_PART_NAME = 'article.docx'", $js);
        $this->assertStringContainsString('function libraryUploadPartData', $js);
        $this->assertStringContainsString('function libraryUploadFields', $js);
        $this->assertStringContainsString('function librarySizeAwareUploadMessage', $js);
        $this->assertStringContainsString('librarySizeAwareUploadMessage(bytes, message)', $js);
        $this->assertStringContainsString('if (el.type === \'file\') return;', $js);
        $this->assertStringContainsString('.slice(0, 200)', $js);
        $this->assertStringContainsString('last.data.chunk_received || !last.data.submission', $js);
        $this->assertStringContainsString('function libraryUrlWithClientBytes', $js);
        $this->assertStringContainsString('X-Upload-Bytes', $js);
        $this->assertStringContainsString('X-Requested-With', $js);
        $this->assertStringContainsString('Your session expired', $js);
        $this->assertStringContainsString('Too many upload attempts', $js);
        $this->assertStringContainsString('The image could not be uploaded', $js);
        $this->assertStringContainsString('LIBRARY_IMAGE_MAX_BYTES = 5120 * 1024', $js);
        $this->assertStringContainsString('LIBRARY_IMAGE_MAX_PER_ARTICLE = 10', $js);
        $this->assertStringContainsString('function libraryTooManyImagesMessage', $js);
        $this->assertStringContainsString('function editorImageCount', $js);
        $this->assertStringContainsString('content_submission_id', $js);
        $this->assertStringContainsString('current_image_count', $js);
        $this->assertSame(10, ContentUploadService::IMAGE_MAX_PER_ARTICLE);
        $this->assertStringContainsString('function librarySizeAwareImageMessage', $js);
        $this->assertStringContainsString('function uploadEditorImageFile', $js);
        $this->assertStringContainsString('function bindEditorImagePasteAndDrop', $js);
        $this->assertStringNotContainsString("? 'The image could not be uploaded. Use a JPG, PNG, GIF, or WebP under 5 MB and try again.'", $js);
        $this->assertStringContainsString('The article editor failed to load', $js);
        $this->assertStringContainsString('editor_notice', $js);
        $this->assertStringContainsString('function submissionForEditor', $js);
        $this->assertStringContainsString('10240 * 1024', $js);
        $this->assertStringContainsString('status === 413 || status === 0', $js);
        $this->assertStringNotContainsString('status === 500', $js);
        $this->assertStringNotContainsString('file.size <= 10240 * 1024', $js);
        $this->assertSame(8000000, ContentUploadService::PREVIEW_HTML_MAX_CHARS);
        $this->assertStringNotContainsString('hosting PHP settings', $js);
        $this->assertStringNotContainsString('server PHP upload limit', $js);
        $this->assertStringNotContainsString('upload_max_filesize to 64M', $js);

        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('PostTooLargeException', $bootstrap);
        $this->assertStringContainsString('phpSizeRejectedMessage', $bootstrap);
    }

    public function test_missing_file_with_oversize_content_length_explains_php_limit(): void
    {
        $advertiser = $this->advertiser();

        $response = $this->actingAs($advertiser)->call(
            'POST',
            route('advertiser.content-library.upload'),
            ['country' => 'us', 'language' => 'en'],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'CONTENT_LENGTH' => (string) (6 * 1024 * 1024),
            ]
        );

        $response->assertStatus(422)->assertJsonPath('success', false);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('The article could not be uploaded', $message);
        $this->assertStringNotContainsString('upload_max_filesize', $message);
        $this->assertStringNotContainsString('hosting PHP settings', $message);
        $this->assertStringNotContainsString('Drop a .docx', $message);
        $this->assertStringNotContainsString('country', strtolower($message));
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
        $this->assertStringNotContainsString('under 10 MB', $message);
        $this->assertStringContainsString('Please try again', $message);
    }

    public function test_missing_file_with_client_bytes_and_no_content_length_is_not_a_missing_file(): void
    {
        $advertiser = $this->advertiser();

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.content-library.upload', ['client_bytes' => 5 * 1024 * 1024]),
            ['country' => 'us', 'language' => 'en'],
            ['X-Upload-Bytes' => (string) (5 * 1024 * 1024)]
        );

        $response->assertStatus(422)->assertJsonPath('success', false);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('The article could not be uploaded', $message);
        $this->assertStringNotContainsString('Drop a .docx', $message);
        $this->assertStringNotContainsString('country', strtolower($message));
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
    }

    public function test_missing_file_uses_query_bytes_when_upload_header_is_zero(): void
    {
        $advertiser = $this->advertiser();

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.content-library.upload', ['client_bytes' => 5 * 1024 * 1024]),
            ['country' => 'us', 'language' => 'en'],
            ['X-Upload-Bytes' => '0']
        );

        $response->assertStatus(422)->assertJsonPath('success', false);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('The article could not be uploaded', $message);
        $this->assertStringNotContainsString('Drop a .docx', $message);
        $this->assertStringNotContainsString('country', strtolower($message));
    }

    public function test_library_list_does_not_select_article_body_columns(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $row = ContentSubmission::query()
            ->forLibraryList()
            ->where('id', $submission->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('extracted_text', $row->getAttributes());
        $this->assertArrayNotHasKey('preview_html', $row->getAttributes());
        $this->assertTrue($row->hasPreviewHtml());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('js-open-preview', false);
    }

    public function test_library_upload_json_omits_preview_html(): void
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/omit-html-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 60));

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-library.upload'), [
            'file' => new UploadedFile(
                $path,
                'article.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title' => 'Omit html article',
            'country' => 'us',
            'language' => 'en',
        ]);

        @unlink($path);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertArrayNotHasKey('preview_html', $response->json('submission') ?? []);
        $this->assertNotEmpty($response->json('submission.id'));
    }

    public function test_article_picker_scope_omits_article_bodies(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $row = ContentSubmission::query()
            ->forArticlePicker()
            ->where('id', $submission->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('extracted_text', $row->getAttributes());
        $this->assertArrayNotHasKey('preview_html', $row->getAttributes());
        $this->assertTrue($row->canBeOrdered());

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->assertJsonPath('approved_articles.0.id', $submission->id)
            ->assertJsonPath('approved_articles.0.language', 'en');
    }

    public function test_uniqueness_corpus_selects_truncated_extracted_text(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(ArticleEvaluationService::class)->scoreUniqueness(
            str_repeat('Useful editorial content about productivity software for busy teams. ', 40)
        );

        $sql = strtolower(implode(' ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        $this->assertStringContainsString('substr(extracted_text', $sql);
    }

    public function test_checkout_page_does_not_embed_full_article_html(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'checkout-excerpt');
        $submission = $this->createApprovedSubmission($advertiser);
        $tail = 'CHECKOUT_BODY_TAIL_'.uniqid('', true);
        $submission->update([
            'title' => 'Checkout Excerpt Article',
            'preview_html' => '<p>Opening sentence about productivity software for teams.</p><p>'.str_repeat('More body copy. ', 80).$tail.'</p>',
        ]);

        $html = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                    'price' => 46,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('Checkout Excerpt Article', false)
            ->assertSee('Opening sentence about productivity software', false)
            ->getContent();

        $this->assertStringNotContainsString($tail, $html);
        $this->assertStringNotContainsString('<p>Opening sentence', $html);
    }

    public function test_library_edit_query_does_not_embed_article_body(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $marker = 'EDIT_BOOT_BODY_'.uniqid('', true);
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'preview_html' => '<p>'.$marker.'</p>',
            'extracted_text' => $marker,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['edit' => $submission->id]))
            ->assertOk()
            ->assertDontSee($marker, false);
    }

    public function test_library_edit_boot_reports_images_without_embedding_html(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $marker = 'EDIT_BOOT_IMG_'.uniqid('', true);
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'preview_html' => '<p>'.$marker.'</p><p><img src="/storage/content-articles/1/x.png" alt=""></p>',
            'extracted_text' => $marker,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['edit' => $submission->id]))
            ->assertOk()
            ->assertDontSee($marker, false)
            ->getContent();

        $this->assertMatchesRegularExpression('/has_images"\s*:\s*true/', $html);
        $this->assertMatchesRegularExpression('/needs_image_rights"\s*:\s*true/', $html);

        $submission->update(['image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN]);

        $covered = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['edit' => $submission->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/has_images"\s*:\s*true/', $covered);
        $this->assertMatchesRegularExpression('/needs_image_rights"\s*:\s*false/', $covered);
        $this->assertMatchesRegularExpression('/image_rights_covers"\s*:\s*true/', $covered);
    }

    public function test_legacy_drafts_json_omits_article_bodies(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.drafts'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('drafts.0.preview_html')
            ->assertJsonMissingPath('drafts.0.extracted_text');
    }

    public function test_library_list_scope_reports_images_without_loading_preview_html(): void
    {
        $advertiser = $this->advertiser();
        $withImage = $this->createApprovedSubmission($advertiser);
        $withImage->update([
            'title' => 'Has Picture',
            'preview_html' => '<p>Body</p><p><img src="/storage/content-articles/1/x.png" alt=""></p>',
        ]);
        $plain = $this->createApprovedSubmission($advertiser);
        $plain->update(['title' => 'No Picture']);

        $imaged = ContentSubmission::query()
            ->forLibraryList()
            ->where('id', $withImage->id)
            ->first();
        $textOnly = ContentSubmission::query()
            ->forLibraryList()
            ->where('id', $plain->id)
            ->first();

        $this->assertNotNull($imaged);
        $this->assertNotNull($textOnly);
        $this->assertArrayNotHasKey('preview_html', $imaged->getAttributes());
        $this->assertTrue($imaged->hasImages());
        $this->assertFalse($textOnly->hasImages());

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.drafts'))
            ->assertOk()
            ->assertJsonFragment(['id' => $withImage->id, 'has_images' => true])
            ->assertJsonFragment(['id' => $plain->id, 'has_images' => false]);
    }

    public function test_library_order_post_array_id_does_not_500(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->from(route('advertiser.content-library'))
            ->post(route('advertiser.content-library.order.post'), [
                'content_submission_id' => ['not-an-id'],
            ])
            ->assertNotFound();
    }

    public function test_drafts_array_cart_key_does_not_500(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.drafts', [
                'cart_key' => ['abc'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('Array to string conversion', false);
    }

    public function test_article_history_uses_site_name_not_generic_website(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'history-named');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $order = $this->makeOrder($advertiser);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ]);
        $submission->update(['order_id' => $order->id]);

        $details = collect($submission->fresh()->articleHistory())
            ->where('label', 'Ordered')
            ->pluck('detail')
            ->implode(' ');

        $this->assertStringContainsString($site->site_name, $details);
        $this->assertStringNotContainsString('Website ·', $details);
    }

    public function test_library_upload_accepts_chunked_docx(): void
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/chunk-src-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 80));
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        $this->assertGreaterThan(200, strlen($bytes));

        $mid = (int) intdiv(strlen($bytes), 2);
        $uploadId = '11111111-1111-4111-8111-111111111111';
        $partOne = sys_get_temp_dir().'/chunk-a-'.uniqid('', true).'.docx';
        $partTwo = sys_get_temp_dir().'/chunk-b-'.uniqid('', true).'.docx';
        file_put_contents($partOne, substr($bytes, 0, $mid));
        file_put_contents($partTwo, substr($bytes, $mid));

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.upload'), [
                'file' => new UploadedFile($partOne, 'article.docx', 'application/octet-stream', null, true),
                'country' => 'ch',
                'language' => 'de',
                'title' => 'AUTODOC delivery',
                'chunk_index' => 0,
                'chunk_total' => 2,
                'upload_id' => $uploadId,
                'original_filename' => "letemps.ch How AUTODOC is solving Europe's long-tail delivery.docx",
                'client_bytes' => (string) strlen($bytes),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('chunk_received', true)
            ->assertJsonPath('received', 1)
            ->assertJsonPath('total', 2);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.upload'), [
                'file' => new UploadedFile($partTwo, 'article.docx', 'application/octet-stream', null, true),
                'country' => 'ch',
                'language' => 'de',
                'title' => 'AUTODOC delivery',
                'chunk_index' => 1,
                'chunk_total' => 2,
                'upload_id' => $uploadId,
                'original_filename' => "letemps.ch How AUTODOC is solving Europe's long-tail delivery.docx",
                'client_bytes' => (string) strlen($bytes),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('chunk_received');

        $this->assertDatabaseHas('content_submissions', [
            'user_id' => $advertiser->id,
            'title' => 'AUTODOC delivery',
            'country' => 'ch',
            'language' => 'de',
        ]);

        $stored = ContentSubmission::query()
            ->where('user_id', $advertiser->id)
            ->where('title', 'AUTODOC delivery')
            ->first();
        $this->assertNotNull($stored);
        $this->assertSame('letemps.ch How AUTODOC is solving Europes long-tail delivery.docx', $stored->original_filename);

        @unlink($partOne);
        @unlink($partTwo);
    }

    public function test_library_upload_last_chunk_without_the_rest_fails(): void
    {
        Storage::fake('local');
        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/chunk-only-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        $mid = (int) intdiv(strlen($bytes), 2);
        $partTwo = sys_get_temp_dir().'/chunk-only-b-'.uniqid('', true).'.docx';
        file_put_contents($partTwo, substr($bytes, $mid));

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.upload'), [
                'file' => new UploadedFile($partTwo, 'article.docx', 'application/octet-stream', null, true),
                'country' => 'us',
                'language' => 'en',
                'chunk_index' => 1,
                'chunk_total' => 2,
                'upload_id' => '11111111-1111-4111-8111-111111111111',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The article could not be uploaded. Please try again.');

        @unlink($partTwo);
    }

    public function test_library_upload_array_country_does_not_500(): void
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/array-country-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 40));

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.upload'), [
                'file' => new UploadedFile($path, 'article.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
                'country' => ['ch'],
                'language' => ['de'],
                'title' => [str_repeat('A very long title that should be trimmed after the upload fields are flattened. ', 8)],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        @unlink($path);
        $this->assertDatabaseHas('content_submissions', [
            'user_id' => $advertiser->id,
            'country' => 'ch',
            'language' => 'de',
        ]);
        $stored = ContentSubmission::query()->where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($stored);
        $this->assertLessThanOrEqual(200, mb_strlen((string) $stored->title));
    }

    public function test_evaluation_crash_keeps_the_upload_and_returns_json(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->mock(ContentModerationService::class, function ($mock) {
            $mock->shouldReceive('linksFromSubmission')->andReturn([]);
            $mock->shouldReceive('scanExtractedContent')->andThrow(new \RuntimeException('scan failed'));
        });

        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/eval-crash-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 60));

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-library.upload'), [
            'file' => new UploadedFile(
                $path,
                'article.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title' => 'Eval crash article',
            'country' => 'us',
            'language' => 'en',
        ]);

        @unlink($path);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertFalse((bool) $response->json('approved'));
        $this->assertStringContainsString('Automatic review could not finish', (string) $response->json('message'));
        $this->assertDatabaseHas('content_submissions', [
            'user_id' => $advertiser->id,
            'title' => 'Eval crash article',
            'moderation_status' => ContentSubmission::STATUS_ERROR,
        ]);
    }

    public function test_editor_save_accepts_html_over_five_hundred_thousand_chars(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $html = '<p>'.str_repeat('Useful editorial content about productivity software. ', 12000).'</p>';
        $this->assertGreaterThan(500000, strlen($html));

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'title' => $submission->title,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function articleHtmlWithImages(int $count): string
    {
        $html = '<p>This is a compliant marketing article about software tools and productivity tips for teams working on digital projects worldwide.</p>';
        for ($i = 1; $i <= $count; $i++) {
            $html .= '<p><img src="/storage/content-articles/demo-'.$i.'.png" alt="Fig '.$i.'"></p>';
        }

        return $html;
    }

    private function extractHtmlBetween(string $html, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($html, $startNeedle);
        $end = strpos($html, $endNeedle);
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }

        return substr($html, $start, $end - $start);
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
}
