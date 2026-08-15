<?php

namespace Tests\Feature;

use App\Mail\BulkSiteItemsRejected;
use App\Mail\BulkSiteRequestCancelled;
use App\Mail\BulkSitesSeededNotification;
use App\Mail\SiteStatusNotification;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\MarketingOpsQueues;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BulkDoneRejectRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $marketer;

    private User $admin;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Marketer Casey',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
            'name' => 'Admin Avery',
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);
    }

    /**
     * @return list<array{0:string,1:User}>
     */
    private function staffActors(): array
    {
        return [
            ['admin', $this->admin],
            ['marketing', $this->marketer],
        ];
    }

    private function marketplaceCodes(): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        return [strtolower($country->code), strtolower($language->code)];
    }

    /**
     * @return array{0:BulkSiteRequest,1:list<BulkSiteRequestItem>}
     */
    private function makeBulkWithItems(int $count, string $prefix): array
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => $count,
        ]);

        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $domain = $prefix.'-'.$i.'.example';
            $items[] = BulkSiteRequestItem::create([
                'bulk_site_request_id' => $bulk->id,
                'site_url' => 'https://'.$domain,
                'domain' => $domain,
                'price' => 40 + $i,
            ]);
        }

        return [$bulk, $items];
    }

    private function completeRow(BulkSiteRequestItem $item): array
    {
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        return [
            $item->id => [
                'language' => $language,
                'country' => $country,
                'da' => 30,
                'dr' => 35,
                'traffic' => 5000,
                'categories' => $category->name,
            ],
        ];
    }

    public function test_done_page_shows_delete_and_note_for_admin_and_marketer(): void
    {
        [$bulk] = $this->makeBulkWithItems(2, 'ui');

        foreach ($this->staffActors() as [$prefix, $user]) {
            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertSee('id="bulkDoneForm"', false)
                ->assertSee(route($prefix.'.bulk-site-requests.done', $bulk), false)
                ->assertSee('data-bulk-reject-row', false)
                ->assertSee('name="rejection_note"', false)
                ->assertSee('Note to publisher (removed sites)', false)
                ->assertSee('Delete a row you will not add', false);
        }

        $blade = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString("staff_route('bulk-site-requests.done'", $blade);
        $this->assertStringContainsString('rejected_item_ids[]', $blade);
        $this->assertStringContainsString('function markRowRejected', $blade);
        $this->assertStringContainsString('function doneFormReady', $blade);
        $this->assertStringContainsString('function noteCharCount', $blade);
        $this->assertStringContainsString("old_text('rejection_note')", $blade);
        $this->assertStringContainsString('rejected.length === 0 || noteOk', $blade);
        $this->assertStringNotContainsString('route(\'admin.bulk-site-requests.done\'', $blade);
        $this->assertStringContainsString("document.querySelectorAll('.bulk-draft-delete')", $blade);
        $this->assertStringContainsString("input: 'textarea'", $blade);
        $this->assertStringContainsString('Reason for the publisher', $blade);
        $this->assertStringContainsString('JSON.stringify({ reason })', $blade);
        $this->assertStringContainsString("'Content-Type': 'application/json'", $blade);
        $this->assertStringContainsString('form.bulk-request-cancel', $blade);
        $this->assertStringContainsString("staff_route('bulk-site-requests.cancel'", $blade);
        $this->assertStringContainsString('Cancel bulk request?', $blade);
        $this->assertStringContainsString('canCancel()', $blade);
        $this->assertStringContainsString("title: 'Remove this site?'", $blade);
        $this->assertStringContainsString('Only pending URL + price domains from this request can be seeded here.', $blade);
    }

    public function test_done_two_complete_and_reject_one_notifies_once_for_both_roles(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(3, $prefix.'-mix');
            [$keepA, $keepB, $drop] = $items;

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($keepA) + $this->completeRow($keepB),
                    'rejected_item_ids' => [$drop->id],
                    'rejection_note' => 'These metrics do not meet our listing bar.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHas('success', function ($message) {
                    $message = (string) $message;

                    return str_contains($message, '2 site(s) added')
                        && str_contains($message, '1 site was removed');
                });

            $this->assertDatabaseHas('sites', ['domain' => $keepA->domain]);
            $this->assertDatabaseHas('sites', ['domain' => $keepB->domain]);
            $this->assertDatabaseMissing('sites', ['domain' => $drop->domain]);
            $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertNotNull($keepA->fresh()->site_id);
            $this->assertSame(2, $bulk->fresh()->items()->count());

            Mail::assertQueued(BulkSitesSeededNotification::class, 1);
            Mail::assertQueued(BulkSiteItemsRejected::class, 1);
            Mail::assertQueued(BulkSiteItemsRejected::class, function (BulkSiteItemsRejected $mail) use ($drop) {
                return $mail->hasTo($this->publisher->email)
                    && $mail->domains === [$drop->domain]
                    && str_contains($mail->note, 'listing bar');
            });
            Mail::assertNotQueued(SiteStatusNotification::class);

            $this->assertSame(1, InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('related_id', $bulk->id)
                ->where('title', 'A site was not added from your bulk request')
                ->count());
            $this->assertSame(1, InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('related_id', $bulk->id)
                ->where('title', '2 sites were added to Pending sites')
                ->count());

            $this->assertDatabaseHas('activity_logs', [
                'action' => 'bulk_request.items_rejected',
                'user_id' => $user->id,
                'subject_id' => $bulk->id,
            ]);
            $rejectLog = ActivityLog::query()
                ->where('action', 'bulk_request.items_rejected')
                ->where('subject_id', $bulk->id)
                ->first();
            $this->assertNotNull($rejectLog);
            $this->assertSame([$drop->domain], $rejectLog->properties['domains'] ?? null);
            $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        }
    }

    public function test_reject_only_with_note_deletes_items_and_does_not_cancel(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-only');

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => array_map(fn ($item) => $item->id, $items),
                    'rejection_note' => 'We are not listing these two domains.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHas('success', fn ($message) => str_contains((string) $message, '2 sites were removed'));

            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertSee('No pending websites left on this request', false)
                ->assertDontSee('All submitted rows are already added', false);

            $this->assertSame(0, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
            $this->assertSame(0, $bulk->fresh()->items()->count());
            $fresh = $bulk->fresh();
            $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $fresh->status);
            $this->assertSame('Finished', $fresh->statusLabel());
            $this->assertNotSame(BulkSiteRequest::STATUS_CANCELLED, $fresh->status);
            $this->assertFalse($fresh->canAddDraftSites());
            $this->assertFalse(
                MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists(),
                'Reject-all with no leftover URL+price rows must leave the Waiting on you queue.'
            );
            $this->assertFalse(
                BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists(),
                'Reject-all must not block the publisher from submitting a new bulk.'
            );

            Mail::assertQueued(BulkSiteItemsRejected::class, 1);
            Mail::assertNotQueued(BulkSitesSeededNotification::class);
            Mail::assertNotQueued(SiteStatusNotification::class);
            Mail::assertNotQueued(BulkSiteRequestCancelled::class);

            $this->assertSame(1, InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('related_id', $bulk->id)
                ->where('title', '2 sites were not added from your bulk request')
                ->count());
        }
    }

    public function test_reject_all_completes_when_only_archived_sites_remain(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'arch-left');

        Site::query()->create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Archived leftover',
            'site_url' => 'https://arch-left-old.example',
            'domain' => 'arch-left-old.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Archived leftover from an earlier seed. ', 2),
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'rejected_item_ids' => [$items[0]->id],
                'rejection_note' => 'We are not listing this domain after review.',
            ])
            ->assertSessionHas('success');

        $fresh = $bulk->fresh();
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $fresh->status);
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
        $this->assertFalse(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://after-arch-reject-a.example', 'price' => 40],
                    ['url' => 'https://after-arch-reject-b.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites', ['status' => 'pending']))
            ->assertSessionHas('success');
    }

    public function test_reject_all_blocks_seed_and_clears_publisher_open_banner(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'pub-open');

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'rejected_item_ids' => [$items[0]->id],
                'rejection_note' => 'We are not listing this domain after review.',
            ])
            ->assertSessionHas('success');

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => 'https://should-not-seed.example,40,20,20,1000,de,de,Nope',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('error', fn ($message) => str_contains(
                (string) $message,
                'no pending websites to seed'
            ));

        $this->assertDatabaseMissing('sites', ['domain' => 'should-not-seed.example']);

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertDontSee('Bulk request #'.$bulk->id, false)
            ->assertDontSee('You already have an open bulk request', false);
    }

    public function test_reject_remaining_seeded_rows_completes_and_leaves_waiting_queue(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'seeded-left');
        $linked = Site::create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Already finished draft',
            'site_url' => 'https://seeded-left-done.example',
            'domain' => 'seeded-left-done.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Finished seeded draft for leftover reject. ', 2),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $bulk->forceFill([
            'status' => BulkSiteRequest::STATUS_SEEDED,
            'seeded_at' => now(),
        ])->save();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'rejected_item_ids' => [$items[0]->id],
                'rejection_note' => 'The leftover URL will not be listed.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $items[0]->id]);
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertTrue($linked->fresh()->exists);
        $this->assertFalse(MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists());
    }

    public function test_reject_without_note_does_not_delete(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-nonote');

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => [$items[0]->id],
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors('rejection_note')
                ->assertSessionHas('error', fn ($message) => str_contains(
                    (string) $message,
                    'Add a note for the publisher about the removed sites'
                ));

            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
            Mail::assertNothingQueued();
        }
    }

    public function test_complete_plus_reject_without_note_does_not_seed_or_delete(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-mixnote');
            [$keep, $drop] = $items;

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($keep),
                    'rejected_item_ids' => [$drop->id],
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors('rejection_note')
                ->assertSessionHas('error', fn ($message) => str_contains(
                    (string) $message,
                    'Add a note for the publisher about the removed sites'
                ));

            $this->assertDatabaseMissing('sites', ['domain' => $keep->domain]);
            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertNull($keep->fresh()->site_id);
            Mail::assertNothingQueued();
        }
    }

    public function test_reject_note_shorter_than_ten_does_not_delete(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-short');

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => [$items[0]->id],
                    'rejection_note' => 'too short',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors('rejection_note');

            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
            Mail::assertNothingQueued();
        }
    }

    public function test_partial_row_still_blocks_even_with_rejects_and_note(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$country, $language] = $this->marketplaceCodes();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-partial');
            [$partial, $drop] = $items;

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => [
                        $partial->id => [
                            'language' => $language,
                            'country' => $country,
                            'da' => 20,
                        ],
                    ],
                    'rejected_item_ids' => [$drop->id],
                    'rejection_note' => 'Valid publisher note for the removed site.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors()
                ->assertSessionHas('error');

            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertDatabaseMissing('sites', ['domain' => $partial->domain]);
            Mail::assertNothingQueued();
        }
    }

    public function test_partial_plus_missing_note_titles_box_errors_not_note_only(): void
    {
        [$country, $language] = $this->marketplaceCodes();
        [$bulk, $items] = $this->makeBulkWithItems(2, 'title-mix');
        [$partial, $drop] = $items;

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $partial->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                    ],
                ],
                'rejected_item_ids' => [$drop->id],
            ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="alert alert-danger py-2 small">\s*<strong>\s*Finish the boxes first\.\s*<\/strong>\s*Finish this field, or clear the row and submit only complete blocks\./s',
            $html
        );
        $this->assertStringNotContainsString('Add a publisher note.', $html);
    }

    public function test_reject_email_still_sends_when_publisher_opts_out_of_system_updates(): void
    {
        EmailNotificationPreference::create([
            'user_id' => $this->publisher->id,
            'preference_key' => 'system_updates',
            'enabled' => false,
        ]);

        [$bulk, $items] = $this->makeBulkWithItems(1, 'pref-off');
        $mail = new BulkSiteItemsRejected(
            $bulk,
            $this->publisher,
            [$items[0]->domain],
            'These metrics do not meet our listing bar.',
            [$items[0]->id]
        );

        $this->assertNull(config('email_notifications.types.bulk_request_items_rejected.preference'));

        $method = new \ReflectionMethod($mail, 'passesNotificationPolicy');
        $this->assertTrue($method->invoke($mail));
    }

    public function test_seeded_email_dedupe_keys_differ_for_separate_same_size_batches(): void
    {
        [$bulk] = $this->makeBulkWithItems(2, 'seed-dedupe');
        $first = new BulkSitesSeededNotification($bulk, 1, $this->publisher, ['seed-dedupe-1.example']);
        $second = new BulkSitesSeededNotification($bulk, 1, $this->publisher, ['seed-dedupe-2.example']);
        $retry = new BulkSitesSeededNotification($bulk, 1, $this->publisher, ['seed-dedupe-1.example']);

        $this->assertNotSame($first->dedupeKey, $second->dedupeKey);
        $this->assertSame($first->dedupeKey, $retry->dedupeKey);

        EmailLog::create([
            'to_email' => $this->publisher->email,
            'subject' => 'Your sites were added to Pending sites',
            'notification_type' => 'bulk_sites_seeded',
            'dedupe_key' => $first->dedupeKey,
            'status' => EmailLog::STATUS_DELIVERED,
        ]);

        $isDuplicate = new \ReflectionMethod(BulkSitesSeededNotification::class, 'isDuplicate');
        $this->assertFalse($isDuplicate->invoke($second, $second->dedupeKey));
        $this->assertTrue($isDuplicate->invoke($retry, $retry->dedupeKey));
    }

    public function test_cannot_reject_item_that_already_has_a_site(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-linked');
            [$linked, $pending] = $items;

            $site = Site::create([
                'publisher_id' => $this->publisher->id,
                'bulk_site_request_id' => $bulk->id,
                'site_name' => $linked->domain,
                'site_url' => $linked->site_url,
                'domain' => $linked->domain,
                'example_url' => $linked->site_url,
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => 'de',
                'language' => 'de',
                'category' => 'Pending',
                'price' => 50,
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => str_repeat('Placeholder description text. ', 3),
                'verified' => false,
                'active' => false,
                'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            ]);
            $linked->forceFill(['site_id' => $site->id])->save();

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => [$linked->id],
                    'rejection_note' => 'Trying to drop an already added site.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('bulk_site_request_items', [
                'id' => $linked->id,
                'site_id' => $site->id,
            ]);
            $this->assertDatabaseHas('sites', ['id' => $site->id]);
            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $pending->id]);
            Mail::assertNothingQueued();
        }
    }

    public function test_stale_already_seeded_item_key_does_not_block_done(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(3, $prefix.'-stale');
            [$linked, $keep, $drop] = $items;

            $site = Site::create([
                'publisher_id' => $this->publisher->id,
                'bulk_site_request_id' => $bulk->id,
                'site_name' => $linked->domain,
                'site_url' => $linked->site_url,
                'domain' => $linked->domain,
                'example_url' => $linked->site_url,
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => 'de',
                'language' => 'de',
                'category' => 'Pending',
                'price' => 50,
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => str_repeat('Placeholder description text. ', 3),
                'verified' => false,
                'active' => false,
                'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            ]);
            $linked->forceFill(['site_id' => $site->id])->save();

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($keep) + [
                        $linked->id => [
                            'da' => 11,
                        ],
                    ],
                    'rejected_item_ids' => [$drop->id],
                    'rejection_note' => 'Dropping the leftover URL after a stale draft key.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHas('success');

            $this->assertDatabaseHas('sites', ['domain' => $keep->domain]);
            $this->assertDatabaseHas('sites', ['id' => $site->id]);
            $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertDatabaseHas('bulk_site_request_items', [
                'id' => $linked->id,
                'site_id' => $site->id,
            ]);
        }
    }

    public function test_emoji_publisher_note_uses_character_count(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-emoji-short');

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => [$items[0]->id],
                    'rejection_note' => '😀😀😀😀😀',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors('rejection_note');

            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
            Mail::assertNothingQueued();
        }

        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'emoji-ok');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'rejected_item_ids' => [$items[0]->id],
                'rejection_note' => '😀😀😀😀😀😀😀😀😀😀',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $items[0]->id]);
        Mail::assertQueued(BulkSiteItemsRejected::class, 1);
    }

    public function test_scalar_rejected_item_id_is_accepted(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'scalar-id');

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.done', $bulk), [
                'rejected_item_ids' => $items[0]->id,
                'rejection_note' => 'Single id posted without an array wrapper.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $items[0]->id]);
        Mail::assertQueued(BulkSiteItemsRejected::class, 1);
    }

    public function test_complete_wins_is_not_replayed_as_rejected_after_note_error(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-replay');
            [$keep, $drop] = $items;

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($keep),
                    'rejected_item_ids' => [$keep->id, $drop->id],
                    'rejection_note' => '',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors('rejection_note');

            $html = $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('name="items['.$keep->id.'][country]"', $html);
            $this->assertStringContainsString('name="rejected_item_ids[]" value="'.$drop->id.'"', $html);
            $this->assertStringNotContainsString('name="rejected_item_ids[]" value="'.$keep->id.'"', $html);
            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $keep->id]);
            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $drop->id]);
            Mail::assertNothingQueued();
        }
    }

    public function test_complete_row_wins_over_same_id_in_rejected_list(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-win');
            $item = $items[0];

            $this->actingAs($user)
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($item),
                    'rejected_item_ids' => [$item->id],
                    'rejection_note' => 'Should be ignored because the row is complete.',
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $this->assertDatabaseHas('sites', ['domain' => $item->domain]);
            $this->assertNotNull($item->fresh()->site_id);
            Mail::assertQueued(BulkSitesSeededNotification::class, 1);
            Mail::assertNotQueued(BulkSiteItemsRejected::class);
        }
    }

    public function test_empty_unmarked_rows_stay_pending(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(3, $prefix.'-leave');
            [$keep, $drop, $leave] = $items;

            $this->actingAs($user)
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($keep),
                    'rejected_item_ids' => [$drop->id],
                    'rejection_note' => 'Dropping the middle domain only.',
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $this->assertNotNull($keep->fresh()->site_id);
            $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertNull($leave->fresh()->site_id);
            $this->assertSame(1, $bulk->fresh()->items()->whereNull('site_id')->count());
        }
    }

    public function test_publisher_and_advertiser_cannot_post_done(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'guest');

        foreach ([$this->publisher, $this->advertiser] as $user) {
            foreach (['admin', 'marketing'] as $prefix) {
                $this->actingAs($user)
                    ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                        'rejected_item_ids' => [$items[0]->id],
                        'rejection_note' => 'Outsiders should not be able to reject rows.',
                    ])
                    ->assertForbidden();
            }
        }

        $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
    }

    public function test_marketer_admin_show_link_opens_marketing_done_form(): void
    {
        [$bulk] = $this->makeBulkWithItems(1, 'admin-get');

        $this->actingAs($this->marketer)
            ->get(route('admin.bulk-site-requests.show', $bulk))
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk));

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.done', $bulk), $html);
        $this->assertStringNotContainsString(route('admin.bulk-site-requests.done', $bulk), $html);
    }

    public function test_marketer_leftover_admin_done_post_replays_on_marketing_url(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'admin-post');
        $payload = [
            'rejected_item_ids' => [$items[0]->id],
            'rejection_note' => 'Leftover admin Done URL should still remove the site.',
        ];

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('admin.bulk-site-requests.done', $bulk), $payload)
            ->assertStatus(307)
            ->assertRedirect(route('marketing.bulk-site-requests.done', $bulk));

        $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), $payload)
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $items[0]->id]);
        Mail::assertQueued(BulkSiteItemsRejected::class, 1);
    }

    public function test_marketer_reject_note_shows_on_history_and_bulk_page(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'hist-note');
        $note = 'These metrics do not meet our listing bar.';

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'rejected_item_ids' => [$items[0]->id],
                'rejection_note' => $note,
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('Removed bulk sites', false)
            ->assertSee('Note: '.$note, false)
            ->assertSee(route('marketing.bulk-site-requests.show', $bulk), false)
            ->assertDontSee(route('admin.bulk-site-requests.show', $bulk), false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Removed bulk sites', false)
            ->assertSee('Note: '.$note, false);
    }

    public function test_admin_with_marketing_active_posts_marketing_done(): void
    {
        Mail::fake();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->marketer->roles()->attach($adminRole->id);
        $this->assertSame('marketing', $this->marketer->fresh()->activeRole());

        [$bulk, $items] = $this->makeBulkWithItems(1, 'dual-role');

        $html = $this->actingAs($this->marketer->fresh())
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('marketing.bulk-site-requests.done', $bulk), $html);
        $this->assertStringNotContainsString(route('admin.bulk-site-requests.done', $bulk), $html);

        $this->actingAs($this->marketer->fresh())
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'rejected_item_ids' => [$items[0]->id],
                'rejection_note' => 'Dual-role marketer can still remove a pending row.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $items[0]->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.items_rejected',
            'user_id' => $this->marketer->id,
            'role' => 'marketing',
        ]);
    }

    public function test_deleting_last_seeded_draft_returns_row_to_marketer_queue(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-redel');
            $item = $items[0];

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($item),
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk));

            $site = Site::query()->where('domain', $item->domain)->firstOrFail();
            $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
            $this->assertNotNull($item->fresh()->site_id);

            $this->actingAs($user)
                ->deleteJson(route($prefix.'.sites.destroy', $site->id), [
                    'reason' => 'Wrong domain was seeded from the bulk row.',
                ])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertDatabaseMissing('sites', ['id' => $site->id]);
            $this->assertNull($item->fresh()->site_id);

            $fresh = $bulk->fresh();
            $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $fresh->status);
            $this->assertNull($fresh->completed_at);
            $this->assertSame('Waiting on marketer', $fresh->statusLabel());
            $this->assertTrue($fresh->canAddDraftSites());
            $this->assertTrue(
                BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
            );
            $this->assertTrue(
                MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
            );

            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertSee('Waiting on marketer', false);

            Mail::assertNotQueued(SiteStatusNotification::class);
            $this->assertSame(0, InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('title', 'like', 'Site submission removed%')
                ->count());

            $this->actingAs($this->publisher)
                ->get(route('publisher.websites'))
                ->assertOk()
                ->assertSee('Waiting on marketer', false)
                ->assertSee('our marketer adds DA/DR', false)
                ->assertDontSee('awaiting publisher', false)
                ->assertDontSee('Complete details, then we approve', false);
        }
    }

    public function test_staff_delete_of_www_draft_does_not_email_removal_when_item_repends(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.repend-mail.example',
            'domain' => 'www.repend-mail.example',
            'price' => 40,
        ]);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($item),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', 'repend-mail.example')->firstOrFail();
        $this->assertNotNull($item->fresh()->site_id);

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $site->id), [
                'reason' => 'Wrong www variant was seeded from the bulk row.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($item->fresh()->site_id);
        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $bulk->fresh()->status);
        Mail::assertNotQueued(SiteStatusNotification::class);
        $this->assertSame(0, InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'like', 'Site submission removed%')
            ->count());
    }

    public function test_done_after_last_draft_delete_reseeds_and_awaits_publisher(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'reseed');
        $item = $items[0];

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($item),
            ])
            ->assertRedirect();

        $firstSite = Site::query()->where('domain', $item->domain)->firstOrFail();

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $firstSite->id), [
                'reason' => 'Wrong metrics were seeded; will Done again.',
            ])
            ->assertOk();

        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $bulk->fresh()->status);
        $this->assertNull($item->fresh()->site_id);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($item->fresh()),
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $reseeds = Site::query()->where('domain', $item->domain)->get();
        $this->assertCount(1, $reseeds);
        $this->assertNotSame($firstSite->id, $reseeds->first()->id);
        $this->assertSame($reseeds->first()->id, $item->fresh()->site_id);
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertSame('Waiting on publisher', $bulk->fresh()->statusLabel());

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Waiting on publisher', false)
            ->assertSee('Complete details, then we approve', false)
            ->assertDontSee('our marketer adds DA/DR', false);
    }

    public function test_show_heals_requested_reject_all_leftover_with_only_archived_sites(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 0,
        ]);
        Site::query()->create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Ghost archived',
            'site_url' => 'https://ghost-arch.example',
            'domain' => 'ghost-arch.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Ghost archived leftover description. ', 2),
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->assertTrue($bulk->needsProgressHeal());

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk();

        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
    }

    public function test_show_heals_awaiting_publisher_with_no_sites_and_pending_rows(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'heal-empty');
        $bulk->forceFill([
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'completed_at' => now(),
        ])->save();

        $this->assertSame(0, $bulk->sites()->count());
        $this->assertTrue($bulk->hasPendingItems());

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Waiting on marketer', false);

        $fresh = $bulk->fresh();
        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $fresh->status);
        $this->assertNull($fresh->completed_at);
        $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
    }

    public function test_deleting_one_of_two_drafts_keeps_waiting_on_publisher(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(2, 'keep-one');

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]) + $this->completeRow($items[1]),
            ])
            ->assertRedirect();

        $keep = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $drop = Site::query()->where('domain', $items[1]->domain)->firstOrFail();
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $drop->id), [
                'reason' => 'Only this seeded domain was wrong.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertNotQueued(SiteStatusNotification::class);

        $this->assertDatabaseHas('sites', ['id' => $keep->id]);
        $this->assertDatabaseMissing('sites', ['id' => $drop->id]);
        $this->assertNotNull($items[0]->fresh()->site_id);
        $this->assertNull($items[1]->fresh()->site_id);

        $fresh = $bulk->fresh();
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $fresh->status);
        $this->assertSame('Waiting on publisher', $fresh->statusLabel());
        $this->assertTrue($fresh->canAddDraftSites());
    }

    public function test_cancel_requires_reason_and_removes_drafts(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'cancel-draft');

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.cancel', $bulk))
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => 'Duplicate of an earlier batch from this publisher.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.index'))
            ->assertSessionHas('success');

        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
        Mail::assertQueued(BulkSiteRequestCancelled::class, 1);
        Mail::assertNotQueued(SiteStatusNotification::class);

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $site->id), [
                'exampleUrl' => 'https://cancel-draft-1.example/example',
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Publisher listing description text. ', 4),
            ])
            ->assertNotFound();
    }

    public function test_show_heals_sheet_sent_with_pending_rows_and_no_sites(): void
    {
        [$bulk] = $this->makeBulkWithItems(1, 'heal-sheet');
        $bulk->forceFill([
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'sheet_sent_at' => now(),
        ])->save();

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Waiting on marketer', false)
            ->assertDontSee('Sheet emailed', false);

        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $bulk->fresh()->status);
    }

    public function test_completed_batch_with_pending_rows_blocks_second_publisher_bulk(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'stuck-done');
        $bulk->forceFill([
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
        $this->assertTrue(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($items[0]->domain, $html);
        $this->assertStringContainsString('data-open-bulk="1"', $html);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://second-a.example', 'price' => 40],
                    ['url' => 'https://second-b.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas('error', 'You already have an open bulk request. Wait for our team to finish it, or message support.');
    }

    public function test_second_bulk_tells_publisher_to_finish_complete_details(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'owe-details');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://owe-a.example', 'price' => 40],
                    ['url' => 'https://owe-b.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas(
                'error',
                'Finish your pending sites under Complete details before submitting another bulk request.'
            );
    }

    public function test_cancel_archives_live_bulk_sites_and_clears_pending_rows(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(2, 'cancel-live');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $live = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $live->forceFill([
            'verified' => true,
            'verified_at' => now(),
            'active' => true,
            'onboarding_status' => null,
        ])->save();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => 'Publisher asked to stop this batch after one listing went live.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.index'))
            ->assertSessionHas('success', function ($message) {
                return is_string($message) && str_contains($message, '1 live listing was archived');
            });

        $freshLive = $live->fresh();
        $this->assertTrue($freshLive->isArchived());
        $this->assertFalse((bool) $freshLive->active);
        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
        $this->assertNull(Site::findOccupyingDomain($freshLive->domain));
        $this->assertSame(0, $bulk->items()->whereNull('site_id')->count());
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
        Mail::assertQueued(BulkSiteRequestCancelled::class, 1);
    }

    public function test_cancel_refuses_when_a_live_listing_has_open_orders(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'cancel-open');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $live = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $live->forceFill([
            'verified' => true,
            'verified_at' => now(),
            'active' => true,
            'onboarding_status' => null,
        ])->save();

        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-BULK-OPEN-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $live->id,
            'site_name' => $live->site_name,
            'site_url' => $live->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/draft-article',
            'anchor_text' => 'best seo tools',
            'target_url' => 'https://advertiser.example',
            'publisher_status' => 'pending',
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => 'Publisher asked to stop this batch after one listing went live.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('error', function ($message) use ($live) {
                return is_string($message)
                    && str_contains($message, 'Cannot cancel while these listings have open orders')
                    && str_contains($message, (string) $live->domain);
            });

        $this->assertFalse($live->fresh()->isArchived());
        $this->assertTrue((bool) $live->fresh()->active);
        $this->assertNotSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
        Mail::assertNotQueued(BulkSiteRequestCancelled::class);
    }

    public function test_cancel_refuses_when_a_live_listing_has_open_disputes(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'cancel-dispute');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $live = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $live->forceFill([
            'verified' => true,
            'verified_at' => now(),
            'active' => true,
            'onboarding_status' => null,
        ])->save();

        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-BULK-DISPUTE-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $live->id,
            'site_name' => $live->site_name,
            'site_url' => $live->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/live-article',
            'anchor_text' => 'best seo tools',
            'target_url' => 'https://advertiser.example',
            'publisher_status' => 'completed',
        ]);

        OrderItemDispute::ensureTable();
        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $this->advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live link was removed after approval.',
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => 'Publisher asked to stop this batch after one listing went live.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('error', function ($message) use ($live) {
                return is_string($message)
                    && str_contains($message, 'Cannot cancel while these listings have open disputes')
                    && str_contains($message, (string) $live->domain);
            });

        $this->assertFalse($live->fresh()->isArchived());
        $this->assertTrue((bool) $live->fresh()->active);
        $this->assertNotSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
        Mail::assertNotQueued(BulkSiteRequestCancelled::class);
    }

    public function test_publisher_can_relist_domain_after_cancelled_bulk_leftover(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'relist-live');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $leftover = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $leftover->forceFill([
            'verified' => true,
            'verified_at' => now(),
            'active' => true,
            'onboarding_status' => null,
        ])->save();

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => 'Publisher asked to withdraw this live listing and start over.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.index'));

        $this->assertTrue($leftover->fresh()->isArchived());
        $this->assertNull(Site::findOccupyingDomain($leftover->domain));

        $category = Category::query()->firstOrFail();
        [$country, $language] = $this->marketplaceCodes();

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Relisted After Cancel',
                'siteUrl' => 'https://'.$items[0]->domain,
                'exampleUrl' => 'https://'.$items[0]->domain.'/post',
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => $country,
                'language' => $language,
                'categories' => [$category->name],
                'price' => 50,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Relist after cancelled bulk leftover. ', 4),
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sites', ['id' => $leftover->id]);
        $this->assertDatabaseHas('sites', [
            'domain' => $items[0]->domain,
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Relisted After Cancel',
        ]);
        $this->assertNotNull(Site::findOccupyingDomain($items[0]->domain));
    }

    public function test_done_reports_archived_domain_instead_of_generic_duplicate(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 're-done-arch');
        $archived = Site::query()->create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Archived Twin',
            'site_url' => 'https://'.$items[0]->domain,
            'domain' => $items[0]->domain,
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Archived listing description. ', 3),
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('seed_failures', function ($failures) {
                return is_array($failures)
                    && collect($failures)->contains(function ($row) {
                        $errors = $row['errors'] ?? [];

                        return collect($errors)->contains(
                            fn ($error) => str_contains((string) $error, 'including archived')
                        );
                    });
            });

        $this->assertNull($items[0]->fresh()->site_id);
        $this->assertDatabaseMissing('sites', [
            'domain' => $items[0]->domain,
            'bulk_site_request_id' => $bulk->id,
        ]);
        $this->assertTrue($archived->fresh()->isArchived());
    }

    public function test_seed_rejects_domain_not_on_pending_list(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'seed-only');
        [$country, $language] = $this->marketplaceCodes();
        $keep = $items[0]->domain;

        $rows = implode("\n", [
            "https://{$keep},80,30,35,5000,{$language},{$country},Keep Pending",
            "https://off-list.example,90,40,45,8000,{$language},{$country},Off List",
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), ['rows' => $rows])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('seed_failures', function ($failures) {
                return is_array($failures)
                    && collect($failures)->contains(fn ($row) => str_contains((string) ($row['url'] ?? ''), 'off-list.example'));
            });

        $this->assertDatabaseHas('sites', ['domain' => $keep, 'bulk_site_request_id' => $bulk->id]);
        $this->assertDatabaseMissing('sites', ['domain' => 'off-list.example']);
        $this->assertNotNull($items[0]->fresh()->site_id);
    }

    public function test_complete_details_hides_cancelled_bulk_leftover(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'cancel-left');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $bulk->forceFill(['status' => BulkSiteRequest::STATUS_CANCELLED])->save();

        $this->actingAs($this->publisher)
            ->get(route('publisher.bulk-sites.complete'))
            ->assertOk()
            ->assertDontSee($site->domain, false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertDontSee('Complete details (', false);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString($site->domain, $html);
    }

    public function test_cancelled_leftover_does_not_block_review_after_finishing_current_batch(): void
    {
        [$old, $oldItems] = $this->makeBulkWithItems(1, 'left-await');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $old), [
                'items' => $this->completeRow($oldItems[0]),
            ])
            ->assertRedirect();
        $old->forceFill(['status' => BulkSiteRequest::STATUS_CANCELLED])->save();

        [$bulk, $items] = $this->makeBulkWithItems(1, 'fresh-await');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $site->id), [
                'exampleUrl' => 'https://'.$items[0]->domain.'/example',
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Publisher listing description text. ', 4),
            ])
            ->assertRedirect(route('publisher.bulk-sites.review'))
            ->assertSessionHas('success');
    }

    public function test_publisher_cannot_unarchive_cancelled_bulk_leftover(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'unarch-live');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $live = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $live->forceFill([
            'verified' => true,
            'verified_at' => now(),
            'active' => true,
            'onboarding_status' => null,
        ])->save();

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => 'Publisher asked to stop this batch after one listing went live.',
            ])
            ->assertRedirect();

        $this->assertTrue($live->fresh()->isArchived());

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.unarchive', $live->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($live->fresh()->isArchived());
    }

    public function test_done_rejects_www_variant_of_an_existing_domain(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'www-collide');
        $this->existingListing('www.'.$items[0]->domain, [
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('seed_failures', function ($failures) use ($items) {
                return is_array($failures)
                    && collect($failures)->contains(function ($row) use ($items) {
                        $errors = $row['errors'] ?? [];

                        return collect($errors)->contains(
                            fn ($error) => str_contains((string) $error, 'Domain already registered: '.$items[0]->domain)
                        );
                    });
            });

        $this->assertNull($items[0]->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('domain', 'www.'.$items[0]->domain)->count());
        $this->assertDatabaseMissing('sites', [
            'domain' => $items[0]->domain,
            'bulk_site_request_id' => $bulk->id,
        ]);
    }

    public function test_publisher_bulk_rejects_www_variant_already_registered(): void
    {
        $this->existingListing('www.bulk-taken.example', [
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://bulk-taken.example', 'price' => 40],
                    ['url' => 'https://bulk-fresh.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('sites.0.url');

        $this->assertSame(0, BulkSiteRequest::query()->count());
    }

    public function test_legacy_archived_only_sheet_still_blocks_publisher(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 6,
            'sheet_sent_at' => now(),
        ]);
        $this->existingListing('legacy-arch-only.example', [
            'bulk_site_request_id' => $bulk->id,
            'archived_at' => now(),
        ]);

        $this->assertTrue($bulk->fresh()->canAddDraftSites());
        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
        $this->assertTrue(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://legacy-new-a.example', 'price' => 40],
                    ['url' => 'https://legacy-new-b.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas('error');
    }

    public function test_show_heals_awaiting_publisher_when_all_sites_are_archived(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'heal-arch');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $site->forceFill(['archived_at' => now(), 'active' => 0])->save();
        $bulk->forceFill(['status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER])->save();

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Completed — ready to verify', false);

        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertFalse(
            MarketingOpsQueues::bulkWaitingOnPublisher()->whereKey($bulk->id)->exists()
        );
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
    }

    public function test_active_tab_hides_cancelled_bulk_live_leftover(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'cancel-active');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $site->forceFill([
            'verified' => true,
            'verified_at' => now(),
            'active' => true,
            'onboarding_status' => null,
        ])->save();
        $bulk->forceFill(['status' => BulkSiteRequest::STATUS_CANCELLED])->save();

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($site->domain, $html);
        $this->assertStringContainsString('data-active="0"', $html);
    }

    public function test_all_tab_hides_cancelled_bulk_leftover(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'cancel-all');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $bulk->forceFill(['status' => BulkSiteRequest::STATUS_CANCELLED])->save();

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($site->domain, $html);
    }

    public function test_publisher_bulk_rejects_port_variant_in_same_list(): void
    {
        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://same-host.example', 'price' => 40],
                    ['url' => 'https://same-host.example:443', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('sites.1.url');

        $this->assertSame(0, BulkSiteRequest::query()->count());
    }

    public function test_done_folds_www_and_apex_pending_rows_onto_one_draft(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.twin-done.example',
            'domain' => 'www.twin-done.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://twin-done.example',
            'domain' => 'twin-done.example',
            'price' => 45,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($www),
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $siteId = (int) $www->fresh()->site_id;
        $this->assertGreaterThan(0, $siteId);
        $this->assertSame($siteId, (int) $apex->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame($siteId, (int) Site::query()->where('domain', 'twin-done.example')->value('id'));
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
        $this->assertFalse(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );
    }

    public function test_done_of_both_www_and_apex_blocks_in_one_request_creates_one_site(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.twin-both.example',
            'domain' => 'www.twin-both.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://twin-both.example',
            'domain' => 'twin-both.example',
            'price' => 45,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($www) + $this->completeRow($apex),
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success')
            ->assertSessionHas('seed_failures', fn ($failures) => $failures === [] || $failures === null);

        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame((int) $www->fresh()->site_id, (int) $apex->fresh()->site_id);
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
    }

    public function test_seed_folds_www_and_apex_pending_rows_onto_one_draft(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.twin-seed.example',
            'domain' => 'www.twin-seed.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://twin-seed.example',
            'domain' => 'twin-seed.example',
            'price' => 45,
        ]);
        [$country, $language] = $this->marketplaceCodes();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => "https://twin-seed.example,80,30,35,5000,{$language},{$country},Twin Seed",
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('seed_failures', fn ($failures) => $failures === [] || $failures === null);

        $siteId = (int) $www->fresh()->site_id;
        $this->assertGreaterThan(0, $siteId);
        $this->assertSame($siteId, (int) $apex->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
    }

    public function test_publisher_deleting_last_bulk_draft_heals_status_to_requested(): void
    {
        Mail::fake();
        [$bulk, $items] = $this->makeBulkWithItems(1, 'pub-del');

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertNotNull($items[0]->fresh()->site_id);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites', ['status' => 'pending']))
            ->delete(route('publisher.sites.destroy', $site->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertNull($items[0]->fresh()->site_id);
        $fresh = $bulk->fresh();
        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $fresh->status);
        $this->assertNull($fresh->completed_at);
        $this->assertTrue($fresh->canAddDraftSites());
    }

    public function test_publisher_bulk_rejects_domain_pending_on_another_open_bulk(): void
    {
        $otherRole = Role::where('name', 'publisher')->firstOrFail();
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $otherRole->id,
        ]);
        $other->roles()->attach($otherRole->id);

        $otherBulk = BulkSiteRequest::create([
            'publisher_id' => $other->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $otherBulk->id,
            'site_url' => 'https://taken-pending.example',
            'domain' => 'taken-pending.example',
            'price' => 40,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $otherBulk->id,
            'site_url' => 'https://other-pending.example',
            'domain' => 'other-pending.example',
            'price' => 50,
        ]);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://www.taken-pending.example', 'price' => 60],
                    ['url' => 'https://fresh-ok.example', 'price' => 70],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('sites.0.url');

        $this->assertStringContainsString(
            'Already in an open bulk request',
            (string) session('errors')->first('sites.0.url')
        );
        $this->assertSame(1, BulkSiteRequest::query()->count());
    }

    public function test_publisher_cannot_edit_cancelled_bulk_leftover(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'cancel-edit');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $site->forceFill([
            'verified' => true,
            'active' => true,
            'onboarding_status' => null,
        ])->save();
        $bulk->forceFill(['status' => BulkSiteRequest::STATUS_CANCELLED])->save();

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->put(route('publisher.sites.update', $site->id), [
                'exampleUrl' => $site->example_url ?: $site->site_url,
                'da' => $site->da,
                'dr' => $site->dr,
                'traffic' => $site->traffic,
                'country' => $site->country,
                'language' => $site->language,
                'categories' => $site->categories ?: [$site->category],
                'price' => 999,
                'turnaround_time' => $site->turnaround_time ?: '3days',
                'publicationTime' => $site->publication_time,
                'link_type' => $site->link_type,
                'siteDescription' => $site->description,
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(40 + 1, (float) $site->fresh()->price);
        $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.edit-data', $site->id))
            ->assertStatus(422);
    }

    public function test_done_plus_reject_of_www_apex_twin_deletes_the_rejected_row(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.twin-reject.example',
            'domain' => 'www.twin-reject.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://twin-reject.example',
            'domain' => 'twin-reject.example',
            'price' => 45,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($www),
                'rejected_item_ids' => [$apex->id],
                'rejection_note' => 'Drop the duplicate apex URL; keep www only.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $apex->id]);
        $this->assertNotNull($www->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
        Mail::assertQueued(BulkSiteItemsRejected::class);
        Mail::assertQueued(BulkSitesSeededNotification::class);
    }

    public function test_done_attaches_leftover_www_twin_to_existing_same_bulk_draft(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.leftover-twin.example',
            'domain' => 'www.leftover-twin.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://leftover-twin.example',
            'domain' => 'leftover-twin.example',
            'price' => 45,
        ]);
        $site = $this->existingListing('leftover-twin.example', [
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $apex->forceFill(['site_id' => $site->id])->save();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($www),
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success')
            ->assertSessionHas('seed_failures', fn ($failures) => $failures === [] || $failures === null);

        $this->assertSame($site->id, (int) $www->fresh()->site_id);
        $this->assertSame($site->id, (int) $apex->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
        Mail::assertNotQueued(BulkSitesSeededNotification::class);
    }

    public function test_show_heals_leftover_pending_twin_onto_existing_same_bulk_site(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.heal-twin.example',
            'domain' => 'www.heal-twin.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://heal-twin.example',
            'domain' => 'heal-twin.example',
            'price' => 45,
        ]);
        $site = $this->existingListing('heal-twin.example', [
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $apex->forceFill(['site_id' => $site->id])->save();

        $this->assertTrue($bulk->needsProgressHeal());

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk();

        $this->assertSame($site->id, (int) $www->fresh()->site_id);
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertFalse(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );
    }

    public function test_show_drops_pending_row_occupied_by_a_listing_that_left_the_batch(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.claimed-left.example',
            'domain' => 'www.claimed-left.example',
            'price' => 40,
        ]);
        $keep = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://keep-on-batch.example',
            'domain' => 'keep-on-batch.example',
            'price' => 45,
        ]);
        $this->existingListing('claimed-left.example', [
            'verified' => true,
            'active' => true,
        ]);
        $keepSite = $this->existingListing('keep-on-batch.example', [
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $keep->forceFill(['site_id' => $keepSite->id])->save();

        $this->assertTrue($bulk->needsProgressHeal());

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk();

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $www->id]);
        $this->assertSame($keepSite->id, (int) $keep->fresh()->site_id);
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertFalse(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );
    }

    public function test_seed_attaches_leftover_twin_instead_of_failing_already_registered(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.seed-left.example',
            'domain' => 'www.seed-left.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://seed-left.example',
            'domain' => 'seed-left.example',
            'price' => 45,
        ]);
        $site = $this->existingListing('seed-left.example', [
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $apex->forceFill(['site_id' => $site->id])->save();
        [$country, $language] = $this->marketplaceCodes();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => "https://seed-left.example,80,30,35,5000,{$language},{$country},Seed Left",
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('seed_failures', fn ($failures) => $failures === [] || $failures === null);

        $this->assertSame($site->id, (int) $www->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
    }

    public function test_complete_and_review_hide_archived_bulk_draft(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'arch-complete');
        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $this->completeRow($items[0]),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $items[0]->domain)->firstOrFail();
        $site->forceFill([
            'archived_at' => now(),
            'onboarding_status' => Site::ONBOARDING_DETAILS_COMPLETE,
        ])->save();

        $this->actingAs($this->publisher)
            ->get(route('publisher.bulk-sites.complete'))
            ->assertOk()
            ->assertDontSee($site->domain, false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.bulk-sites.review'))
            ->assertOk()
            ->assertDontSee($site->domain, false);

        $this->actingAs($this->publisher)
            ->from(route('publisher.bulk-sites.review'))
            ->post(route('publisher.bulk-sites.review.submit'), ['submit_all' => 1])
            ->assertRedirect(route('publisher.bulk-sites.review'))
            ->assertSessionHas('error');

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertDontSee('Complete details (', false);

        $this->assertSame(Site::ONBOARDING_DETAILS_COMPLETE, $site->fresh()->onboarding_status);
    }

    public function test_show_completes_requested_batch_whose_pending_domains_are_already_listed(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $first = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://taken-req-a.example',
            'domain' => 'taken-req-a.example',
            'price' => 40,
        ]);
        $second = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://taken-req-b.example',
            'domain' => 'taken-req-b.example',
            'price' => 50,
        ]);
        $this->existingListing('taken-req-a.example', ['verified' => true, 'active' => true]);
        $this->existingListing('taken-req-b.example', ['verified' => true, 'active' => true]);

        $this->assertTrue($bulk->needsProgressHeal());

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk();

        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $first->id]);
        $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $second->id]);
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://after-taken-a.example', 'price' => 40],
                    ['url' => 'https://after-taken-b.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites', ['status' => 'pending']))
            ->assertSessionHas('success')
            ->assertSessionMissing('error');
    }

    public function test_publisher_websites_heals_requested_occupied_leftover(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.sites-heal.example',
            'domain' => 'www.sites-heal.example',
            'price' => 40,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://sites-heal-b.example',
            'domain' => 'sites-heal-b.example',
            'price' => 50,
        ]);
        $this->existingListing('sites-heal.example', ['verified' => true, 'active' => true]);
        $this->existingListing('sites-heal-b.example', ['verified' => true, 'active' => true]);

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertDontSee('Bulk request #'.$bulk->id, false);

        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function existingListing(string $domain, array $overrides = []): Site
    {
        return Site::query()->create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Existing '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Existing listing description. ', 3),
            'verified' => false,
            'active' => false,
        ], $overrides));
    }
}
