<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The add-site wizard splits the listing across three panes, so a publisher
 * never saw the whole thing at once before it went to review. A wrong price or
 * country is cheap to fix before staff look at it and expensive afterwards.
 */
class SiteListingPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function publisherPage(): string
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->attach($role->id);

        return $this->actingAs($user->fresh())
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();
    }

    private function publisherJs(): string
    {
        return (string) file_get_contents(public_path('assets/js/publisher-websites.js'));
    }

    public function test_the_listing_is_shown_for_review_before_it_is_submitted(): void
    {
        $page = $this->publisherPage();

        $this->assertStringContainsString('id="sitePreviewModal"', $page);
        $this->assertStringContainsString('Check your listing before you submit', $page);
        $this->assertStringContainsString('id="sitePreviewConfirmBtn"', $page);
        $this->assertStringContainsString('Back to edit', $page);
    }

    public function test_submitting_is_gated_on_the_review(): void
    {
        $page = $this->publisherPage();
        $js = $this->publisherJs();

        // A valid form opens the preview rather than posting; only the confirm
        // button lets the second submit through.
        $this->assertStringContainsString('publisher-websites.js', $page);
        $this->assertStringContainsString('window.showSiteListingPreview', $page);
        $this->assertStringContainsString('!window.sitePreviewConfirmed', $page);
        $this->assertStringContainsString('showSiteListingPreview', $js);
        $this->assertStringContainsString('publisherWebsitesListingPreview', $js);
        $this->assertStringContainsString('} else if (!sitePreviewConfirmed) {', $js);
        $this->assertStringContainsString('sitePreviewConfirmed = true;', $js);
    }

    public function test_the_review_covers_the_fields_that_are_costly_to_get_wrong(): void
    {
        $this->publisherPage();
        $js = $this->publisherJs();

        foreach ([
            'Price advertisers pay',
            'Country',
            'Language',
            'Niches',
            'Link type',
            'Turnaround time',
            'Description advertisers will read',
        ] as $label) {
            $this->assertStringContainsString($label, $js, "Preview is missing: {$label}");
        }
    }

    public function test_preview_description_expands_in_place_with_show_more(): void
    {
        $page = $this->publisherPage();
        $js = $this->publisherJs();
        $css = (string) file_get_contents(public_path('assets/css/publisher-websites.css'));

        $this->assertStringContainsString('publisher-websites.js', $page);
        $this->assertStringContainsString('function previewDescriptionBlock', $js);
        $this->assertStringContainsString('site-preview-desc-toggle', $js);
        $this->assertStringContainsString('Show more', $js);
        $this->assertStringContainsString('syncSitePreviewDescToggles', $js);
        $this->assertStringContainsString('site-preview-desc is-clamped', $js);
        $this->assertStringContainsString('.site-preview-desc.is-clamped', $css);
    }

    public function test_preview_reads_the_checked_link_type_radio(): void
    {
        $js = $this->publisherJs();

        $this->assertStringContainsString('$el.filter(\':checked\').val()', $js);
        $this->assertStringContainsString('function previewLinkTypeLabel', $js);
        $this->assertStringContainsString('for="linkTypeNofollow"', $this->publisherPage());
    }
}
