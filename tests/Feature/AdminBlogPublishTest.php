<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class AdminBlogPublishTest extends TestCase
{
    use CreatesBlogUploads;
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_toggle_status_rejects_get_and_requires_post(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::factory()->published()->create([
            'title' => 'Toggle Me',
            'slug' => 'toggle-me',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.blogs.toggle-status', $blog->id))
            ->assertMethodNotAllowed();

        $this->actingAs($admin)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertRedirect(route('admin.blogs.index'))
            ->assertSessionHas('success');

        $this->assertSame('draft', $blog->fresh()->status);
        $this->assertNotNull($blog->fresh()->manually_edited_at);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'blog.unpublished',
            'subject_id' => $blog->id,
        ]);
    }

    public function test_store_does_not_steal_legacy_blog_slug(): void
    {
        $admin = $this->adminUser();
        $legacy = Blog::factory()->published()->create([
            'title' => 'Legacy Shared Slug',
            'slug' => 'shared-public-slug',
            'content' => '<p>Legacy body</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $legacy->id,
            'locale' => 'en',
            'title' => 'Legacy Shared Slug',
            'slug' => 'legacy-other-slug',
            'excerpt' => 'Excerpt',
            'content' => '<p>Legacy body</p>',
            'is_published' => true,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.blogs.store'), [
                'status' => 'published',
                'translations' => [
                    'en' => [
                        'title' => 'New Shared Slug',
                        'slug' => 'shared-public-slug',
                        'content' => '<p>New body that must not hijack the legacy URL.</p>',
                    ],
                ],
            ]);

        $created = Blog::query()->where('title', 'New Shared Slug')->first();
        $this->assertNotNull($created);
        $response->assertRedirect(route('admin.blogs.edit', $created->id));
        $this->assertNotSame('shared-public-slug', $created->slug);
        $this->assertNotSame(
            'shared-public-slug',
            $created->translations()->where('locale', 'en')->value('slug')
        );

        $this->get(route('blog.show', ['slug' => 'shared-public-slug']))
            ->assertRedirect(url('/blog/legacy-other-slug'));

        $html = $this->followingRedirects()
            ->get(route('blog.show', ['slug' => 'shared-public-slug']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Legacy Shared Slug\s*<\/h1>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*New Shared Slug\s*<\/h1>/', $html);
    }

    public function test_store_stamps_manually_edited_at_so_curated_sync_cannot_adopt_it(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Brand New Custom Post',
                    'slug' => 'brand-new-custom-post',
                    'content' => '<p>Custom body.</p>',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'brand-new-custom-post')->firstOrFail();
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertNotNull($blog->manually_edited_at);
        $this->assertNull($blog->curated_key);
    }

    public function test_republish_keeps_original_published_at(): void
    {
        $admin = $this->adminUser();
        $publishedAt = now()->subDays(10);
        $blog = Blog::factory()->published()->create([
            'title' => 'Dated Post',
            'slug' => 'dated-post',
            'published_at' => $publishedAt,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        $unpublished = $blog->fresh();
        $this->assertSame('draft', $unpublished->status);
        $this->assertTrue($unpublished->published_at->isSameSecond($publishedAt));

        $this->actingAs($admin)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        $republished = $blog->fresh();
        $this->assertSame('published', $republished->status);
        $this->assertTrue($republished->published_at->isSameSecond($publishedAt));
    }

    public function test_store_rejects_content_that_sanitizes_to_blank(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->from(route('admin.blogs.create'))
            ->post(route('admin.blogs.store'), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => 'Blank After Sanitize',
                        'slug' => 'blank-after-sanitize',
                        'content' => '<p><img src="javascript:alert(1)"></p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.create'))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('blogs', ['slug' => 'blank-after-sanitize']);
    }

    public function test_store_deletes_featured_file_when_create_transaction_fails(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $forced = false;
        Blog::creating(function () use (&$forced) {
            if ($forced) {
                return;
            }
            $forced = true;
            throw new \RuntimeException('forced create failure');
        });

        $this->actingAs($admin)
            ->from(route('admin.blogs.create'))
            ->post(route('admin.blogs.store'), [
                'status' => 'draft',
                'featured_image' => $this->fakeBlogUpload('hero.jpg', 800, 450),
                'translations' => [
                    'en' => [
                        'title' => 'Orphan Featured',
                        'slug' => 'orphan-featured',
                        'content' => '<p>Valid body that should persist if create worked.</p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.create'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('blogs', ['slug' => 'orphan-featured']);
        $this->assertSame([], Storage::disk('public')->allFiles('blogs/featured'));
    }

    public function test_store_does_not_write_featured_file_when_content_sanitizes_blank(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->from(route('admin.blogs.create'))
            ->post(route('admin.blogs.store'), [
                'status' => 'draft',
                'featured_image' => $this->fakeBlogUpload('hero.jpg', 800, 450),
                'translations' => [
                    'en' => [
                        'title' => 'No Persist Featured',
                        'slug' => 'no-persist-featured',
                        'content' => '<p><img src="javascript:alert(1)"></p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.create'))
            ->assertSessionHasErrors()
            ->assertSessionHas('warning', 'Choose the featured image again.');

        $this->assertDatabaseMissing('blogs', ['slug' => 'no-persist-featured']);
        $this->assertSame([], Storage::disk('public')->allFiles('blogs/featured'));
    }

    public function test_create_redisplay_rewrites_legacy_asset_images(): void
    {
        $admin = $this->adminUser();

        $html = $this->actingAs($admin)
            ->from(route('admin.blogs.create'))
            ->followingRedirects()
            ->post(route('admin.blogs.store'), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => '',
                        'content' => '<p><img src="/assets/img/blog/gastbeitraege-europa-sprachen.jpg" alt="A"></p>',
                    ],
                ],
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/media/blogs/content/gastbeitraege-europa-sprachen.jpg', $html);
        $this->assertStringNotContainsString('/assets/img/blog/gastbeitraege-europa-sprachen.jpg', $html);
    }

    public function test_store_sanitizes_script_tags_before_persist(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Sanitized Store',
                    'slug' => 'sanitized-store',
                    'content' => '<p>Hello</p><script>alert(1)</script>',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'sanitized-store')->firstOrFail();
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertStringNotContainsString('<script', $blog->content);
        $this->assertStringContainsString('<p>Hello</p>', $blog->content);
    }

    public function test_public_show_does_not_render_stored_scripts(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Public XSS',
            'slug' => 'public-xss',
            'content' => '<p>Visible</p><script>alert("xss")</script><p onclick="alert(1)">Click</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Public XSS',
            'slug' => 'public-xss',
            'excerpt' => 'Excerpt',
            'content' => '<p>Visible</p><script>alert("xss")</script><p onclick="alert(1)">Click</p>',
            'is_published' => true,
        ]);

        $html = $this->get(route('blog.show', ['slug' => 'public-xss']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Visible', $html);
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('onclick="alert(1)"', $html);
    }

    public function test_public_show_rewrites_storage_blog_images_to_media(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Legacy Storage Image',
            'slug' => 'legacy-storage-image',
            'content' => '<p><img src="/storage/blogs/content/legacy.jpg" alt="L"></p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Legacy Storage Image',
            'slug' => 'legacy-storage-image',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/storage/blogs/content/legacy.jpg" alt="L"></p>',
            'is_published' => true,
        ]);

        $this->get(route('blog.show', ['slug' => 'legacy-storage-image']))
            ->assertOk()
            ->assertSee('/media/blogs/content/legacy.jpg', false)
            ->assertDontSee('/storage/blogs/content/legacy.jpg', false);

        $blog->refresh();
        $this->assertStringContainsString('/storage/blogs/content/legacy.jpg', $blog->content);
    }

    public function test_admin_save_rewrites_legacy_asset_images_instead_of_stripping_them(): void
    {
        $admin = $this->adminUser();
        $html = '<p>Keep this paragraph.</p><p><img src="/assets/img/blog/gastbeitraege-europa-sprachen.jpg" alt="A"></p>';

        $blog = Blog::factory()->create([
            'title' => 'Legacy Asset Save',
            'slug' => 'legacy-asset-save',
            'content' => $html,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Legacy Asset Save',
            'slug' => 'legacy-asset-save',
            'excerpt' => 'Excerpt',
            'content' => $html,
            'is_published' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => 'Legacy Asset Save',
                        'slug' => 'legacy-asset-save',
                        'content' => $html,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.index'));

        $blog->refresh();
        $this->assertStringContainsString('Keep this paragraph.', $blog->content);
        $this->assertStringContainsString('/media/blogs/content/gastbeitraege-europa-sprachen.jpg', $blog->content);
        $this->assertStringNotContainsString('/assets/img/blog/', $blog->content);
        $this->assertStringContainsString(
            '/media/blogs/content/gastbeitraege-europa-sprachen.jpg',
            $blog->translations()->where('locale', 'en')->value('content')
        );
    }

    public function test_destroy_deletes_unreferenced_content_images(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = $this->fakeBlogUpload('inline-cleanup.jpg')->store('blogs/content', 'public');

        $blog = Blog::factory()->create([
            'title' => 'Cleanup Post',
            'slug' => 'cleanup-post',
            'content' => '<p><img src="/storage/'.$path.'"></p>',
            'created_by' => $admin->id,
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Cleanup Post',
            'slug' => 'cleanup-post',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/storage/'.$path.'"></p>',
            'is_published' => true,
        ]);

        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->delete(route('admin.blogs.destroy', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        Storage::disk('public')->assertMissing($path);
    }

    public function test_destroy_keeps_featured_image_referenced_with_media_prefix(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = $this->fakeBlogUpload('shared-hero.jpg')->store('blogs/featured', 'public');

        $keep = Blog::factory()->create([
            'title' => 'Keeps Featured',
            'slug' => 'keeps-featured',
            'content' => '<p>Keep</p>',
            'featured_image' => '/media/'.$path,
            'created_by' => $admin->id,
        ]);
        BlogTranslation::create([
            'blog_id' => $keep->id,
            'locale' => 'en',
            'title' => 'Keeps Featured',
            'slug' => 'keeps-featured',
            'excerpt' => 'Excerpt',
            'content' => '<p>Keep</p>',
            'is_published' => true,
        ]);

        $gone = Blog::factory()->create([
            'title' => 'Drops Featured',
            'slug' => 'drops-featured',
            'content' => '<p>Gone</p>',
            'featured_image' => $path,
            'created_by' => $admin->id,
        ]);
        BlogTranslation::create([
            'blog_id' => $gone->id,
            'locale' => 'en',
            'title' => 'Drops Featured',
            'slug' => 'drops-featured',
            'excerpt' => 'Excerpt',
            'content' => '<p>Gone</p>',
            'is_published' => true,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.blogs.destroy', $gone->id))
            ->assertRedirect(route('admin.blogs.index'));

        Storage::disk('public')->assertExists($path);
    }

    public function test_destroy_deletes_unreferenced_media_content_images(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = $this->fakeBlogUpload('inline-media-cleanup.webp')->store('blogs/content', 'public');

        $blog = Blog::factory()->create([
            'title' => 'Media Cleanup Post',
            'slug' => 'media-cleanup-post',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'created_by' => $admin->id,
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Media Cleanup Post',
            'slug' => 'media-cleanup-post',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'is_published' => true,
        ]);

        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->delete(route('admin.blogs.destroy', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_filter_blogs_by_search_and_set_author(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'draft',
            'author' => 'Ada Lovelace',
            'translations' => [
                'en' => [
                    'title' => 'Unique Filter Title XYZ',
                    'slug' => 'unique-filter-title-xyz',
                    'content' => '<p>Body</p>',
                ],
            ],
        ]);
        $created = Blog::query()->where('slug', 'unique-filter-title-xyz')->firstOrFail();
        $response->assertRedirect(route('admin.blogs.edit', $created->id));

        $this->actingAs($admin)
            ->get(route('admin.blogs.index', ['q' => 'Unique Filter Title XYZ', 'status' => 'draft', 'kind' => 'custom']))
            ->assertOk()
            ->assertSee('Unique Filter Title XYZ', false)
            ->assertSee('Ada Lovelace', false);

        $this->assertDatabaseHas('blogs', [
            'slug' => 'unique-filter-title-xyz',
            'author' => 'Ada Lovelace',
        ]);
    }

    public function test_invalid_editor_image_upload_returns_422(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson(route('admin.blogs.upload-image'), [
                'image' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
