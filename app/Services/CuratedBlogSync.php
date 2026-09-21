<?php

namespace App\Services;

use App\Models\Blog;
use App\Support\BlogInlineImages;
use App\Support\BlogTranslationSlug;
use App\Support\CuratedBlogCatalog;
use App\Support\PublicI18n;
use App\Support\ThinBlogRedirects;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CuratedBlogSync
{
    /** @return list<string> */
    public static function curatedSlugs(): array
    {
        return CuratedBlogCatalog::slugs();
    }

    /**
     * Ensure blog schema pieces exist (older production DBs may have skipped migrations).
     */
    public static function ensureSchema(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        if (! Schema::hasColumn('blogs', 'primary_locale')) {
            Schema::table('blogs', function ($table) {
                $table->string('primary_locale', 5)->nullable()->after('slug');
            });
        }

        if (! Schema::hasColumn('blogs', 'curated_key')) {
            Schema::table('blogs', function ($table) {
                $table->string('curated_key')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('blogs', 'manually_edited_at')) {
            Schema::table('blogs', function ($table) {
                $table->timestamp('manually_edited_at')->nullable();
            });
        }

        if (! Schema::hasTable('curated_blog_tombstones')) {
            Schema::create('curated_blog_tombstones', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        self::ensureTranslationsTable();
    }

    /**
     * Create blog_translations + backfill from blogs when the migration was skipped.
     * Public /blog and locale sitemaps query this table and 500 without it.
     */
    public static function ensureTranslationsTable(): void
    {
        try {
            if (! Schema::hasTable('blogs')) {
                return;
            }

            if (! Schema::hasTable('blog_translations')) {
                Schema::create('blog_translations', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
                    $table->string('locale', 8);
                    $table->string('title');
                    $table->string('slug');
                    $table->text('excerpt')->nullable();
                    $table->longText('content');
                    $table->string('meta_title')->nullable();
                    $table->text('meta_description')->nullable();
                    $table->boolean('is_published')->default(true);
                    $table->timestamps();

                    $table->unique(['blog_id', 'locale']);
                    $table->unique('slug');
                });
                // Production Hostinger heal signal; tests often drop/recreate this on purpose.
                if (! app()->environment('testing')) {
                    Log::warning('blog_translations table was missing — created at runtime');
                }
                self::backfillTranslationsFromBlogs();

                return;
            }

            // One empty-table check per process; table presence is re-checked each call.
            static $emptyChecked = false;
            if (! $emptyChecked) {
                $emptyChecked = true;
                if (Blog::query()->exists() && ! DB::table('blog_translations')->exists()) {
                    self::backfillTranslationsFromBlogs();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to ensure blog_translations schema', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Copy legacy blogs.* content into blog_translations (idempotent per blog/locale).
     */
    public static function backfillTranslationsFromBlogs(): void
    {
        if (! Schema::hasTable('blog_translations') || ! Schema::hasTable('blogs')) {
            return;
        }

        $usedSlugs = DB::table('blog_translations')->pluck('slug')->all();
        $used = array_fill_keys($usedSlugs, true);
        $blogSlugsById = DB::table('blogs')->pluck('slug', 'id')->all();

        DB::table('blogs')->orderBy('id')->chunkById(100, function ($blogs) use (&$used, $blogSlugsById): void {
            foreach ($blogs as $blog) {
                $locale = (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isSupported') && PublicI18n::isSupported($blog->primary_locale ?? null))
                    ? $blog->primary_locale
                    : 'en';

                $exists = DB::table('blog_translations')
                    ->where('blog_id', $blog->id)
                    ->where('locale', $locale)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $taken = $used;
                foreach ($blogSlugsById as $otherId => $otherSlug) {
                    if ((int) $otherId === (int) $blog->id || ! is_string($otherSlug) || $otherSlug === '') {
                        continue;
                    }
                    $taken[$otherSlug] = true;
                }

                $baseSlug = $blog->slug ?: Str::slug((string) $blog->title);
                $slug = $baseSlug !== '' ? $baseSlug : 'post-'.$blog->id;
                $counter = 1;
                while (isset($taken[$slug])) {
                    $slug = $baseSlug.'-'.$counter;
                    $counter++;
                }
                $used[$slug] = true;

                DB::table('blog_translations')->insert([
                    'blog_id' => $blog->id,
                    'locale' => $locale,
                    'title' => $blog->title ?: 'Untitled',
                    'slug' => $slug,
                    'excerpt' => $blog->excerpt,
                    'content' => $blog->content ?: '',
                    'meta_title' => null,
                    'meta_description' => null,
                    'is_published' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Run all curated upserts. Returns true when Artisan exits 0.
     */
    public static function sync(): bool
    {
        self::ensureSchema();

        try {
            $exit = Artisan::call('blog:upsert-curated');
            $output = trim(Artisan::output());
            if ($exit !== 0) {
                Log::error('Curated blog sync failed', ['exit' => $exit, 'output' => $output]);

                return false;
            }

            Cache::forget('curated_blogs_present_v1');

            return true;
        } catch (\Throwable $e) {
            Log::error('Curated blog sync exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Idempotent heal for production: if any curated slug is missing, sync once.
     * Also re-sync when content still points at /assets/img/blog/ (broken on many hosts).
     * Safe to call from public blog routes after deploy.
     */
    public static function ensurePresent(): void
    {
        try {
            if (! Schema::hasTable('blogs')) {
                return;
            }

            // Always heal schema first — curated presence cache must not skip translations table.
            self::ensureSchema();
            self::ensureLocalizedTranslationSlugs();

            static $legacyUnpublished = false;
            if (! $legacyUnpublished) {
                $legacyUnpublished = true;
                if (class_exists(ThinBlogRedirects::class)) {
                    ThinBlogRedirects::unpublishLegacy();
                }
            }

            $present = Cache::remember('curated_blogs_present_v1', now()->addMinutes(30), function () {
                $slugs = array_values(array_filter(
                    self::curatedSlugs(),
                    static fn (string $slug): bool => ! CuratedBlogWriter::isTombstoned($slug)
                ));
                // Count real pillars only. A custom post that reused a catalog
                // slug must not satisfy presence — otherwise auto-heal never
                // creates the uniquified curated row.
                $found = count(array_filter(
                    $slugs,
                    static fn (string $slug): bool => CuratedBlogWriter::findExisting($slug) !== null
                ));

                if ($slugs === [] || $found >= count($slugs)) {
                    return true;
                }

                Log::warning('Curated blogs missing from database — auto-syncing', [
                    'expected' => $slugs,
                    'found' => $found,
                ]);

                return self::sync();
            });

            // If cache says false from a failed sync, retry on next request after short TTL
            if ($present === false) {
                Cache::forget('curated_blogs_present_v1');
            }

            self::ensureInlineImagesOnStorage();
        } catch (\Throwable $e) {
            Log::error('Curated blog ensurePresent failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Published translations that still copy another locale's slug get a title-based slug.
     * One check per process so public blog routes do not join on every request.
     */
    public static function ensureLocalizedTranslationSlugs(): void
    {
        try {
            if (! class_exists(BlogTranslationSlug::class) || ! BlogTranslationSlug::hasCopiedSlugs()) {
                return;
            }

            BlogTranslationSlug::localizeCopiedSlugs();
        } catch (\Throwable $e) {
            Log::error('Failed to localize copied blog translation slugs', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Heal curated posts whose HTML still references public /assets/img/blog/ paths.
     */
    public static function ensureInlineImagesOnStorage(): void
    {
        Cache::remember('curated_blogs_inline_storage_v1', now()->addMinutes(30), function () {
            self::ensureSchema();
            BlogInlineImages::publishAllFromPublicAssets();
            BlogInlineImages::publishAllFeaturedFromCatalog();

            $slugs = self::curatedSlugs();
            $needsRewrite = Blog::query()
                ->where(function ($query) use ($slugs) {
                    $query->whereIn('slug', $slugs);
                    if (Schema::hasColumn('blogs', 'curated_key')) {
                        $query->orWhereIn('curated_key', $slugs);
                    }
                })
                ->where(function ($query) {
                    $query->where('content', 'like', '%/assets/img/blog/%')
                        ->orWhereHas('translations', function ($translations) {
                            $translations->where('content', 'like', '%/assets/img/blog/%');
                        });
                })
                ->exists();

            if (! $needsRewrite) {
                return true;
            }

            Log::warning('Curated blogs still use /assets/img/blog/ inline paths — re-syncing to storage URLs');

            $ok = self::sync();
            if (! $ok) {
                Cache::forget('curated_blogs_inline_storage_v1');
            }

            return $ok;
        });
    }
}
