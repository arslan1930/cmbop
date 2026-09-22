<?php

namespace Tests\Feature;

use App\Mail\PublisherListingNudge;
use App\Mail\SiteStatusNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSitesArchiveBulkNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->admin = $this->userWithRole('admin');
        $this->marketer = $this->userWithRole('marketing');
        $this->publisher = $this->userWithRole('publisher', [
            'name' => 'Archive Bulk Publisher',
            'email' => 'archive-bulk-pub@example.test',
        ]);
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

    private function makeSite(array $overrides = []): Site
    {
        $domain = $overrides['domain'] ?? 'archive-bulk-'.uniqid('', true).'.example';

        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Archive Bulk Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'description' => 'Archive bulk notes fixture listing for staff queues.',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_archived_queue_lists_archived_rows_in_place(): void
    {
        $live = $this->makeSite([
            'site_name' => 'Live Stay Visible Site',
            'domain' => 'live-stay.example',
            'site_url' => 'https://live-stay.example',
        ]);
        $archived = $this->makeSite([
            'site_name' => 'Shelved Archive Site',
            'domain' => 'shelved-archive.example',
            'site_url' => 'https://shelved-archive.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee('Archived listings', false)
            ->assertSee('Shelved Archive Site', false)
            ->assertDontSee('Live Stay Visible Site', false)
            ->assertSee('Restore', false)
            ->assertSee('js-site-bulk-action', false)
            ->assertSee('id="usersSection" class="d-none"', false);
    }

    public function test_admin_restores_archived_site_and_marketer_cannot(): void
    {
        Mail::fake();
        $site = $this->makeSite([
            'site_name' => 'Restore Me Site',
            'domain' => 'restore-me.example',
            'site_url' => 'https://restore-me.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.restore', $site->id))
            ->assertForbidden();
        $this->assertNotNull($site->fresh()->archived_at);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.restore', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('archived', false);

        $fresh = $site->fresh();
        $this->assertNull($fresh->archived_at);
        $this->assertFalse((bool) $fresh->active);
        Mail::assertQueued(SiteStatusNotification::class, fn ($mail) => $mail->action === 'restored');
    }

    public function test_restore_blocked_when_live_duplicate_occupies_domain(): void
    {
        $this->makeSite([
            'site_name' => 'Live Occupier',
            'domain' => 'shared-restore.example',
            'site_url' => 'https://shared-restore.example',
        ]);
        $otherPub = $this->userWithRole('publisher', [
            'email' => 'shared-restore-other@example.test',
        ]);
        $archived = $this->makeSite([
            'publisher_id' => $otherPub->id,
            'site_name' => 'Archived Twin',
            'domain' => 'shared-restore.example',
            'site_url' => 'https://shared-restore.example/old',
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.restore', $archived->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNotNull($archived->fresh()->archived_at);
    }

    public function test_user_sites_archived_filter_and_duplicate_flag(): void
    {
        $live = $this->makeSite([
            'site_name' => 'Dup Live Site',
            'domain' => 'dup-flag.example',
            'site_url' => 'https://dup-flag.example',
        ]);
        $otherPub = $this->userWithRole('publisher', [
            'email' => 'dup-other@example.test',
        ]);
        $twin = $this->makeSite([
            'publisher_id' => $otherPub->id,
            'site_name' => 'Dup Twin Site',
            'domain' => 'dup-flag.example',
            'site_url' => 'https://dup-flag.example/b',
        ]);
        $archived = $this->makeSite([
            'site_name' => 'Hidden Archived',
            'domain' => 'hidden-arch.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $livePayload = $this->actingAs($this->admin)
            ->getJson(route('admin.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('meta.archived', false)
            ->json('sites');
        $liveIds = collect($livePayload)->pluck('id')->all();
        $this->assertContains($live->id, $liveIds);
        $this->assertNotContains($archived->id, $liveIds);

        $liveRow = collect($livePayload)->firstWhere('id', $live->id);
        $this->assertNotNull($liveRow['duplicate'] ?? null);
        $this->assertSame($twin->id, $liveRow['duplicate']['id']);

        $archivedPayload = $this->actingAs($this->admin)
            ->getJson(route('admin.users.sites', ['id' => $this->publisher->id, 'archived' => 1]))
            ->assertOk()
            ->json('sites');
        $archivedIds = collect($archivedPayload)->pluck('id')->all();
        $this->assertContains($archived->id, $archivedIds);
        $this->assertNotContains($live->id, $archivedIds);
    }

    public function test_note_and_nudge_and_bulk_actions(): void
    {
        Mail::fake();
        $waiting = $this->makeSite([
            'site_name' => 'Waiting Nudge Site',
            'domain' => 'waiting-nudge.example',
            'site_url' => 'https://waiting-nudge.example',
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $liveA = $this->makeSite([
            'site_name' => 'Bulk Live A',
            'domain' => 'bulk-live-a.example',
            'site_url' => 'https://bulk-live-a.example',
        ]);
        $liveB = $this->makeSite([
            'site_name' => 'Bulk Live B',
            'domain' => 'bulk-live-b.example',
            'site_url' => 'https://bulk-live-b.example',
        ]);
        $pending = $this->makeSite([
            'site_name' => 'Pending Not Bulk Archive',
            'domain' => 'pending-not-archive.example',
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.notes.store', $waiting->id), [
                'body' => 'Publisher promised metrics Friday.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('site_admin_notes', [
            'site_id' => $waiting->id,
            'admin_id' => $this->admin->id,
            'body' => 'Publisher promised metrics Friday.',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.nudge', $waiting->id))
            ->assertOk()
            ->assertJsonPath('success', true);
        Mail::assertQueued(PublisherListingNudge::class);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk'), [
                'ids' => [$liveA->id, $liveB->id, $pending->id],
                'action' => 'archive',
                'reason' => 'Quality review — hiding these live listings from the catalog.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($liveA->fresh()->archived_at);
        $this->assertNotNull($liveB->fresh()->archived_at);
        $this->assertNull($pending->fresh()->archived_at);
        $this->assertNotNull($pending->fresh());

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk'), [
                'ids' => [$liveA->id, $liveB->id],
                'action' => 'restore',
            ])
            ->assertOk();
        $this->assertNull($liveA->fresh()->archived_at);
        $this->assertNull($liveB->fresh()->archived_at);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.bulk'), [
                'ids' => [$liveA->id],
                'action' => 'deactivate',
                'reason' => 'Paused while we check traffic quality numbers.',
            ])
            ->assertOk();
        $this->assertFalse((bool) $liveA->fresh()->active);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('archived', false)
            ->assertSee('js-site-note', false)
            ->assertSee('js-site-nudge', false);
    }

    public function test_archived_search_finds_archived_only_with_filter(): void
    {
        $this->makeSite([
            'site_name' => 'Only In Archive',
            'domain' => 'only-in-archive.example',
            'site_url' => 'https://only-in-archive.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['q' => 'only-in-archive.example']))
            ->assertOk()
            ->assertDontSee('Only In Archive', false);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['archived' => 1, 'q' => 'only-in-archive.example']))
            ->assertRedirect();
    }
}
