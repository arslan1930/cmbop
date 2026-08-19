<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PublicI18n;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserUiAndTrustFooterTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->advertiser->roles()->attach($role->id);
    }

    public function test_footer_links_the_trustpilot_profile(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(config('services.trustpilot.review_url'), $html);
        $this->assertStringContainsString('trustpilot-trust', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('Read our reviews', $html);
    }

    public function test_advertiser_shell_footer_shows_trustpilot(): void
    {
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('trustpilot-trust', $html);
        $this->assertStringContainsString('app-shell-footer__grid', $html);
        $this->assertStringContainsString('app-shell-footer__left', $html);
        $this->assertStringContainsString('app-shell-footer__legal', $html);
        $this->assertStringContainsString('Payments secured by', $html);
        $this->assertStringNotContainsString('Card details never touch our servers.', $html);
        $this->assertStringContainsString(config('services.trustpilot.review_url'), $html);
        $this->assertStringContainsString('helpFeedbackHide', $html);
        $this->assertStringContainsString('helpFeedbackShow', $html);
        // Pagination markup only renders when lastPage > 1; chrome lives in catalog.css.
        $this->assertStringContainsString(
            '.catalog-pagination',
            (string) file_get_contents(public_path('assets/css/catalog.css'))
        );
        $shell = (string) file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('.app-shell-footer__grid', $shell);
        $this->assertStringContainsString('.app-shell-footer__left', $shell);
        $this->assertMatchesRegularExpression(
            '/\.app-shell-footer__grid\s*\{[^}]*flex-wrap:\s*nowrap/s',
            $shell
        );
        $this->assertMatchesRegularExpression(
            '/\.app-shell-footer__left\s*\{[^}]*flex:\s*0 1 auto/s',
            $shell
        );
        $this->assertMatchesRegularExpression(
            '/#content,\s*#main-content,\s*footer\.app-shell-footer,\s*body > footer\s*\{[^}]*width:\s*calc\(\s*100%\s*-\s*var\(--shell-sidebar-width\)/s',
            $shell
        );
        $this->assertMatchesRegularExpression(
            '/\.app-shell-footer__grid\s*>\s*\.payment-trust\s*\{[^}]*flex-wrap:\s*nowrap/s',
            $shell
        );
        $this->assertMatchesRegularExpression(
            '/\.app-shell-footer__grid \.payment-trust__methods\s*\{[^}]*flex-wrap:\s*nowrap/s',
            $shell
        );
        $this->assertMatchesRegularExpression(
            '/\.app-shell-footer__grid \.payment-trust__methods\s*\{[^}]*flex:\s*0 0 auto/s',
            $shell
        );
        $this->assertStringContainsString('flex-wrap: nowrap', $shell);
        $this->assertStringContainsString('text-overflow: ellipsis', $shell);
        $this->assertStringNotContainsString('"legal secure"', $shell);
    }

    public function test_the_trust_badge_claims_no_rating_we_cannot_prove(): void
    {
        $partial = (string) file_get_contents(resource_path('views/partials/trustpilot-trust.blade.php'));

        // Printing a score or review count we do not hold would be misleading.
        $this->assertDoesNotMatchRegularExpression('/\d(\.\d)?\s*(\/\s*5|out of 5|stars?)/i', $partial);
        $this->assertStringNotContainsString('reviews)', $partial);
    }

    public function test_review_and_evaluate_urls_are_used_for_their_own_purpose(): void
    {
        $this->assertStringContainsString('/review/', (string) config('services.trustpilot.review_url'));
        $this->assertStringContainsString('/evaluate/', (string) config('services.trustpilot.evaluate_url'));

        // The email asks for a review, so it must open the write-a-review form.
        $mail = (string) file_get_contents(app_path('Mail/TrustpilotReviewRequest.php'));
        $this->assertStringContainsString("config('services.trustpilot.evaluate_url')", $mail);
    }

    public function test_trustpilot_strings_exist_in_every_locale(): void
    {
        foreach (PublicI18n::supported() as $locale) {
            $messages = require resource_path('lang/'.$locale.'/messages.php');
            foreach (['trustpilot_read_reviews', 'trustpilot_aria'] as $key) {
                $this->assertArrayHasKey($key, $messages, $locale.' is missing '.$key);
                $this->assertNotSame('', trim((string) $messages[$key]));
            }
        }
    }

    public function test_help_feedback_widget_can_hide_and_show(): void
    {
        $blade = (string) file_get_contents(resource_path('views/components/help-feedback-widget.blade.php'));

        $this->assertStringContainsString('helpFeedbackHide', $blade);
        $this->assertStringContainsString('helpFeedbackShow', $blade);
        $this->assertStringContainsString('helpFeedback.hidden', $blade);
        $this->assertStringContainsString('is-hidden', $blade);
    }
}
