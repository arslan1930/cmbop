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
use Illuminate\Support\Facades\DB;
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
        $readyMarkup = $this->nodeHtml($html, 'data-queue', 'ready-sites');
        $this->assertStringContainsString('js-mkt-activate', $readyMarkup);
        $this->assertStringContainsString('This listing is below the quality bar', $readyMarkup);
        $this->assertStringContainsString(
            e(route('marketing.sites.index', ['publisher' => $ready->publisher_id, 'site' => $ready->id], false)),
            $html
        );
        $this->assertStringContainsString(
            e(route('marketing.sites.index', ['waiting_on_publisher' => 1, 'flat' => 1], false)),
            $html
        );
        $this->assertStringContainsString('Showing 2 of 2', $this->nodeText($html, 'data-queue', 'ready-sites'));
        $this->assertStringContainsString('Showing 2 of 2', $this->nodeText($html, 'data-queue', 'waiting-sites'));
        $this->assertStringContainsString('View all', $this->nodeText($html, 'data-queue', 'waiting-sites'));
        $this->assertMatchesRegularExpression('/ago|just now/i', $this->nodeText($html, 'data-queue', 'ready-sites'));
        $this->assertStringContainsString('slbHttpMessage', $html);
        $this->assertStringContainsString('Find a publisher', $html);
        $this->assertStringContainsString(route('marketing.promotions.index', [], false), $html);
        $this->assertStringContainsString(route('marketing.staff-handbook', [], false), $html);
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

    public function test_leftover_publisher_accepted_at_stays_waiting_on_publisher(): void
    {
        $invite = $this->makeSite([
            'site_name' => 'Leftover Accept Invite',
            'site_url' => 'https://leftover-accept-invite.example',
            'domain' => 'leftover-accept-invite.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'assigned_by_user_id' => $this->marketer->id,
            'publisher_accepted_at' => now(),
        ]);
        DB::table('sites')->where('id', $invite->id)->update([
            'publisher_accepted_at' => 'not-a-date',
        ]);

        $invite->refresh();
        $this->assertTrue($invite->isPendingPublisherAcceptance());
        $this->assertFalse($invite->needsAdminReview());
        $this->assertTrue(MarketingOpsQueues::sitesWaitingOnPublisher()->whereKey($invite->id)->exists());
        $this->assertFalse(MarketingOpsQueues::sitesReadyForStaff()->whereKey($invite->id)->exists());
    }

    public function test_dashboard_open_bulk_includes_completed_rows_still_needing_done(): void
    {
        $requested = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
            'handled_by' => $this->marketer->id,
        ]);
        $this->addPendingItem($requested, 'requested-waiting.example');
        $awaitingPublisher = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
        ]);
        $this->makeSite([
            'site_name' => 'Awaiting Publisher Draft',
            'site_url' => 'https://awaiting-publisher-draft.example',
            'domain' => 'awaiting-publisher-draft.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'bulk_site_request_id' => $awaitingPublisher->id,
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
            route('marketing.bulk-site-requests.index', ['status' => MarketingOpsQueues::FILTER_WAITING_PUBLISHER], false),
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

        // The index heals a completed batch that still has URL rows and no
        // drafts back to requested, so the Requested filter lists it too.
        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $leftover->fresh()->status);
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $trulyDone->fresh()->status);

        $requestedOnly = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', ['status' => BulkSiteRequest::STATUS_REQUESTED]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $requested), $requestedOnly);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $leftover), $requestedOnly);
