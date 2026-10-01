<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBlogRequest;
use App\Http\Requests\Admin\UpdateBlogRequest;
use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\BlogHtmlSanitizer;
use App\Services\CuratedBlogSync;
use App\Services\CuratedBlogWriter;
use App\Services\SiteEnrichment\ImageOptimizationService;
use App\Support\AdminBlog;
use App\Support\BlogInlineImages;
use App\Support\PublicI18n;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BlogController extends Controller
{
    /**
     * Display a listing of blogs.
     */
    public function index(Request $request)
    {
        try {
            CuratedBlogSync::ensurePresent();
        } catch (\Throwable $e) {
            Log::error('Error ensuring curated blogs: '.$e->getMessage());
            session()->now('error', UserFacingError::message($e, 'Curated blog sync failed. The list below may be incomplete.'));
        }

        $filters = AdminBlog::listFilters($request);

        if (! $this->schemaTableAvailable('blogs')) {
            return view('admin.blogs.index', [
                'blogs' => $this->emptyBlogPaginator(),
                'filters' => $filters,
            ]);
        }

        $translationsAvailable = $this->schemaTableAvailable('blog_translations');

        try {
            $query = Blog::query();
            if ($translationsAvailable) {
                $query->with('translations');
            }

            AdminBlog::applySearch($query, $filters['q'], $translationsAvailable);

            if ($filters['status'] !== '' && AdminBlog::columnExists('blogs', 'status')) {
                $query->where('status', $filters['status']);
            }

            if ($filters['locale'] !== '' && AdminBlog::columnExists('blogs', 'primary_locale')) {
                $query->where('primary_locale', $filters['locale']);
            }

            if (AdminBlog::columnExists('blogs', 'curated_key')) {
                if ($filters['kind'] === 'curated') {
                    $query->whereNotNull('curated_key');
                } elseif ($filters['kind'] === 'custom') {
                    $query->whereNull('curated_key');
                }
            }

            if ($filters['incomplete'] && $translationsAvailable) {
                AdminBlog::constrainIncomplete($query);
            }

            AdminBlog::applyListSort($query, $filters['sort']);

            $page = (int) (filter_number($request->input('page')) ?? 1);
            $blogs = $query->paginate(20, ['*'], 'page', max(1, $page))->withQueryString();
            try {
                $blogs->getCollection()->loadMissing('creator');
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            Log::warning('Admin blogs list leftover query failed', ['error' => $e->getMessage()]);
            $blogs = $this->emptyBlogPaginator();
        }

        return view('admin.blogs.index', compact('blogs', 'filters'));
    }

    /**
     * Upsert curated SEO pillar posts so they appear in Admin → Blogs.
     */
    public function syncCurated()
    {
        try {
            $ok = CuratedBlogSync::sync();

            if (! $ok) {
                return redirect()->route('admin.blogs.index')
                    ->with('error', 'Curated blog sync reported errors. Check logs or run: php artisan blog:upsert-curated');
            }

            $count = Blog::query()->count();

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Curated SEO blogs synced. You can edit them below ('.$count.' posts in total).');
        } catch (\Throwable $e) {
            Log::error('Curated blog sync exception: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', UserFacingError::message($e, 'Failed to sync curated blogs. Please try again.'));
        }
    }

    /**
     * Show the form for creating a new blog.
     */
    public function create(Request $request)
    {
        $locales = $this->publicLocales();

        return view('admin.blogs.create', [
            'locales' => $locales,
            'formLocales' => AdminBlog::formLocales(null, $request),
            'indexQuery' => AdminBlog::rememberReturnQuery($request),
        ]);
    }

    /**
     * Store a newly created blog.
     */
    public function store(StoreBlogRequest $request)
    {
        $featuredImage = null;

        try {
            $translations = $this->sanitizeTranslations((array) $request->input('translations', []), true);
            $this->assertPrimaryLocalePresent($this->requestedPrimaryLocale($request), $translations);

            $featuredFile = $request->file('featured_image');
            if ($featuredFile instanceof UploadedFile) {
                $featuredImage = $this->storeBlogImage($featuredFile, 'blogs/featured');
                if ($featuredImage === null) {
                    throw ValidationException::withMessages([
                        'featured_image' => [self::imageConversionFailedMessage()],
                    ]);
                }
                Log::info('Featured image uploaded', ['path' => $featuredImage]);
            }

            $tags = $this->tagsFromRequest($request);
            $en = $translations['en'] ?? null;
            if (! is_array($en)) {
                throw ValidationException::withMessages([
                    'translations.en.title' => 'English title and content are required.',
                ]);
            }
            $enSlug = $this->uniquePublicSlug($en['slug'] ?: Str::slug($en['title']));
            $legacyExcerpt = filled($en['excerpt'])
                ? Str::limit(trim((string) $en['excerpt']), 300)
                : Str::limit(strip_tags((string) $en['content']), 160);
            $primaryLocale = $this->requestedPrimaryLocale($request);
            $status = AdminBlog::resolveStoreStatus($request);

            $blog = DB::transaction(function () use ($request, $featuredImage, $tags, $translations, $en, $enSlug, $legacyExcerpt, $primaryLocale, $status) {
                $blog = Blog::create([
                    'title' => $en['title'],
                    'slug' => $enSlug,
                    'primary_locale' => AdminBlog::normalizeLocale($request->input('primary_locale')) ?: null,
                    'excerpt' => $legacyExcerpt,
                    'content' => $en['content'],
                    'featured_image' => $featuredImage,
                    'author' => search_text($request->input('author')) ?: (auth()->user()?->name ?? 'Admin'),
                    'tags' => $tags,
                    'status' => $status,
                    'published_at' => $status === 'published' ? now() : null,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                    'manually_edited_at' => now(),
                ]);

                $slugsByLocale = [];
                foreach ($translations as $locale => $data) {
                    $slug = $locale === 'en'
                        ? $enSlug
                        : $this->uniquePublicSlug($data['slug'] ?: Str::slug($data['title']));
                    $slugsByLocale[$locale] = $slug;

                    BlogTranslation::create(array_merge(
                        $this->translationAttributes($data, $slug),
                        [
                            'blog_id' => $blog->id,
                            'locale' => $locale,
                        ]
                    ));
                }

                $primarySlug = $slugsByLocale[$primaryLocale] ?? $enSlug;
                if ($primarySlug !== $enSlug) {
                    $blog->update(['slug' => $primarySlug]);
                }

                return $blog;
            });

            Log::info('Blog created successfully', [
                'blog_id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
            ]);

            ActivityLogger::tryLog(
                'blog.created',
                (auth()->user()?->name ?? 'Admin').' created blog "'.$blog->title.'"',
                $blog,
                ['blog_id' => $blog->id, 'status' => $blog->status],
                $blog->title
            );

            return redirect()->route('admin.blogs.edit', $blog->id)
                ->with('success', 'Blog "'.$blog->title.'" created successfully!');
        } catch (ValidationException $e) {
            $this->deleteOrphanedBlogUpload($featuredImage);

            $redirect = redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
            if ($request->hasFile('featured_image')) {
                $redirect->with('warning', 'Choose the featured image again.');
            }

            return $redirect;
        } catch (\Throwable $e) {
            $this->deleteOrphanedBlogUpload($featuredImage);
            Log::error('Blog creation failed: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return redirect()->back()
                ->with('error', UserFacingError::message($e, 'Failed to create blog. Please try again.'))
                ->withInput();
        }
    }

    /**
     * Display the specified blog.
     */
    public function show($id)
    {
        if (! $this->schemaTableAvailable('blogs')) {
            abort(404);
        }

        try {
            $blog = $this->findAdminBlog($id);
            $en = $blog->relationLoaded('translations')
                ? $blog->translations->firstWhere('locale', 'en')
                : null;
            $safeContent = app(BlogHtmlSanitizer::class)->sanitize(
                filled($en?->content) ? $en->content : $blog->content
            );

            return view('admin.blogs.show', [
                'blog' => $blog,
                'safeContent' => $safeContent,
                'locales' => $this->publicLocales(),
            ]);
        } catch (ModelNotFoundException $e) {
            return redirect()->route('admin.blogs.index')
                ->with('error', 'Blog not found.');
        } catch (\Throwable $e) {
            if (! $this->schemaTableAvailable('blogs')) {
                abort(404);
            }

            Log::error('Error showing blog: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', UserFacingError::message($e, 'Failed to load blog. Please try again.'));
        }
    }

    /**
     * Staff-only preview of a locale, including drafts / unpublished locales.
     */
    public function preview(Request $request, $id)
    {
        if (! $this->schemaTableAvailable('blogs')) {
            abort(404);
        }

        try {
            $blog = $this->findAdminBlog($id);
            $locale = AdminBlog::normalizeLocale($request->query('locale'))
                ?: AdminBlog::normalizeLocale($blog->primary_locale)
                ?: 'en';
            $translation = $blog->translations->firstWhere('locale', $locale)
                ?: $blog->translations->firstWhere('locale', 'en');
            $html = filled($translation?->content) ? $translation->content : $blog->content;
            $safeContent = app(BlogHtmlSanitizer::class)->sanitize($html);

            return view('admin.blogs.preview', [
                'blog' => $blog,
                'locale' => $locale,
                'translation' => $translation,
                'safeContent' => $safeContent,
                'locales' => $this->publicLocales(),
            ]);
        } catch (ModelNotFoundException $e) {
            return redirect()->route('admin.blogs.index')
                ->with('error', 'Blog not found.');
        } catch (\Throwable $e) {
            Log::error('Error previewing blog: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', UserFacingError::message($e, 'Failed to preview blog. Please try again.'));
        }
    }

    /**
     * Show the form for editing the specified blog.
     */
    public function edit($id)
    {
        if (! $this->schemaTableAvailable('blogs')) {
            abort(404);
        }

        try {
            $blog = $this->findAdminBlog($id);

            return view('admin.blogs.edit', [
                'blog' => $blog,
                'locales' => $this->publicLocales(),
                'formLocales' => AdminBlog::formLocales($blog, request()),
                'indexQuery' => AdminBlog::storedReturnQuery(request()),
            ]);
        } catch (ModelNotFoundException $e) {
            return redirect()->route('admin.blogs.index')
                ->with('error', 'Blog not found.');
        } catch (\Throwable $e) {
            if (! $this->schemaTableAvailable('blogs')) {
                abort(404);
            }

            Log::error('Error editing blog: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', UserFacingError::message($e, 'Failed to open blog editor. Please try again.'));
        }
    }

    /**
     * Update the specified blog.
     */
    public function update(UpdateBlogRequest $request, $id)
    {
        $newFeaturedImage = null;

        try {
            $blog = Blog::with('translations')->findOrFail($id);
            $oldImagePaths = $this->collectStoredBlogImagePaths($blog);

            $tags = $this->tagsFromRequest($request);

            $translations = $this->sanitizeTranslations((array) $request->input('translations', []), true);
            $this->assertPrimaryLocalePresent($this->requestedPrimaryLocale($request), $translations);
            $en = $translations['en'] ?? null;
            if (! is_array($en)) {
                throw ValidationException::withMessages([
                    'translations.en.title' => 'English title and content are required.',
                ]);
            }
            $data = [
                'title' => $en['title'],
                'primary_locale' => AdminBlog::normalizeLocale($request->input('primary_locale')) ?: null,
                'excerpt' => filled($en['excerpt'])
                    ? Str::limit(trim((string) $en['excerpt']), 300)
                    : Str::limit(strip_tags((string) $en['content']), 160),
                'content' => $en['content'],
                'author' => search_text($request->input('author')) ?: ($blog->author ?: auth()->user()?->name),
                'tags' => $tags,
                'status' => $request->status,
                'updated_by' => auth()->id(),
                'manually_edited_at' => now(),
            ];

            $existingEn = $blog->translations()->where('locale', 'en')->first();
            $enSlug = $this->uniquePublicSlug(
                $en['slug'] ?: Str::slug($en['title']),
                $blog->id,
                $existingEn?->id
            );
            $primaryLocale = $this->requestedPrimaryLocale($request);
            $slugsByLocale = ['en' => $enSlug];
            $reservedSlugs = [$enSlug];
            foreach ($translations as $locale => $translationData) {
                if ($locale === 'en') {
                    continue;
                }
                $existing = $blog->translations->firstWhere('locale', $locale);
                $slugsByLocale[$locale] = $this->uniquePublicSlug(
                    $translationData['slug'] ?: Str::slug($translationData['title']),
                    $blog->id,
                    $existing?->id,
                    $reservedSlugs
                );
                $reservedSlugs[] = $slugsByLocale[$locale];
            }
            $data['slug'] = $slugsByLocale[$primaryLocale] ?? $enSlug;

            $featuredFile = $request->file('featured_image');
            if ($featuredFile instanceof UploadedFile) {
                $newFeaturedImage = $this->storeBlogImage($featuredFile, 'blogs/featured');
                if ($newFeaturedImage === null) {
                    throw ValidationException::withMessages([
                        'featured_image' => [self::imageConversionFailedMessage()],
                    ]);
                }

                $data['featured_image'] = $newFeaturedImage;
                Log::info('New featured image uploaded', ['path' => $newFeaturedImage]);
            } elseif (AdminBlog::normalizeIncomplete($request->input('remove_featured_image'))) {
                $data['featured_image'] = null;
            }

            if ($request->status === 'published' && ! $blog->published_at) {
                $data['published_at'] = now();
                Log::info('Blog published', ['blog_id' => $id]);
            }

            DB::transaction(function () use ($blog, $data, $translations, $slugsByLocale, $primaryLocale) {
                $blog->update($data);

                foreach ($translations as $locale => $translationData) {
                    $blog->translations()->updateOrCreate(
                        ['locale' => $locale],
                        $this->translationAttributes($translationData, $slugsByLocale[$locale])
                    );
                }

                $blog->translations()
                    ->whereNotIn('locale', array_keys($translations))
                    ->where('locale', '!=', 'en')
                    ->where('locale', '!=', $primaryLocale)
                    ->delete();
            });

            try {
                $blog->refresh()->load('translations');
                foreach (array_diff($oldImagePaths, $this->collectStoredBlogImagePaths($blog)) as $stalePath) {
                    $this->deletePublicBlogPath($stalePath, $blog->id);
                }
            } catch (\Throwable $e) {
                Log::error('Blog image cleanup after update failed', [
                    'blog_id' => $blog->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Blog updated successfully', [
                'blog_id' => $blog->id,
                'title' => $blog->title,
                'status' => $blog->status,
            ]);

            ActivityLogger::tryLog(
                'blog.updated',
                (auth()->user()?->name ?? 'Admin').' updated blog "'.$blog->title.'"',
                $blog,
                ['blog_id' => $blog->id, 'status' => $blog->status],
                $blog->title
            );

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog "'.$blog->title.'" updated successfully!');
        } catch (ValidationException $e) {
            $this->deleteOrphanedBlogUpload($newFeaturedImage);

            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Throwable $e) {
            $this->deleteOrphanedBlogUpload($newFeaturedImage);
            Log::error('Blog update failed: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return redirect()->back()
                ->with('error', UserFacingError::message($e, 'Failed to update blog. Please try again.'))
                ->withInput();
        }
    }

    /**
     * Remove the specified blog.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $blog = Blog::with('translations')->findOrFail($id);
            $imagePaths = $this->collectStoredBlogImagePaths($blog);
            $blogTitle = $blog->title;

            DB::transaction(function () use ($blog) {
                CuratedBlogWriter::rememberDeleted($blog);
                $blog->delete();
            });

            try {
                foreach ($imagePaths as $path) {
                    $this->deletePublicBlogPath($path);
                }
            } catch (\Throwable $e) {
                Log::error('Blog image cleanup after delete failed', [
                    'title' => $blogTitle,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Blog deleted successfully', [
                'blog_id' => $id,
                'title' => $blogTitle,
                'deleted_by' => auth()->id(),
            ]);

            ActivityLogger::tryLog(
                'blog.deleted',
                (auth()->user()?->name ?? 'Admin').' deleted blog "'.$blogTitle.'"',
                null,
                ['blog_id' => (int) $id, 'title' => $blogTitle],
                $blogTitle
            );

            return redirect()->route('admin.blogs.index', AdminBlog::indexQuery($request))
                ->with('success', 'Blog "'.$blogTitle.'" deleted successfully!');
        } catch (\Throwable $e) {
            Log::error('Blog deletion failed: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', UserFacingError::message($e, 'Failed to delete blog. Please try again.'));
        }
    }

    /**
     * Toggle blog status (publish/unpublish).
     */
    public function toggleStatus(Request $request, $id)
    {
        try {
            $blog = Blog::findOrFail($id);

            if ($blog->status === 'published') {
                $blog->status = 'draft';
                $message = 'Blog "'.$blog->title.'" moved to draft.';
                Log::info('Blog unpublished', ['blog_id' => $id, 'title' => $blog->title]);
            } else {
                $blog->status = 'published';
                $blog->published_at = $blog->published_at ?? now();
                $message = 'Blog "'.$blog->title.'" published successfully!';
                Log::info('Blog published', ['blog_id' => $id, 'title' => $blog->title]);
            }

            $blog->updated_by = auth()->id();
            $blog->manually_edited_at = now();
            $blog->save();

            ActivityLogger::tryLog(
                $blog->status === 'published' ? 'blog.published' : 'blog.unpublished',
                (auth()->user()?->name ?? 'Admin').' '.($blog->status === 'published' ? 'published' : 'unpublished').' blog "'.$blog->title.'"',
                $blog,
                ['blog_id' => $blog->id, 'status' => $blog->status],
                $blog->title
            );

            return redirect()->route('admin.blogs.index', AdminBlog::indexQuery($request))
                ->with('success', $message);
        } catch (\Throwable $e) {
            Log::error('Blog status toggle failed: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', UserFacingError::message($e, 'Failed to change blog status. Please try again.'));
        }
    }

    /**
     * Upload image from Quill editor.
     */
    public function uploadImage(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);

            $imagePath = $this->storeBlogImage($request->file('image'), 'blogs/content');
            if ($imagePath === null) {
                return response()->json([
                    'success' => false,
                    'error' => self::imageConversionFailedMessage(),
                ], 422);
            }
            $imageUrl = Site::publicDiskUrl($imagePath);
            if ($imageUrl === null) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not save the image to storage. Check disk permissions and MEDIA_PATH.',
                ], 500);
            }

            Log::info('Image uploaded via editor', ['path' => $imagePath]);

            return response()->json([
                'success' => true,
                'url' => $imageUrl,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?: 'Invalid image.',
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Image upload failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => UserFacingError::message($e, 'Failed to upload image. Please try again.'),
            ], 500);
        }
    }

    /**
     * Delete a stored blog content/featured image after it is removed from the editor.
     */
    public function deleteContentImage(Request $request)
    {
        $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        $path = $this->blogStoragePathFromUrl((string) $request->input('url'));
        if ($path === null) {
            return response()->json([
                'success' => false,
                'error' => 'Only blog storage images can be deleted.',
            ], 422);
        }

        try {
            $this->deletePublicBlogPath($path);
            Log::info('Blog content image delete requested', ['path' => $path]);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Blog content image delete failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => UserFacingError::message($e, 'Failed to delete image. Please try again.'),
            ], 500);
        }
    }

    /**
     * Resolve a public storage URL/path to blogs/content|featured/...
     */
    private function blogStoragePathFromUrl(string $url): ?string
    {
        $path = trim($url);
        if ($path === '') {
            return null;
        }

        if (str_contains($path, '://') || str_starts_with($path, '//')) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '');
        } else {
            $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        foreach (['storage/', 'media/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = ltrim(substr($path, strlen($prefix)), '/');
            }
        }

        $path = rawurldecode($path);
        if ($path === '' || str_contains($path, '..') || str_contains($path, '%') || str_contains($path, "\0")) {
            return null;
        }

        if (! str_starts_with($path, 'blogs/content/') && ! str_starts_with($path, 'blogs/featured/')) {
            return null;
        }

        return $path;
    }

    private function deleteOrphanedBlogUpload(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        try {
            $this->deletePublicBlogPath($path);
        } catch (\Throwable $e) {
            Log::error('Orphaned blog upload cleanup failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deletePublicBlogPath(?string $path, ?int $exceptBlogId = null): void
    {
        $resolved = $this->blogStoragePathFromUrl((string) $path);
        if ($resolved === null || BlogInlineImages::isBundledAsset($resolved)) {
            return;
        }

        if ($this->blogImageIsReferenced($resolved, $exceptBlogId)) {
            return;
        }

        if (Storage::disk('public')->exists($resolved)) {
            Storage::disk('public')->delete($resolved);
        }
    }

    private function blogImageIsReferenced(string $path, ?int $exceptBlogId = null): bool
    {
        $like = '%'.addcslashes($path, '\\%_').'%';

        $usedOnBlog = Blog::query()
            ->when($exceptBlogId, fn ($query) => $query->where('id', '!=', $exceptBlogId))
            ->where(function ($query) use ($path, $like) {
                $query->where('featured_image', $path)
                    ->orWhere('featured_image', 'like', $like)
                    ->orWhere('content', 'like', $like);
            })
            ->exists();

        $usedOnTranslation = BlogTranslation::query()
            ->when($exceptBlogId, fn ($query) => $query->where('blog_id', '!=', $exceptBlogId))
            ->where('content', 'like', $like)
            ->exists();

        return $usedOnBlog || $usedOnTranslation;
    }

    /**
     * @return list<string>
     */
    private function collectStoredBlogImagePaths(Blog $blog): array
    {
        $paths = [];
        if (filled($blog->featured_image)) {
            $paths[] = (string) $blog->featured_image;
        }

        $html = (string) $blog->content;
        foreach ($blog->translations as $translation) {
            $html .= ' '.$translation->content;
        }
        $html = BlogHtmlSanitizer::rewritePublicBlogUrls($html);

        if (preg_match_all('#(?:https?://[^"\'\s>]+)?(?:/(?:storage|media)/)?(blogs/(?:content|featured)/[^"\'?\s>]+)#i', $html, $matches)) {
            $paths = array_merge($paths, $matches[1]);
        }

        $resolved = [];
        foreach ($paths as $path) {
            $item = $this->blogStoragePathFromUrl($path);
            if ($item !== null) {
                $resolved[] = $item;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Persist a blog image as WebP when GD can convert. GIF stays GIF.
     * JPEG/PNG keep original bytes when they are a real image and WebP is unavailable.
     */
    private function storeBlogImage(UploadedFile $file, string $directory): ?string
    {
        try {
            $disk = Storage::disk('public');
            $disk->makeDirectory($directory);

            $stored = app(ImageOptimizationService::class)->storeSafePublicImage($file, $directory);

            if (! is_string($stored) || $stored === '' || ! $disk->exists($stored)) {
                return null;
            }

            return $stored;
        } catch (\Throwable $e) {
            Log::error('Blog image store failed', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function imageConversionFailedMessage(): string
    {
        return 'Could not store this image. Use a valid JPEG, PNG, GIF, or WebP file.';
    }

    private function sanitizeTranslations(array $translations, bool $requireEnglish): array
    {
        $normalized = [];

        foreach ($this->publicLocales() as $locale) {
            $item = (array) ($translations[$locale] ?? []);
            $title = search_text($item['title'] ?? '');
            $slug = search_text($item['slug'] ?? '');
            $excerpt = array_key_exists('excerpt', $item) ? search_text($item['excerpt']) : null;
            $metaTitle = array_key_exists('meta_title', $item) ? search_text($item['meta_title']) : null;
            $metaDescription = array_key_exists('meta_description', $item) ? search_text($item['meta_description']) : null;
            $isPublished = array_key_exists('is_published', $item)
                ? AdminBlog::normalizeIncomplete($item['is_published'])
                : true;
            $rawContent = is_string($item['content'] ?? null) ? trim($item['content']) : '';
            $content = BlogHtmlSanitizer::isBlank($rawContent)
                ? ''
                : app(BlogHtmlSanitizer::class)->sanitize($rawContent);
            if (BlogHtmlSanitizer::isBlank($content)) {
                $content = '';
            }

            if ($locale === 'en') {
                if ($requireEnglish && ($title === '' || $content === '')) {
                    throw ValidationException::withMessages([
                        'translations.en.title' => 'English title and content are required.',
                    ]);
                }

                $normalized[$locale] = [
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => $excerpt,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'is_published' => $isPublished,
                    'content' => $content,
                ];

                continue;
            }

            if ($title === '' && $content === '' && $slug === '' && ! filled($excerpt)) {
                continue;
            }

            if ($title === '' || $content === '') {
                throw ValidationException::withMessages([
                    "translations.{$locale}.title" => strtoupper($locale).' translation must include both title and content.',
                ]);
            }

            $normalized[$locale] = [
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDescription,
                'is_published' => $isPublished,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array{title: string, excerpt: ?string, content: string, meta_title: ?string, meta_description: ?string, is_published: bool}  $data
     * @return array<string, mixed>
     */
    private function translationAttributes(array $data, string $slug): array
    {
        return [
            'title' => $data['title'],
            'slug' => $slug,
            'excerpt' => filled($data['excerpt'])
                ? Str::limit(trim((string) $data['excerpt']), 300)
                : Str::limit(strip_tags((string) $data['content']), 160),
            'content' => $data['content'],
            'meta_title' => filled($data['meta_title'] ?? null) ? $data['meta_title'] : null,
            'meta_description' => filled($data['meta_description'] ?? null) ? $data['meta_description'] : null,
            'is_published' => (bool) ($data['is_published'] ?? true),
        ];
    }

    /**
     * Public /blog/{slug} resolves translations first, then blogs.slug.
     * Both tables must share one namespace or a new translation can steal
     * a legacy post's URL.
     */
    /**
     * @return list<string>
     */
    /**
     * @return list<string>|null
     */
    private function tagsFromRequest(Request $request): ?array
    {
        $raw = $request->input('tags');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $tags === [] ? null : $tags;
    }

    private function publicLocales(): array
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'supported')) {
            return PublicI18n::supported();
        }

        return ['en'];
    }

    private function requestedPrimaryLocale(Request $request): string
    {
        $locale = AdminBlog::normalizeLocale($request->input('primary_locale'));

        return $locale !== '' ? $locale : 'en';
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function assertPrimaryLocalePresent(string $primaryLocale, array $translations): void
    {
        if ($primaryLocale !== 'en' && ! isset($translations[$primaryLocale])) {
            throw ValidationException::withMessages([
                "translations.{$primaryLocale}.title" => strtoupper($primaryLocale).' is the primary locale and must include both title and content.',
            ]);
        }
    }

    /**
     * @param  list<string>  $reserved
     */
    private function uniquePublicSlug(
        string $slug,
        ?int $ignoreBlogId = null,
        ?int $ignoreTranslationId = null,
        array $reserved = []
    ): string {
        $base = Str::slug($slug) ?: Str::random(8);
        $candidate = $base;
        $counter = 1;

        while ($this->publicSlugTaken($candidate, $ignoreBlogId, $ignoreTranslationId, $reserved)) {
            $candidate = $base.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    private function publicSlugTaken(
        string $candidate,
        ?int $ignoreBlogId = null,
        ?int $ignoreTranslationId = null,
        array $reserved = []
    ): bool {
        if (in_array($candidate, $reserved, true)) {
            return true;
        }

        $blogTaken = Blog::query()
            ->when($ignoreBlogId, fn ($query) => $query->where('id', '!=', $ignoreBlogId))
            ->where('slug', $candidate)
            ->exists();

        if ($blogTaken) {
            return true;
        }

        return BlogTranslation::query()
            ->when($ignoreTranslationId, fn ($query) => $query->where('id', '!=', $ignoreTranslationId))
            ->where('slug', $candidate)
            ->exists();
    }

    /**
     * Load one admin blog. Skip translations when that leftover table is gone
     * so show/edit do not 500, and hand the view an empty relation.
     */
    private function findAdminBlog(int|string $id): Blog
    {
        $query = Blog::query();
        $translationsAvailable = $this->schemaTableAvailable('blog_translations');
        if ($translationsAvailable) {
            $query->with('translations');
        }

        $blog = $query->findOrFail($id);
        if (! $translationsAvailable) {
            $blog->setRelation('translations', $blog->newCollection());
        }
        try {
            $blog->loadMissing(['creator:id,name,email', 'updater:id,name,email']);
        } catch (\Throwable) {
        }

        return $blog;
    }

    private function emptyBlogPaginator(): LengthAwarePaginator
    {
        return (new LengthAwarePaginator([], 0, 20))->withQueryString();
    }

    private function schemaTableAvailable(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
