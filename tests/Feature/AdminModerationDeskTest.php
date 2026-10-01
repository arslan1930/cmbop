<?php

namespace Tests\Feature;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdminModerationDeskTest extends TestCase
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

    private function rejectCasinoArticle(User $advertiser): array
    {
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission = $this->createApprovedSubmission($advertiser);
        $body = 'Play at the best online casino and claim your no deposit bonus for slots and roulette today.';
        $submission->update([
            'title' => 'Casino guide',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
        ]);

        $result = app(ContentModerationService::class)->scanExtractedContent(
            text: $body,
            html: '<p>'.$body.'</p>',
            sourceLabel: 'upload:'.$submission->id,
            user: $advertiser,
            title: 'Casino guide',
            links: [],
            contentSubmissionId: (int) $submission->id,
        );

        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $result['log']?->id,
            'scan_token' => $result['scan_token'],
        ]);

        return [$submission->fresh(), $result['log']];
    }

    public function test_needs_filter_hides_stale_rejects_and_index_has_no_override_form(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $old] = $this->rejectCasinoArticle($advertiser);

        $current = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-current',
            'word_count' => 20,
        ]);
        $submission->update(['moderation_log_id' => $current->id, 'scan_token' => 'scan-current']);

        $index = $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['status' => 'needs']))
            ->assertOk()
            ->assertSee('Needs decision')
            ->assertSee('Casino guide')
            ->assertSee(route('admin.users.show', $advertiser), false)
            ->assertDontSee(route('admin.moderation.override', $old), false)
            ->assertDontSee(route('admin.moderation.override', $current), false);

        $html = $index->getContent();
        $this->assertStringNotContainsString('Approve this submission via admin override', $html);
        $this->assertStringContainsString('href="'.route('admin.moderation.show', $current).'"', $html);
        $this->assertStringNotContainsString('href="'.route('admin.moderation.show', $old).'"', $html);
    }

    public function test_approved_kpi_and_filter_exclude_overrides(): void
    {
        $admin = $this->admin();
        [$submission, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), ['notes' => 'Allow this version.'])
            ->assertRedirect();

        $stats = app(ContentModerationService::class)->adminStats();
        $this->assertSame(0, $stats['approved']);
        $this->assertGreaterThanOrEqual(1, $stats['overridden']);
        $this->assertSame(0, $stats['needs']);

        $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['status' => 'approved']))
            ->assertOk()
            ->assertDontSee($submission->title);

        $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['status' => 'overridden']))
            ->assertOk()
            ->assertSee($submission->title);
    }

    public function test_show_back_keeps_list_filters(): void
    {
        $admin = $this->admin();
        [, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['status' => 'needs', 'q' => 'Casino']));

        $this->actingAs($admin)
            ->get(route('admin.moderation.show', $log))
            ->assertOk()
            ->assertSee(route('admin.moderation.index', ['status' => 'needs', 'q' => 'Casino']), false);
    }

    public function test_policy_save_does_not_write_upload_config(): void
    {
        $admin = $this->admin();
        ContentModerationSetting::setValue('upload_config', ['retention_months' => 6, 'enabled' => true]);

        $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['status' => 'error']));

        $this->actingAs($admin)
            ->post(route('admin.moderation.settings'), [
                'enabled' => '1',
                'confidence_threshold' => 72,
                'categories' => ['gambling', 'adult', 'cbd', 'alcohol', 'tobacco', 'weapons'],
            ])
            ->assertRedirect(route('admin.moderation.index', ['status' => 'error']))
            ->assertSessionHas('success');

        $upload = ContentModerationSetting::getValue('upload_config', []);
        $this->assertSame(6, (int) ($upload['retention_months'] ?? 0));
        $override = ContentModerationSetting::getValue('config_override', []);
        $this->assertSame(72, (int) ($override['confidence_threshold'] ?? 0));
        $this->assertTrue((bool) ($override['enabled'] ?? false));
    }

    public function test_upload_settings_save_does_not_write_policy(): void
    {
        $admin = $this->admin();
        ContentModerationSetting::setValue('config_override', ['enabled' => true, 'confidence_threshold' => 70]);

        $this->actingAs($admin)
            ->post(route('admin.moderation.upload-settings'), [
                'uploads_enabled' => '1',
                'retention_months' => 8,
                'min_uniqueness' => 40,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $upload = ContentModerationSetting::getValue('upload_config', []);
        $this->assertSame(8, (int) ($upload['retention_months'] ?? 0));
        $this->assertSame(40, (int) data_get($upload, 'evaluation.min_uniqueness'));
        $override = ContentModerationSetting::getValue('config_override', []);
        $this->assertSame(70, (int) ($override['confidence_threshold'] ?? 0));
    }

    public function test_failed_test_scan_does_not_uncheck_policy(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->followingRedirects()
            ->post(route('admin.moderation.test-scan'), [])
            ->assertOk()
            ->assertSee('Paste article text or a public URL.')
            ->getContent();

        $this->assertMatchesRegularExpression('/id="modEnabled"[^>]*\bchecked\b/', $html);
        $this->assertSame(0, ContentModerationLog::query()->count());
    }

    public function test_test_scan_does_not_persist_a_log(): void
    {
        $admin = $this->admin();
        $before = ContentModerationLog::query()->count();

        $this->actingAs($admin)
            ->post(route('admin.moderation.test-scan'), [
                'text' => 'Play at the best online casino and claim your no deposit bonus tonight.',
            ])
            ->assertRedirect()
            ->assertSessionHas('moderation_test');

        $this->assertSame($before, ContentModerationLog::query()->count());
        $report = session('moderation_test');
        $this->assertIsArray($report);
        $this->assertFalse((bool) ($report['passed'] ?? true));
        $this->assertSame('rejected', $report['status'] ?? null);
    }

    public function test_rescan_error_log_writes_a_new_current_scan(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = 'This guide covers building a content calendar for a B2B SaaS blog.';
        $submission->update([
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
        ]);

        $error = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_ERROR,
            'passed' => false,
            'error_code' => 'timeout',
            'error_message' => 'provider down',
            'scan_token' => 'scan-error',
            'word_count' => 20,
        ]);
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_ERROR,
            'moderation_log_id' => $error->id,
            'scan_token' => 'scan-error',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.moderation.rescan', $error))
            ->assertRedirect();

        $submission->refresh();
        $this->assertNotSame((int) $error->id, (int) $submission->moderation_log_id);
        $this->assertNotNull($submission->moderation_log_id);
        $this->assertNotSame(ContentModerationLog::STATUS_ERROR, $submission->moderationLog?->status);
    }

    public function test_rescan_rejects_non_error_logs(): void
    {
        $admin = $this->admin();
        [, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->from(route('admin.moderation.show', $log))
            ->post(route('admin.moderation.rescan', $log))
            ->assertRedirect(route('admin.moderation.show', $log))
            ->assertSessionHas('error');
    }
}
