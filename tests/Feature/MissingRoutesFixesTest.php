<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MissingRoutesFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function siteFor(User $publisher, string $domain): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Claim Target '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'A publisher site for claim route tests',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function pendingClaim(Site $site, User $claimer): SiteClaim
    {
        return SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'I own this domain and can prove it.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);
    }

    public function test_marketing_community_page_hides_claim_actions(): void
    {
        $marketer = $this->makeUser('marketing');
        $owner = $this->makeUser('publisher');
        $claimer = $this->makeUser('publisher');
        $this->pendingClaim($this->siteFor($owner, 'claim-target.example'), $claimer);

        $html = $this->actingAs($marketer)
            ->get(route('admin.community.index', ['tab' => 'claims']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Awaiting admin review', $html);
        $this->assertStringNotContainsString('data-mode="approve"', $html);
        $this->assertStringNotContainsString('>Approve</button>', $html);
        $this->assertStringNotContainsString(route('admin.community.claims.approve', SiteClaim::first()->id), $html);
    }

    public function test_admin_community_page_still_shows_claim_actions(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('publisher');
        $claimer = $this->makeUser('publisher');
        $this->pendingClaim($this->siteFor($owner, 'claim-target-admin.example'), $claimer);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('btn-claim-action', $html);
        $this->assertStringContainsString('>Approve</button>', $html);
    }

    public function test_publisher_feature_wallet_top_up_points_to_publisher_balance(): void
    {
        $publisher = $this->makeUser('publisher');

        $this->actingAs($publisher)
            ->getJson(route('publisher.promotions.wallet'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('top_up_url', route('publisher.balance'))
            ->assertJsonPath('balance_url', route('publisher.balance'));

        $html = $this->actingAs($publisher)->get(route('publisher.websites'))->assertOk()->getContent();
        $this->assertStringContainsString(route('publisher.balance'), $html);
        $this->assertStringNotContainsString(route('advertiser.add-funds'), $html);
    }

    public function test_admin_withdrawals_statistics_is_not_shadowed_by_show(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $matched = app('router')->getRoutes()->match(
            Request::create('/admin/withdrawals/statistics', 'GET')
        );
        $this->assertSame('admin.withdrawals.statistics', $matched->getName());
    }
}
