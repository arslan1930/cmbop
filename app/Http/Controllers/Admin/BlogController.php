<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\BlogHtmlSanitizer;
use App\Services\CuratedBlogSync;
use App\Support\PublicI18n;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BlogController extends Controller
{
    /**
     * Display a listing of blogs.
     */
    public function index()
    {
        try {
            CuratedBlogSync::ensurePresent();

            $blogs = Blog::orderBy('created_at', 'desc')->paginate(20);

            return view('admin.blogs.index', compact('blogs'));
        } catch (\Exception $e) {
            Log::error('Error fetching blogs: '.$e->getMessage());

            return redirect()->back()->with('error', UserFacingError::message($e, 'Failed to load blogs. Please try again.'));
        }
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
            'locales' => PublicI18n::supported(),
        ]);
    }

    /**
     * Store a newly created blog.
     */
    public function store(Request $request)
    {
        try {
            $this->hydrateLegacyTranslationInput($request);
            $request->validate([
                'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'tags' => 'nullable|string',
                'status' => 'required|in:draft,published',
                'primary_locale' => 'nullable|string|in:'.implode(',', PublicI18n::supported()),
                'translations.en.title' => 'required|string|max:255',
                'translations.en.slug' => 'nullable|string|max:255',
                'translations.en.excerpt' => 'nullable|string|max:300',
                'translations.en.content' => 'required|string',
                'translations.de.title' => 'nullable|string|max:255',
                'translations.de.slug' => 'nullable|string|max:255',
                'translations.de.excerpt' => 'nullable|string|max:300',
                'translations.de.content' => 'nullable|string',
                'translations.fr.title' => 'nullable|string|max:255',
                'translations.fr.slug' => 'nullable|string|max:255',
                'translations.fr.excerpt' => 'nullable|string|max:300',
                'translations.fr.content' => 'nullable|string',
                'translations.nl.title' => 'nullable|string|max:255',
                'translations.nl.slug' => 'nullable|string|max:255',
                'translations.nl.excerpt' => 'nullable|string|max:300',
                'translations.nl.content' => 'nullable|string',
            ]);

            if (! auth()->check()) {
                throw new \Exception('You must be logged in to create a blog post.');
            }

            $featuredImage = null;
            if ($request->hasFile('featured_image')) {
                $featuredImage = $request->file('featured_image')->store('blogs/featured', 'public');
                Log::info('Featured image uploaded', ['path' => $featuredImage]);
            }

            $tags = null;
            if ($request->tags) {
                $tags = array_map('trim', explode(',', csv_text($request->tags)));
                $tags = array_filter($tags);
                $tags = array_values($tags);
            }

            $translations = $this->sanitizeTranslations((array) $request->input('translations', []), true);
            $en = $translations['en'];
            $enSlug = $this->uniqueTranslationSlug($en['slug'] ?: Str::slug($en['title']));
            $legacySlug = $this->uniqueBlogSlug($enSlug);
            $legacyExcerpt = filled($en['excerpt'])
                ? Str::limit(trim((string) $en['excerpt']), 300)
                : Str::limit(strip_tags((string) $en['content']), 160);

            $blog = DB::transaction(function () use ($request, $featuredImage, $tags, $translations, $en, $enSlug, $legacySlug, $legacyExcerpt) {
                $blog = Blog::create([
                    'title' => $en['title'],
                    'slug' => $legacySlug,
                    'primary_locale' => $request->input('primary_locale') ?: null,
                    'excerpt' => $legacyExcerpt,
                    'content' => $en['content'],
                    'featured_image' => $featuredImage,
                    'author' => auth()->user()->name,
                    'tags' => $tags,
                    'status' => $request->status,
                    'published_at' => $request->status === 'published' ? now() : null,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);

                foreach ($translations as $locale => $data) {
                    $slug = $locale === 'en'
                        ? $enSlug
                        : $this->uniqueTranslationSlug($data['slug'] ?: Str::slug($data['title']));

                    BlogTranslation::create([
                        'blog_id' => $blog->id,
                        'locale' => $locale,
                        'title' => $data['title'],
                        'slug' => $slug,
                        'excerpt' => filled($data['excerpt'])
                            ? Str::limit(trim((string) $data['excerpt']), 300)
                            : Str::limit(strip_tags((string) $data['content']), 160),
                        'content' => $data['content'],
                        'meta_title' => null,
                        'meta_description' => null,
                        'is_published' => true,
                    ]);
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
            Log::error('Validation failed for blog creation', ['errors' => $e->errors()]);

            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
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
        try {
            $blog = Blog::with('translations')->findOrFail($id);
            $safeContent = app(BlogHtmlSanitizer::class)->sanitize($blog->content);

            return view('admin.blogs.show', compact('blog', 'safeContent'));
        } catch (\Exception $e) {
            Log::error('Error showing blog: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', 'Blog not found.');
        }
    }

    /**
     * Show the form for editing the specified blog.
     */
    public function edit($id)
    {
        try {
            $blog = Blog::with('translations')->findOrFail($id);

            return view('admin.blogs.edit', [
                'blog' => $blog,
                'locales' => PublicI18n::supported(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error editing blog: '.$e->getMessage());

            return redirect()->route('admin.blogs.index')
                ->with('error', 'Blog not found.');
        }
    }

    /**
     * Update the specified blog.
     */
    public function update(Request $request, $id)
    {
        try {
            $this->hydrateLegacyTranslationInput($request);
            Log::info('Blog update attempt', [
                'blog_id' => $id,
                'user_id' => auth()->id(),
                'has_file' => $request->hasFile('featured_image'),
                'request_data' => $request->except('_token', '_method', 'content'),
            ]);

            $blog = Blog::findOrFail($id);

            $request->validate([
                'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'remove_featured_image' => 'nullable|boolean',
                'tags' => 'nullable|string',
                'status' => 'required|in:draft,published',
                'primary_locale' => 'nullable|string|in:'.implode(',', PublicI18n::supported()),
                'translations.en.title' => 'required|string|max:255',
                'translations.en.slug' => 'nullable|string|max:255',
                'translations.en.excerpt' => 'nullable|string|max:300',
                'translations.en.content' => 'required|string',
                'translations.de.title' => 'nullable|string|max:255',
                'translations.de.slug' => 'nullable|string|max:255',
                'translations.de.excerpt' => 'nullable|string|max:300',
                'translations.de.content' => 'nullable|string',
                'translations.fr.title' => 'nullable|string|max:255',
                'translations.fr.slug' => 'nullable|string|max:255',
                'translations.fr.excerpt' => 'nullable|string|max:300',
                'translations.fr.content' => 'nullable|string',
                'translations.nl.title' => 'nullable|string|max:255',
                'translations.nl.slug' => 'nullable|string|max:255',
                'translations.nl.excerpt' => 'nullable|string|max:300',
                'translations.nl.content' => 'nullable|string',
            ]);

            $tags = null;
            if ($request->tags) {
                $tags = array_map('trim', explode(',', csv_text($request->tags)));
                $tags = array_filter($tags);
                $tags = array_values($tags);
            }

            $translations = $this->sanitizeTranslations((array) $request->input('translations', []), true);
            $en = $translations['en'];
            $data = [
                'title' => $en['title'],
                'primary_locale' => $request->input('primary_locale') ?: null,
                'excerpt' => filled($en['excerpt'])
                    ? Str::limit(trim((string) $en['excerpt']), 300)
                    : Str::limit(strip_tags((string) $en['content']), 160),
                'content' => $en['content'],
                'tags' => $tags,
                'status' => $request->status,
                'updated_by' => auth()->id(),
            ];

            $existingEn = $blog->translations()->where('locale', 'en')->first();
            $enSlug = $this->uniqueTranslationSlug(
                $en['slug'] ?: Str::slug($en['title']),
                $existingEn?->id
            );
            $data['slug'] = $this->uniqueBlogSlug($enSlug, $blog->id);

            if ($request->hasFile('featured_image')) {
                if ($blog->featured_image && Storage::disk('public')->exists($blog->featured_image)) {
                    Storage::disk('public')->delete($blog->featured_image);
                    Log::info('Old featured image deleted', ['path' => $blog->featured_image]);
                }

                $data['featured_image'] = $request->file('featured_image')->store('blogs/featured', 'public');
                Log::info('New featured image uploaded', ['path' => $data['featured_image']]);
            } elseif ($request->boolean('remove_featured_image')) {
                if ($blog->featured_image && Storage::disk('public')->exists($blog->featured_image)) {
                    Storage::disk('public')->delete($blog->featured_image);
                    Log::info('Featured image removed', ['path' => $blog->featured_image]);
                }
                $data['featured_image'] = null;
            }

            if ($request->status === 'published' && $blog->status !== 'published') {
                $data['published_at'] = now();
                Log::info('Blog published', ['blog_id' => $id]);
            } elseif ($request->status === 'draft' && $blog->status === 'published') {
                $data['published_at'] = null;
            }

            DB::transaction(function () use ($blog, $data, $translations, $enSlug) {
                $blog->update($data);

                foreach ($translations as $locale => $translationData) {
                    $existing = $blog->translations()->where('locale', $locale)->first();
                    $slug = $locale === 'en'
                        ? $enSlug
                        : $this->uniqueTranslationSlug(
                            $translationData['slug'] ?: Str::slug($translationData['title']),
                            $existing?->id
                        );

                    $blog->translations()->updateOrCreate(
                        ['locale' => $locale],
                        [
                            'title' => $translationData['title'],
                            'slug' => $slug,
                            'excerpt' => filled($translationData['excerpt'])
                                ? Str::limit(trim((string) $translationData['excerpt']), 300)
                                : Str::limit(strip_tags((string) $translationData['content']), 160),
                            'content' => $translationData['content'],
                            'meta_title' => null,
                            'meta_description' => null,
                            'is_published' => true,
                        ]
                    );
                }
            });

            Log::info('Blog updated successfully', [
                'blog_id' => $blog->id,
                'title' => $blog->title,
                'status' => $blog->status,
            ]);

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog "'.$blog->title.'" updated successfully!');
        } catch (ValidationException $e) {
            Log::error('Validation failed for blog update', ['errors' => $e->errors()]);

            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
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
            $blog = Blog::findOrFail($id);

            if ($blog->featured_image && Storage::disk('public')->exists($blog->featured_image)) {
                Storage::disk('public')->delete($blog->featured_image);
                Log::info('Featured image deleted', ['path' => $blog->featured_image]);
            }

            $blogTitle = $blog->title;
            $blog->delete();

            Log::info('Blog deleted successfully', [
                'blog_id' => $id,
                'title' => $blogTitle,
                'deleted_by' => auth()->id(),
            ]);

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog "'.$blogTitle.'" deleted successfully!');
        } catch (\Exception $e) {
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
                $blog->published_at = null;
                $message = 'Blog "'.$blog->title.'" moved to draft.';
                Log::info('Blog unpublished', ['blog_id' => $id, 'title' => $blog->title]);
            } else {
                $blog->status = 'published';
                $blog->published_at = now();
                $message = 'Blog "'.$blog->title.'" published successfully!';
                Log::info('Blog published', ['blog_id' => $id, 'title' => $blog->title]);
            }

            $blog->updated_by = auth()->id();
            $blog->save();

            return redirect()->route('admin.blogs.index')
                ->with('success', $message);
        } catch (\Exception $e) {
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

            $imagePath = $request->file('image')->store('blogs/content', 'public');
            $imageUrl = Storage::url($imagePath);

            Log::info('Image uploaded via editor', ['path' => $imagePath]);

            return response()->json([
                'success' => true,
                'url' => $imageUrl,
            ]);
        } catch (\Exception $e) {
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
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
                Log::info('Blog content image deleted', ['path' => $path]);
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
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
        $path = $url;
        if (str_contains($path, '://')) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '');
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        if (! str_starts_with($path, 'blogs/content/') && ! str_starts_with($path, 'blogs/featured/')) {
            return null;
        }

        return $path;
    }

    private function sanitizeTranslations(array $translations, bool $requireEnglish): array
    {
        $normalized = [];

        foreach (PublicI18n::supported() as $locale) {
            $item = (array) ($translations[$locale] ?? []);
            $title = trim((string) ($item['title'] ?? ''));
            $slug = trim((string) ($item['slug'] ?? ''));
            $excerpt = isset($item['excerpt']) ? trim((string) $item['excerpt']) : null;
            $content = trim((string) ($item['content'] ?? ''));

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
                'content' => $content,
            ];
        }

        return $normalized;
    }

    private function hydrateLegacyTranslationInput(Request $request): void
    {
        $translations = (array) $request->input('translations', []);
        $en = (array) ($translations['en'] ?? []);

        if (($en['title'] ?? '') === '' && filled($request->input('title'))) {
            $en['title'] = (string) $request->input('title');
        }
        if (($en['slug'] ?? '') === '' && filled($request->input('slug'))) {
            $en['slug'] = (string) $request->input('slug');
        }
        if (($en['excerpt'] ?? '') === '' && filled($request->input('excerpt'))) {
            $en['excerpt'] = (string) $request->input('excerpt');
        }
        if (($en['content'] ?? '') === '' && filled($request->input('content'))) {
            $en['content'] = (string) $request->input('content');
        }

        $translations['en'] = $en;
        $request->merge(['translations' => $translations]);
    }

    private function uniqueTranslationSlug(string $slug, ?int $ignoreTranslationId = null): string
    {
        $base = Str::slug($slug) ?: Str::random(8);
        $candidate = $base;
        $counter = 1;

        while (
            BlogTranslation::query()
                ->when($ignoreTranslationId, fn ($query) => $query->where('id', '!=', $ignoreTranslationId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    private function uniqueBlogSlug(string $slug, ?int $ignoreBlogId = null): string
    {
        $base = Str::slug($slug) ?: Str::random(8);
        $candidate = $base;
        $counter = 1;

        while (
            Blog::query()
                ->when($ignoreBlogId, fn ($query) => $query->where('id', '!=', $ignoreBlogId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }
}
