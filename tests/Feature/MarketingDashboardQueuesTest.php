<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\MarketingOpsQueues;
use Database\Seeders\RolesTableSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingDashboardQueuesTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Queue Marketer',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
            'name' => 'Queue Publisher',
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    public function test_dashboard_splits_ready_sites_from_publisher_owned_work(): void
    {
        $ready = $this->makeSite([
            'site_name' => 'Ready Activate Target',
            'site_url' => 'https://ready-activate.example',
            'domain' => 'ready-activate.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
        ]);
        $thinReady = $this->makeSite([
            'site_name' => 'Thin Metrics Ready',
            'site_url' => 'https://thin-ready.example',
            'domain' => 'thin-ready.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $awaiting = $this->makeSite([
            'site_name' => 'Awaiting Details Draft',
            'site_url' => 'https://awaiting-details.example',
            'domain' => 'awaiting-details.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $invite = $this->makeSite([
            'site_name' => 'Unaccepted Invite Site',
            'site_url' => 'https://unaccepted-invite.example',
            'domain' => 'unaccepted-invite.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'assigned_by_user_id' => $this->marketer->id,
            'publisher_accepted_at' => null,
        ]);
        $this->makeSite([
            'site_name' => 'Archived Ready Site',
            'site_url' => 'https://archived-ready.example',
            'domain' => 'archived-ready.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'archived_at' => now(),
        ]);

        $this->assertTrue($ready->needsAdminReview());
        $this->assertFalse($awaiting->needsAdminReview());
        $this->assertFalse($invite->needsAdminReview());
        $this->assertSame(2, MarketingOpsQueues::sitesReadyForStaff()->count());
        $this->assertSame(2, MarketingOpsQueues::sitesWaitingOnPublisher()->count());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Ready to activate', false)
            ->assertSee('Waiting on you (bulk)', false)
            ->assertSee('Waiting on publisher', false)
            ->assertSee('You can add and edit listings', false)
            ->assertDontSee('Admin handles verify, activate, enrichment', false)
            ->getContent();

        $this->assertSame('2', $this->attrValue($html, 'data-stat', 'ready-to-activate', 'data-stat-value'));
        $this->assertSame('2', $this->attrValue($html, 'data-stat', 'waiting-on-publisher', 'data-stat-sites'));
        $this->assertSame('0', $this->attrValue($html, 'data-stat', 'waiting-on-publisher', 'data-stat-bulk'));

        $readyTable = $this->nodeText($html, 'data-queue', 'ready-sites');
        $waitingTable = $this->nodeText($html, 'data-queue', 'waiting-sites');

        $this->assertStringContainsString('Ready Activate Target', $readyTable);
        $this->assertStringContainsString('Thin Metrics Ready', $readyTable);
        $this->assertStringContainsString('Ready for review', $readyTable);
        $this->assertStringContainsString('Below quality bar', $readyTable);
        $this->assertStringContainsString('Open', $readyTable);
        $this->assertStringContainsString('Edit', $readyTable);
        $this->assertStringNotContainsString('js-mkt-activate', $this->nodeHtml($html, 'data-queue', 'ready-sites'));
        $this->assertStringContainsString(
            e(route('marketing.sites.index', ['publisher' => $ready->publisher_id, 'site' => $ready->id], false)),
            $html
        );
        $this->assertStringContainsString(route('marketing.sites.edit', $ready->id, false), $html);
        $this->assertStringNotContainsString('Awaiting Details Draft', $readyTable);
        $this->assertStringNotContainsString('Unaccepted Invite Site', $readyTable);
        $this->assertStringNotContainsString('Archived Ready Site', $readyTable);

        $this->assertStringContainsString('Awaiting Details Draft', $waitingTable);
        $this->assertStringContainsString('Filling details', $waitingTable);
        $this->assertStringContainsString('Unaccepted Invite Site', $waitingTable);
        $this->assertStringContainsString('Waiting on accept', $waitingTable);
        $this->assertStringNotContainsString('Ready Activate Target', $waitingTable);
        $this->assertStringNotContainsString('Archived Ready Site', $waitingTable);

        $this->assertStringContainsString(
            e(route('marketing.sites.index', ['needs_review' => 1, 'flat' => 1], false)),
            $html
        );
        $this->assertStringContainsString(route('marketing.sites.create', [], false), $html);
        $this->assertSame('2', $this->node($html, 'data-nav-badge', 'sites')->attributes->getNamedItem('data-count')?->nodeValue);
        $this->assertStringContainsString('Open', $waitingTable);
        $this->assertStringContainsString('Metrics/geo/niche edits do not email the publisher', $html);
        $this->assertStringContainsString('sites\\/__ID__\\/active', $html);
    }

    public function test_dashboard_open_bulk_includes_completed_rows_still_needing_done(): void
    {
        $requested = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
            'handled_by' => $this->marketer->id,
        ]);
        $awaitingPublisher = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
        ]);
        $leftover = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 1,
            'completed_at' => now(),
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $leftover->id,
            'site_url' => 'https://leftover-done.example',
            'domain' => 'leftover-done.example',
            'price' => 40,
            'site_id' => null,
        ]);
        BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 4,
        ]);
        $seededSite = $this->makeSite([
            'site_name' => 'Already Seeded Listing',
            'site_url' => 'https://already-seeded.example',
            'domain' => 'already-seeded.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $trulyDone = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 1,
            'completed_at' => now(),
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $trulyDone->id,
            'site_url' => 'https://already-seeded.example',
            'domain' => 'already-seeded.example',
            'price' => 25,
            'site_id' => $seededSite->id,
        ]);

        $this->assertSame(2, MarketingOpsQueues::bulkWaitingOnMarketer()->count());
        $this->assertSame(1, MarketingOpsQueues::bulkWaitingOnPublisher()->count());
        $this->assertSame(3, MarketingOpsQueues::openBulkForMarketer()->count());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Waiting on marketer', false)
            ->assertSee('Waiting on publisher', false)
            ->assertSee('Completed — ready to verify', false)
            ->assertDontSee('awaiting publisher', false)
            ->getContent();

        $this->assertSame('2', $this->attrValue($html, 'data-stat', 'bulk-waiting-on-you', 'data-stat-value'));
        $this->assertSame('1', $this->attrValue($html, 'data-stat', 'waiting-on-publisher', 'data-stat-bulk'));

        $bulkTable = $this->nodeText($html, 'data-queue', 'open-bulk');
        $this->assertStringContainsString('#'.$requested->id, $bulkTable);
        $this->assertStringNotContainsString('#'.$awaitingPublisher->id, $bulkTable);
        $this->assertStringContainsString('#'.$leftover->id, $bulkTable);
        $this->assertStringContainsString('Queue Marketer', $bulkTable);
        $this->assertStringNotContainsString('#'.$trulyDone->id, $bulkTable);

        $waitingCard = $this->nodeHtml($html, 'data-stat', 'waiting-on-publisher');
        $this->assertSame('div', strtolower($this->node($html, 'data-stat', 'waiting-on-publisher')->nodeName));
        $this->assertStringContainsString(
            route('marketing.bulk-site-requests.index', ['status' => 'awaiting_publisher'], false),
            $waitingCard
        );
        $this->assertStringContainsString(
            route('marketing.bulk-site-requests.index', ['status' => MarketingOpsQueues::FILTER_NEEDS_MARKETER], false),
            $html
        );
        $this->assertSame('2', $this->node($html, 'data-nav-badge', 'bulk')->attributes->getNamedItem('data-count')?->nodeValue);

        $filtered = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', [
                'status' => MarketingOpsQueues::FILTER_NEEDS_MARKETER,
            ]))
            ->assertOk()
            ->assertSee('Waiting on you', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $requested), $filtered);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $leftover), $filtered);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $awaitingPublisher), $filtered);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $trulyDone), $filtered);

        $requestedOnly = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', ['status' => BulkSiteRequest::STATUS_REQUESTED]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $requested), $requestedOnly);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $leftover), $requestedOnly);
    }

    public function test_partial_done_batch_stays_on_waiting_on_you(): void
    {
        $partial = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
            'seeded_at' => now(),
        ]);
        $draft = $this->makeSite([
            'site_name' => 'Partial Done Draft',
            'site_url' => 'https://partial-done-draft.example',
            'domain' => 'partial-done-draft.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'bulk_site_request_id' => $partial->id,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $partial->id,
            'site_url' => $draft->site_url,
            'domain' => $draft->domain,
            'price' => 40,
            'site_id' => $draft->id,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $partial->id,
            'site_url' => 'https://partial-done-pending.example',
            'domain' => 'partial-done-pending.example',
            'price' => 55,
            'site_id' => null,
        ]);

        $publisherOnly = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $publisherOnly->id,
            'site_url' => 'https://publisher-only-bulk.example',
            'domain' => 'publisher-only-bulk.example',
            'price' => 30,
            'site_id' => $this->makeSite([
                'site_name' => 'Publisher Only Draft',
                'site_url' => 'https://publisher-only-bulk.example',
                'domain' => 'publisher-only-bulk.example',
                'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
                'bulk_site_request_id' => $publisherOnly->id,
            ])->id,
        ]);

        $this->assertSame(1, MarketingOpsQueues::bulkWaitingOnMarketer()->count());
        $this->assertSame(1, MarketingOpsQueues::bulkWaitingOnPublisher()->count());
        $this->assertTrue(MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($partial->id)->exists());
        $this->assertFalse(MarketingOpsQueues::bulkWaitingOnPublisher()->whereKey($partial->id)->exists());
        $this->assertTrue(MarketingOpsQueues::bulkWaitingOnPublisher()->whereKey($publisherOnly->id)->exists());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame('1', $this->attrValue($html, 'data-stat', 'bulk-waiting-on-you', 'data-stat-value'));
        $this->assertSame('1', $this->attrValue($html, 'data-stat', 'waiting-on-publisher', 'data-stat-bulk'));
        $this->assertSame('1', $this->node($html, 'data-nav-badge', 'bulk')->attributes->getNamedItem('data-count')?->nodeValue);

        $bulkTable = $this->nodeText($html, 'data-queue', 'open-bulk');
        $this->assertStringContainsString('#'.$partial->id, $bulkTable);
        $this->assertStringNotContainsString('#'.$publisherOnly->id, $bulkTable);

        $index = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', [
                'status' => MarketingOpsQueues::FILTER_NEEDS_MARKETER,
            ]))
            ->assertOk()
            ->assertSee('1 waiting on you', false)
            ->assertSee('Pending to add', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $partial), $index);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $publisherOnly), $index);
    }

    public function test_bulk_index_filter_empty_state_and_status_labels(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index'))
            ->assertOk()
            ->assertSee('No bulk requests yet.', false)
            ->assertDontSee('No requests match this filter.', false)
            ->assertSee('Waiting on marketer', false)
            ->assertSee('Sheet emailed', false)
            ->assertSee('Waiting on publisher', false)
            ->assertDontSee('>sheet_sent<', false)
            ->assertDontSee('>awaiting_publisher<', false);

        BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', [
                'status' => BulkSiteRequest::STATUS_CANCELLED,
            ]))
            ->assertOk()
            ->assertSee('No requests match this filter.', false)
            ->assertSee('Reset filter', false)
            ->assertDontSee('No bulk requests yet.', false);
    }

    public function test_dashboard_queues_oldest_first_so_stale_rows_stay_visible(): void
    {
        $newest = null;
        for ($i = 1; $i <= 6; $i++) {
            $req = BulkSiteRequest::create([
                'publisher_id' => $this->publisher->id,
                'status' => BulkSiteRequest::STATUS_REQUESTED,
                'estimated_count' => $i,
            ]);
            $req->forceFill([
                'created_at' => now()->subDays(7 - $i),
                'updated_at' => now()->subDays(7 - $i),
            ])->save();
            if ($i === 6) {
                $newest = $req;
            }
        }

        $oldest = BulkSiteRequest::query()->orderBy('created_at')->orderBy('id')->first();

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $bulkTable = $this->nodeText($html, 'data-queue', 'open-bulk');
        $this->assertStringContainsString('#'.$oldest->id, $bulkTable);
        $this->assertStringNotContainsString('#'.$newest->id, $bulkTable);
    }

    public function test_dashboard_queue_counts_json_matches_ready_and_bulk_queues(): void
    {
        $this->makeSite([
            'site_name' => 'Count Ready Site',
            'site_url' => 'https://count-ready.example',
            'domain' => 'count-ready.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);

        $this->actingAs($this->marketer)
            ->getJson(route('marketing.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ready_sites', 1)
            ->assertJsonPath('bulk_waiting', 1);
    }

    public function test_empty_dashboard_shows_queue_ctas(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('No sites ready to activate.', false)
            ->assertSee('No bulk requests waiting on you.', false)
            ->assertSee('No listings waiting on a publisher.', false)
            ->assertSee('No marketing tasks recorded yet.', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.sites.create', [], false), $html);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.index', [], false), $html);
        $this->assertSame('0', $this->node($html, 'data-nav-badge', 'sites')->attributes->getNamedItem('data-count')?->nodeValue);
        $this->assertSame('0', $this->node($html, 'data-nav-badge', 'bulk')->attributes->getNamedItem('data-count')?->nodeValue);
    }

    public function test_marketer_notification_inbox_uses_marketing_shell(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('notifications.all'))
            ->assertOk()
            ->assertSee('All notifications', false)
            ->getContent();

        $this->assertStringContainsString('role-shell-marketing', $html);
        $this->assertStringContainsString(route('marketing.history'), $html);
        $this->assertStringNotContainsString(route('admin.payments', [], false), $html);
        $this->assertStringNotContainsString(route('admin.deposits', [], false), $html);
        $this->assertStringNotContainsString('>Deposits</span>', $html);
        $this->assertStringNotContainsString(route('marketing.site-enrichment.index'), $html);
        $this->assertStringNotContainsString('>Enrichment</span>', $html);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Queue Site',
            'site_url' => 'https://queue-site.example',
            'domain' => 'queue-site.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Queue dashboard site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    private function attrValue(string $html, string $parentAttr, string $parentValue, string $childAttr): string
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query(sprintf('//*[@%s="%s"]//*[@%s]', $parentAttr, $parentValue, $childAttr));
        $this->assertGreaterThan(0, $nodes->length, "Missing {$childAttr} inside {$parentAttr}={$parentValue}");

        return (string) $nodes->item(0)->attributes->getNamedItem($childAttr)?->nodeValue;
    }

    private function nodeText(string $html, string $attr, string $value): string
    {
        return (string) $this->node($html, $attr, $value)->textContent;
    }

    private function nodeHtml(string $html, string $attr, string $value): string
    {
        $node = $this->node($html, $attr, $value);

        return (string) $node->ownerDocument?->saveHTML($node);
    }

    private function node(string $html, string $attr, string $value): \DOMNode
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query(sprintf('//*[@%s="%s"]', $attr, $value));
        $this->assertGreaterThan(0, $nodes->length, "Missing {$attr}={$value}");

        return $nodes->item(0);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
