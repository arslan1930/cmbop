<?php

namespace Tests\Feature;

use App\Mail\SiteStatusNotification;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSiteDestroyProtectsOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    private function site(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Order Site',
            'site_url' => 'https://order-site.example',
            'domain' => 'order-site.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Site used to assert delete cannot wipe orders.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    private function orderItemFor(Site $site, User $advertiser): OrderItem
    {
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

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/article.docx',
        ]);
    }

    public function test_deleting_a_site_with_orders_is_blocked_and_items_remain(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site($publisher);
        $item = $this->orderItemFor($site, $advertiser);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('order_count', 1);

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'active' => 1]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'site_id' => $site->id]);
        Mail::assertNotQueued(SiteStatusNotification::class);
    }

    public function test_eloquent_delete_does_not_cascade_order_items(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site($publisher);
        $item = $this->orderItemFor($site, $advertiser);

        try {
            $site->delete();
        } catch (\Throwable $e) {
            $this->assertTrue(
                str_contains(strtolower($e->getMessage()), 'foreign')
                || str_contains(strtolower($e->getMessage()), 'constraint')
                || str_contains(strtolower($e->getMessage()), 'restrict'),
                $e->getMessage()
            );
        }

        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'site_id' => $site->id]);
    }

    public function test_admin_archives_live_site_without_orders_instead_of_deleting(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, [
            'site_name' => 'Live No Orders',
            'site_url' => 'https://live-no-orders.example',
            'domain' => 'live-no-orders.example',
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => 'Publisher asked to take the listing down.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('archived', true);

        $fresh = $site->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame(0, (int) $fresh->active);
        $this->assertTrue((bool) $fresh->verified);

        Mail::assertQueued(SiteStatusNotification::class, function (SiteStatusNotification $mail) use ($publisher) {
            return $mail->hasTo($publisher->email) && $mail->action === 'archived';
        });

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'audience' => InAppNotification::AUDIENCE_PUBLISHER,
        ]);
    }

    public function test_pending_site_without_orders_is_still_hard_deleted(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, [
            'site_name' => 'Pending Draft',
            'site_url' => 'https://pending-draft.example',
            'domain' => 'pending-draft.example',
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id), [
                'reason' => 'Incomplete listing details from the publisher.',
            ])
            ->assertOk()
            ->assertJsonPath('archived', false);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_already_archived_site_is_not_deleted(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, [
            'site_name' => 'Already Archived',
            'site_url' => 'https://already-archived.example',
            'domain' => 'already-archived.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_staff_site_list_includes_orders_count_and_archived(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site($publisher);
        $this->orderItemFor($site, $advertiser);

        $this->actingAs($admin)
            ->getJson(route('admin.users.sites', $publisher->id))
            ->assertOk()
            ->assertJsonPath('sites.0.orders_count', 1)
            ->assertJsonPath('sites.0.archived', false);
    }

    public function test_staff_site_list_marks_archived_sites(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, [
            'site_name' => 'Archived List Site',
            'site_url' => 'https://archived-list.example',
            'domain' => 'archived-list.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.users.sites', $publisher->id))
            ->assertOk()
            ->assertJsonPath('sites.0.archived', true);
    }

    public function test_sites_management_ui_offers_archive_not_blind_delete(): void
    {
        $blade = file_get_contents(resource_path('views/admin/sites.blade.php'));

        $this->assertStringContainsString('canArchiveSiteRow', $blade);
        $this->assertStringContainsString('Has orders — deactivate instead', $blade);
        $this->assertStringContainsString('Archive this site?', $blade);
        $this->assertStringContainsString('body: JSON.stringify({ reason })', $blade);
        $this->assertStringContainsString('if (!res.ok || !data.success)', $blade);
        $this->assertStringContainsString('Please enter a reason (at least 10 characters).', $blade);
    }

    public function test_order_items_site_id_foreign_key_restricts_delete(): void
    {
        $keys = Schema::getForeignKeys('order_items');
        $siteKey = collect($keys)->first(function (array $key) {
            return in_array('site_id', $key['columns'] ?? [], true)
                && ($key['foreign_table'] ?? '') === 'sites';
        });

        $this->assertNotNull($siteKey, 'order_items.site_id must reference sites.id');
        $onDelete = strtolower((string) ($siteKey['on_delete'] ?? ''));
        $this->assertContains(
            $onDelete,
            ['restrict', 'no action'],
            'order_items.site_id must not cascade on delete (got '.$onDelete.')'
        );
    }
}
