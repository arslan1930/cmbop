<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use App\Services\CuratedBlogSync;
use App\Support\BlogTranslationSlug;
use App\Support\GastbeitraegeEuropaBlogPost;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlogTranslationFeatureTest extends TestCase
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

    public function test_locale_slug_renders_translated_post(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-title',
            'content' => '<p>English body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-titel',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/de/blog/deutscher-titel')
            ->assertOk()
            ->assertSee('Deutscher Titel', false)
            ->assertSee('Deutscher Inhalt', false)
            ->assertSee('hreflang="de"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="en-GB" href="'.url('/blog/english-title').'"', false)
            ->assertSee('hreflang="de" href="'.url('/de/blog/deutscher-titel').'"', false)
            ->assertDontSee('hreflang="en-GB" href="'.url('/blog/deutscher-titel').'"', false);
    }

    public function test_english_blog_slug_redirects_to_localized_slug(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-title',
            'content' => '<p>English body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-titel',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/de/blog/english-title')
            ->assertRedirect(url('/de/blog/deutscher-titel'));

        $this->followingRedirects()
            ->get('/de/blog/english-title')
            ->assertOk()
            ->assertSee('Deutscher Titel', false);
    }

    public function test_language_switcher_on_post_uses_target_locale_slug(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-title',
            'content' => '<p>English body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-titel',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->assertSame(
            url('/de/blog/deutscher-titel'),
            PublicI18n::urlForLocale(PublicI18n::blogPathForLocale('english-title', 'de'), 'de')
        );

        $this->get('/blog/english-title')
            ->assertOk()
            ->assertSee(url('/de/blog/deutscher-titel'), false)
            ->assertDontSee(url('/de/blog/english-title'), false);
    }

    public function test_language_switcher_on_untranslated_post_goes_to_locale_blog_index(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-title',
            'content' => '<p>English body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        $this->assertSame('blog', PublicI18n::blogPathForLocale('english-title', 'de'));

        $this->get('/blog/english-title')
            ->assertOk()
            ->assertSee(url('/de/blog'), false)
            ->assertDontSee(url('/de/blog/english-title'), false);
    }

    public function test_copied_translation_slugs_are_localized_from_title(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-title',
            'content' => '<p>English body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'english-title-de',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->assertTrue(BlogTranslationSlug::hasCopiedSlugs());
        $this->assertSame(1, BlogTranslationSlug::localizeCopiedSlugs());
        $this->assertSame(
            'deutscher-titel',
            $blog->translations()->where('locale', 'de')->value('slug')
        );
        $this->assertSame(
            'english-title',
            $blog->translations()->where('locale', 'en')->value('slug')
        );
        $this->assertFalse(BlogTranslationSlug::hasCopiedSlugs());
    }

    public function test_show_resolves_translation_slug_when_blogs_slug_was_renamed(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'renamed-pillar-slug',
            'content' => '<p>English body</p>',
            'primary_locale' => 'de',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title-renamed-pillar',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-titel-renamed-pillar',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/de/blog/deutscher-titel-renamed-pillar')
            ->assertOk()
            ->assertSee('Deutscher Titel', false)
            ->assertSee('Deutscher Inhalt', false);

        $this->get('/blog/deutscher-titel-renamed-pillar')
            ->assertRedirect(url('/blog/english-title-renamed-pillar'));

        $this->followingRedirects()
            ->get('/blog/deutscher-titel-renamed-pillar')
            ->assertOk()
            ->assertSee('English title', false)
            ->assertSee('English body', false);

        $this->get('/blog/renamed-pillar-slug')
            ->assertRedirect(url('/blog/english-title-renamed-pillar'));
    }

    public function test_missing_locale_translation_falls_back_to_english_notice(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-fallback',
            'content' => '<p>English body</p>',
            'primary_locale' => 'en',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-fallback',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        $this->get('/de/blog/english-fallback')
            ->assertRedirect(url('/blog/english-fallback'));
        $this->assertSame(301, $this->get('/de/blog/english-fallback')->status());
    }

    public function test_canonical_url_falls_back_to_primary_locale_when_english_is_missing(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English leftover title',
            'slug' => 'english-leftover-canonical',
            'content' => '<p>English leftover body</p>',
            'primary_locale' => 'de',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-canonical-titel',
            'excerpt' => 'Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->assertSame(
            url('/de/blog/deutscher-canonical-titel'),
            $blog->canonicalUrl('en')
        );
    }

    public function test_admin_can_create_english_only_post_when_other_locales_submit_empty_quill_html(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'English Only Title',
                    'slug' => 'english-only-title',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => '',
                    'slug' => '',
                    'excerpt' => '',
                    'content' => '<p><br></p>',
                ],
                'fr' => [
                    'title' => '',
                    'slug' => '',
                    'excerpt' => '',
                    'content' => '<p></p>',
                ],
                'nl' => [
                    'title' => '',
                    'slug' => '',
                    'excerpt' => '',
                    'content' => '',
                ],
            ],
        ]);
        $blog = Blog::query()->where('slug', 'english-only-title')->firstOrFail();
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English Only Title',
        ]);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de']);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'fr']);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'nl']);
    }

    public function test_admin_can_update_english_only_post_when_other_locales_submit_empty_quill_html(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::factory()->published()->create([
            'title' => 'Existing English',
            'slug' => 'existing-english',
            'content' => '<p>Original</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Existing English',
            'slug' => 'existing-english',
            'excerpt' => 'Excerpt',
            'content' => '<p>Original</p>',
            'is_published' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Existing English',
                    'slug' => 'existing-english',
                    'excerpt' => 'Excerpt',
                    'content' => '<p>Updated body</p>',
                ],
                'de' => [
                    'title' => '',
                    'content' => '<p><br></p>',
                ],
                'fr' => [
                    'title' => '',
                    'content' => '<p></p>',
                ],
                'nl' => [
                    'title' => '',
                    'content' => '',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'content' => '<p>Updated body</p>',
        ]);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de']);
    }

    public function test_create_page_hydrates_quill_from_old_input_and_escapes_script_tags(): void
    {
        $admin = $this->adminUser();

        $html = $this->actingAs($admin)
            ->from(route('admin.blogs.create'))
            ->followingRedirects()
            ->post(route('admin.blogs.store'), [
                'status' => 'published',
                'translations' => [
                    'en' => [
                        'title' => '',
                        'content' => '<p>Keep this body</p></script><script>alert(1)</script>',
                    ],
                ],
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="existingContent-en"', $html);
        $this->assertStringContainsString('Keep this body', $html);
        $this->assertStringContainsString('\\u003Cp\\u003E', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
    }

    public function test_edit_page_escapes_script_tags_in_existing_content_json(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::factory()->create([
            'title' => 'XSS Edit',
            'slug' => 'xss-edit',
            'content' => '<p>Safe</p></script><script>alert(1)</script>',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'XSS Edit',
            'slug' => 'xss-edit',
            'excerpt' => 'Excerpt',
            'content' => '<p>Safe</p></script><script>alert(1)</script>',
            'is_published' => true,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="existingContent-en"', $html);
        $this->assertStringContainsString('\\u003Cp\\u003E', $html);
        $this->assertStringContainsString('Safe', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString(
            'id="existingContent-en"><p>Safe</p></script><script>alert(1)</script>',
            $html
        );
    }

    public function test_admin_persists_meta_and_can_unpublish_or_remove_a_locale(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Meta English',
                    'slug' => 'meta-english',
                    'excerpt' => 'English excerpt',
                    'meta_title' => 'Custom EN title',
                    'meta_description' => 'Custom EN description',
                    'content' => '<p>English body</p>',
                    'is_published' => '1',
                ],
                'de' => [
                    'title' => 'Meta Deutsch',
                    'slug' => 'meta-deutsch',
                    'excerpt' => 'Deutscher Auszug',
                    'content' => '<p>Deutscher Inhalt</p>',
                    'is_published' => '1',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'meta-english')->firstOrFail();
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'meta_title' => 'Custom EN title',
            'meta_description' => 'Custom EN description',
        ]);

        $this->get('/blog/meta-english')
            ->assertOk()
            ->assertSee('Custom EN title — SEOLinkBuildings', false)
            ->assertSee('Custom EN description', false);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Meta English',
                    'slug' => 'meta-english',
                    'excerpt' => 'English excerpt',
                    'meta_title' => 'Custom EN title',
                    'meta_description' => 'Custom EN description',
                    'content' => '<p>English body</p>',
                    'is_published' => '1',
                ],
                'de' => [
                    'title' => 'Meta Deutsch',
                    'slug' => 'meta-deutsch',
                    'excerpt' => 'Deutscher Auszug',
                    'content' => '<p>Deutscher Inhalt</p>',
                    'is_published' => '0',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'de',
            'is_published' => false,
        ]);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Meta English',
                    'slug' => 'meta-english',
                    'content' => '<p>English body</p>',
                    'is_published' => '1',
                ],
                'de' => [
                    'title' => '',
                    'content' => '<p><br></p>',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseMissing('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'de',
        ]);
    }

    public function test_admin_index_links_published_posts_to_the_live_url(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('admin.blogs.index'))
            ->assertOk()
            ->assertSee('View live', false)
            ->assertSee('fa-external-link', false);
    }

    public function test_admin_can_create_and_update_translations_without_overwriting_others(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'English Admin Title',
                    'slug' => 'english-admin-title',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => 'Deutscher Admin Titel',
                    'slug' => 'deutscher-admin-titel',
                    'excerpt' => 'Deutscher Auszug',
                    'content' => '<p>Deutscher Inhalt</p>',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'english-admin-title')->firstOrFail();
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'en', 'title' => 'English Admin Title']);
        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de', 'title' => 'Deutscher Admin Titel']);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'English Admin Title',
                    'slug' => 'english-admin-title',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => 'Geaenderter Deutscher Titel',
                    'slug' => 'geaenderter-deutscher-titel',
                    'excerpt' => 'Neuer Auszug',
                    'content' => '<p>Aktualisierter Inhalt</p>',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'en', 'title' => 'English Admin Title']);
        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de', 'title' => 'Geaenderter Deutscher Titel']);
    }

    public function test_store_uses_primary_locale_slug_as_public_fallback(): void
    {
        $response = $this->actingAs($this->adminUser())->post(route('admin.blogs.store'), [
            'status' => 'published',
            'primary_locale' => 'de',
            'translations' => [
                'en' => [
                    'title' => 'English Primary Slug Post',
                    'slug' => 'english-primary-slug-post',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => 'Deutscher Primary Slug Beitrag',
                    'slug' => 'deutscher-primary-slug-beitrag',
                    'excerpt' => 'Deutscher Auszug',
                    'content' => '<p>Deutscher Inhalt</p>',
                ],
            ],
        ]);

        $blog = Blog::query()->where('slug', 'deutscher-primary-slug-beitrag')->firstOrFail();
        $response->assertRedirect(route('admin.blogs.edit', $blog->id));
        $this->assertSame('de', $blog->primary_locale);
        $this->assertSame('english-primary-slug-post', $blog->translations()->where('locale', 'en')->value('slug'));
        $this->assertSame('deutscher-primary-slug-beitrag', $blog->translations()->where('locale', 'de')->value('slug'));
    }

    public function test_update_keeps_de_primary_slug_instead_of_replacing_it_with_english(): void
    {
        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $catalog = GastbeitraegeEuropaBlogPost::SLUG;
        $blog = Blog::query()->where('curated_key', $catalog)->orWhere('slug', $catalog)->firstOrFail();

        $this->actingAs($this->adminUser())->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'primary_locale' => 'de',
            'translations' => [
                'en' => [
                    'title' => 'Buy guest posts in Europe',
                    'slug' => 'buy-guest-posts-in-europe-admin-save',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => (string) $blog->title,
                    'slug' => $catalog,
                    'excerpt' => (string) $blog->excerpt,
                    'content' => (string) $blog->content,
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $blog->refresh();
        $this->assertSame($catalog, $blog->slug);
        $this->assertSame('buy-guest-posts-in-europe-admin-save', $blog->translations()->where('locale', 'en')->value('slug'));
        $this->assertSame($catalog, $blog->translations()->where('locale', 'de')->value('slug'));
    }

    public function test_update_rejects_clearing_the_primary_locale_translation(): void
    {
        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $catalog = GastbeitraegeEuropaBlogPost::SLUG;
        $blog = Blog::query()->where('curated_key', $catalog)->orWhere('slug', $catalog)->firstOrFail();

        $this->actingAs($this->adminUser())
            ->from(route('admin.blogs.edit', $blog->id))
            ->put(route('admin.blogs.update', $blog->id), [
                'status' => 'published',
                'primary_locale' => 'de',
                'translations' => [
                    'en' => [
                        'title' => 'Buy guest posts in Europe',
                        'slug' => 'buy-guest-posts-in-europe-cleared-de',
                        'excerpt' => 'English excerpt',
                        'content' => '<p>English body</p>',
                    ],
                    'de' => [
                        'title' => '',
                        'slug' => '',
                        'excerpt' => '',
                        'content' => '<p><br></p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.edit', $blog->id))
            ->assertSessionHasErrors('translations.de.title');

        $blog->refresh();
        $this->assertSame($catalog, $blog->slug);
        $this->assertTrue($blog->translations()->where('locale', 'de')->exists());
    }

    public function test_public_blog_heals_missing_translations_table(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Heal Me Post',
            'slug' => 'heal-me-post',
            'excerpt' => 'Excerpt for heal',
            'content' => '<p>Heal body</p>',
            'primary_locale' => 'en',
            'published_at' => now(),
        ]);

        Schema::dropIfExists('blog_translations');
        $this->assertFalse(Schema::hasTable('blog_translations'));

        // Index auto-syncs curated posts; keep Heal Me newest so it stays on page 1.
        $response = $this->get('/blog')->assertOk();
        Blog::query()->where('id', '!=', $blog->id)->update(['published_at' => now()->subDay()]);
        $blog->update(['published_at' => now()]);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('Heal Me Post', false);
        unset($response);

        $this->assertTrue(Schema::hasTable('blog_translations'));
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'slug' => 'heal-me-post',
        ]);

        $this->get('/blog/heal-me-post')
            ->assertOk()
            ->assertSee('Heal Me Post', false);

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertSee('/blog/heal-me-post', false);
    }

    public function test_translation_backfill_does_not_steal_another_blogs_public_slug(): void
    {
        $other = Blog::factory()->published()->create([
            'title' => 'Other Host',
            'slug' => 'other-heal-host',
            'content' => '<p>Other body</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $other->id,
            'locale' => 'en',
            'title' => 'Other Host',
            'slug' => 'hello-heal-base',
            'excerpt' => 'Excerpt',
            'content' => '<p>Other body</p>',
            'is_published' => true,
        ]);

        $older = Blog::factory()->published()->create([
            'title' => 'Older Heal Post',
            'slug' => 'hello-heal-base',
            'content' => '<p>Older body</p>',
        ]);
        $newer = Blog::factory()->published()->create([
            'title' => 'Newer Heal Post',
            'slug' => 'hello-heal-base-1',
            'content' => '<p>Newer body</p>',
        ]);

        CuratedBlogSync::backfillTranslationsFromBlogs();

        $this->assertNotSame(
            'hello-heal-base-1',
            $older->translations()->where('locale', 'en')->value('slug')
        );

        $html = $this->get('/blog/hello-heal-base-1')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Newer Heal Post\s*<\/h1>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Older Heal Post\s*<\/h1>/', $html);
    }

    public function test_sitemap_contains_only_localized_blog_urls(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Localized Post',
            'slug' => 'localized-post',
            'content' => '<p>Body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Localized Post',
            'slug' => 'localized-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Lokalisierter Beitrag',
            'slug' => 'lokalisierter-beitrag',
            'excerpt' => 'Auszug',
            'content' => '<p>Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertSee('/blog/localized-post', false)
            ->assertSee('/de/blog/lokalisierter-beitrag', false)
            ->assertDontSee('/de/blog/localized-post', false);

        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertSee('/de/blog/lokalisierter-beitrag', false)
            ->assertSee('/blog/localized-post', false)
            ->assertDontSee('/de/blog/localized-post', false);
    }

    public function test_public_index_lists_only_published_translation_for_selected_locale(): void
    {
        CuratedBlogSync::ensurePresent();
        Blog::query()->update(['published_at' => now()->subDay()]);

        $english = Blog::factory()->published()->create([
            'title' => 'English Index Only Title',
            'slug' => 'english-index-only',
            'published_at' => now(),
        ]);
        BlogTranslation::create([
            'blog_id' => $english->id,
            'locale' => 'en',
            'title' => 'English Index Only Title',
            'slug' => 'english-index-only',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        $german = Blog::factory()->published()->create([
            'title' => 'German leftover index title',
            'slug' => 'german-index-only',
            'primary_locale' => 'de',
            'published_at' => now(),
        ]);
        BlogTranslation::create([
            'blog_id' => $german->id,
            'locale' => 'de',
            'title' => 'Deutscher Index Only Titel',
            'slug' => 'deutscher-index-only',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $bilingual = Blog::factory()->published()->create([
            'title' => 'Bilingual English Index Title',
            'slug' => 'bilingual-index-post',
            'published_at' => now(),
        ]);
        BlogTranslation::create([
            'blog_id' => $bilingual->id,
            'locale' => 'en',
            'title' => 'Bilingual English Index Title',
            'slug' => 'bilingual-index-post',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);
        BlogTranslation::create([
            'blog_id' => $bilingual->id,
            'locale' => 'de',
            'title' => 'Zweisprachiger Index Titel',
            'slug' => 'zweisprachiger-index-beitrag',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('English Index Only Title', false)
            ->assertSee('Bilingual English Index Title', false)
            ->assertDontSee('Deutscher Index Only Titel', false)
            ->assertDontSee('German leftover index title', false)
            ->assertDontSee('Zweisprachiger Index Titel', false);

        $this->get('/de/blog')
            ->assertOk()
            ->assertSee('Deutscher Index Only Titel', false)
            ->assertSee('Zweisprachiger Index Titel', false)
            ->assertDontSee('English Index Only Title', false)
            ->assertDontSee('Bilingual English Index Title', false);
    }

    public function test_related_posts_list_only_the_requested_locale(): void
    {
        CuratedBlogSync::ensurePresent();
        Blog::query()->update(['published_at' => now()->subDay()]);

        $shown = Blog::factory()->published()->create([
            'title' => 'English Shown Related Host',
            'slug' => 'english-shown-related-host',
            'published_at' => now(),
        ]);
        BlogTranslation::create([
            'blog_id' => $shown->id,
            'locale' => 'en',
            'title' => 'English Shown Related Host',
            'slug' => 'english-shown-related-host',
            'excerpt' => 'Excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        $german = Blog::factory()->published()->create([
            'title' => 'German leftover related title',
            'slug' => 'german-related-only',
            'primary_locale' => 'de',
            'published_at' => now()->subSecond(),
        ]);
        BlogTranslation::create([
            'blog_id' => $german->id,
            'locale' => 'de',
            'title' => 'Deutscher Related Hidden Titel',
            'slug' => 'deutscher-related-hidden',
            'excerpt' => 'Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $related = Blog::factory()->published()->create([
            'title' => 'English Related Visible Title',
            'slug' => 'english-related-visible',
            'published_at' => now()->subSeconds(2),
        ]);
        BlogTranslation::create([
            'blog_id' => $related->id,
            'locale' => 'en',
            'title' => 'English Related Visible Title',
            'slug' => 'english-related-visible',
            'excerpt' => 'Excerpt',
            'content' => '<p>Related body</p>',
            'is_published' => true,
        ]);

        $this->get('/blog/english-shown-related-host')
            ->assertOk()
            ->assertSee('English Related Visible Title', false)
            ->assertDontSee('Deutscher Related Hidden Titel', false)
            ->assertDontSee('German leftover related title', false);
    }

    public function test_english_sitemap_excludes_de_primary_post_without_en_translation(): void
    {
        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $blog = Blog::query()->where('slug', GastbeitraegeEuropaBlogPost::SLUG)->firstOrFail();
        $this->assertFalse(
            $blog->translations()->where('locale', 'en')->exists(),
            'Fixture must stay DE-only so the locale sitemap filter is actually tested.'
        );

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertDontSee('/blog/'.GastbeitraegeEuropaBlogPost::SLUG, false);

        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertSee('/de/blog/'.GastbeitraegeEuropaBlogPost::SLUG, false);
    }

    public function test_english_sitemap_does_not_list_de_only_posts(): void
    {
        $listed = Blog::factory()->published()->create([
            'title' => 'Listed English Post',
            'slug' => 'listed-english-post',
            'content' => '<p>Listed body</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $listed->id,
            'locale' => 'en',
            'title' => 'Listed English Post',
            'slug' => 'shared-fallback-slug',
            'excerpt' => 'Excerpt',
            'content' => '<p>Listed body</p>',
            'is_published' => true,
        ]);

        $deOnly = Blog::factory()->published()->create([
            'title' => 'DE-only fallback post',
            'slug' => 'shared-fallback-slug',
            'content' => '<p>German body</p>',
            'primary_locale' => 'de',
        ]);
        BlogTranslation::create([
            'blog_id' => $deOnly->id,
            'locale' => 'de',
            'title' => 'Nur Deutscher Beitrag',
            'slug' => 'nur-deutscher-beitrag',
            'excerpt' => 'Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $enSitemap = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $this->assertSame(1, substr_count($enSitemap, '<loc>'.url('/blog/shared-fallback-slug').'</loc>'));
        $this->assertSame(0, substr_count($enSitemap, '<loc>'.url('/blog/nur-deutscher-beitrag').'</loc>'));
        $this->assertSame(0, substr_count($enSitemap, '<loc>'.url('/de/blog/nur-deutscher-beitrag').'</loc>'));

        $html = $this->get('/blog/shared-fallback-slug')
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Listed English Post\s*<\/h1>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Nur Deutscher Beitrag\s*<\/h1>/', $html);
    }

    public function test_english_sitemap_skips_slug_owned_by_another_locale_translation(): void
    {
        $owner = Blog::factory()->published()->create([
            'title' => 'Owner English Title',
            'slug' => 'owner-blog-slug',
            'content' => '<p>Owner body</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $owner->id,
            'locale' => 'en',
            'title' => 'Owner English Title',
            'slug' => 'owner-en-slug',
            'excerpt' => 'Excerpt',
            'content' => '<p>Owner body</p>',
            'is_published' => true,
        ]);
        BlogTranslation::create([
            'blog_id' => $owner->id,
            'locale' => 'fr',
            'title' => 'Titre propriétaire',
            'slug' => 'cross-locale-owned-slug',
            'excerpt' => 'Extrait',
            'content' => '<p>Corps propriétaire</p>',
            'is_published' => true,
        ]);

        $orphan = Blog::factory()->published()->create([
            'title' => 'Orphan fallback post',
            'slug' => 'cross-locale-owned-slug',
            'content' => '<p>Orphan body</p>',
            'primary_locale' => 'de',
        ]);

        $enSitemap = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $this->assertSame(1, substr_count($enSitemap, '<loc>'.url('/blog/owner-en-slug').'</loc>'));
        $this->assertSame(0, substr_count($enSitemap, '<loc>'.url('/blog/cross-locale-owned-slug').'</loc>'));
        $this->assertSame(0, substr_count($enSitemap, '<loc>'.url('/blog/'.$orphan->slug).'</loc>'));

        $this->get('/blog/cross-locale-owned-slug')
            ->assertRedirect(url('/blog/owner-en-slug'));

        $html = $this->followingRedirects()
            ->get('/blog/cross-locale-owned-slug')
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Owner English Title\s*<\/h1>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Orphan fallback post\s*<\/h1>/', $html);
    }

    public function test_show_does_not_render_draft_translation_on_another_published_slug(): void
    {
        Blog::factory()->published()->create([
            'title' => 'Live Published Post',
            'slug' => 'shared-show-slug',
            'content' => '<p>Live published body</p>',
        ]);

        $draft = Blog::factory()->create([
            'title' => 'Draft Hijack Title',
            'slug' => 'draft-hijack-slug',
            'content' => '<p>Draft hijack body</p>',
            'status' => 'draft',
            'published_at' => null,
        ]);
        BlogTranslation::create([
            'blog_id' => $draft->id,
            'locale' => 'en',
            'title' => 'Draft Hijack Title',
            'slug' => 'shared-show-slug',
            'excerpt' => 'Draft excerpt',
            'content' => '<p>Draft hijack body</p>',
            'is_published' => true,
        ]);

        $html = $this->get('/blog/shared-show-slug')
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Live Published Post\s*<\/h1>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Draft Hijack Title\s*<\/h1>/', $html);
        $this->assertStringContainsString('Live published body', $html);
        $this->assertStringNotContainsString('Draft hijack body', $html);
    }

    public function test_sitemap_excludes_posts_outside_the_published_scope(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Ancient Leftover Post',
            'slug' => 'ancient-leftover-post',
            'published_at' => '1969-06-01 00:00:00',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Ancient Leftover Post',
            'slug' => 'ancient-leftover-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $this->assertFalse(Blog::published()->whereKey($blog->id)->exists());

        $this->get('/blog')
            ->assertOk()
            ->assertDontSee('Ancient Leftover Post', false);
        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertDontSee('/blog/ancient-leftover-post', false);
    }

    public function test_unpublished_translation_is_hidden_from_that_locale_index(): void
    {
        CuratedBlogSync::ensurePresent();
        Blog::query()->update(['published_at' => now()->subDay()]);

        $blog = Blog::factory()->published()->create([
            'title' => 'English Published Locale',
            'slug' => 'english-published-locale',
            'published_at' => now(),
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English Published Locale',
            'slug' => 'english-published-locale',
            'excerpt' => 'Excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Unpublished German Index Title',
            'slug' => 'unpublished-german-index',
            'excerpt' => 'Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => false,
        ]);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('English Published Locale', false);
        $this->get('/de/blog')
            ->assertOk()
            ->assertDontSee('Unpublished German Index Title', false);
        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertDontSee('/de/blog/unpublished-german-index', false);
    }
}
