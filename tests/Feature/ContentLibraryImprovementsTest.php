<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryImprovementsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug, float $price = 40, string $country = 'us', string $language = 'en'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => $country,
            'language' => $language,
            'countries' => [$country],
            'languages' => [$language],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_library_compact_table_filters_and_status_labels(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'alpha');

        $available = $this->createApprovedSubmission($advertiser, null, 0, 'anchor a', 'https://example.com/a');
        $available->update(['title' => 'Growth Playbook']);

        $ordered = $this->createApprovedSubmission($advertiser, $site->id, 0, 'anchor b', 'https://example.com/b');
        $ordered->update(['title' => 'Ordered Piece']);
        $order = $this->makeOrder($advertiser);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $ordered->id,
        ]);
        $ordered->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $uk = $this->createApprovedSubmission($advertiser, null, 0, 'anchor c', 'https://example.com/c', 'gb', 'en');
        $uk->update(['title' => 'UK Guide']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Title')
            ->assertSee('Market')
            ->assertSee('Scores')
            ->assertSee('Growth Playbook')
            ->assertSee('Available');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'available']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertSee('UK Guide')
            ->assertDontSee('Ordered Piece');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Ordered Piece')
            ->assertDontSee('Growth Playbook')
            ->assertSee('In progress');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'Growth']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertDontSee('UK Guide');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['country' => 'gb']))
            ->assertOk()
            ->assertSee('UK Guide')
            ->assertDontSee('Growth Playbook');
    }

    public function test_library_shows_published_live_link(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'live');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $submission->update(['title' => 'Live Article']);
        $order = $this->makeOrder($advertiser);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
            'live_url' => 'https://live.example/post',
            'live_url_submitted_at' => now(),
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'published']))
            ->assertOk()
            ->assertSee('Live Article')
            ->assertSee('Published')
            ->assertSee('https://live.example/post')
            ->assertSee('Published on '.$site->site_name);
    }

    public function test_advertiser_can_archive_and_restore_article(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Archive Me']);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.archive', $submission))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($submission->fresh()->archived_at);
        $this->assertFalse($submission->fresh()->canBeOrdered());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertDontSee('Archive Me');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'archived']))
            ->assertOk()
            ->assertSee('Archive Me');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.restore', $submission))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($submission->fresh()->archived_at);
        $this->assertTrue($submission->fresh()->canBeOrdered());
    }

    public function test_library_order_button_links_to_catalog_flow(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Ready Article']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Order')
            ->assertSee(route('advertiser.content-library.order', $submission), false)
            ->assertDontSee('id="orderContentModal"', false);
    }

    public function test_advertiser_can_rename_and_delete_unlinked_library_article(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Old Title']);

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'title' => 'New Title',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.title', 'New Title');

        $this->assertSame('New Title', $submission->fresh()->title);

        $this->actingAs($advertiser)
            ->deleteJson(route('advertiser.content-submissions.destroy', $submission))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('content_submissions', ['id' => $submission->id]);
    }

    public function test_cannot_delete_article_linked_to_order(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'linked');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $order = $this->makeOrder($advertiser);
        $submission->update(['order_id' => $order->id]);

        $this->actingAs($advertiser)
            ->deleteJson(route('advertiser.content-submissions.destroy', $submission))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('content_submissions', ['id' => $submission->id]);
    }

    public function test_library_availability_helper_on_model(): void
    {
        $advertiser = $this->advertiser();
        $available = $this->createApprovedSubmission($advertiser);
        $this->assertSame('available', $available->libraryAvailability());

        $inProgress = $this->createApprovedSubmission($advertiser);
        $order = $this->makeOrder($advertiser);
        $inProgress->update(['order_id' => $order->id]);
        $this->assertSame('in_progress', $inProgress->fresh()->libraryAvailability());

        $expired = $this->createApprovedSubmission($advertiser);
        $expired->update(['expires_at' => now()->subDay()]);
        $this->assertSame('expired', $expired->fresh()->libraryAvailability());

        $archived = $this->createApprovedSubmission($advertiser);
        $archived->archive();
        $this->assertSame('archived', $archived->fresh()->libraryAvailability());
    }

    public function test_upload_button_sits_under_content_library_heading(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $headingPos = strpos($html, 'Content Library');
        $uploadPos = strpos($html, 'id="openUploadModalBtn"');
        $this->assertNotFalse($headingPos);
        $this->assertNotFalse($uploadPos);
        $this->assertGreaterThan($headingPos, $uploadPos);
        $this->assertStringContainsString('articleQuillEditor', $html);
        $this->assertStringContainsString('Edit article', $html);
    }

    public function test_advertiser_can_edit_article_html_with_links_and_images(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $html = '<p>Updated article body with a <a href="https://example.com/new-guide">helpful guide</a> for marketers.</p>'
            .'<p><img src="/storage/content-articles/demo.png" alt="Chart"></p>'
            .'<p>More compliant content about software tools and productivity for digital teams worldwide.</p>';

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
                'title' => 'Edited Doc Title',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('submission.title', 'Edited Doc Title');

        $fresh = $submission->fresh();
        $this->assertSame('Edited Doc Title', $fresh->title);
        $this->assertStringContainsString('helpful guide', (string) $fresh->preview_html);
        $this->assertStringContainsString('<img', (string) $fresh->preview_html);
        $this->assertStringContainsString('src="/storage/content-articles/demo.png"', (string) $fresh->preview_html);
        $this->assertStringContainsString('https://example.com/new-guide', (string) $fresh->target_url);
        $this->assertNotEmpty($fresh->draft_payload['content_history'] ?? null);
        $this->assertNotEmpty($fresh->articleHistory());
    }

    public function test_preview_rewrites_absolute_storage_urls_to_relative(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $html = '<p>Guide with an embedded figure for marketers.</p>'
            .'<p><img src="http://127.0.0.1:8000/storage/content-articles/'.$advertiser->id.'/fig.png" alt="Fig"></p>'
            .'<p>More compliant content about software tools and productivity for digital teams worldwide.</p>';

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => $html,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $submission->fresh();
        $this->assertStringContainsString(
            'src="/storage/content-articles/'.$advertiser->id.'/fig.png"',
            (string) $fresh->preview_html
        );
        $this->assertStringNotContainsString('127.0.0.1', (string) $fresh->preview_html);
    }

    public function test_advertiser_can_upload_editor_image(): void
    {
        Storage::fake('public');
        $advertiser = $this->advertiser();
        $file = UploadedFile::fake()->image('figure.png', 40, 40);

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image'), [
                'image' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['url']);

        $url = (string) $response->json('url');
        $this->assertStringStartsWith('/storage/content-articles/', $url);
    }

    private function makeOrder(User $advertiser): Order
    {
        return Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 46,
            'tax' => 0,
            'total_amount' => 46,
            'payment_method' => 'wallet',
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ]);
    }
}