<<<<<<< HEAD
=======
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $awaitingPublisher), $requestedOnly);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $trulyDone), $requestedOnly);
>>>>>>> 0bb3d9020eae73f9a21e06aacdfaefda4934bbfc
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
            ->assertSee('Nothing waiting on you.', false)
            ->assertDontSee('No requests match this filter.', false)
            ->assertSee('Waiting on marketer', false)
            ->assertSee('Sheet emailed', false)
            ->assertSee('Waiting on publisher', false)
            ->assertDontSee('>sheet_sent<', false)
            ->assertDontSee('value="awaiting_publisher"', false)
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
            ->assertDontSee('Nothing waiting on you.', false);
    }

    public function test_bulk_index_defaults_to_waiting_on_you_and_can_claim(): void
    {
        $waiting = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $this->addPendingItem($waiting, 'claim-waiting.example');

        $publisherOnly = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
        ]);
        $this->addPendingItem($publisherOnly, 'claim-publisher.example');
        $site = $this->makeSite([
            'site_name' => 'Publisher Only Claim',
            'site_url' => 'https://claim-publisher.example',
            'domain' => 'claim-publisher.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'bulk_site_request_id' => $publisherOnly->id,
        ]);
        $publisherOnly->items()->first()->forceFill(['site_id' => $site->id])->save();

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index'))
            ->assertOk()
            ->assertSee('staff-sites-strip', false)
            ->assertSee('Waiting on you', false)
            ->assertSee('Finished', false)
            ->assertSee('Cancelled', false)
            ->assertSee('Unclaimed', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $waiting), $html);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $publisherOnly), $html);

        $legacyPublisherQueue = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', [
                'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="waiting_publisher"', $legacyPublisherQueue);
        $this->assertStringNotContainsString('value="awaiting_publisher"', $legacyPublisherQueue);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $publisherOnly), $legacyPublisherQueue);
        $this->assertStringNotContainsString(route('marketing.bulk-site-requests.show', $waiting), $legacyPublisherQueue);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $waiting))
            ->post(route('marketing.bulk-site-requests.claim', $waiting))
            ->assertRedirect();

        $this->assertSame($this->marketer->id, (int) $waiting->fresh()->handled_by);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee(route('marketing.bulk-site-requests.show', $publisherOnly), false);
    }

    public function test_bulk_show_links_previous_and_next_waiting(): void
    {
        $older = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $this->addPendingItem($older, 'neighbor-older.example');
        $older->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->save();

        $middle = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $this->addPendingItem($middle, 'neighbor-middle.example');
        $middle->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $newer = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $this->addPendingItem($newer, 'neighbor-newer.example');

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $middle))
            ->assertOk()
            ->assertSee('Previous waiting', false)
            ->assertSee('Next waiting', false)
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $older), $html);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $newer), $html);
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
            $this->addPendingItem($req, 'oldest-first-'.$i.'.example');
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
        $waiting = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $this->addPendingItem($waiting, 'count-waiting.example');

        $this->actingAs($this->marketer)
            ->getJson(route('marketing.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ready_sites', 1)
            ->assertJsonPath('bulk_waiting', 1)
            ->assertJsonPath('sites_waiting_on_publisher', 0)
            ->assertJsonPath('bulk_waiting_on_publisher', 0)
            ->assertJsonPath('my_tasks_today', 0)
            ->assertJsonPath('my_tasks_total', 0);
    }

    public function test_legacy_sheet_batch_counts_as_waiting_on_marketer(): void
    {
        $legacy = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 8,
            'sheet_sent_at' => now(),
        ]);

        $this->assertTrue($legacy->canAddDraftSites());
        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($legacy->id)->blockingPublisher()->exists()
        );
        $this->assertTrue(MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($legacy->id)->exists());
        $this->assertSame(1, MarketingOpsQueues::bulkWaitingOnMarketer()->count());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame('1', $this->attrValue($html, 'data-stat', 'bulk-waiting-on-you', 'data-stat-value'));
        $this->assertSame('1', $this->node($html, 'data-nav-badge', 'bulk')->attributes->getNamedItem('data-count')?->nodeValue);
        $this->assertStringContainsString('#'.$legacy->id, $this->nodeText($html, 'data-queue', 'open-bulk'));

        $index = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index', [
                'status' => MarketingOpsQueues::FILTER_NEEDS_MARKETER,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.show', $legacy), $index);
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
        $this->assertStringContainsString(
            json_encode(staff_route('sites.active', '__ID__', false)),
            $html
        );
        $this->assertStringNotContainsString('Promise.resolve(true)', $html);
        $this->assertStringContainsString('Swal.fire', $html);
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
    private function addPendingItem(BulkSiteRequest $bulk, string $domain): BulkSiteRequestItem
    {
        return BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'price' => 40,
        ]);
    }

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
