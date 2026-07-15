<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlogRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_index_loads_via_controller_route(): void
    {
        $this->get(route('blog.index'))
            ->assertOk()
            ->assertViewIs('pages.blog');
    }

    public function test_blog_show_displays_published_post(): void
    {
        $author = User::factory()->create();

        $blog = Blog::create([
            'title' => 'Test Post',
            'slug' => 'test-post',
            'content' => 'Full content here',
            'author' => $author->name,
            'status' => 'published',
            'published_at' => now(),
            'created_by' => $author->id,
        ]);

        $this->get(route('blog.show', $blog->slug))
            ->assertOk()
            ->assertViewIs('pages.blog-single')
            ->assertSee('Test Post');
    }

    public function test_blog_show_returns_404_for_draft_post(): void
    {
        $author = User::factory()->create();

        Blog::create([
            'title' => 'Draft Post',
            'slug' => 'draft-post',
            'content' => 'Full content here',
            'author' => $author->name,
            'status' => 'draft',
            'published_at' => null,
            'created_by' => $author->id,
        ]);

        $this->get(route('blog.show', 'draft-post'))->assertNotFound();
    }

    public function test_blog_show_returns_404_for_unknown_slug(): void
    {
        $this->get(route('blog.show', 'missing-'.Str::random(8)))->assertNotFound();
    }
}
