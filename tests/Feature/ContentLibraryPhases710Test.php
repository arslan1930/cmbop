<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PhpIniSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryPhases710Test extends TestCase
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

    public function test_content_library_assets_are_split_out_of_blade(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('assets/css/content-library.css', $html);
        $this->assertStringContainsString('assets/js/content-library.js', $html);
        $this->assertStringContainsString('ContentLibraryBoot', $html);
        $this->assertStringContainsString('article-preview-tools.js', $html);
        $this->assertFileExists(public_path('assets/css/content-library.css'));
        $this->assertFileExists(public_path('assets/js/content-library.js'));
        $this->assertStringNotContainsString('data-preview-payload=', $html);
    }

    public function test_admin_can_browse_content_library(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Admin Visible Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index'))
            ->assertOk()
            ->assertSee('Admin Visible Piece')
            ->assertSee($advertiser->email);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('Admin Visible Piece')
            ->assertSee('Preview');

        $this->actingAs($admin)
            ->getJson(route('admin.content-library.preview', $submission))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('id', $submission->id);
    }

    public function test_admin_content_library_array_q_does_not_500(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Array Library Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', [
                'q' => ['Array Library'],
            ]))
            ->assertOk()
            ->assertSee('Array Library Piece')
            ->assertDontSee('Array to string conversion', false);
    }

    public function test_admin_moderation_exposes_placement_language_toggle(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('require_same_language', $html);
        $this->assertStringContainsString('uploads_enabled', $html);
        $this->assertStringContainsString('Browse articles', $html);
        $this->assertStringContainsString('Fixed at 10 MB', $html);
        $this->assertStringContainsString('max="10240"', $html);
        $this->assertStringContainsString('Admin cannot raise this limit', $html);
        $this->assertStringNotContainsString('max="51200"', $html);

        $phpKb = PhpIniSize::uploadMaxKilobytes();
        if ($phpKb < 10240) {
            $this->assertStringContainsString('PHP still allows only', $html);
            $this->assertStringContainsString('upload_max_filesize', $html);
        }
    }

    public function test_legacy_wizard_partial_is_retired(): void
    {
        $wizard = file_get_contents(resource_path('views/advertiser/partials/content-submission-wizard.blade.php'));
        $this->assertStringContainsString('Retired', $wizard);
        $this->assertStringNotContainsString('contentSubmissionWizard', $wizard);
    }

    public function test_config_reports_require_same_language_flag(): void
    {
        config(['content_upload.placement.require_same_language' => true]);
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.config'))
            ->assertOk()
            ->assertJsonPath('config.require_same_language', true);
    }
}
