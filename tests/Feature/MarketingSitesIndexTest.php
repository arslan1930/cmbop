<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingSitesIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->marketer = $this->userWithRole('marketing');
        $this->admin = $this->userWithRole('admin');
    }

    private function userWithRole(string $roleName, array $attrs = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        $domain = $overrides['domain'] ?? 'index-'.uniqid().'.example';

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Index Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Sites index publisher list fixture',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_sites_index_lists_publishers_not_advertisers(): void
    {
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Listed Publisher',
            'email' => 'listed-publisher@example.test',
        ]);
        $advertiser = $this->userWithRole('advertiser', [
            'name' => 'Hidden Advertiser',
            'email' => 'hidden-advertiser@example.test',
        ]);
        $this->makeSite($publisher);

        foreach ([
            route('marketing.sites.index') => $this->marketer,
            route('admin.sites.index') => $this->admin,
        ] as $url => $actor) {
            $this->actingAs($actor)
                ->get($url)
                ->assertOk()
                ->assertSee('listed-publisher@example.test', false)
                ->assertSee('Listed Publisher', false)
                ->assertDontSee('hidden-advertiser@example.test', false)
                ->assertDontSee('Hidden Advertiser', false)
                ->assertSee('Search publishers', false)
                ->assertDontSee('Search users', false)
                ->assertDontSee('No users found', false);
        }
    }

    public function test_sites_index_search_finds_publisher_by_name_or_email(): void
    {
        $match = $this->userWithRole('publisher', [
            'name' => 'Zebra Unique Search',
            'email' => 'zebra-unique-search@example.test',
        ]);
        $other = $this->userWithRole('publisher', [
            'name' => 'Alpha Other Pub',
            'email' => 'alpha-other-pub@example.test',
        ]);
        $this->makeSite($match);
        $this->makeSite($other);

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['q' => 'zebra-unique-search@example.test']))
            ->assertOk()
            ->assertSee('zebra-unique-search@example.test', false)
            ->assertDontSee('alpha-other-pub@example.test', false)
            ->assertSee('name="q"', false)
            ->assertSee('value="zebra-unique-search@example.test"', false)
            ->assertSee('data-slb-live-search="form"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['q' => 'Zebra Unique']))
            ->assertOk()
            ->assertSee('zebra-unique-search@example.test', false)
            ->assertDontSee('alpha-other-pub@example.test', false);
    }

    public function test_sites_index_array_q_does_not_500(): void
    {
        $match = $this->userWithRole('publisher', [
            'name' => 'Array Query Pub',
            'email' => 'array-query-pub@example.test',
        ]);
        $other = $this->userWithRole('publisher', [
            'name' => 'Other Query Pub',
            'email' => 'other-query-pub@example.test',
        ]);
        $this->makeSite($match);
        $this->makeSite($other);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['q' => ['Array Query Pub']]))
            ->assertOk()
            ->assertSee('array-query-pub@example.test', false)
            ->assertDontSee('other-query-pub@example.test', false)
            ->assertDontSee('Array to string conversion', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['q' => [['not-a-name']]]))
            ->assertOk()
            ->assertDontSee('Array to string conversion', false);
    }

    public function test_sites_index_search_survives_pagination_and_needs_review_toggle(): void
    {
        foreach (range(1, 21) as $i) {
            $this->userWithRole('publisher', [
                'name' => sprintf('PubCo Search %02d', $i),
                'email' => sprintf('pubco-search-%02d@example.test', $i),
            ]);
        }

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['q' => 'PubCo Search']))
            ->assertOk()
            ->assertSee('pubco-search-01@example.test', false)
            ->getContent();

        $this->assertTrue(
            str_contains($html, 'q=PubCo+Search') || str_contains($html, 'q=PubCo%20Search'),
            'Pagination links should keep the publisher search query'
        );
        $this->assertStringContainsString('page=2', $html);

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['q' => 'PubCo Search', 'needs_review' => 1]))
            ->assertOk()
            ->assertSee('name="needs_review"', false)
            ->assertSee('value="PubCo Search"', false);
    }

    public function test_sites_index_orders_review_queue_publishers_first(): void
    {
        $empty = $this->userWithRole('publisher', [
            'name' => 'AAA Empty Publisher',
            'email' => 'aaa-empty-publisher@example.test',
        ]);
        $queued = $this->userWithRole('publisher', [
            'name' => 'ZZZ Queued Publisher',
            'email' => 'zzz-queued-publisher@example.test',
        ]);
        $this->makeSite($queued, [
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->assertSee($empty->email, false)
            ->assertSee($queued->email, false)
            ->getContent();

        $queuedPos = strpos($html, $queued->email);
        $emptyPos = strpos($html, $empty->email);
        $this->assertNotFalse($queuedPos);
        $this->assertNotFalse($emptyPos);
        $this->assertLessThan($emptyPos, $queuedPos);
    }

    public function test_sites_index_excludes_archived_from_review_count_and_user_sites(): void
    {
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Archive Queue Publisher',
            'email' => 'archive-queue-publisher@example.test',
        ]);
        $live = $this->makeSite($publisher, [
            'domain' => 'live-review.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $archived = $this->makeSite($publisher, [
            'domain' => 'archived-review.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'archived_at' => now(),
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['needs_review' => 1]))
            ->assertOk()
            ->assertSee($publisher->email, false)
            ->assertDontSee('waiting for Verify, Activate, Reject, or Delete', false)
            ->assertSee('waiting for Activate or delete (pending only)', false)
            ->getContent();

        $this->assertStringContainsString('1 new', $html);

        $payload = $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $publisher->id))
            ->assertOk()
            ->json();

        $ids = collect($payload['sites'] ?? [])->pluck('id')->all();
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($archived->id, $ids);
        $liveRow = collect($payload['sites'] ?? [])->firstWhere('id', $live->id);
        $this->assertFalse((bool) ($liveRow['listing_locked'] ?? true));

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['needs_review' => 1]))
            ->assertOk()
            ->assertSee('waiting for Verify, Activate, Reject, or Delete', false);
    }

    public function test_sites_delete_script_checks_failed_responses(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("method:'DELETE'", $html);
        $this->assertStringContainsString("'Accept': 'application/json'", $html);
        $this->assertStringContainsString('if (!res.ok || !data.success)', $html);
        $this->assertStringContainsString("toast(error.message || (isArchive ? 'Could not archive site' : 'Failed to delete site'), 'error')", $html);
        $this->assertStringContainsString("IS_MARKETING_EDITOR ? 'Reject this site?' : 'Delete this site?'", $html);
        $this->assertStringContainsString('const isArchive = canArchiveSiteRow(site)', $html);
        $this->assertStringContainsString('JSON.stringify({ reason })', $html);
        $this->assertStringContainsString('${STAFF_BASE}/sites/${site.id}/edit', $html);
        $this->assertStringContainsString('IS_MARKETING_EDITOR && listingLocked', $html);
        $this->assertStringContainsString('Missing market', $html);
        $this->assertStringContainsString('Below quality bar', $html);
        $this->assertStringContainsString('QUALITY_MIN_DA', $html);
        $this->assertStringContainsString('sitesLoadMore', $html);
        $this->assertStringContainsString('Site queue', $html);
        $this->assertStringNotContainsString("}).then(() => {\n                toast('Deleted successfully');", $html);
    }

    public function test_flat_review_queue_lists_sites_across_publishers(): void
    {
        $first = $this->userWithRole('publisher', [
            'name' => 'Flat First Publisher',
            'email' => 'flat-first-publisher@example.test',
        ]);
        $second = $this->userWithRole('publisher', [
            'name' => 'Flat Second Publisher',
            'email' => 'flat-second-publisher@example.test',
        ]);
        $ready = $this->makeSite($first, [
            'site_name' => 'Flat Ready Site',
            'domain' => 'flat-ready.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
        ]);
        $thin = $this->makeSite($second, [
            'site_name' => 'Flat Thin Site',
            'domain' => 'flat-thin.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $noMarket = $this->makeSite($second, [
            'site_name' => 'Flat No Market Site',
            'domain' => 'flat-no-market.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'country' => '',
            'da' => 40,
            'dr' => 40,
            'traffic' => 20000,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['needs_review' => 1, 'flat' => 1]))
            ->assertOk()
            ->assertSee('Flat list of sites waiting for Activate or delete (pending only)', false)
            ->assertSee('By publisher', false)
            ->assertSee('data-flat-queue="1"', false)
            ->assertSee('Flat Ready Site', false)
            ->assertSee('Flat Thin Site', false)
            ->assertSee('Flat No Market Site', false)
            ->assertSee('Below quality bar', false)
            ->assertSee('Missing market', false)
            ->assertSee('Open', false)
            ->assertSee('Edit', false)
            ->assertDontSee('Hidden Advertiser', false)
            ->getContent();

        $this->assertStringContainsString(
            e(route('marketing.sites.index', ['publisher' => $ready->publisher_id, 'site' => $ready->id], false)),
            $html
        );
        $this->assertStringContainsString(route('marketing.sites.edit', $ready->id, false), $html);
        $this->assertStringContainsString('js-mkt-activate', $html);
        $this->assertStringContainsString($first->email, $html);
        $this->assertStringContainsString($second->email, $html);
        $this->assertStringContainsString('id="usersSection" class="d-none"', $html);
        $this->assertFalse($thin->hasGoodMetrics());
        $this->assertFalse($noMarket->hasMarketplaceCountry());
    }
}
