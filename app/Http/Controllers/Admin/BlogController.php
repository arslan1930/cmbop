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

        if (! $this->schemaTableAvailable('blogs')) {
            return view('admin.blogs.index', [
                'blogs' => $this->emptyBlogPaginator(),
            ]);
        }

        $with = ['creator'];
        $translationsAvailable = $this->schemaTableAvailable('blog_translations');
        if ($translationsAvailable) {
            $with[] = 'translations';
        }

        try {
            $query = Blog::with($with)->orderByDesc('created_at');

            $search = trim((string) $request->input('q', ''));
            if ($search !== '') {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like, $translationsAvailable) {
                    $inner->where('title', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('author', 'like', $like);
                    if ($translationsAvailable) {
                        $inner->orWhereHas('translations', function ($translations) use ($like) {
                            $translations->where('title', 'like', $like)
                                ->orWhere('slug', 'like', $like);
                        });
                    }
                });
            }

            $status = (string) $request->input('status', '');
            if (in_array($status, ['draft', 'published'], true)) {
                $query->where('status', $status);
            }

            $locale = (string) $request->input('locale', '');
            if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isSupported') && PublicI18n::isSupported($locale)) {
                $query->where('primary_locale', $locale);
            }

            $kind = (string) $request->input('kind', '');
            if ($kind === 'curated') {
                $query->whereNotNull('curated_key');
            } elseif ($kind === 'custom') {
                $query->whereNull('curated_key');
            }

            if ($request->boolean('missing_translations') && $translationsAvailable) {
                $needed = count($this->publicLocales());
                $query->whereRaw(
                    '(select count(*) from blog_translations where blog_translations.blog_id = blogs.id) < ?',
                    [$needed]
                );
            }

            $blogs = $query->paginate(20)->withQueryString();
        } catch (\Throwable $e) {
            Log::warning('Admin blogs list leftover query failed', ['error' => $e->getMessage()]);
            $blogs = $this->emptyBlogPaginator();
        }

        return view('admin.blogs.index', compact('blogs'));
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
    public function create()
    {
        return view('admin.blogs.create', [
            'locales' => $this->publicLocales(),
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

            if ($request->hasFile('featured_image')) {
                $featuredImage = $this->storeBlogImage($request->file('featured_image'), 'blogs/featured');
                if ($featuredImage === null) {
                    throw ValidationException::withMessages([
                        'featured_image' => [self::imageConversionFailedMessage()],
                    ]);
                }
                Log::info('Featured image uploaded', ['path' => $featuredImage]);
            }

            $tags = null;
            if ($request->tags) {
                $tags = array_map('trim', explode(',', $request->tags));
                $tags = array_filter($tags);
                $tags = array_values($tags);
            }
            $en = $translations['en'];
            $enSlug = $this->uniquePublicSlug($en['slug'] ?: Str::slug($en['title']));
            $legacyExcerpt = filled($en['excerpt'])
                ? Str::limit(trim((string) $en['excerpt']), 300)
                : Str::limit(strip_tags((string) $en['content']), 160);
            $primaryLocale = $this->requestedPrimaryLocale($request);

            $blog = DB::transaction(function () use ($request, $featuredImage, $tags, $translations, $en, $enSlug, $legacyExcerpt, $primaryLocale) {
                $blog = Blog::create([
                    'title' => $en['title'],
                    'slug' => $enSlug,
                    'primary_locale' => $request->input('primary_locale') ?: null,
                    'excerpt' => $legacyExcerpt,
                    'content' => $en['content'],
                    'featured_image' => $featuredImage,
                    'author' => trim((string) $request->input('author')) ?: auth()->user()->name,
                    'tags' => $tags,
                    'status' => $request->status,
                    'published_at' => $request->status === 'published' ? now() : null,
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

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog "'.$blog->title.'" created successfully!');
        } catch (ValidationException $e) {
            $this->deleteOrphanedBlogUpload($featuredImage);

            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
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

            return view('admin.blogs.show', compact('blog', 'safeContent'));
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

            $tags = null;
            if ($request->tags) {
                $tags = array_map('trim', explode(',', $request->tags));
                $tags = array_filter($tags);
                $tags = array_values($tags);
            }

            $translations = $this->sanitizeTranslations((array) $request->input('translations', []), true);
            $this->assertPrimaryLocalePresent($this->requestedPrimaryLocale($request), $translations);
            $en = $translations['en'];
            $data = [
                'title' => $en['title'],
                'primary_locale' => $request->input('primary_locale') ?: null,
                'excerpt' => filled($en['excerpt'])
                    ? Str::limit(trim((string) $en['excerpt']), 300)
                    : Str::limit(strip_tags((string) $en['content']), 160),
                'content' => $en['content'],
                'author' => trim((string) $request->input('author')) ?: ($blog->author ?: auth()->user()?->name),
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

            if ($request->hasFile('featured_image')) {
                $newFeaturedImage = $this->storeBlogImage($request->file('featured_image'), 'blogs/featured');
                if ($newFeaturedImage === null) {
                    throw ValidationException::withMessages([
                        'featured_image' => [self::imageConversionFailedMessage()],
                    ]);
                }

                $data['featured_image'] = $newFeaturedImage;
                Log::info('New featured image uploaded', ['path' => $newFeaturedImage]);
            } elseif ($request->boolean('remove_featured_image')) {
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
    public function destroy($id)
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

            return redirect()->route('admin.blogs.index')
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
    public function toggleStatus($id)
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

            return redirect()->route('admin.blogs.index')
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
            $title = trim((string) ($item['title'] ?? ''));
            $slug = trim((string) ($item['slug'] ?? ''));
            $excerpt = isset($item['excerpt']) ? trim((string) $item['excerpt']) : null;
            $metaTitle = isset($item['meta_title']) ? trim((string) $item['meta_title']) : null;
            $metaDescription = isset($item['meta_description']) ? trim((string) $item['meta_description']) : null;
            $isPublished = ! array_key_exists('is_published', $item)
                || filter_var($item['is_published'], FILTER_VALIDATE_BOOLEAN);
            $rawContent = trim((string) ($item['content'] ?? ''));
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
    private function publicLocales(): array
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'supported')) {
            return PublicI18n::supported();
        }

        return ['en'];
    }

    private function requestedPrimaryLocale(Request $request): string
    {
        $locale = (string) $request->input('primary_locale');

        return (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isSupported') && PublicI18n::isSupported($locale)) ? $locale : 'en';
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
