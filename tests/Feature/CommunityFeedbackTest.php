<?php

namespace Tests\Feature;

use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ])->assertStatus(422);
    }
}
