<?php

namespace Tests\Feature;

use App\Mail\LiveUrlSubmitted;
use App\Mail\ManagedOrderLiveUrlMail;
use App\Mail\OrderPaymentConfirmed;
use App\Mail\OrderStatusChanged;
use App\Mail\PackProgressMail;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPack;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Support\LegalIdentity;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailNotificationAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->admin = $this->userWithRole('admin');
        $this->advertiser = $this->userWithRole('advertiser', ['name' => 'Arslan']);
        $this->publisher = $this->userWithRole('publisher', ['name' => 'josen J']);
    }

    private function userWithRole(string $roleName, array $attrs = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function site(string $name, string $domain): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 30,
            'dr' => 30,
            'traffic' => 2000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Mail audit fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-MAIL-1',
            'reference_code' => 'REF-MAIL-1',
            'subtotal' => 135.60,
            'tax' => 0,
            'total_amount' => 135.60,
            'status' => 'review',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ], $attrs));
    }

    public function test_review_ready_advertiser_mail_shows_site_and_live_url_without_party_or_payment_rows(): void
    {
        $site = $this->site('Inka Magazin', 'inkamagazin.example');
        $order = $this->order();
        $longUrl = 'https://inkamagazin.example/'.str_repeat('guest-post-slug-', 8).'article';
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 135.60,
            'status' => 'review',
            'live_url' => $longUrl,
        ]);

        $html = (new OrderStatusChanged(
            $order->fresh(['user', 'items.site.publisher']),
            $this->advertiser,
            'advertiser',
            'status',
            'processing',
            'review',
        ))->render();

        $this->assertStringContainsString('Ready for review', $html);
        $this->assertStringContainsString('Inka Magazin', $html);
        $this->assertStringContainsString('inkamagazin.example', $html);
        $this->assertStringContainsString($longUrl, $html);
        $this->assertStringContainsString('href="'.$longUrl.'"', $html);
        $this->assertStringContainsString('#ORD-MAIL-1', $html);
        $this->assertStringContainsString('In review', $html);
        $this->assertStringContainsString('View Order Details', $html);
        $this->assertStringContainsString('approve it or request corrections', $html);
        $this->assertTrue(
            str_contains($html, 'word-break:break-all') || str_contains($html, 'word-break: break-all'),
            'Long live URLs should wrap with word-break: break-all'
        );
        $this->assertMatchesRegularExpression('/\b(UTC|GMT)\b/', $html);
        $this->assertStringContainsString('Hello Arslan', $html);
        $this->assertStringNotContainsString('>Advertiser<', $html);
        $this->assertStringNotContainsString('josen J', $html);
        $this->assertStringNotContainsString('Payment Status', $html);
        $this->assertStringNotContainsString('Payment status', $html);
        $this->assertStringNotContainsString('€135.60', $html);
        $this->assertStringNotContainsString('reply to this email', $html);
        $this->assertStringNotContainsString('/advertiser/orders/approve', $html);
        $this->assertStringContainsString(LegalIdentity::footer(), $html);
        $this->assertStringNotContainsString('Partners with', $html);
    }

    public function test_publisher_and_admin_variants_omit_counterparty_names_and_advertiser_totals(): void
    {
        $site = $this->site('Inka Magazin', 'inkamagazin.example');
        $order = $this->order(['status' => 'processing']);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 135.60,
        ]);
        $fresh = $order->fresh(['user', 'items.site.publisher']);

        $publisherHtml = (new OrderStatusChanged($fresh, $this->publisher, 'publisher', 'status', 'pending', 'processing'))->render();
        $adminHtml = (new OrderStatusChanged($fresh, $this->admin, 'admin', 'status', 'pending', 'processing'))->render();

        foreach ([$publisherHtml, $adminHtml] as $html) {
            $this->assertStringContainsString('Inka Magazin', $html);
            $this->assertStringContainsString('#ORD-MAIL-1', $html);
            $this->assertStringNotContainsString('Arslan', $html);
            $this->assertStringNotContainsString('>Advertiser<', $html);
            $this->assertStringNotContainsString('>Publisher<', $html);
            $this->assertStringNotContainsString('€135.60', $html);
            $this->assertStringNotContainsString('javascript:', $html);
        }

        $this->assertStringContainsString('/publisher/tasks', $publisherHtml);
        $this->assertStringContainsString('/admin/orders/'.$order->id, $adminHtml);
    }

    public function test_missing_live_url_is_not_invented_or_called_live(): void
    {
        $site = $this->site('Pending Mag', 'pending-mag.example');
        $order = $this->order(['status' => 'review']);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 80,
            'live_url' => null,
        ]);

        $html = (new OrderStatusChanged(
            $order->fresh(['user', 'items.site']),
            $this->advertiser,
            'advertiser',
            'status',
            'processing',
            'review',
        ))->render();

        $this->assertStringContainsString('In review', $html);
        $this->assertStringContainsString('Not submitted yet', $html);
        $this->assertStringNotContainsString('https://pending-mag.example/invented', $html);
        $this->assertStringNotContainsString('The guest post is live and ready', $html);
    }

    public function test_multi_site_pairs_each_placement_with_its_own_live_url(): void
    {
        $first = $this->site('Site One', 'one.example');
        $second = $this->site('Site Two', 'two.example');
        $order = $this->order();
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $first->id,
            'site_name' => $first->site_name,
            'site_url' => $first->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 50,
            'live_url' => 'https://one.example/live-one',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $second->id,
            'site_name' => $second->site_name,
            'site_url' => $second->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 50,
            'live_url' => null,
        ]);

        $html = (new OrderStatusChanged(
            $order->fresh(['user', 'items.site']),
            $this->advertiser,
            'advertiser',
            'status',
            'processing',
            'review',
        ))->render();

        $this->assertStringContainsString('https://one.example/live-one', $html);
        $this->assertStringContainsString('Site Two', $html);
        $this->assertStringContainsString('Not submitted yet', $html);
        $this->assertStringNotContainsString('https://two.example/live-one', $html);
    }

    public function test_dedicated_live_url_mail_and_payment_receipt_keep_correct_scopes(): void
    {
        $site = $this->site('Inka Magazin', 'inkamagazin.example');
        $order = $this->order();
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 135.60,
            'live_url' => 'https://inkamagazin.example/post',
        ]);

        $reviewHtml = (new LiveUrlSubmitted($order->fresh(), $item, $site, $item->live_url))->render();
        $this->assertStringContainsString('https://inkamagazin.example/post', $reviewHtml);
        $this->assertStringContainsString('View Order Details', $reviewHtml);
        $this->assertStringNotContainsString('€135.60', $reviewHtml);
        $this->assertStringNotContainsString('josen J', $reviewHtml);

        $paymentHtml = (new OrderPaymentConfirmed($order->fresh(['user', 'items'])))->render();
        $this->assertStringContainsString('€135.60', $paymentHtml);
        $this->assertStringContainsString('Payment confirmed', $paymentHtml);
        $this->assertStringNotContainsString('🎉', $paymentHtml);
        $this->assertMatchesRegularExpression('/\b(UTC|GMT)\b/', $paymentHtml);
    }

    public function test_managed_order_lifecycle_never_emails_the_publisher(): void
    {
        Mail::fake();
        $site = $this->site('Managed Mag', 'managed-mag.example');
        $order = $this->order(['status' => 'processing']);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 115,
            'publisher_price' => 100,
            'fulfilment_source' => 'managed_by_slb',
        ]);

        app(EmailNotificationService::class)->notifyOrderLifecycle(
            $order->fresh(['user', 'items.site.publisher']),
            'status',
            'pending',
            'processing',
        );

        Mail::assertQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($this->advertiser->email));
        Mail::assertQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($this->admin->email));
        Mail::assertNotQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($this->publisher->email));

        $managedHtml = (new ManagedOrderLiveUrlMail(
            $order->fresh(['user', 'items.site']),
            $order->items->first(),
            'Your article is live. Open it from My Orders.',
        ))->render();
        $this->assertStringNotContainsString('josen J', $managedHtml);
        $this->assertStringNotContainsString('Email with publisher', $managedHtml);
    }

    public function test_pack_progress_lists_each_article_url_without_inventing_unpublished_links(): void
    {
        $site = $this->site('Pack Site', 'pack-site.example');
        $pack = OrderPack::create([
            'user_id' => $this->advertiser->id,
            'site_id' => $site->id,
            'reference_code' => 'PACK-1',
            'pack_size' => 2,
            'status' => OrderPack::STATUS_IN_PROGRESS,
            'published_count' => 1,
            'total_amount' => 200,
        ]);
        $first = $this->order(['order_number' => 'ORD-PACK-1', 'order_pack_id' => $pack->id, 'status' => 'review']);
        $second = $this->order(['order_number' => 'ORD-PACK-2', 'order_pack_id' => $pack->id, 'status' => 'processing']);
        OrderItem::create([
            'order_id' => $first->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 100,
            'live_url' => 'https://pack-site.example/article-1',
        ]);
        OrderItem::create([
            'order_id' => $second->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 100,
            'live_url' => null,
        ]);

        $html = (new PackProgressMail($pack->fresh(['site', 'orders.items.site']), $first, false))->render();
        $this->assertStringContainsString('https://pack-site.example/article-1', $html);
        $this->assertStringContainsString('Not published yet', $html);
        $this->assertStringNotContainsString('https://pack-site.example/article-2', $html);
    }

    public function test_invoice_payment_email_keeps_amount_and_legal_identity(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->advertiser->id,
            'invoice_number' => 'INV-MAIL-000001',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $this->advertiser->name,
            'customer_email' => $this->advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 135.60,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 135.60,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'order_number' => 'ORD-MAIL-1',
            'line_items' => [
                ['description' => 'Guest post / sponsored placement', 'quantity' => 1, 'unit_price' => 135.60, 'line_total' => 135.60],
            ],
            'billing_snapshot' => [],
        ]);

        $html = (new PaymentSuccessfulInvoiceMail($invoice->fresh(['user', 'order'])))->render();
        $this->assertStringContainsString('€135.60', $html);
        $this->assertStringContainsString(LegalIdentity::footer(), $html);
        $this->assertStringNotContainsString('Partners with', $html);
        $this->assertMatchesRegularExpression('/\b(UTC|GMT)\b/', $html);
    }
}
