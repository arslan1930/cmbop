<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentUpload\ArticleEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryUrlModerationFilterTest extends TestCase
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

    private function englishBody(): string
    {
        return str_repeat(
            'This article explains digital marketing strategies that help brands grow organic traffic with useful content. ',
            12
        ).'Readers will find clear tips about SEO, content, and conversion which are useful for their business.';
    }

    public function test_library_moderation_filter_approved_rejected_needs_corrections(): void
    {
        $advertiser = $this->advertiser();

        $approved = $this->createApprovedSubmission($advertiser);
        $approved->update(['title' => 'Approved Piece']);

        $rejected = $this->createApprovedSubmission($advertiser);
        $rejected->update([
            'title' => 'Rejected Casino Link',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => 'rejected',
            'evaluation_report' => [
                'summary' => 'This article links to restricted casino sites.',
                'matched_terms' => ['bet365'],
                'blocked_urls' => ['https://www.bet365.com/en'],
            ],
        ]);

        $needsFix = $this->createApprovedSubmission($advertiser);
        $needsFix->update([
            'title' => 'Needs Corrections Piece',
            'moderation_status' => ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
            'evaluation_status' => 'needs_improvement',
            'evaluation_report' => [
                'summary' => 'Please improve clarity and resubmit.',
                'matched_terms' => [],
            ],
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Library status filter', false)
            ->assertSee('Approved')
            ->assertSee('Needs corrections')
            ->assertSee('Completed/LIVE')
            ->assertDontSee('library-moderation-row', false)
            ->assertSee('Approved Piece')
            ->assertSee('Rejected Casino Link')
            ->assertSee('Needs Corrections Piece');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Approved Piece')
            ->assertDontSee('Rejected Casino Link')
            ->assertDontSee('Needs Corrections Piece');

        $approvedPage = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'approved']));
        $approvedPage->assertOk()->assertSee('Approved Piece');
        $approvedHtml = $approvedPage->getContent();
        $this->assertStringContainsString('name="status" value="approved"', $approvedHtml);
        $this->assertStringContainsString('name="availability" value="available"', $approvedHtml);
        $this->assertMatchesRegularExpression(
            '/library-status-box--approved[^>]*is-active/i',
            $approvedHtml
        );

        // Rejected chip removed from UI; deep-link status=rejected still works.
        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee('Rejected Casino Link')
            ->assertSee('Blocked links:')
            ->assertSee('https://www.bet365.com/en')
            ->assertDontSee('Approved Piece')
            ->assertDontSee('Needs Corrections Piece');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'needs_improvement']))
            ->assertOk()
            ->assertSee('Needs Corrections Piece')
            ->assertDontSee('Approved Piece')
            ->assertDontSee('Rejected Casino Link');
    }

    public function test_saving_article_with_cloaked_gambling_url_rejects_and_highlights(): void
    {
        Mail::fake();
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Clean Looking Article',
            'language' => 'en',
            'country' => 'us',
            'extracted_text' => $this->englishBody(),
            'preview_html' => '<p>'.$this->englishBody().'</p>',
        ]);

        // Re-enable moderation after createApprovedSubmission disabled it.
        config(['content_moderation.enabled' => true]);

        $html = '<p>'.$this->englishBody().'</p>'
            .'<p>Learn more <a href="https://www.bet365.com/sports">click here</a> for tips.</p>';

        $response = $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'title' => 'Clean Looking Article',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('approved', false);

        $submission->refresh();
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->moderation_status);
        $this->assertNotEmpty($submission->evaluation_report['blocked_urls'] ?? []);
        $this->assertStringContainsString('bet365', implode(' ', $submission->evaluation_report['blocked_urls'] ?? []));
        $this->assertStringContainsString('slb-mod-hit-link', (string) $submission->preview_html);
        $this->assertStringContainsString('click here', (string) $submission->preview_html);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee('Clean Looking Article')
            ->assertSee('Blocked links:')
            ->assertSee('Needs corrections');
    }

    public function test_saving_article_with_adult_url_rejects(): void
    {
        Mail::fake();
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Travel Notes',
            'language' => 'en',
            'country' => 'us',
            'extracted_text' => $this->englishBody(),
            'preview_html' => '<p>'.$this->englishBody().'</p>',
        ]);
        config(['content_moderation.enabled' => true]);

        $html = '<p>'.$this->englishBody().'</p>'
            .'<p>Gallery <a href="https://www.pornhub.com/video/1">view gallery</a>.</p>';

        $response = $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'title' => 'Travel Notes',
            ]);

        $response->assertOk()->assertJsonPath('approved', false);
        $submission->refresh();
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->moderation_status);
        $this->assertStringContainsString('pornhub', implode(' ', $submission->evaluation_report['blocked_urls'] ?? []));
    }

    public function test_patching_links_on_approved_article_rechecks_and_rejects_casino(): void
    {
        Mail::fake();
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'title' => 'Approved Then Edited',
            'language' => 'en',
            'country' => 'us',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p><p><a href="https://example.com/guide">helpful guide</a></p>',
            'anchor_text' => 'helpful guide',
            'target_url' => 'https://example.com/guide',
        ]);
        config(['content_moderation.enabled' => true]);

        $html = '<p>'.$body.'</p><p><a href="https://www.bet365.com/sports">helpful guide</a></p>';

        $response = $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'links' => [
                    ['anchor' => 'helpful guide', 'url' => 'https://www.bet365.com/sports'],
                ],
                'preview_html' => $html,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('approved', false)
            ->assertJsonPath('moderation_status', ContentSubmission::STATUS_REJECTED);

        $submission->refresh();
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->moderation_status);
        $this->assertFalse($submission->canBeOrdered());
        $this->assertSame('needs_fix', $submission->libraryAvailability());
        $this->assertNotEmpty($submission->evaluation_report['blocked_urls'] ?? []);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee('Approved Then Edited')
            ->assertSee('Needs corrections', false)
            ->assertDontSee('>Available<', false);
    }

    public function test_content_library_chrome_keeps_browse_publishers_without_top_upload(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Browse publishers', $html);
        $this->assertStringContainsString('id="openUploadModalBtn"', $html);
        $this->assertStringNotContainsString('id="openUploadModalBtnTop"', $html);
    }

    public function test_evaluation_service_flags_cloaked_url_directly(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p><p><a href="https://pokerstars.com/play">read more</a></p>',
            'target_url' => null,
        ]);
        config(['content_moderation.enabled' => true]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertFalse($result['approved']);
        $this->assertNotEmpty($result['blocked_urls'] ?? []);
        $this->assertStringContainsString('slb-mod-hit', (string) ($result['highlighted_html'] ?? ''));
    }

    public function test_evaluation_service_flags_prohibited_keyword_in_body(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody().' Readers should avoid online casino promotions entirely.';
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'target_url' => null,
        ]);
        config(['content_moderation.enabled' => true]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertFalse($result['approved']);
        $this->assertNotEmpty($result['matched_terms'] ?? []);
        $this->assertTrue(
            collect($result['matched_terms'] ?? [])->contains(fn ($t) => str_contains(strtolower((string) $t), 'casino'))
        );
    }
}
