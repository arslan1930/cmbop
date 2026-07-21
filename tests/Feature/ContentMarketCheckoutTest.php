<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentMarketCheckoutTest extends TestCase
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

    private function site(User $publisher, string $country, string $language): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => "Site {$country}-{$language}",
            'site_url' => "https://{$country}-{$language}.example",
            'domain' => "{$country}-{$language}.example",
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => $country,
            'language' => $language,
            'countries' => [$country],
            'languages' => [$language],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_upload_requires_country_and_language(): void
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $path = sys_get_temp_dir().'/market-upload.docx';
        $this->makeDocxFile($path, str_repeat('Quality editorial content for marketplace testing with useful insights for readers. ', 60));

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-library.upload'), [
            'file' => new UploadedFile($path, 'article.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
            'title' => 'No market selected',
        ]);

        $response->assertStatus(422);
        @unlink($path);
    }

    public function test_checkout_rejects_article_with_mismatched_market(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $deSite = $this->site($publisher, 'de', 'de');
        $enArticle = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [['id' => $deSite->id, 'name' => $deSite->site_name, 'quantity' => 1]],
                'checkout_content_submission_id' => $enArticle->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'MKT1',
                'publication_mode' => 'immediate',
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertStringContainsString('language', strtolower((string) $response->json('message')));
    }

    public function test_library_order_rejects_mismatched_sites(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $frSite = $this->site($publisher, 'fr', 'fr');
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $article))
            ->assertRedirect();

        $this->actingAs($advertiser)
            ->withSession([
                'checkout_content_submission_id' => $article->id,
                'ordering_from_library' => true,
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $frSite->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
