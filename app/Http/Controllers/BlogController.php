<?php
// app/Http/Controllers/BlogController.php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BlogController extends Controller
{
    /**
     * Display a listing of published blog posts.
     */
    public function index()
    {
        $blog = Blog::published()
            ->orderByDesc('published_at')
            ->paginate(12);

        return view('pages.blog', compact('blog'));
    }

    /**
     * Display a single blog post.
     */
    public function show($slug)
    {
        $blog = Blog::published()
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Blog::published()
            ->where('id', '!=', $blog->id)
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return view('pages.blog-single', compact('blog', 'related'));
    }
}