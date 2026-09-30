<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use App\Services\SiteEnrichment\ImageOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class AdminBlogImageTest extends TestCase
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

    public function test_admin_can_upload_content_image_via_editor_endpoint(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.blogs.upload-image'), [
                'image' => $this->fakeBlogUpload('inline.jpg', 640, 360),
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/media/blogs/content/', $url);

        $relative = $this->blogDiskPathFromUrl($url);
        $this->assertNotSame('', $relative);
        Storage::disk('public')->assertExists($relative);
        if (ImageOptimizationService::canEncodeWebp()) {
            $this->assertStringEndsWith('.webp', $relative);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($relative));
        } else {
            $this->assertMatchesRegularExpression('/\.(jpe?g|png)$/i', $relative);
        }
    }

    public function test_editor_gif_upload_stays_gif_and_uses_media_url(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.blogs.upload-image'), [
                'image' => $this->fakeBlogUpload('inline.gif', 32, 32),
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/media/blogs/content/', $url);

        $relative = $this->blogDiskPathFromUrl($url);
        $this->assertStringEndsWith('.gif', $relative);
        Storage::disk('public')->assertExists($relative);
    }

    public function test_admin_can_remove_featured_image_on_update(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = $this->fakeBlogUpload('featured.jpg')->store('blogs/featured', 'public');
        Storage::disk('public')->assertExists($path);

        $blog = Blog::create([
            'title' => 'Image Manage Post',
            'slug' => 'image-manage-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body with text.</p>',
            'status' => 'published',
            'published_at' => now(),
            'featured_image' => $path,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'title' => 'Image Manage Post',
                'excerpt' => 'Excerpt',
                'content' => '<p>Body with text.</p>',
                'status' => 'published',
                'remove_featured_image' => '1',
            ])
            ->assertRedirect(route('admin.blogs.index'));

        $blog->refresh();
        $this->assertNull($blog->featured_image);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_replace_featured_image_on_update(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $oldPath = $this->fakeBlogUpload('old-featured.jpg')->store('blogs/featured', 'public');

        $blog = Blog::create([
            'title' => 'Replace Featured Post',
            'slug' => 'replace-featured-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body with text.</p>',
            'status' => 'draft',
            'featured_image' => $oldPath,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $update = $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'title' => 'Replace Featured Post',
                'excerpt' => 'Excerpt',
                'content' => '<p>Body with text.</p>',
                'status' => 'draft',
                'featured_image' => $this->fakeBlogUpload('new-featured.jpg', 800, 450),
            ]);

        $update->assertRedirect(route('admin.blogs.index'));

        $blog->refresh();
        $this->assertNotNull($blog->featured_image);
        $this->assertNotSame($oldPath, $blog->featured_image);
        $this->assertStringStartsWith('blogs/featured/', $blog->featured_image);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($blog->featured_image);
        if (ImageOptimizationService::canEncodeWebp()) {
            $this->assertStringEndsWith('.webp', $blog->featured_image);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($blog->featured_image));
        } else {
            $this->assertMatchesRegularExpression('/\.(jpe?g|png)$/i', (string) $blog->featured_image);
        }

        $this->actingAs($admin)
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->assertSee('/media/'.$blog->featured_image, false);
    }

    public function test_admin_edit_page_wires_quill_upload_and_remove_featured_controls(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::create([
            'title' => 'Edit Controls Post',
            'slug' => 'edit-controls-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Hello</p><p><img src="/storage/blogs/content/demo.jpg"></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $editHtml = $this->actingAs($admin)
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->assertSee('admin\/blogs\/upload-image', false)
            ->assertSee('admin\/blogs\/content-image', false)
            ->assertSee('remove_featured_image', false)
            ->assertSee('featuredImageRemoveBtn', false)
            ->assertSee('quillImageInput', false)
            ->assertSee('articleImagesManager', false)
            ->assertSee('Article images', false)
            ->assertSee('admin-blog-images.js', false)
            ->assertSee('/media/blogs/content/demo.jpg', false)
            ->assertDontSee('/storage/blogs/content/demo.jpg', false)
            ->getContent();
        $this->assertSame(1, substr_count($editHtml, 'new Quill('));

        $create = $this->actingAs($admin)
            ->get(route('admin.blogs.create'))
            ->assertOk()
            ->assertSee('admin\/blogs\/upload-image', false)
            ->assertSee('articleImagesManager', false)
            ->assertSee('quillImageInput', false);

        $this->assertSame(1, substr_count($create->getContent(), 'new Quill('));
    }

    public function test_admin_can_delete_stored_blog_content_image(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = $this->fakeBlogUpload('inline-delete.jpg')->store('blogs/content', 'public');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/storage/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_cannot_delete_bundled_curated_image(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = 'blogs/content/gastbeitraege-europa-sprachen.jpg';
        Storage::disk('public')->put($path, 'bundled-bytes');

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/media/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_cannot_delete_image_still_referenced_by_a_post(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = $this->fakeBlogUpload('shared-inline.jpg')->store('blogs/content', 'public');

        Blog::create([
            'title' => 'Still Uses Image',
            'slug' => 'still-uses-image',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/media/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_cannot_delete_image_outside_blog_storage(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = $this->fakeBlogUpload('other.jpg')->store('uploads/other', 'public');

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/storage/'.$path,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_can_delete_content_image_via_media_url(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = $this->fakeBlogUpload('inline-media.webp')->store('blogs/content', 'public');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/media/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_delete_content_image_via_absolute_media_url_with_query(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = $this->fakeBlogUpload('inline-abs.webp')->store('blogs/content', 'public');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => 'https://example.test/media/'.$path.'?cache=1#preview',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_cannot_delete_content_image_via_encoded_traversal(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = $this->fakeBlogUpload('keep.webp')->store('blogs/content', 'public');

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/media/blogs/content/%2e%2e/'.$path,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_blog_images_js_deletes_media_and_storage_urls(): void
    {
        $js = file_get_contents(public_path('assets/js/admin-blog-images.js'));

        $this->assertIsString($js);
        $this->assertStringContainsString('/media/blogs/', $js);
        $this->assertStringContainsString('/storage/blogs/', $js);
        $this->assertStringContainsString('isStoredBlogImageSrc', $js);
        $this->assertStringNotContainsString('maybeDeleteStoredFile(target.src)', $js);
        $this->assertStringNotContainsString('maybeDeleteStoredFile(src)', $js);
    }

    public function test_update_deletes_removed_inline_image_only_after_save(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = $this->fakeBlogUpload('will-remove.jpg')->store('blogs/content', 'public');

        $blog = Blog::create([
            'title' => 'Inline Cleanup On Save',
            'slug' => 'inline-cleanup-on-save',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $blog->translations()->create([
            'locale' => 'en',
            'title' => 'Inline Cleanup On Save',
            'slug' => 'inline-cleanup-on-save',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'is_published' => true,
        ]);

        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => 'Inline Cleanup On Save',
                        'slug' => 'inline-cleanup-on-save',
                        'content' => '<p>Image removed from the body.</p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.index'));

        Storage::disk('public')->assertMissing($path);
    }

    public function test_update_deletes_legacy_asset_inline_image_after_save(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = 'blogs/content/custom-orphan.jpg';
        Storage::disk('public')->put($path, 'orphan');

        $blog = Blog::create([
            'title' => 'Legacy Asset Cleanup',
            'slug' => 'legacy-asset-cleanup',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/assets/img/blog/custom-orphan.jpg" alt="A"></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $blog->translations()->create([
            'locale' => 'en',
            'title' => 'Legacy Asset Cleanup',
            'slug' => 'legacy-asset-cleanup',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/assets/img/blog/custom-orphan.jpg" alt="A"></p>',
            'is_published' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => 'Legacy Asset Cleanup',
                        'slug' => 'legacy-asset-cleanup',
                        'content' => '<p>Image removed from the body.</p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.index'));

        Storage::disk('public')->assertMissing($path);
    }

    public function test_featured_image_url_rewrites_absolute_storage_urls(): void
    {
        $blog = new Blog([
            'featured_image' => 'https://example.test/storage/blogs/featured/hero.jpg?cache=1',
        ]);

        $this->assertSame('/media/blogs/featured/hero.jpg', $blog->featuredImageUrl());
    }

    public function test_featured_image_url_rewrites_legacy_asset_paths(): void
    {
        Storage::fake('public');

        $blog = new Blog([
            'featured_image' => '/assets/img/blog/gastbeitraege-europa-featured.jpg',
        ]);

        $this->assertSame(
            '/media/blogs/featured/gastbeitraege-europa-featured.jpg',
            $blog->featuredImageUrl()
        );
        $this->assertNull($blog->publicFeaturedImageUrl());

        Storage::disk('public')->put('blogs/content/gastbeitraege-europa-featured.jpg', 'content-copy');
        $this->assertSame(
            '/media/blogs/content/gastbeitraege-europa-featured.jpg',
            $blog->featuredImageUrl()
        );
        $this->assertSame(
            '/media/blogs/content/gastbeitraege-europa-featured.jpg',
            $blog->publicFeaturedImageUrl()
        );

        Storage::disk('public')->put('blogs/featured/gastbeitraege-europa-featured.jpg', 'featured-copy');
        $this->assertSame(
            '/media/blogs/featured/gastbeitraege-europa-featured.jpg',
            $blog->featuredImageUrl()
        );
    }

    public function test_store_persists_featured_jpeg_and_converts_to_webp_when_gd_can(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $store = $this->actingAs($admin)
            ->post(route('admin.blogs.store'), [
                'status' => 'draft',
                'featured_image' => $this->fakeBlogUpload('hero.jpg', 800, 450),
                'translations' => [
                    'en' => [
                        'title' => 'WebP Featured Post',
                        'slug' => 'webp-featured-post',
                        'content' => '<p>Body with text.</p>',
                    ],
                ],
            ]);

        $blog = Blog::query()->where('slug', 'webp-featured-post')->first();
        $this->assertNotNull($blog);
        $store->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertNotNull($blog->featured_image);
        $this->assertStringStartsWith('blogs/featured/', $blog->featured_image);
        Storage::disk('public')->assertExists($blog->featured_image);
        $this->assertSame('/media/'.$blog->featured_image, $blog->featuredImageUrl());
        if (ImageOptimizationService::canEncodeWebp()) {
            $this->assertStringEndsWith('.webp', $blog->featured_image);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($blog->featured_image));
        } else {
            $this->assertMatchesRegularExpression('/\.(jpe?g|png)$/i', (string) $blog->featured_image);
        }
    }

    private function blogDiskPathFromUrl(string $url): string
    {
        $relative = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
        $relative = preg_replace('#^(storage|media)/#', '', $relative) ?: '';

        return $relative;
    }
}
