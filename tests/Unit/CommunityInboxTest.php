<?php

namespace Tests\Unit;

use App\Models\WebsiteSuggestion;
use App\Support\CommunityInbox;
use Tests\TestCase;

class CommunityInboxTest extends TestCase
{
    public function test_claims_include_approved_not_accepted(): void
    {
        $this->assertContains('approved', CommunityInbox::statusesFor('claims'));
        $this->assertNotContains('accepted', CommunityInbox::statusesFor('claims'));
        $this->assertNotContains('resolved', CommunityInbox::statusesFor('claims'));
        $this->assertNull(CommunityInbox::normalizeStatus('claims', 'accepted'));
        $this->assertSame('approved', CommunityInbox::normalizeStatus('claims', 'approved'));
    }

    public function test_problems_and_suggestions_use_resolved_not_accepted(): void
    {
        foreach (['problems', 'suggestions'] as $tab) {
            $this->assertContains('resolved', CommunityInbox::statusesFor($tab));
            $this->assertNotContains('accepted', CommunityInbox::statusesFor($tab));
            $this->assertNotContains('approved', CommunityInbox::statusesFor($tab));
            $this->assertNull(CommunityInbox::normalizeStatus($tab, 'accepted'));
            $this->assertNull(CommunityInbox::normalizeStatus($tab, 'approved'));
        }
    }

    public function test_websites_use_accepted_not_approved(): void
    {
        $this->assertContains('accepted', CommunityInbox::statusesFor('websites'));
        $this->assertNotContains('approved', CommunityInbox::statusesFor('websites'));
        $this->assertNotContains('resolved', CommunityInbox::statusesFor('websites'));
        $this->assertSame('accepted', CommunityInbox::normalizeStatus('websites', 'accepted'));
        $this->assertNull(CommunityInbox::normalizeStatus('websites', 'approved'));
    }

    public function test_tab_query_drops_status_that_is_illegal_on_the_target_tab(): void
    {
        $this->assertSame(
            ['tab' => 'problems'],
            CommunityInbox::tabQuery('problems', null, 'approved')
        );
        $this->assertSame(
            ['tab' => 'claims', 'status' => 'approved'],
            CommunityInbox::tabQuery('claims', null, 'approved')
        );
        $this->assertSame(
            ['tab' => 'problems', 'q' => 'checkout'],
            CommunityInbox::tabQuery('problems', 'checkout', 'accepted')
        );
        $this->assertSame(
            ['tab' => 'websites', 'q' => 'saas', 'status' => 'pending'],
            CommunityInbox::tabQuery('websites', 'saas', 'pending')
        );
    }

    public function test_unknown_tab_and_array_status_are_ignored(): void
    {
        $this->assertSame('problems', CommunityInbox::normalizeTab('nope'));
        $this->assertSame('problems', CommunityInbox::normalizeTab(['injected']));
        $this->assertNull(CommunityInbox::normalizeStatus('problems', ['pending']));
        $this->assertNull(CommunityInbox::normalizeStatus('problems', ''));
    }

    public function test_landing_tab_picks_the_first_pending_inbox(): void
    {
        $this->assertSame('problems', CommunityInbox::landingTab([
            'problems' => 0,
            'suggestions' => 0,
            'websites' => 0,
            'claims' => 0,
        ]));
        $this->assertSame('claims', CommunityInbox::landingTab([
            'problems' => 0,
            'suggestions' => 0,
            'websites' => 0,
            'claims' => 2,
        ]));
        $this->assertSame('suggestions', CommunityInbox::landingTab([
            'problems' => 0,
            'suggestions' => 1,
            'websites' => 4,
            'claims' => 2,
        ]));
    }

    public function test_safe_http_url_rejects_non_http_schemes(): void
    {
        $this->assertSame('https://app.example/checkout', CommunityInbox::safeHttpUrl('https://app.example/checkout'));
        $this->assertSame('http://app.example/x', CommunityInbox::safeHttpUrl('http://app.example/x'));
        $this->assertNull(CommunityInbox::safeHttpUrl('javascript:alert(1)'));
        $this->assertNull(CommunityInbox::safeHttpUrl('/relative'));
        $this->assertNull(CommunityInbox::safeHttpUrl(['https://x.example']));
    }

    public function test_status_badge_classes(): void
    {
        $this->assertSame('bg-warning text-dark', CommunityInbox::statusBadgeClass('pending'));
        $this->assertSame('bg-success', CommunityInbox::statusBadgeClass('approved'));
        $this->assertSame('bg-danger', CommunityInbox::statusBadgeClass('rejected'));
    }

    public function test_create_listing_query_prefills_safe_http_and_iso_codes(): void
    {
        $suggestion = new WebsiteSuggestion([
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'country' => 'US',
            'language' => 'en',
        ]);
        $suggestion->id = 12;

        $this->assertSame([
            'suggestion_id' => 12,
            'site_name' => 'Fresh Tech Blog',
            'site_url' => 'https://fresh-tech.example',
            'country' => 'us',
            'language' => 'en',
        ], CommunityInbox::createListingQuery($suggestion));
    }

    public function test_create_listing_query_drops_unsafe_url_and_long_locale(): void
    {
        $suggestion = new WebsiteSuggestion([
            'website_name' => 'Bad Site',
            'website_url' => 'javascript:alert(1)',
            'country' => 'United States',
            'language' => 'english',
        ]);
        $suggestion->id = 4;

        $this->assertSame([
            'suggestion_id' => 4,
            'site_name' => 'Bad Site',
        ], CommunityInbox::createListingQuery($suggestion));
    }

    public function test_suggestion_lookup_domain_uses_url_host_when_domain_is_empty(): void
    {
        $fromUrl = new WebsiteSuggestion([
            'website_url' => 'https://fresh-tech.example/path',
            'domain' => null,
        ]);
        $this->assertSame('fresh-tech.example', CommunityInbox::suggestionLookupDomain($fromUrl));

        $fromDomain = new WebsiteSuggestion([
            'website_url' => 'https://other.example',
            'domain' => 'WWW.Owned-News.example',
        ]);
        $this->assertSame('owned-news.example', CommunityInbox::suggestionLookupDomain($fromDomain));

        $urlInDomain = new WebsiteSuggestion([
            'website_url' => 'https://other.example',
            'domain' => 'https://fresh-tech.example/path',
        ]);
        $this->assertSame('fresh-tech.example', CommunityInbox::suggestionLookupDomain($urlInDomain));

        $unsafe = new WebsiteSuggestion([
            'website_url' => 'javascript:alert(1)',
            'domain' => null,
        ]);
        $this->assertSame('', CommunityInbox::suggestionLookupDomain($unsafe));
    }

    public function test_suggestion_id_from_keeps_scalars_and_drops_arrays(): void
    {
        $this->assertSame(12, CommunityInbox::suggestionIdFrom(12));
        $this->assertSame(12, CommunityInbox::suggestionIdFrom('12'));
        $this->assertSame(0, CommunityInbox::suggestionIdFrom(['12']));
        $this->assertSame(0, CommunityInbox::suggestionIdFrom(true));
        $this->assertSame(0, CommunityInbox::suggestionIdFrom(null));
        $this->assertSame(0, CommunityInbox::suggestionIdFrom(''));
    }
}
