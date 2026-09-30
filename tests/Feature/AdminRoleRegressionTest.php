<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BulkSiteRequest;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Models\Withdrawal;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admin-role regressions found by a full panel pass: validation 500s,
 * missing-row 500s, and community claims that 500 when the listing is gone.
 */
class AdminRoleRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    public function test_company_update_validation_is_422_not_500(): void
    {
        $admin = $this->userWithRole('admin');
        $user = $this->userWithRole('advertiser', ['company_name' => 'Old Co']);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateCompany', $user->id), [
                'company_name' => str_repeat('X', 256),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name']);

        $this->assertSame('Old Co', $user->fresh()->company_name);
    }

    public function test_company_update_for_missing_user_is_404_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateCompany', 999999), [
                'company_name' => 'Ghost Co',
            ])
            ->assertNotFound();
    }

    public function test_role_update_for_missing_user_is_404_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', 999999), [
                'marketing' => false,
            ])
            ->assertNotFound();
    }

    public function test_invalid_payment_status_is_422_not_500(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-BAD-STATUS',
            'reference_code' => 'REF-BAD-STATUS',
            'subtotal' => 20,
            'tax' => 0,
            'total_amount' => 20,
            'payment_method' => 'wallet',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'not-a-status',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_status']);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_payment_status_update_for_missing_order_is_404_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', 999999), [
                'payment_status' => 'paid',
            ])
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Payment not found');
    }

    public function test_community_claims_tab_survives_a_missing_listing(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('advertiser', [
            'name' => 'Orphan Claimer',
            'email' => 'orphan-claimer@example.com',
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ghost listing',
            'site_url' => 'https://ghost.example',
            'domain' => 'ghost.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 20,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Orphaned listing used to test claims',
            'verified' => true,
            'active' => true,
        ]);
        $claim = SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => 'Ghost listing',
            'website_url' => 'https://ghost.example',
            'domain' => 'ghost.example',
            'proof_message' => 'I still own this.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);
        $claim->setRelation('site', null);
        $claim->setRelation('claimer', $claimer);

        $this->actingAs($admin);
        $detail = view('admin.community.detail', [
            'tab' => 'claims',
            'item' => $claim,
            'ctx' => [
                'open_orders' => 0,
                'open_disputes' => 0,
                'verified' => false,
                'name_matches' => false,
                'claimer_has_publisher_role' => false,
            ],
            'siblings' => 0,
            'pageUrl' => null,
        ])->render();

        $this->assertStringContainsString('Ghost listing', $detail);
        $this->assertStringContainsString('Orphan Claimer', $detail);

        $rendered = Blade::render(
            '<a href="{{ route(\'admin.sites.edit\', $item->site_id) }}">{{ $item->site?->site_name ?? $item->website_name }}</a>'
            .'{{ $item->site?->publisher?->name ?? \'—\' }}',
            ['item' => $claim]
        );
        $this->assertStringContainsString('Ghost listing', $rendered);
        $this->assertStringContainsString('—', $rendered);
    }

    public function test_blogs_index_and_show_survive_a_null_created_at(): void
    {
        $admin = $this->userWithRole('admin');
        $blog = Blog::factory()->create([
            'title' => 'Null Created Blog',
            'status' => 'draft',
        ]);
        DB::table('blogs')->where('id', $blog->id)->update(['created_at' => null]);

        $this->actingAs($admin)
            ->get(route('admin.blogs.index', ['q' => 'Null Created Blog']))
            ->assertOk()
            ->assertSee('Null Created Blog');

        $this->actingAs($admin)
            ->get(route('admin.blogs.show', $blog))
            ->assertOk()
            ->assertSee('Null Created Blog');
    }

    public function test_site_active_toggle_for_missing_site_is_404_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', 999999), [
                'active' => 0,
                'reason' => 'Listing was removed from the catalog.',
            ])
            ->assertNotFound();
    }

    public function test_site_active_toggle_still_succeeds_when_activity_log_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Toggle After Log Drop',
            'site_url' => 'https://toggle-log.example',
            'domain' => 'toggle-log.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Used to prove activate still works without activity_logs.',
            'verified' => true,
            'active' => true,
        ]);

        Schema::dropIfExists('activity_logs');

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), [
                'active' => 0,
                'reason' => 'Pause this listing after a quality review.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_publisher_reminder_for_missing_item_is_404_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', 999999))
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Order item not found.');
    }

    public function test_deposit_approve_confirm_survives_a_missing_user(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-ORPHAN-USER',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);
        $deposit->setRelation('user', null);

        $this->actingAs($admin)->withViewErrors([]);

        $html = view('admin.deposits.approve-confirm', [
            'deposit' => $deposit,
            'canApprove' => true,
            'confirmAction' => 'https://example.test/confirm',
            'currentBalance' => 0.0,
            'incomingAmount' => 40.0,
            'projectedBalance' => 40.0,
            'priorDeposits' => collect(),
            'bonusBalance' => 0.0,
            'possibleDuplicate' => false,
            'duplicateMatches' => collect(),
        ])->render();

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('DEP-ORPHAN-USER', $html);
    }

    public function test_withdrawal_mark_paid_confirm_survives_a_missing_user(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 80,
            'fee' => 0,
            'net_amount' => 80,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ]);
        $withdrawal->setRelation('user', null);

        $this->actingAs($admin)->withViewErrors([]);

        $html = view('admin.withdrawals.mark-paid-confirm', [
            'withdrawal' => $withdrawal,
            'canMarkPaid' => true,
            'confirmAction' => 'https://example.test/confirm',
            'currentBalance' => 0.0,
            'priorPaid' => collect(),
            'possibleDuplicate' => false,
            'duplicateMatches' => collect(),
        ])->render();

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('WD-'.$withdrawal->id, $html);
    }

    public function test_bulk_request_show_survives_missing_activity_logs(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);

        Schema::dropIfExists('activity_logs');
        $this->assertFalse(Schema::hasTable('activity_logs'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertDontSee('Something went wrong');
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_15_150505_create_activity_logs_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_bulk_requests_index_survives_an_unhandled_request(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
            'publisher_note' => 'Please add these',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.bulk-site-requests.index'))
            ->assertOk()
            ->assertSee((string) $publisher->name)
            ->assertSee('Unclaimed', false);
    }

    public function test_bulk_requests_index_survives_missing_onboarding_status(): void
    {
        if (! Schema::hasColumn('sites', 'onboarding_status')) {
            $this->markTestSkipped('sites.onboarding_status is already absent');
        }

        try {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('onboarding_status');
            });
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop sites.onboarding_status on this driver');
        }

        if (Schema::hasColumn('sites', 'onboarding_status')) {
            $this->markTestSkipped('sites.onboarding_status is still present after drop');
        }

        try {
            $admin = $this->userWithRole('admin');
            $publisher = $this->userWithRole('publisher');
            BulkSiteRequest::create([
                'publisher_id' => $publisher->id,
                'status' => BulkSiteRequest::STATUS_REQUESTED,
                'estimated_count' => 2,
            ]);

            $this->actingAs($admin)
                ->get(route('admin.bulk-site-requests.index'))
                ->assertOk()
                ->assertDontSee('Something went wrong');
        } finally {
            if (! Schema::hasColumn('sites', 'onboarding_status')) {
                Schema::table('sites', function (Blueprint $table) {
                    $table->string('onboarding_status', 32)->nullable();
                });
            }
        }
    }

    public function test_order_show_survives_a_missing_publisher_and_chat_user(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Orphan Publisher Order',
            'site_url' => 'https://orphan-pub.example',
            'domain' => 'orphan-pub.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Order show missing publisher fixture',
            'verified' => true,
            'active' => true,
        ]);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-ORPHAN-PUB',
            'reference_code' => 'REF-ORPHAN-PUB',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'publisher_price' => 35,
            'content_link' => 'https://example.com/article.docx',
        ]);
        $site->setRelation('publisher', null);
        $item->setRelation('site', $site);
        $order->setRelation('user', $advertiser);
        $order->setRelation('items', collect([$item]));
        $order->setRelation('invoices', collect());

        $message = new OrderChatMessage([
            'order_id' => $order->id,
            'sender_type' => 'advertiser',
            'message' => 'Still waiting on the live URL.',
        ]);
        $message->setRelation('user', null);
        $message->created_at = now();

        $this->actingAs($admin)->withViewErrors([]);

        $html = view('admin.orders.show', [
            'order' => $order,
            'activities' => collect(),
            'messages' => collect([$message]),
            'disputes' => collect(),
            'openDispute' => null,
            'disputableItems' => collect(),
            'canOpenDispute' => false,
            'statusTargets' => [],
            'canOverrideStatus' => false,
        ])->render();

        $this->assertStringContainsString('ORD-ORPHAN-PUB', $html);
        $this->assertStringContainsString('Advertiser', $html);
        $this->assertStringContainsString('Still waiting on the live URL.', $html);
    }
}
