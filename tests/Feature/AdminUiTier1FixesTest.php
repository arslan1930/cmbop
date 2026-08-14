<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUiTier1FixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    public function test_deposits_page_does_not_leak_raw_blade_directives(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.deposits', [
                'search' => ['DEP'],
                'status' => ['pending'],
            ]))
            ->assertOk()
            ->assertDontSee('Array to string conversion', false)
            ->getContent();

        $this->assertStringNotContainsString('@endsection', $html);
        $this->assertStringNotContainsString('@if', $html);
    }

    public function test_deposit_modal_escapes_reference_and_method(): void
    {
        $js = $this->actingAs($this->admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('escapeHtml(deposit.reference_code)', $js);
        $this->assertStringNotContainsString('${deposit.reference_code}', $js);
        $this->assertStringNotContainsString('${deposit.payment_method.toUpperCase()}', $js);
    }

    public function test_payments_table_escapes_api_values(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function escapeHtml(', $html);
        $this->assertStringContainsString('escapeHtml(order.order_number)', $html);
        $this->assertStringContainsString('escapeHtml(order.reference_code)', $html);
        // Raw concatenation of user-controlled fields must be gone.
        $this->assertStringNotContainsString("'<strong>' + order.order_number", $html);
        $this->assertStringNotContainsString("+ (order.user ? order.user.name : 'N/A') +", $html);
        $this->assertStringNotContainsString("'<code class=\"small\">' + order.reference_code", $html);
    }

    public function test_site_ratings_dialog_does_not_interpolate_comment_into_markup(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.site-ratings.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function escapeHtml(', $html);
        $this->assertStringContainsString('escapeHtml(s.label)', $html);
        $this->assertStringNotContainsString('value="${btn.dataset.comment', $html);
        $this->assertStringContainsString("getElementById('swal-comment').value = btn.dataset.comment", $html);
    }

    public function test_blog_preview_strips_scripts_but_keeps_formatting(): void
    {
        $blog = Blog::create([
            'title' => 'Tier 1 Preview',
            'slug' => 'tier-1-preview-'.uniqid(),
            'content' => '<h2>Heading</h2><p>Body copy</p><script>alert("xss")</script>'
                .'<p onclick="alert(1)">Handler</p>',
            'excerpt' => 'Preview excerpt',
            'status' => 'draft',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.blogs.show', $blog->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('onclick="alert(1)"', $html);
        $this->assertStringContainsString('<h2>Heading</h2>', $html);
        $this->assertStringContainsString('Body copy', $html);
    }

    public function test_blogs_empty_state_spans_every_column(): void
    {
        // The controller always seeds curated posts, so the empty row cannot be
        // reached over HTTP — assert the template keeps colspan in step instead.
        $blade = file_get_contents(resource_path('views/admin/blogs/index.blade.php'));

        $headerCount = substr_count($blade, '<th>');
        $this->assertSame(9, $headerCount);
        $this->assertStringContainsString('colspan="'.$headerCount.'"', $blade);

        $this->actingAs($this->admin)
            ->get(route('admin.blogs.index'))
            ->assertOk();
    }

    public function test_users_table_keeps_hidden_role_input_inside_a_cell(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="role-id"', $html);
        // A bare input directly under <tr> is invalid markup.
        $this->assertDoesNotMatchRegularExpression(
            '/<tr\b[^>]*>\s*(<!--.*?-->\s*)*<input\b/s',
            $html
        );
    }

    public function test_admin_layout_renders_per_page_title(): void
    {
        $payments = $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('<title>Payments — SEOLinkBuildings</title>', $payments);

        $users = $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('<title>Admin Dashboard — SEOLinkBuildings</title>', $users);
    }
}
