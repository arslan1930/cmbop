<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentImageRightsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function upload(User $advertiser, array $extra = []): TestResponse
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $path = sys_get_temp_dir().'/image-rights-'.uniqid().'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 60));

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-library.upload'), array_merge([
            'file' => new UploadedFile(
                $path,
                'article.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title' => 'Image rights article',
            'country' => 'us',
            'language' => 'en',
        ], $extra));

        @unlink($path);

        return $response;
    }

    public function test_upload_without_rights_records_none_when_the_article_has_no_images(): void
    {
        $advertiser = $this->advertiser();

        $this->upload($advertiser)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.has_images', false)
            ->assertJsonPath('submission.needs_image_rights', false);

        $submission = ContentSubmission::where('user_id', $advertiser->id)->firstOrFail();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_NONE, $submission->image_rights);
        $this->assertNotNull($submission->image_rights_declared_at);
    }

    public function test_blank_rights_plus_images_blocks_save_until_declared(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => null,
            'image_rights_declared_at' => null,
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Body copy</p><img src="/storage/content-articles/1/x.png" alt="">',
            ])
            ->assertStatus(422)
            ->assertJsonPath('needs_image_rights', true);
    }

    public function test_upload_is_rejected_for_an_unknown_declaration(): void
    {
        $this->upload($this->advertiser(), ['image_rights' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image_rights']);
    }

    public function test_sourced_images_must_name_a_source(): void
    {
        $this->upload($this->advertiser(), ['image_rights' => ContentSubmission::IMAGE_RIGHTS_LICENSED])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image_rights_source']);
    }

    public function test_owning_the_images_is_recorded_on_the_article(): void
    {
        $advertiser = $this->advertiser();

        $this->upload($advertiser, ['image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN])
            ->assertOk()
            ->assertJsonPath('success', true);

        $submission = ContentSubmission::where('user_id', $advertiser->id)->firstOrFail();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_OWN, $submission->image_rights);
        $this->assertNull($submission->image_rights_source);
        $this->assertNotNull($submission->image_rights_declared_at);
    }

    public function test_a_sourced_declaration_stores_the_source(): void
    {
        $advertiser = $this->advertiser();

        $this->upload($advertiser, [
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_LICENSED,
            'image_rights_source' => 'https://unsplash.com/photos/abc123',
        ])->assertOk();

        $submission = ContentSubmission::where('user_id', $advertiser->id)->firstOrFail();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_LICENSED, $submission->image_rights);
        $this->assertSame('https://unsplash.com/photos/abc123', $submission->image_rights_source);
    }

    public function test_declaring_no_images_does_not_keep_a_stale_source(): void
    {
        $advertiser = $this->advertiser();

        $this->upload($advertiser, [
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_source' => 'https://example.com/ignored',
        ])->assertOk();

        $submission = ContentSubmission::where('user_id', $advertiser->id)->firstOrFail();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_NONE, $submission->image_rights);
        $this->assertNull($submission->image_rights_source);
    }

    public function test_images_added_in_the_editor_cannot_bypass_the_declaration(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_declared_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Body copy</p><img src="/storage/content-articles/1/x.png" alt="">',
            ])
            ->assertStatus(422)
            ->assertJsonPath('needs_image_rights', true);

        $this->assertSame(
            ContentSubmission::IMAGE_RIGHTS_NONE,
            $submission->fresh()->image_rights
        );
    }

    public function test_updating_the_declaration_lets_the_edit_through(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_declared_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Body copy</p><img src="/storage/content-articles/1/x.png" alt="">',
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_LICENSED,
                'image_rights_source' => 'https://pexels.com/photo/999',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $submission->fresh();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_LICENSED, $fresh->image_rights);
        $this->assertSame('https://pexels.com/photo/999', $fresh->image_rights_source);
    }

    public function test_claiming_no_images_does_not_overwrite_a_covering_declaration(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN,
            'image_rights_declared_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Body copy</p><img src="/storage/content-articles/1/x.png" alt="">',
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('needs_image_rights', true);

        $this->assertSame(
            ContentSubmission::IMAGE_RIGHTS_OWN,
            $submission->fresh()->image_rights
        );
    }

    public function test_preview_payload_tells_the_editor_when_rights_are_still_needed(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'preview_html' => '<p>Body</p><img src="/storage/content-articles/1/x.png" alt="">',
            'image_rights' => null,
            'image_rights_declared_at' => null,
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.preview', $submission))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('has_images', true)
            ->assertJsonPath('needs_image_rights', true)
            ->assertJsonPath('image_rights_covers', false);
    }

    public function test_library_upload_rejects_an_unpaired_market_as_json(): void
    {
        $this->upload($this->advertiser(), [
            'country' => 'de',
            'language' => 'en',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'That language is not allowed for the selected country. Pick country first, then a paired language.'
            );
    }

    public function test_text_only_edits_are_unaffected(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_declared_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Still no pictures in this article at all.</p>',
            ])
            ->assertOk();
    }

    public function test_the_model_knows_when_a_declaration_stops_covering_the_article(): void
    {
        $submission = new ContentSubmission;

        $submission->preview_html = '<p>text only</p>';
        $submission->image_rights = ContentSubmission::IMAGE_RIGHTS_NONE;
        $this->assertFalse($submission->hasImages());
        $this->assertTrue($submission->imageRightsCoverContent());

        $submission->preview_html = '<p>text</p><img src="/storage/a.png">';
        $this->assertTrue($submission->hasImages());
        $this->assertFalse($submission->imageRightsCoverContent());

        $submission->image_rights = null;
        $this->assertFalse($submission->imageRightsCoverContent());

        $submission->image_rights = ContentSubmission::IMAGE_RIGHTS_OWN;
        $this->assertTrue($submission->imageRightsCoverContent());

        $this->assertTrue(ContentSubmission::imageRightsNeedsSource(ContentSubmission::IMAGE_RIGHTS_LICENSED));
        $this->assertFalse(ContentSubmission::imageRightsNeedsSource(ContentSubmission::IMAGE_RIGHTS_OWN));
    }

    public function test_library_upload_modal_defers_rights_until_the_editor(): void
    {
        $advertiser = $this->advertiser();

        $library = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="libraryDropzone"', $library);
        $this->assertStringContainsString('id="libraryUploadBtn"', $library);
        $this->assertStringContainsString('Upload and edit', $library);
        $this->assertStringNotContainsString('Upload &amp; preview', $library);
        $this->assertStringContainsString('data-upload-step="file"', $library);
        $this->assertStringContainsString('data-upload-step="market"', $library);
        $this->assertStringContainsString('data-upload-step="rights"', $library);
        $this->assertStringContainsString('id="libraryMarketChip"', $library);
        $this->assertStringContainsString('not PDF, Google Doc, or pasted text', $library);
        $this->assertStringNotContainsString('id="libraryImageRightsOwn"', $library);
        $this->assertStringContainsString('id="editorImageRightsOwn"', $library);
        $this->assertStringContainsString('id="articleEditorImageRights"', $library);
        $this->assertStringContainsString('name="image_rights"', $library);
        $this->assertStringContainsString('image-rights.js', $library);
        preg_match('/id="uploadContentModal"[\s\S]*?id="libraryUploadBtn"/', $library, $uploadModal);
        $this->assertNotEmpty($uploadModal);
        $this->assertStringContainsString('ui-callout--info', $uploadModal[0]);
        $this->assertStringNotContainsString('ui-callout--attention', $uploadModal[0]);

        $js = file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('function bindLibraryDropzone', $js);
        $this->assertStringContainsString('Opening editor…', $js);
        $this->assertStringContainsString('hidden.bs.modal', $js);
        $this->assertStringContainsString('function showArticleEditorAfterUploadModal', $js);
        $this->assertStringContainsString('function dismissLibraryUploadByUser', $js);
        $this->assertStringContainsString('function resetLibraryUploadUi', $js);
        $this->assertStringContainsString('function cancelLibraryUploadHandoffState', $js);
        $this->assertStringContainsString('libraryUploadHandoff', $js);
        $this->assertStringContainsString('window.toggleLibraryTitleEdit = toggleLibraryTitleEdit', $js);
        $this->assertStringContainsString('window.copyLibraryLiveUrl = copyLibraryLiveUrl', $js);
        $this->assertStringContainsString('window.saveLibraryTitle = saveLibraryTitle', $js);
        $this->assertStringContainsString('window.archiveLibraryArticle = archiveLibraryArticle', $js);
        $this->assertStringContainsString('window.deleteLibraryArticle = deleteLibraryArticle', $js);
        $this->assertStringContainsString('window.restoreLibraryArticle = restoreLibraryArticle', $js);
        $this->assertStringNotContainsString('readImageRights(this)', $js);

        $declaration = file_get_contents(resource_path('views/advertiser/partials/image-rights-declaration.blade.php'));
        $this->assertStringContainsString('image_rights', $declaration);
        $this->assertStringContainsString('image_rights_source', $declaration);
    }
}
