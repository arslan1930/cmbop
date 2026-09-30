<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBlogLocaleTabsTest extends TestCase
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

    public function test_create_form_starts_with_english_and_add_locale(): void
    {
        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('locale-pane-en', $html);
        $this->assertStringContainsString('UK', $html);
        $this->assertStringContainsString('Add locale', $html);
        $this->assertStringNotContainsString('id="locale-pane-de"', $html);
        $this->assertStringNotContainsString('id="quillEditor-de"', $html);

        $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.create', ['add_locale' => 'es']))
            ->assertOk()
            ->assertSee('id="locale-pane-es"', false)
            ->assertSee('id="quillEditor-es"', false);
    }

    public function test_edit_form_shows_existing_locales_not_every_supported_tab(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'EN only edit',
            'slug' => 'en-only-edit',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'EN only edit',
            'slug' => 'en-only-edit',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('locale-pane-en', $html);
        $this->assertStringNotContainsString('id="locale-pane-fr"', $html);
        foreach (PublicI18n::supported() as $locale) {
            $this->assertStringContainsString('value="'.$locale.'"', $html);
        }
    }

    public function test_admin_can_save_spanish_and_us_translations(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->post(route('admin.blogs.store'), [
                'status' => 'published',
                'primary_locale' => 'es',
                'translations' => [
                    'en' => [
                        'title' => 'UK English title',
                        'slug' => 'uk-english-title',
                        'excerpt' => 'UK excerpt',
                        'content' => '<p>UK body</p>',
                    ],
                    'es' => [
                        'title' => 'Título en español',
                        'slug' => 'titulo-en-espanol',
                        'excerpt' => 'Extracto',
                        'content' => '<p>Cuerpo en español</p>',
                    ],
                    'us' => [
                        'title' => 'US English title',
                        'slug' => 'us-english-title',
                        'excerpt' => 'US excerpt',
                        'content' => '<p>US body</p>',
                    ],
                ],
            ]);

        $blog = Blog::query()->where('title', 'UK English title')->first();
        $this->assertNotNull($blog);
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertSame('es', $blog->primary_locale);
        $this->assertSame('titulo-en-espanol', $blog->slug);

        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'es',
            'title' => 'Título en español',
            'slug' => 'titulo-en-espanol',
        ]);
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'us',
            'title' => 'US English title',
        ]);

        $this->assertSame(3, BlogTranslation::query()->where('blog_id', $blog->id)->count());
    }

    public function test_empty_quill_tabs_are_ignored_so_english_only_save_works(): void
    {
        $admin = $this->adminUser();

        $translations = [
            'en' => [
                'title' => 'English only post',
                'slug' => 'english-only-post',
                'excerpt' => 'Excerpt',
                'content' => '<p>English body</p>',
            ],
        ];
        foreach (['de', 'fr', 'nl', 'es', 'it', 'us'] as $locale) {
            $translations[$locale] = [
                'title' => '',
                'slug' => '',
                'excerpt' => '',
                'content' => '<p><br></p>',
            ];
        }

        $response = $this->actingAs($admin)
            ->post(route('admin.blogs.store'), [
                'status' => 'published',
                'translations' => $translations,
            ]);

        $blog = Blog::query()->where('slug', 'english-only-post')->first();
        $this->assertNotNull($blog);
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertSame(['en'], $blog->translations()->pluck('locale')->all());
    }

    public function test_incomplete_locales_filter_ignores_unadded_languages(): void
    {
        $admin = $this->adminUser();
        $complete = Blog::factory()->published()->create([
            'title' => 'Complete EN DE',
            'slug' => 'complete-en-de',
        ]);
        BlogTranslation::create([
            'blog_id' => $complete->id,
            'locale' => 'en',
            'title' => 'Complete EN DE',
            'slug' => 'complete-en-de',
            'content' => '<p>EN</p>',
            'is_published' => true,
        ]);
        BlogTranslation::create([
            'blog_id' => $complete->id,
            'locale' => 'de',
            'title' => 'Komplett',
            'slug' => 'komplett',
            'content' => '<p>DE</p>',
            'is_published' => true,
        ]);

        $draftDe = Blog::factory()->published()->create([
            'title' => 'Draft DE locale',
            'slug' => 'draft-de-locale',
        ]);
        BlogTranslation::create([
            'blog_id' => $draftDe->id,
            'locale' => 'en',
            'title' => 'Draft DE locale',
            'slug' => 'draft-de-locale',
            'content' => '<p>EN</p>',
            'is_published' => true,
        ]);
        BlogTranslation::create([
            'blog_id' => $draftDe->id,
            'locale' => 'de',
            'title' => 'Entwurf',
            'slug' => 'entwurf',
            'content' => '<p>DE</p>',
            'is_published' => false,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.blogs.index', ['missing_translations' => 1, 'kind' => 'custom']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Draft DE locale', $html);
        $this->assertStringNotContainsString('Complete EN DE', $html);
    }

    public function test_index_ignores_junk_filter_arrays(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.index', [
                'q' => ['seo'],
                'status' => ['draft'],
                'missing_translations' => ['1'],
                'sort' => ['published'],
            ]))
            ->assertOk();
    }

    public function test_staff_can_preview_a_draft_locale_and_guests_cannot(): void
    {
        $blog = Blog::factory()->create([
            'title' => 'Preview draft',
            'slug' => 'preview-draft',
            'status' => 'draft',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Preview draft',
            'slug' => 'preview-draft',
            'content' => '<p>Secret draft body</p>',
            'is_published' => false,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.preview', ['id' => $blog->id, 'locale' => 'en']))
            ->assertOk()
            ->assertSee('Secret draft body', false)
            ->assertSee('Staff preview only', false);

        $this->get(route('admin.blogs.preview', $blog->id))
            ->assertRedirect(route('login'));
    }
}
