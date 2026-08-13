<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertSee('In placement');

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
            ->assertSee('Done — not orderable')
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
        $this->assertStringContainsString('Completed/LIVE', $html);
        $this->assertStringContainsString('>Approved</span>', $html);
        $this->assertStringContainsString('>In progress</span>', $html);
        $this->assertStringContainsString('>Needs corrections</span>', $html);
        $this->assertStringContainsString('>Archived</span>', $html);
        $this->assertStringContainsString('>Expired</span>', $html);
        $this->assertStringContainsString('library-status-box--in_progress', $html);
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
        $this->assertStringContainsString('visually-hidden" for="librarySearchInput"', $html);
        $this->assertStringContainsString('visually-hidden">Search</button>', $html);
        $this->assertStringContainsString('visually-hidden" for="libraryCountryFilter"', $html);
        $this->assertStringContainsString('visually-hidden" for="libraryLanguageFilter"', $html);
        $this->assertStringContainsString('Search title or filename', $html);
        $this->assertStringContainsString('All countries', $html);
        $this->assertStringContainsString('All languages', $html);
        $this->assertStringNotContainsString('>Apply<', $html);
        $this->assertStringNotContainsString('form-label small text-muted mb-1" for="librarySearchInput"', $html);
        $this->assertStringNotContainsString('library-filter-bar__actions', $html);

        $css = (string) file_get_contents(public_path('assets/css/content-library.css'));
        $this->assertStringContainsString('.library-status-row', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);
        $this->assertStringContainsString('.mod-count.is-zero', $css);
        $this->assertStringContainsString('.library-status-box.is-active .mod-count:not(.is-zero)', $css);
        $this->assertStringNotContainsString('.library-status-box.is-active .mod-count {', $css);
        $this->assertStringContainsString('.library-browse-link', $css);
        $this->assertStringNotContainsString('.library-page-actions.upload-zone', $css);
        $this->assertStringContainsString(".library-filter-bar {\n        display: flex;\n        flex-wrap: wrap;\n        align-items: center;", $css);
        $this->assertStringNotContainsString('align-items: flex-end', $css);
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

    public function test_library_order_button_links_to_catalog_flow(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Ready Article']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Order')
            ->assertSee(route('advertiser.content-library.order', $submission), false)
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
        $file = UploadedFile::fake()->image('figure.png', 40, 40);

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image'), [
                'image' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['url']);

        $url = (string) $response->json('url');
        $this->assertStringStartsWith('/storage/content-articles/', $url);
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
        $this->assertStringContainsString(
            'function openPreviewModal(title, html, links, submissionId, editable)',
            file_get_contents(public_path('assets/js/content-library.js'))
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
        $this->assertStringContainsString('#articleEditorModal .article-docs-shell .ql-editor', $css);
        $this->assertStringContainsString('overscroll-behavior: contain', $css);
        $this->assertStringContainsString('.library-row--focus', $css);
        $this->assertStringContainsString('.library-row--focus > td', $css);
        $this->assertStringContainsString('function goToLibraryResult', $js);
        $this->assertStringContainsString('function libraryDestinationUrl', $js);
        $this->assertStringContainsString("availability: 'needs_fix'", $js);
        $this->assertStringContainsString('libraryResultFlash', $js);
        $this->assertStringContainsString('function applyLibraryResultFocus', $js);
        $this->assertStringNotContainsString('window.location.reload()', $js);
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
        $this->assertStringContainsString('MB limit', $message);
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
        $this->assertStringContainsString("fd.set('file', file, file.name)", $js);
        $this->assertStringContainsString('function firstErrorMessage', $js);
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
