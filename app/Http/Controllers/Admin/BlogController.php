<?php
// app/Http/Controllers/Admin/BlogController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class BlogController extends Controller
{
    /**
     * Display a listing of blogs.
     */
    public function index()
    {
        try {
            $blogs = Blog::orderBy('created_at', 'desc')->paginate(20);
            return view('admin.blogs.index', compact('blogs'));
        } catch (\Exception $e) {
            Log::error('Error fetching blogs: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to load blogs: ' . $e->getMessage());
        }
    }

    /**
     * Show the form for creating a new blog.
     */
    public function create()
    {
        return view('admin.blogs.create');
    }

    /**
     * Store a newly created blog.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'tags' => 'nullable|string',
                'status' => 'required|in:draft,published'
            ]);

            // Check if user is authenticated
            if (!auth()->check()) {
                throw new \Exception('You must be logged in to create a blog post.');
            }

            // Handle featured image upload (optional for drafts / text-first SEO posts)
            $featuredImage = null;
            if ($request->hasFile('featured_image')) {
                $featuredImage = $request->file('featured_image')->store('blogs/featured', 'public');
                Log::info('Featured image uploaded', ['path' => $featuredImage]);
            }

            // Process tags
            $tags = null;
            if ($request->tags) {
                $tags = array_map('trim', explode(',', $request->tags));
                $tags = array_filter($tags);
                $tags = array_values($tags);
            }

            // Generate unique slug
            $slug = Str::slug($request->title);
            $originalSlug = $slug;
            $counter = 1;
            while (Blog::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            // Create blog post (tags cast to array on the model)
            $blog = Blog::create([
                'title' => $request->title,
                'slug' => $slug,
                'excerpt' => Str::limit(strip_tags($request->content), 160),
                'content' => $request->content,
                'featured_image' => $featuredImage,
                'author' => auth()->user()->name,
                'tags' => $tags,
                'status' => $request->status,
                'published_at' => $request->status === 'published' ? now() : null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);

            Log::info('Blog created successfully', [
                'blog_id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug
            ]);

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog "' . $blog->title . '" created successfully!');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed for blog creation', ['errors' => $e->errors()]);
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            Log::error('Blog creation failed: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return redirect()->back()
                ->with('error', 'Failed to create blog: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified blog.
     */
    public function show($id)
    {
        try {
            $blog = Blog::findOrFail($id);
            return view('admin.blogs.show', compact('blog'));
        } catch (\Exception $e) {
            Log::error('Error showing blog: ' . $e->getMessage());
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
            $blog = Blog::findOrFail($id);
            return view('admin.blogs.edit', compact('blog'));
        } catch (\Exception $e) {
            Log::error('Error editing blog: ' . $e->getMessage());
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
            Log::info('Blog update attempt', [
                'blog_id' => $id,
                'user_id' => auth()->id(),
                'has_file' => $request->hasFile('featured_image'),
                'request_data' => $request->except('_token', '_method', 'content')
            ]);

            $blog = Blog::findOrFail($id);

            // Validate request - SIMPLIFIED validation for debugging
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'tags' => 'nullable|string',
                'status' => 'required|in:draft,published'
            ]);

            // Process tags
            $tags = null;
            if ($request->tags) {
                $tags = array_map('trim', explode(',', $request->tags));
                $tags = array_filter($tags);
                $tags = array_values($tags);
            }

            // Prepare update data (tags cast to array on the model)
            $data = [
                'title' => $request->title,
                'excerpt' => Str::limit(strip_tags($request->content), 160),
                'content' => $request->content,
                'tags' => $tags,
                'status' => $request->status,
                'updated_by' => auth()->id()
            ];

            // Update slug only if title changed
            if ($request->title !== $blog->title) {
                $slug = Str::slug($request->title);
                $originalSlug = $slug;
                $counter = 1;
                while (Blog::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                    $slug = $originalSlug . '-' . $counter;
                    $counter++;
                }
                $data['slug'] = $slug;
            }

            // Handle featured image upload
            if ($request->hasFile('featured_image')) {
                // Delete old image if exists
                if ($blog->featured_image && Storage::disk('public')->exists($blog->featured_image)) {
                    Storage::disk('public')->delete($blog->featured_image);
                    Log::info('Old featured image deleted', ['path' => $blog->featured_image]);
                }
                
                $data['featured_image'] = $request->file('featured_image')->store('blogs/featured', 'public');
                Log::info('New featured image uploaded', ['path' => $data['featured_image']]);
            }

            // Handle published_at
            if ($request->status === 'published' && $blog->status !== 'published') {
                $data['published_at'] = now();
                Log::info('Blog published', ['blog_id' => $id]);
            } elseif ($request->status === 'draft' && $blog->status === 'published') {
                $data['published_at'] = null;
            }

            // Update blog
            $blog->update($data);

            Log::info('Blog updated successfully', [
                'blog_id' => $blog->id,
                'title' => $blog->title,
                'status' => $blog->status
            ]);

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog "' . $blog->title . '" updated successfully!');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed for blog update', ['errors' => $e->errors()]);
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            Log::error('Blog update failed: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return redirect()->back()
                ->with('error', 'Failed to update blog: ' . $e->getMessage())
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
            
            // Delete featured image if exists
            if ($blog->featured_image && Storage::disk('public')->exists($blog->featured_image)) {
                Storage::disk('public')->delete($blog->featured_image);
                Log::info('Featured image deleted', ['path' => $blog->featured_image]);
            }
            
            $blogTitle = $blog->title;
            $blog->delete();

            Log::info('Blog deleted successfully', [
                'blog_id' => $id,
                'title' => $blogTitle,
                'deleted_by' => auth()->id()
            ]);

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog "' . $blogTitle . '" deleted successfully!');
                
        } catch (\Exception $e) {
            Log::error('Blog deletion failed: ' . $e->getMessage());
            return redirect()->route('admin.blogs.index')
                ->with('error', 'Failed to delete blog: ' . $e->getMessage());
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
                $message = 'Blog "' . $blog->title . '" moved to draft.';
                Log::info('Blog unpublished', ['blog_id' => $id, 'title' => $blog->title]);
            } else {
                $blog->status = 'published';
                $blog->published_at = now();
                $message = 'Blog "' . $blog->title . '" published successfully!';
                Log::info('Blog published', ['blog_id' => $id, 'title' => $blog->title]);
            }
            
            $blog->updated_by = auth()->id();
            $blog->save();

            return redirect()->route('admin.blogs.index')
                ->with('success', $message);
                
        } catch (\Exception $e) {
            Log::error('Blog status toggle failed: ' . $e->getMessage());
            return redirect()->route('admin.blogs.index')
                ->with('error', 'Failed to change blog status: ' . $e->getMessage());
        }
    }

    /**
     * Upload image from Quill editor.
     */
    public function uploadImage(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120'
            ]);

            $imagePath = $request->file('image')->store('blogs/content', 'public');
            $imageUrl = Storage::url($imagePath);

            Log::info('Image uploaded via editor', ['path' => $imagePath]);

            return response()->json([
                'success' => true,
                'url' => $imageUrl
            ]);
            
        } catch (\Exception $e) {
            Log::error('Image upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }
}