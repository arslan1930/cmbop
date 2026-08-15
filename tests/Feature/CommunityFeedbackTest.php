<?php

namespace Tests\Feature;

use App\Mail\CommunityFeedbackReviewed;
use App\Mail\WebsiteSuggestionReviewed;
use App\Models\InAppNotification;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Services\CommunityInboxNotifier;
use App\Support\CommunityInbox;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommunityFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Owned News Daily',
            'site_url' => 'https://owned-news.example',
            'domain' => 'owned-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'A publisher site for claim tests',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_guest_can_report_a_problem(): void
    {
        $this->postJson(route('feedback.problem'), [
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'subject' => 'Checkout broken',
            'message' => 'The checkout button does nothing on mobile.',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('problem_reports', [
            'email' => 'guest@example.com',
            'subject' => 'Checkout broken',
            'status' => 'pending',
        ]);
    }

    public function test_user_can_send_suggestion(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->actingAs($user)->postJson(route('feedback.suggestion'), [
            'category' => 'feature',
            'message' => 'Please add CSV export for orders.',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, Suggestion::count());
    }

    public function test_advertiser_can_suggest_missing_website(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->actingAs($user)->postJson(route('advertiser.website-suggestions.store'), [
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'notes' => 'Great niche for SaaS',
            'search_query' => 'fresh tech',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('website_suggestions', [
            'domain' => 'fresh-tech.example',
            'status' => 'pending',
        ]);
    }

    public function test_publisher_can_claim_website_with_matching_name(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $this->actingAs($claimer)->postJson(route('publisher.sites.claim'), [
            'website_url' => 'https://owned-news.example',
            'website_name' => 'Owned News Daily',
            'proof_message' => 'I own this domain via registrar account and CMS admin access.',
            'contact_email' => $claimer->email,
        ])->assertOk()->assertJson(['success' => true, 'name_matches' => true]);

        $claim = SiteClaim::first();
        $this->assertNotNull($claim);
        $this->assertTrue($claim->name_matches);
        $this->assertSame('pending', $claim->status);
    }

    public function test_advertiser_can_claim_catalog_site_by_id(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('advertiser');
        $site = $this->siteFor($owner);

        $this->actingAs($claimer)->postJson(route('advertiser.sites.claim'), [
            'site_id' => $site->id,
            'proof_message' => 'I own this domain via registrar account and CMS admin access.',
            'contact_email' => $claimer->email,
        ])->assertOk()->assertJson(['success' => true, 'name_matches' => true]);

        $claim = SiteClaim::first();
        $this->assertNotNull($claim);
        $this->assertSame($site->id, (int) $claim->site_id);
        $this->assertSame($claimer->id, (int) $claim->claimer_id);
        $this->assertSame('Owned News Daily', $claim->website_name);
    }

    public function test_catalog_and_publisher_sites_both_expose_claim_entry_points(): void
    {
        $owner = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->siteFor($owner);

        $catalog = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('btn-claim-site', $catalog);
        $this->assertStringContainsString('siteClaim', $catalog);
        $this->assertMatchesRegularExpression('#advertiser\\\\?/sites\\\\?/claim#', $catalog);

        // Publishers can also claim via My Sites (URL + exact listing name form).
        $publisherPage = $this->actingAs($owner)
            ->get(route('publisher.sites.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('showClaimBtn', $publisherPage);
        $this->assertStringContainsString('Claim a website', $publisherPage);
        $this->assertStringContainsString('My claims', $publisherPage);
        $this->assertStringContainsString('site-claims', $publisherPage);
    }

    public function test_admin_can_approve_claim_and_transfer_ownership(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $claim = SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Domain WHOIS matches my company email.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->postJson(route('admin.community.claims.approve', $claim->id), [
            'admin_notes' => 'Verified via domain email.',
        ])->assertOk()->assertJson(['success' => true]);

        $site->refresh();
        $claim->refresh();
        $this->assertSame($claimer->id, (int) $site->publisher_id);
        $this->assertSame('approved', $claim->status);
    }

    public function test_cannot_suggest_website_already_in_catalog(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)->postJson(route('advertiser.website-suggestions.store'), [
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'That website is already listed in our catalog. Try searching for “owned-news.example”.']);
    }

    public function test_cannot_suggest_website_already_on_file_but_not_in_catalog(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $site->update(['verified' => false]);
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)->postJson(route('advertiser.website-suggestions.store'), [
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
        ])->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'We already have this website on file. It is not currently available in the catalog.',
            ]);
    }

    public function test_admin_can_resolve_a_problem_but_not_mark_it_accepted(): void
    {
        $admin = $this->userWithRole('admin');
        $report = ProblemReport::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'resolved',
            'admin_notes' => 'Fixed the mobile CTA.',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('resolved', $report->fresh()->status);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'accepted',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'approved',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_accept_a_website_suggestion_but_not_approve_it(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $suggestion = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'domain' => 'fresh-tech.example',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.websites.update', $suggestion->id), [
            'status' => 'accepted',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('accepted', $suggestion->fresh()->status);

        $this->actingAs($admin)->patchJson(route('admin.community.websites.update', $suggestion->id), [
            'status' => 'approved',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_claims_filter_includes_approved_and_ignores_accepted(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Approved-claim WHOIS proof for filter test.',
            'contact_email' => $claimer->email,
            'status' => 'approved',
        ]);
        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $this->userWithRole('publisher')->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => false,
            'proof_message' => 'Pending-claim registrar screenshots for filter test.',
            'contact_email' => 'other@example.com',
            'status' => 'pending',
        ]);

        $approvedHtml = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims', 'status' => 'approved']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="approved"[^>]*\bselected\b/', $approvedHtml);
        $this->assertStringNotContainsString('value="accepted"', $approvedHtml);
        $this->assertStringContainsString('Approved-claim WHOIS proof for filter test.', $approvedHtml);
        $this->assertStringNotContainsString('Pending-claim registrar screenshots for filter test.', $approvedHtml);

        $acceptedFilter = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims', 'status' => 'accepted']))
            ->assertOk()
            ->getContent();

        // Invalid for claims → treated as All, so both rows render.
        $this->assertStringContainsString('Pending-claim registrar screenshots for filter test.', $acceptedFilter);
        $this->assertStringContainsString('Approved-claim WHOIS proof for filter test.', $acceptedFilter);
        $this->assertStringNotContainsString('value="accepted"', $acceptedFilter);
    }

    public function test_problems_filter_omits_approved_and_tab_switch_drops_it(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="resolved"', $html);
        $this->assertStringNotContainsString('value="approved"', $html);
        $this->assertStringNotContainsString('value="accepted"', $html);
        $this->assertStringNotContainsString('${btn.dataset.notes', $html);
        $this->assertStringContainsString('notes.value = btn.dataset.notes', $html);
        $this->assertStringContainsString('Network error', $html);
        $this->assertStringNotContainsString("data.message || 'Done'", $html);

        $fromClaims = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims', 'status' => 'approved']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('admin.community.index', ['tab' => 'problems']),
            $fromClaims
        );
        $this->assertStringNotContainsString(
            route('admin.community.index', ['tab' => 'problems', 'status' => 'approved']),
            $fromClaims
        );
    }

    public function test_status_modal_does_not_embed_admin_notes_in_markup(): void
    {
        $admin = $this->userWithRole('admin');
        ProblemReport::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'subject' => 'XSS check',
            'message' => 'Notes should not be interpolated into SweetAlert html.',
            'status' => 'pending',
            'admin_notes' => '</textarea><img src=x onerror=alert(1)>',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('</textarea><img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;/textarea&gt;', $html);
    }

    public function test_reopening_a_problem_clears_reviewer_and_keeps_first_reviewer_on_resolve(): void
    {
        $admin = $this->userWithRole('admin');
        $other = $this->userWithRole('admin');
        $report = ProblemReport::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'reviewed',
        ])->assertOk();

        $report->refresh();
        $this->assertSame('reviewed', $report->status);
        $this->assertNotNull($report->reviewed_at);
        $this->assertSame($admin->id, (int) $report->reviewed_by);

        $this->actingAs($other)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'resolved',
        ])->assertOk();

        $report->refresh();
        $this->assertSame('resolved', $report->status);
        $this->assertSame($admin->id, (int) $report->reviewed_by);
        $this->assertNotNull($report->reviewed_at);

        $this->actingAs($other)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'pending',
        ])->assertOk();

        $report->refresh();
        $this->assertSame('pending', $report->status);
        $this->assertNull($report->reviewed_at);
        $this->assertNull($report->reviewed_by);
    }

    public function test_filtered_empty_state_and_page_url_are_visible(): void
    {
        $admin = $this->userWithRole('admin');
        ProblemReport::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
            'page_url' => 'https://app.example/checkout',
            'status' => 'pending',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://app.example/checkout', $html);
        $this->assertStringContainsString('communityDrawer', $html);
        $this->assertStringContainsString('btn-community-drawer', $html);
        $this->assertStringContainsString('bg-warning text-dark', $html);

        $empty = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems', 'status' => 'resolved']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No matches for this filter.', $empty);
        $this->assertStringNotContainsString('No problem reports yet.', $empty);
    }

    public function test_website_search_finds_requester_email_and_literal_percent_is_not_a_wildcard(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $other = $this->userWithRole('advertiser');

        WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'domain' => 'fresh-tech.example',
            'status' => 'pending',
        ]);
        WebsiteSuggestion::create([
            'user_id' => $other->id,
            'website_name' => 'Other News',
            'website_url' => 'https://other-news.example',
            'domain' => 'other-news.example',
            'status' => 'pending',
        ]);

        $byEmail = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'websites', 'q' => $advertiser->email]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Fresh Tech Blog', $byEmail);
        $this->assertStringNotContainsString('Other News', $byEmail);

        $percent = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'websites', 'q' => '%']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No matches for this filter.', $percent);
        $this->assertStringNotContainsString('Fresh Tech Blog', $percent);
        $this->assertStringNotContainsString('Other News', $percent);
    }

    public function test_community_without_tab_lands_on_the_busiest_pending_inbox(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Landing-tab claim proof unique string.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Landing-tab claim proof unique string.', $html);
        $this->assertStringContainsString('nav-link active', $html);
        $this->assertMatchesRegularExpression('/tab=claims/', $html);
    }

    public function test_guest_problem_notifies_admins_and_writes_an_activity_log(): void
    {
        $admin = $this->userWithRole('admin');
        $marketing = $this->userWithRole('marketing');

        $this->postJson(route('feedback.problem'), [
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'subject' => 'Checkout broken',
            'message' => 'The checkout button does nothing on mobile.',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'feedback.problem',
            'description' => 'Guest User reported a problem: Checkout broken',
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $admin->id,
            'audience' => InAppNotification::AUDIENCE_ADMIN,
            'title' => 'New problem report',
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $marketing->id,
            'title' => 'New problem report',
        ]);
    }

    public function test_resolving_a_logged_in_problem_mails_and_bells_once(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $report = ProblemReport::create([
            'user_id' => $advertiser->id,
            'name' => $advertiser->name,
            'email' => $advertiser->email,
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'resolved',
            'admin_notes' => 'Fixed the mobile CTA.',
        ])->assertOk()->assertJson(['success' => true]);

        Mail::assertQueued(CommunityFeedbackReviewed::class, 1);
        Mail::assertQueued(CommunityFeedbackReviewed::class, function (CommunityFeedbackReviewed $mail) use ($advertiser, $report) {
            return $mail->hasTo($advertiser->email)
                && $mail->kind === 'problem'
                && (int) $mail->item->id === (int) $report->id
                && $mail->item->status === 'resolved';
        });
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'title' => 'We reviewed your report — Checkout broken',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'resolved',
            'admin_notes' => 'Still fixed.',
        ])->assertOk();

        Mail::assertQueued(CommunityFeedbackReviewed::class, 1);
        $this->assertSame(1, InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('title', 'We reviewed your report — Checkout broken')
            ->count());
    }

    public function test_website_tab_offers_create_listing_handoff_or_existing_catalog_link(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $fresh = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'domain' => 'fresh-tech.example',
            'country' => 'us',
            'language' => 'en',
            'status' => 'pending',
        ]);
        $occupied = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Owned News Daily',
            'website_url' => 'https://owned-news.example',
            'domain' => 'owned-news.example',
            'status' => 'pending',
        ]);
        $site = $this->siteFor($publisher);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'websites']))
            ->assertOk()
            ->getContent();

        $createUrl = route('admin.sites.create', CommunityInbox::createListingQuery($fresh));
        $this->assertStringContainsString('Create listing', $html);
        $this->assertStringContainsString('suggestion_id='.$fresh->id, $html);
        $this->assertStringContainsString('site_name=Fresh%20Tech%20Blog', $html);
        $this->assertStringContainsString('Already in catalog', $html);
        $this->assertStringContainsString(route('admin.sites.edit', $site->id), $html);
        $this->assertStringNotContainsString('suggestion_id='.$occupied->id, $html);

        $form = $this->actingAs($admin)
            ->get($createUrl)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="Fresh Tech Blog"', $form);
        $this->assertStringContainsString('value="https://fresh-tech.example"', $form);
        $this->assertStringContainsString('name="suggestion_id"', $form);
        $this->assertStringContainsString('value="'.$fresh->id.'"', $form);
        $this->assertStringContainsString('Prefilling from website suggestion', $form);
    }

    public function test_accept_website_suggestion_after_listing_marks_accepted_and_notifies(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $suggestion = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Owned News Daily',
            'website_url' => 'https://owned-news.example',
            'domain' => 'owned-news.example',
            'status' => 'pending',
        ]);
        $site = $this->siteFor($publisher);

        app(CommunityInboxNotifier::class)->acceptWebsiteSuggestionAfterListing(
            (int) $suggestion->id,
            $site,
            $admin
        );

        $suggestion->refresh();
        $this->assertSame('accepted', $suggestion->status);
        $this->assertSame($admin->id, (int) $suggestion->reviewed_by);
        $this->assertStringContainsString('Listing created: '.$site->domain, (string) $suggestion->admin_notes);

        Mail::assertQueued(WebsiteSuggestionReviewed::class, function (WebsiteSuggestionReviewed $mail) use ($advertiser) {
            return $mail->hasTo($advertiser->email) && $mail->suggestion->status === 'accepted';
        });
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'title' => 'Website suggestion accepted — Owned News Daily',
        ]);
    }

    public function test_accept_after_listing_ignores_a_suggestion_for_a_different_domain(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $suggestion = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'domain' => 'fresh-tech.example',
            'status' => 'pending',
        ]);
        $site = $this->siteFor($publisher);

        app(CommunityInboxNotifier::class)->acceptWebsiteSuggestionAfterListing(
            (int) $suggestion->id,
            $site,
            $admin
        );

        $this->assertSame('pending', $suggestion->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_listing_handoff_accepts_a_rejected_suggestion_for_the_same_domain(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $suggestion = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Owned News Daily',
            'website_url' => 'https://owned-news.example',
            'domain' => 'owned-news.example',
            'status' => 'rejected',
            'admin_notes' => 'Not a fit yet.',
        ]);
        $site = $this->siteFor($publisher);

        app(CommunityInboxNotifier::class)->acceptWebsiteSuggestionAfterListing(
            (int) $suggestion->id,
            $site,
            $admin
        );

        $suggestion->refresh();
        $this->assertSame('accepted', $suggestion->status);
        $this->assertStringContainsString('Listing created: '.$site->domain, (string) $suggestion->admin_notes);
        Mail::assertQueued(WebsiteSuggestionReviewed::class, 1);

        app(CommunityInboxNotifier::class)->acceptWebsiteSuggestionAfterListing(
            (int) $suggestion->id,
            $site,
            $admin
        );
        Mail::assertQueued(WebsiteSuggestionReviewed::class, 1);
    }

    public function test_array_suggestion_id_query_does_not_prefill_site_create(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'domain' => 'fresh-tech.example',
            'status' => 'pending',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.create', ['suggestion_id' => ['1']]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Prefilling from website suggestion', $html);
        $this->assertStringNotContainsString('name="suggestion_id"', $html);
    }

    public function test_inactive_community_tabs_are_not_paginated(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Inactive-tab claim should not load on problems.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->assertViewHas('claims', fn ($claims) => $claims->total() === 0)
            ->assertViewHas('problems', fn ($problems) => $problems->total() === 0);

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims']))
            ->assertOk()
            ->assertViewHas('claims', fn ($claims) => $claims->total() === 1)
            ->assertSee('Inactive-tab claim should not load on problems.', false);
    }

    public function test_email_catalog_can_preview_community_review_mailables(): void
    {
        $feedback = EmailCatalog::makeMailable('community_feedback_reviewed');
        $website = EmailCatalog::makeMailable('website_suggestion_reviewed');

        $this->assertInstanceOf(CommunityFeedbackReviewed::class, $feedback);
        $this->assertInstanceOf(WebsiteSuggestionReviewed::class, $website);
        $this->assertStringContainsString('We reviewed your problem report', $feedback->render());
        $this->assertStringContainsString('We will try to add', $website->render());
        $this->assertStringContainsString(rtrim(app_public_url(), '/'), $feedback->render());
        $this->assertStringContainsString('/advertiser/catalog', $website->render());
    }

    public function test_blank_report_email_falls_back_to_the_user_account(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $report = ProblemReport::create([
            'user_id' => $advertiser->id,
            'name' => $advertiser->name,
            'email' => '',
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'resolved',
        ])->assertOk();

        Mail::assertQueued(CommunityFeedbackReviewed::class, function (CommunityFeedbackReviewed $mail) use ($advertiser) {
            return $mail->hasTo($advertiser->email);
        });
    }

    public function test_url_only_website_suggestion_still_shows_already_in_catalog(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Owned News Daily',
            'website_url' => 'https://owned-news.example/about',
            'domain' => null,
            'status' => 'pending',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'websites']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Already in catalog', $html);
        $this->assertStringContainsString(route('admin.sites.edit', $site->id), $html);
        $this->assertStringNotContainsString('Create listing', $html);
    }

    public function test_email_center_can_preview_community_review_templates(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'community_feedback_reviewed'))
            ->assertOk()
            ->assertSee('We reviewed your problem report', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'website_suggestion_reviewed'))
            ->assertOk()
            ->assertSee('We will try to add', false);
    }
}
