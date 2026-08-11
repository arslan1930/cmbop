<?php

namespace Tests\Feature;

use App\Mail\DepositMarkedPaid;
use App\Mail\DepositRequestSubmitted;
use App\Mail\OrderStatusChanged;
use App\Mail\SiteStatusNotification;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationEmailCtaFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketer = $this->userWithRole('marketing');
        $this->advertiser = $this->userWithRole('advertiser');
        $this->publisher = $this->userWithRole('publisher');

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'CTA Fix Site',
            'site_url' => 'https://cta-fix.example',
            'domain' => 'cta-fix.example',
            'da' => 25,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 90,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Notification CTA fixture site. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-CTA-FIX-1',
            'reference_code' => 'REF-CTA-FIX-1',
            'subtotal' => 90,
            'tax' => 0,
            'total_amount' => 90,
            'status' => 'processing',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $this->order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://cta-fix.example/article',
            'price' => 90,
            'additional_price' => 0,
            'status' => 'processing',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function pendingDeposit(): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $this->advertiser->id,
            'reference_code' => (string) random_int(100000, 999999),
            'amount' => 150,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);
    }

    public function test_deposit_admin_emails_link_to_approve_confirm_not_json_show(): void
    {
        $deposit = $this->pendingDeposit()->fresh('user');
        $listPath = parse_url(route('admin.deposits'), PHP_URL_PATH);
        $confirmPath = parse_url(
            route('admin.deposits.approve-confirm.show', $deposit->id),
            PHP_URL_PATH
        );

        $submitted = (new DepositRequestSubmitted($deposit))->render();
        $this->assertStringContainsString($confirmPath, $submitted);
        $this->assertStringContainsString($listPath, $submitted);
        $this->assertStringContainsString('Review &amp; approve', $submitted);
        $this->assertDepositIdLinksAreApproveConfirm($submitted, (int) $deposit->id);

        $marked = (new DepositMarkedPaid($deposit))->render();
        $this->assertStringContainsString($confirmPath, $marked);
        $this->assertStringContainsString($listPath, $marked);
        $this->assertStringContainsString('Approve &amp; credit wallet', $marked);
        $this->assertDepositIdLinksAreApproveConfirm($marked, (int) $deposit->id);
    }

    private function assertDepositIdLinksAreApproveConfirm(string $html, int $depositId): void
    {
        preg_match_all('#/admin/deposits/'.$depositId.'(/[a-z0-9\-]*)?#', $html, $matches);

        $this->assertNotEmpty($matches[0], 'Expected at least one deposit deep link.');
        foreach ($matches[0] as $path) {
            $this->assertStringContainsString(
                '/approve-confirm',
                $path,
                "Deposit #{$depositId} link should be approve-confirm, got {$path}"
            );
        }
    }

    public function test_order_lifecycle_emails_skip_marketing(): void
    {
        Mail::fake();

        app(EmailNotificationService::class)->notifyOrderLifecycle(
            $this->order->fresh(['user', 'items.site.publisher']),
            'status',
            'pending',
            'processing',
        );

        Mail::assertQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($this->admin->email));
        Mail::assertQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($this->advertiser->email));
        Mail::assertQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($this->publisher->email));
        Mail::assertNotQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($this->marketer->email));
    }

    public function test_site_status_email_cta_goes_to_publisher_websites(): void
    {
        $html = (new SiteStatusNotification($this->site->fresh('publisher'), 'activated'))->render();
        $websitesPath = parse_url(route('publisher.websites'), PHP_URL_PATH);

        $this->assertStringContainsString($websitesPath, $html);
        $this->assertStringContainsString('View Your Sites', $html);
        $this->assertStringNotContainsString(url('/login'), $html);
    }
}
