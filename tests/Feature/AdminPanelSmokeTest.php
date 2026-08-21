<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\AdBanner;
use App\Models\Blog;
use App\Models\BulkSiteRequest;
use App\Models\ContentModerationLog;
use App\Models\DepositRequest;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteAnnouncement;
use App\Models\SiteClaim;
use App\Models\SiteRating;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSuggestion;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Services\Wallet\ManualWithdrawalMarkPaidLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Whole-admin-panel smoke: every GET screen and JSON feed an admin can open
 * from the sidebar or a typical deep-link must return 200 (or a documented
 * redirect) instead of a 500.
 */
class AdminPanelSmokeTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

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

    private function relativeSignedUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Smoke Test Site',
            'site_url' => 'https://smoke-test.example',
            'domain' => 'smoke-test.example',
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
            'description' => 'Smoke test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * @return list<array{0: string, 1?: array<string, mixed>}>
     */
    private function indexRoutes(): array
    {
        return [
            ['admin.dashboard'],
            ['admin.orders.index'],
            ['admin.sites.index'],
            ['admin.sites.create'],
            ['admin.sites.records'],
            ['admin.bulk-site-requests.index'],
            ['admin.site-enrichment.index'],
            ['admin.staff-handbook'],
            ['admin.site-ratings.index'],
            ['admin.finance'],
            ['admin.finance.ledger'],
            ['admin.payments'],
            ['admin.invoices.index'],
            ['admin.deposits'],
            ['admin.withdrawals'],
            ['admin.users.index'],
            ['admin.community.index'],
            ['admin.blogs.index'],
            ['admin.blogs.create'],
            ['admin.emails.index'],
            ['admin.campaigns.index'],
            ['admin.campaigns.drafts'],
            ['admin.campaigns.index', ['tab' => 'sending']],
            ['admin.campaigns.index', ['tab' => 'sent']],
            ['admin.audiences.index'],
            ['admin.promotions.index'],
            ['admin.promotions.preview'],
            ['admin.promotions.announcements.index'],
            ['admin.promotions.announcements.create'],
            ['admin.promotions.banners.index'],
            ['admin.promotions.banners.create'],
            ['admin.moderation.index'],
            ['admin.content-library.index'],
            ['admin.activity-logs.index'],
            ['admin.catalog-activity'],
            ['admin.dashboard.stalled-orders'],
        ];
    }

    public function test_guest_is_sent_to_login_from_admin_dashboard(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_advertiser_cannot_open_admin_dashboard(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_marketing_is_redirected_away_from_admin_dashboard(): void
    {
        $marketing = $this->userWithRole('marketing');

        $this->actingAs($marketing)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_every_admin_index_and_form_page_loads_empty(): void
    {
        $admin = $this->userWithRole('admin');

        foreach ($this->indexRoutes() as $entry) {
            $name = $entry[0];
            $params = $entry[1] ?? [];

            $this->actingAs($admin)
                ->get(route($name, $params))
                ->assertOk("Expected {$name} to return 200");
        }
    }

    public function test_admin_sidebar_nav_links_all_resolve(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Admin Dashboard', $html);
        $this->assertStringContainsString(route('admin.orders.index'), $html);
        $this->assertStringContainsString(route('admin.sites.index'), $html);
        $this->assertStringContainsString(route('admin.finance'), $html);
        $this->assertStringContainsString(route('admin.users.index'), $html);
        $this->assertStringContainsString(route('admin.emails.index'), $html);
        $this->assertStringContainsString(route('admin.catalog-activity'), $html);
    }

    public function test_dashboard_json_feeds_succeed(): void
    {
        $admin = $this->userWithRole('admin');

        foreach ([
            'admin.dashboard.statistics',
            'admin.dashboard.trends',
            'admin.dashboard.distributions',
            'admin.dashboard.action-queue',
            'admin.dashboard.finance',
            'admin.dashboard.queue-counts',
        ] as $name) {
            $this->actingAs($admin)
                ->getJson(route($name))
                ->assertOk()
                ->assertJson(['success' => true]);
        }
    }

    public function test_list_json_feeds_succeed(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data'))
            ->assertOk();

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data'))
            ->assertOk();

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics'))
            ->assertOk();
    }

    public function test_common_filter_query_strings_do_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        $urls = [
            route('admin.orders.index', ['status' => 'processing', 'dispute' => 'open']),
            route('admin.sites.index', ['needs_review' => 1, 'q' => 'example']),
            route('admin.sites.records', ['country' => 'us', 'q' => 'smoke']),
            route('admin.finance', ['period' => 'month']),
            route('admin.finance.ledger', ['type' => 'deposit', 'search' => 'smoke']),
            route('admin.payments', ['payment_status' => 'unpaid']),
            route('admin.invoices.index', ['status' => 'paid', 'type' => 'tax_invoice', 'search' => 'INV']),
            route('admin.deposits', ['status' => 'pending']),
            route('admin.withdrawals', ['queue' => 'open']),
            route('admin.users.index', ['user' => 1]),
            route('admin.community.index', ['status' => 'pending']),
            route('admin.blogs.index', ['status' => 'published', 'q' => 'seo']),
            route('admin.emails.index', ['status' => 'delivered']),
            route('admin.audiences.index', ['tab' => 'advertisers', 'verified' => 'yes']),
            route('admin.promotions.announcements.index', ['status' => 'live']),
            route('admin.moderation.index', ['status' => 'rejected']),
            route('admin.content-library.index', ['availability' => 'available']),
            route('admin.activity-logs.index', ['role' => 'admin', 'q' => 'login']),
            route('admin.catalog-activity', ['days' => 14, 'copy' => 'all']),
            route('admin.site-ratings.index', ['status' => 'approved', 'q' => 'great']),
            route('admin.site-enrichment.index', ['status' => 'failed']),
            route('admin.bulk-site-requests.index', ['status' => 'requested']),
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($admin)->get($url);
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Expected {$url} to return 200, got {$response->getStatusCode()}"
            );
        }
    }

    public function test_detail_pages_and_edit_forms_load_with_fixtures(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', [
            'name' => 'Smoke Advertiser',
            'email' => 'smoke-adv@example.com',
        ]);
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Smoke Publisher',
            'email' => 'smoke-pub@example.com',
        ]);

        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 100,
            'bonus_balance' => 20,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $site = $this->siteFor($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-SMOKE-1',
            'reference_code' => 'REF-SMOKE-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
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
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
            'modification_requested' => 'no',
        ]);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-SMOKE-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'wallet_id' => $advWallet->id,
            'amount' => 25,
            'fee' => 1,
            'net_amount' => 24,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'smoke-pub@example.com'],
            'status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-SMOKE-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $advertiser->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'subtotal' => 50,
            'total_amount' => 50,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Guest post', 'line_total' => 50]],
            'pdf_disk' => 'local',
        ]);

        $blog = Blog::factory()->published()->create([
            'title' => 'Smoke Blog',
            'slug' => 'smoke-blog',
            'created_by' => $admin->id,
        ]);

        $announcement = SiteAnnouncement::create([
            'title' => 'Smoke notice',
            'message' => 'Hello advertisers',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'priority' => 10,
        ]);

        $banner = AdBanner::create([
            'name' => 'Smoke banner',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'image_url' => 'https://example.com/b.png',
            'link_url' => 'https://example.com/offer',
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
        ]);

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
            'publisher_note' => 'Please add these',
        ]);

        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $modLog = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'passed' => true,
            'scan_token' => 'scan-smoke',
            'word_count' => 20,
        ]);

        $emailLog = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => $advertiser->email,
            'to_name' => $advertiser->name,
            'subject' => 'Welcome',
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now(),
        ]);

        SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'rating' => 5,
            'comment' => 'Great placement',
            'status' => SiteRating::STATUS_APPROVED,
        ]);

        ProblemReport::create([
            'user_id' => $advertiser->id,
            'name' => $advertiser->name,
            'email' => $advertiser->email,
            'subject' => 'Broken filter',
            'message' => 'The catalog filter reset itself.',
            'status' => 'pending',
        ]);
        Suggestion::create([
            'user_id' => $advertiser->id,
            'name' => $advertiser->name,
            'email' => $advertiser->email,
            'category' => 'catalog',
            'message' => 'Add a DA slider.',
            'status' => 'pending',
        ]);
        WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Suggested Site',
            'website_url' => 'https://suggested.example',
            'domain' => 'suggested.example',
            'country' => 'us',
            'language' => 'en',
            'status' => 'pending',
        ]);
        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $advertiser->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'proof_message' => 'I own this.',
            'contact_email' => $advertiser->email,
            'status' => 'pending',
        ]);

        $pages = [
            ['admin.orders.show', $order->id],
            ['admin.sites.edit', $site->id],
            ['admin.users.sites', $publisher->id],
            ['admin.bulk-site-requests.show', $bulk->id],
            ['admin.payments.show', $order->id],
            ['admin.invoices.show', $invoice],
            ['admin.deposits.show', $deposit->id],
            ['admin.withdrawals.show', $withdrawal->id],
            ['admin.finance.user', $advertiser],
            ['admin.blogs.show', $blog],
            ['admin.blogs.edit', $blog],
            ['admin.emails.log', $emailLog],
            ['admin.emails.preview', 'welcome'],
            ['admin.promotions.announcements.edit', $announcement],
            ['admin.promotions.banners.edit', $banner],
            ['admin.moderation.show', $modLog],
            ['admin.content-library.show', $submission],
            ['admin.catalog-activity.show', $advertiser],
        ];

        foreach ($pages as [$name, $param]) {
            $response = $this->actingAs($admin)->get(route($name, $param));
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Expected {$name} to return 200, got {$response->getStatusCode()}"
            );
        }

        $this->actingAs($admin)
            ->get($this->relativeSignedUrl(ManualDepositApproveLink::url($deposit)))
            ->assertOk();

        $this->actingAs($admin)
            ->get($this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal)))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.deposits.approve-confirm.show', $deposit))
            ->assertRedirect(route('admin.deposits'));

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.mark-paid-confirm.show', $withdrawal))
            ->assertRedirect(route('admin.withdrawals'));

        $this->actingAs($admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Smoke Test Site');

        $this->actingAs($admin)
            ->get(route('admin.users.sites', $publisher->id))
            ->assertOk()
            ->assertSee('Smoke Test Site');

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->assertSee('Broken filter');

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'suggestions']))
            ->assertOk()
            ->assertSee('Add a DA slider');

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims']))
            ->assertOk()
            ->assertSee('Smoke Test Site');
    }

    public function test_export_endpoints_do_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        foreach ([
            'admin.sites.records.export',
            'admin.activity-logs.export',
            'admin.payments.export',
            'admin.finance.export',
            'admin.finance.ledger.export',
            'admin.withdrawals.export',
            'admin.audiences.export',
        ] as $name) {
            $response = $this->actingAs($admin)->get(route($name));
            $status = $response->getStatusCode();
            $this->assertTrue(
                $status >= 200 && $status < 400,
                "Expected {$name} to succeed or redirect, got {$status}"
            );
        }
    }
}
