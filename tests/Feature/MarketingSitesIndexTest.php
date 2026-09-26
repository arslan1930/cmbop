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
        $this->assertStringContainsString("'Reject this site?'", $html);
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
        $this->assertStringContainsString('delete-site', $html);
        $this->assertStringContainsString('>Reject</button>', $html);
        $this->assertStringContainsString($first->email, $html);
        $this->assertStringContainsString($second->email, $html);
        $this->assertStringContainsString('id="usersSection" class="d-none"', $html);
        $this->assertFalse($thin->hasGoodMetrics());
        $this->assertFalse($noMarket->hasMarketplaceCountry());
        $thinSlice = substr($html, (int) strpos($html, 'Flat Thin Site'), 3200);
        $this->assertStringNotContainsString('js-mkt-activate', $thinSlice);
        $this->assertStringContainsString('This listing is below the quality bar', $html);
        $this->assertStringContainsString('Set a marketplace country before activating', $html);
        $flatCard = substr($html, (int) strpos($html, 'data-flat-queue="1"'), 12000);
        $this->assertStringNotContainsString('toggle-verify', $flatCard);
        $this->assertFalse($thin->hasGoodMetrics());
        $this->assertFalse($noMarket->hasMarketplaceCountry());
        $thinSlice = substr($html, (int) strpos($html, 'Flat Thin Site'), 3200);
        $this->assertStringNotContainsString('js-mkt-activate', $thinSlice);
        $this->assertStringContainsString('This listing is below the quality bar', $html);
        $this->assertStringContainsString('Set a marketplace country before activating', $html);
    }

    public function test_waiting_on_publisher_flat_queue_lists_publisher_owned_drafts(): void
    {
        $first = $this->userWithRole('publisher', [
            'name' => 'Waiting First Publisher',
            'email' => 'waiting-first-publisher@example.test',
        ]);
        $second = $this->userWithRole('publisher', [
            'name' => 'Waiting Second Publisher',
            'email' => 'waiting-second-publisher@example.test',
        ]);
        $waiting = $this->makeSite($first, [
            'site_name' => 'Waiting Details Draft',
            'domain' => 'waiting-details-draft.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $this->makeSite($second, [
            'site_name' => 'Ready Should Hide',
            'domain' => 'ready-should-hide.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['waiting_on_publisher' => 1, 'flat' => 1]))
            ->assertOk()
            ->assertSee('Waiting on publisher', false)
            ->assertSee('Listings still with the publisher', false)
            ->assertSee('Waiting Details Draft', false)
            ->assertDontSee('Ready Should Hide', false)
            ->getContent();

        $flatStart = strpos($html, 'data-flat-queue="1"');
        $this->assertNotFalse($flatStart);
        $namePos = strpos($html, 'Waiting Details Draft');
        $this->assertNotFalse($namePos);
        $flatSlice = substr($html, $flatStart, ($namePos - $flatStart) + 2500);
        $this->assertStringContainsString('Waiting Details Draft', $flatSlice);
        $this->assertStringNotContainsString('js-mkt-activate', $flatSlice);

        $this->assertStringContainsString(
            e(route('marketing.sites.index', ['publisher' => $waiting->publisher_id, 'site' => $waiting->id], false)),
            $html
        );
        $this->assertStringNotContainsString('toggle-verify', $flatSlice);
        $this->assertStringNotContainsString('delete-site', $flatSlice);
    }

    public function test_sites_index_search_finds_publisher_by_site_domain(): void
    {
        $match = $this->userWithRole('publisher', [
            'name' => 'Domain Match Pub',
            'email' => 'domain-match-pub@example.test',
        ]);
        $other = $this->userWithRole('publisher', [
            'name' => 'Domain Other Pub',
            'email' => 'domain-other-pub@example.test',
        ]);
        $this->makeSite($match, [
            'site_name' => 'Alpha Listed News',
            'domain' => 'alpha-listed-news.example',
            'site_url' => 'https://alpha-listed-news.example',
        ]);
        $this->makeSite($other, [
            'site_name' => 'Beta Hidden News',
            'domain' => 'beta-hidden-news.example',
            'site_url' => 'https://beta-hidden-news.example',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['q' => 'alpha-listed-news.example']))
            ->assertRedirect();

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index', ['q' => 'listed-news']))
            ->assertOk()
            ->assertSee('domain-match-pub@example.test', false)
            ->assertDontSee('domain-other-pub@example.test', false)
            ->assertSee('1 matched', false);
    }

    public function test_sites_index_exact_domain_search_deep_links_unique_site(): void
    {
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Unique Domain Pub',
            'email' => 'unique-domain-pub@example.test',
        ]);
        $site = $this->makeSite($publisher, [
            'site_name' => 'Unique Find Site',
            'domain' => 'unique-find-site.example',
            'site_url' => 'https://unique-find-site.example/path',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['q' => 'https://www.unique-find-site.example']));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('publisher='.$publisher->id, $location);
        $this->assertStringContainsString('site='.$site->id, $location);
        $this->assertStringContainsString('q=', $location);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', [
                'q' => (string) $site->id,
            ]))
            ->assertRedirect();
    }

    public function test_sites_index_email_search_does_not_treat_host_as_domain(): void
    {
        $match = $this->userWithRole('publisher', [
            'name' => 'Email Host Pub',
            'email' => 'email-host-pub@example.test',
        ]);
        $other = $this->userWithRole('publisher', [
            'name' => 'Other Host Pub',
            'email' => 'other-host-pub@example.test',
        ]);
        $this->makeSite($match, ['domain' => 'email-host-site.example']);
        $this->makeSite($other, ['domain' => 'example.test']);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['q' => 'email-host-pub@example.test']))
            ->assertOk()
            ->assertSee('email-host-pub@example.test', false)
            ->assertDontSee('other-host-pub@example.test', false);
    }

    public function test_sites_index_search_skips_archived_sites(): void
    {
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Archived Domain Pub',
            'email' => 'archived-domain-pub@example.test',
        ]);
        $this->makeSite($publisher, [
            'domain' => 'archived-only.example',
            'site_url' => 'https://archived-only.example',
            'archived_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['q' => 'archived-only.example']))
            ->assertOk()
            ->assertDontSee('archived-domain-pub@example.test', false);
    }

    public function test_user_sites_search_and_needs_review_are_server_side(): void
    {
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Filter Sites Pub',
            'email' => 'filter-sites-pub@example.test',
        ]);
        $ready = $this->makeSite($publisher, [
            'site_name' => 'Ready Filter Site',
            'domain' => 'ready-filter.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $live = $this->makeSite($publisher, [
            'site_name' => 'Live Filter Site',
            'domain' => 'live-filter.example',
            'verified' => true,
            'active' => true,
            'onboarding_status' => null,
        ]);

        $search = $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', ['id' => $publisher->id, 'q' => 'ready-filter.example']))
            ->assertOk()
            ->json();

        $searchIds = collect($search['sites'] ?? [])->pluck('id')->all();
        $this->assertContains($ready->id, $searchIds);
        $this->assertNotContains($live->id, $searchIds);
        $this->assertSame('ready-filter.example', $search['meta']['q'] ?? null);

        $review = $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', ['id' => $publisher->id, 'needs_review' => 1]))
            ->assertOk()
            ->json();

        $reviewIds = collect($review['sites'] ?? [])->pluck('id')->all();
        $this->assertContains($ready->id, $reviewIds);
        $this->assertNotContains($live->id, $reviewIds);
        $this->assertTrue((bool) ($review['meta']['needs_review'] ?? false));
    }

    public function test_admin_flat_review_queue_offers_verify_and_delete(): void
    {
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Admin Queue Pub',
            'email' => 'admin-queue-pub@example.test',
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Admin Queue Ready Site',
            'domain' => 'admin-queue-ready.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['needs_review' => 1, 'flat' => 1]))
            ->assertOk()
            ->assertSee('Admin Queue Ready Site', false)
            ->assertSee('Unverified', false)
            ->getContent();

        $this->assertStringContainsString('toggle-verify', $html);
        $this->assertStringContainsString('>Verify</button>', $html);
        $this->assertStringContainsString('delete-site', $html);
        $this->assertStringContainsString('>Reject</button>', $html);
        $this->assertStringContainsString('js-mkt-activate', $html);
        $this->assertStringContainsString('queryLooksLikeSiteSearch', $html);
        $this->assertStringContainsString('refetchOpenPublisherSites', $html);
    }
}
