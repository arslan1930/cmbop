<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminBlogCreateFlowTest extends TestCase
{
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

    public function test_create_form_chrome_and_copy(): void
    {
        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('admin-page-header', $html);
        $this->assertStringContainsString('English (UK) — default canonical', $html);
        $this->assertStringContainsString('Save draft', $html);
        $this->assertStringContainsString('Publish', $html);
        $this->assertStringContainsString('This goes live on the public blog.', $html);
        $this->assertStringContainsString('/blog/', $html);
        $this->assertStringContainsString('data-seo-count', $html);
        $this->assertStringContainsString('data-blog-unsaved-guard="1"', $html);
        $this->assertStringNotContainsString('Auto (current URL locale)', $html);
    }

    public function test_create_cancel_keeps_list_query(): void
    {
        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.create', ['status' => 'draft', 'q' => 'pillar']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('status=draft', $html);
        $this->assertStringContainsString('q=pillar', $html);
    }

    public function test_store_without_intent_saves_as_draft_and_opens_edit(): void
    {
        $response = $this->actingAs($this->adminUser())->post(route('admin.blogs.store'), [
            'translations' => [
                'en' => [
                    'title' => 'Draft From Create',
                    'slug' => 'draft-from-create',
                    'content' => '<p>Draft body</p>',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'draft-from-create')->first();
        $this->assertNotNull($blog);
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertSame('draft', $blog->status);
        $this->assertNull($blog->published_at);
        $this->assertNull($blog->primary_locale);
    }

    public function test_store_with_publish_intent_goes_live(): void
    {
        $response = $this->actingAs($this->adminUser())->post(route('admin.blogs.store'), [
            'intent' => 'publish',
            'translations' => [
                'en' => [
                    'title' => 'Live From Create',
                    'slug' => 'live-from-create',
                    'content' => '<p>Live body</p>',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'live-from-create')->first();
        $this->assertNotNull($blog);
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertSame('published', $blog->status);
        $this->assertNotNull($blog->published_at);
    }

    public function test_empty_primary_locale_stays_english_canonical(): void
    {
        $this->actingAs($this->adminUser())->post(route('admin.blogs.store'), [
            'primary_locale' => '',
            'intent' => 'publish',
            'translations' => [
                'en' => [
                    'title' => 'Canonical English',
                    'slug' => 'canonical-english',
                    'content' => '<p>Body</p>',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'canonical-english')->firstOrFail();
        $this->assertNull($blog->primary_locale);
        $this->assertSame('canonical-english', $blog->slug);
    }

    public function test_failed_store_with_featured_image_asks_to_reselect(): void
    {
        Storage::fake('public');

        $this->actingAs($this->adminUser())
            ->from(route('admin.blogs.create'))
            ->post(route('admin.blogs.store'), [
                'featured_image' => UploadedFile::fake()->image('hero.jpg', 800, 450),
                'translations' => [
                    'en' => [
                        'title' => '',
                        'content' => '<p>Keep this</p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.create'))
            ->assertSessionHasErrors()
            ->assertSessionHas('warning', 'Choose the featured image again.');

        $this->assertSame([], Storage::disk('public')->allFiles('blogs/featured'));
    }
}
