<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSitesArchivedRowsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles']);
    }

    private function site(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Archived Row Site',
            'site_url' => 'https://archived-row.example',
            'domain' => 'archived-row.example',
            'da' => 12,
            'dr' => 8,
            'traffic' => 400,
            'country' => 'us',
            'countries' => ['us'],
            'language' => 'en',
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Archived listing used for staff row tests.',
            'verified' => true,
            'active' => false,
            'archived_at' => now()->subDays(3),
            'archive_reason' => 'below_quality',
            'status_reason' => 'Please keep **"Verify"** before sub'.html_entity_decode('&shy;').'mitting. This site is below the quality bar.',
        ], $overrides));
    }

    public function test_archived_list_summarizes_decision_and_hides_live_pills(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, [
            'site_name' => 'Quiet Archive Row',
            'created_at' => now()->subDays(30),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.index', ['all' => 1, 'archived' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Quiet Archive Row', $html);
        $this->assertStringContainsString('Archived', $html);
        $this->assertStringContainsString('Below quality bar', $html);
        $this->assertStringContainsString('Archived: below quality bar', $html);
        $this->assertStringContainsString('View message', $html);
        $this->assertStringContainsString('Please keep &quot;Verify&quot; before submitting.', $html);
        $this->assertStringNotContainsString('**"Verify"**', $html);
        $this->assertStringNotContainsString('sub-mitting', $html);
        $this->assertStringNotContainsString('badge rounded-pill bg-success">Verified', $html);
        $this->assertDoesNotMatchRegularExpression('/Quiet Archive Row[\s\S]{0,800}No cover/', $html);
        $this->assertStringContainsString('Restore archived site', $html);
        $this->assertStringContainsString('data-staff-bulk="restore"', $html);
        $this->assertStringContainsString('data-staff-bulk="purge"', $html);
        $this->assertStringContainsString('data-staff-compact-toggle', $html);
        $this->assertStringContainsString('staff-sites-table-scroll', $html);
    }

    public function test_archive_reason_filters_and_default_sort_uses_archived_at(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $older = $this->site($publisher, [
            'site_name' => 'Older Spam Archive',
            'site_url' => 'https://older-spam.example',
            'domain' => 'older-spam.example',
            'archive_reason' => 'spam',
            'archived_at' => now()->subDays(10),
            'status_reason' => null,
        ]);
        $newer = $this->site($publisher, [
            'site_name' => 'Newer Quality Archive',
            'site_url' => 'https://newer-quality.example',
            'domain' => 'newer-quality.example',
            'archive_reason' => 'below_quality',
            'archived_at' => now()->subDay(),
            'status_reason' => null,
        ]);

        $spam = $this->actingAs($admin)
            ->get(route('admin.sites.index', ['all' => 1, 'archive_reason' => 'spam']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Older Spam Archive', $spam);
        $this->assertStringNotContainsString('Newer Quality Archive', $spam);

        $sorted = $this->actingAs($admin)
            ->get(route('admin.sites.index', ['all' => 1, 'archived' => 1]))
            ->assertOk()
            ->getContent();
        $this->assertTrue(
            strpos($sorted, 'Newer Quality Archive') < strpos($sorted, 'Older Spam Archive'),
            'Archived view should list newest archived_at first.'
        );
        $this->assertNotNull($older->fresh()->archived_at);
        $this->assertNotNull($newer->fresh()->archived_at);
    }

    public function test_archive_persists_staff_reason_quietly(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, [
            'archived_at' => null,
            'archive_reason' => null,
            'status_reason' => null,
            'verified' => true,
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.archive', $site->id), [
                'archive_reason' => 'duplicate',
            ])
            ->assertOk()
            ->assertJsonPath('quiet', true);

        $site->refresh();
        $this->assertNotNull($site->archived_at);
        $this->assertSame('duplicate', $site->archive_reason);
        $this->assertNull($site->status_reason);
    }

    public function test_bulk_restore_and_purge_skips_rows_with_orders(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');

        $restore = $this->site($publisher, [
            'site_name' => 'Restore Me',
            'site_url' => 'https://restore-me.example',
            'domain' => 'restore-me.example',
            'status_reason' => null,
        ]);
        $purge = $this->site($publisher, [
            'site_name' => 'Purge Me',
            'site_url' => 'https://purge-me.example',
            'domain' => 'purge-me.example',
            'status_reason' => null,
        ]);
        $kept = $this->site($publisher, [
            'site_name' => 'Has Orders',
            'site_url' => 'https://has-orders-archive.example',
            'domain' => 'has-orders-archive.example',
            'status_reason' => null,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $kept->id,
            'site_name' => $kept->site_name,
            'site_url' => $kept->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/article.docx',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.bulk-action'), [
                'action' => 'restore',
                'ids' => [$restore->id],
            ])
            ->assertOk();
        $this->assertNull($restore->fresh()->archived_at);
        $this->assertNull($restore->fresh()->archive_reason);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.bulk-action'), [
                'action' => 'purge',
                'ids' => [$purge->id, $kept->id],
            ])
            ->assertOk()
            ->assertJsonPath('updated.0', $purge->id);

        $this->assertDatabaseMissing('sites', ['id' => $purge->id]);
        $this->assertDatabaseHas('sites', ['id' => $kept->id]);
    }

    public function test_regular_destroy_still_refuses_archived_sites(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, ['status_reason' => null]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }
}
