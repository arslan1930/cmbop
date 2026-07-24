<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class PostApprovalModerationRecheckTest extends TestCase
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

    public function test_editing_approved_article_html_with_sensitive_link_revokes_approval(): void
    {
        Mail::fake();
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'title' => 'Was Approved',
            'language' => 'en',
            'country' => 'us',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'evaluation_status' => 'approved',
        ]);
        config(['content_moderation.enabled' => true]);

        $html = '<p>'.$body.'</p>'
            .'<p>Learn more <a href="https://www.bet365.com/sports">click here</a>.</p>';

        $response = $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'title' => 'Was Approved',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('approved', false);

        $submission->refresh();
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->moderation_status);
        $this->assertFalse($submission->canBeOrdered());
        $this->assertNotEmpty($submission->evaluation_report['blocked_urls'] ?? []);
    }

    public function test_patching_links_on_approved_article_revokes_approval_for_casino(): void
    {
        Mail::fake();
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'title' => 'Approved Then Linked',
            'language' => 'en',
            'country' => 'us',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p><p><a href="https://example.com/ok">guide</a></p>',
            'anchor_text' => 'guide',
            'target_url' => 'https://example.com/ok',
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
        ]);
        config(['content_moderation.enabled' => true]);

        $html = '<p>'.$body.'</p><p><a href="https://pokerstars.com/play">guide</a></p>';

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'links' => [
                    ['anchor' => 'guide', 'url' => 'https://pokerstars.com/play'],
                ],
                'preview_html' => $html,
            ])
            ->assertOk()
            ->assertJsonPath('approved', false)
            ->assertJsonPath('moderation_status', ContentSubmission::STATUS_REJECTED);

        $this->assertFalse($submission->fresh()->canBeOrdered());
    }

    public function test_checkout_rechecks_stale_approved_article_with_sensitive_link(): void
    {
        Mail::fake();
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();

        // Simulate a stale approval that somehow still has a bad link in HTML.
        $submission->update([
            'language' => 'en',
            'country' => 'us',
            'extracted_text' => $body.' Visit our partner.',
            'preview_html' => '<p>'.$body.'</p><p><a href="https://www.bet365.com/x">partner offer</a></p>',
            'target_url' => 'https://www.bet365.com/x',
            'anchor_text' => 'partner offer',
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'evaluation_status' => 'approved',
        ]);
        config(['content_moderation.enabled' => true]);

        $this->assertTrue($submission->fresh()->isApproved());

        $result = app(ContentModerationService::class)
            ->assertSubmissionsApproved([$submission->fresh()], $advertiser);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['failures']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_content_library_editor_mentions_recheck_in_script(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Saving and re-checking content moderation', $html);
        $this->assertStringContainsString('data.approved !== false', $html);
    }
}
