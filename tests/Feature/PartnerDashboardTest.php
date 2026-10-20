<?php

namespace Tests\Feature;

use App\Mail\ModificationRequested;
use App\Mail\PartnerCorrectionMail;
use App\Mail\PartnerEmailChangeCancelMail;
use App\Mail\PartnerEmailChangeConfirmMail;
use App\Mail\PartnerNewOrderMail;
use App\Mail\PartnerPayoutReleasedMail;
use App\Mail\PublisherStaffOnboardingMail;
use App\Mail\SiteOwnerOrderNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\LiveUrlHealthChecker;
use App\Services\Partners\PartnerAccountService;
use App\Services\Partners\PartnerAlertService;
use App\Services\Partners\PartnerConfidentiality;
use App\Services\Partners\PartnerEmailActionService;
use App\Support\PartnerDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partnerUser;

    private Partner $partner;

    private User $advertiser;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $this->partnerUser = User::factory()->create([
            'name' => 'PlaCla Test',
            'email' => 'partner-dash@example.com',
            'password' => 'secret-pass-12',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->partnerUser->roles()->attach($publisherRole->id);
        $this->partner = app(PartnerAccountService::class)->mark($this->partnerUser, $this->admin, [
            'legal_name' => 'PlaCla Test s.r.o.',
        ]);

        $this->advertiser = User::factory()->create([
            'name' => 'Secret Buyer LLC',
            'email' => 'secretbuyer@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->site = Site::create([
            'publisher_id' => $this->partnerUser->id,
            'site_name' => 'Garden Today',
            'site_url' => 'https://garden-today.example',
            'domain' => 'garden-today.example',
            'da' => 30,
            'dr' => 32,
            'traffic' => 4000,
            'country' => 'cz',
            'language' => 'cs',
            'countries' => ['cz'],
            'languages' => ['cs'],
            'category' => 'home',
            'price' => 40,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Partner dashboard test site',
            'verified' => true,
            'active' => true,
        ]);

        $this->swap(LiveUrlHealthChecker::class, new class extends LiveUrlHealthChecker
        {
            public function check(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'checked_at' => now(), 'message' => 'OK'];
            }
        });
    }

    private function partnerUrl(string $path = '/'): string
    {
        return 'http://partners.seolinkbuildings.com'.$path;
    }

    private function makeOrderItem(array $overrides = []): OrderItem
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 127.50,
            'tax' => 0,
            'total_amount' => 127.50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $overrides['order_status'] ?? 'pending',
            'paid_at' => now(),
        ]);

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article',
            'anchor_text' => 'garden tools',
            'target_url' => 'https://shop.example/tools',
            'price' => 127.50,
            'advertiser_eur_amount' => 127.50,
            'publisher_amount' => 40.00,
            'publisher_currency' => 'EUR',
            'publisher_status' => 'pending',
        ], $overrides));
    }

    public function test_partners_host_never_renders_the_marketing_site_and_sends_noindex(): void
    {
        $response = $this->get($this->partnerUrl('/advertiser/catalog'));
        $response->assertRedirect();
        $this->assertSame(
            rtrim($this->partnerUrl('/'), '/'),
            rtrim((string) $response->headers->get('Location'), '/')
        );
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $login = $this->get($this->partnerUrl('/login'));
        $login->assertOk();
        $login->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $login->assertSee('Partner dashboard');
        $login->assertDontSee('Access your dashboard to manage orders, track campaigns');
        $html = $login->getContent();
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString('Teqno Ltd', $html);
    }

    public function test_partner_host_uses_a_separate_host_only_session_cookie(): void
    {
        $response = $this->get($this->partnerUrl('/login'));
        $response->assertOk();
        $names = [];
        $domains = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $names[] = $cookie->getName();
            $domains[] = $cookie->getDomain();
        }
        $this->assertTrue(
            collect($names)->contains(fn ($name) => str_ends_with((string) $name, '-partner')),
            'Partner host must set a dedicated *-partner session cookie. Got: '.implode(',', $names)
        );
        foreach ($domains as $domain) {
            $this->assertTrue($domain === null || $domain === '', 'Partner cookie must be host-only, got '.$domain);
        }
    }

    public function test_catalog_partner_can_log_in_and_non_partners_are_refused(): void
    {
        $login = $this->post($this->partnerUrl('/login'), [
            'email' => 'partner-dash@example.com',
            'password' => 'secret-pass-12',
        ]);
        $login->assertRedirect();
        $this->assertSame(
            rtrim($this->partnerUrl('/'), '/'),
            rtrim((string) $login->headers->get('Location'), '/')
        );

        $this->get($this->partnerUrl('/'))->assertOk()->assertSee('New / pending');

        $this->post($this->partnerUrl('/logout'));

        $this->post($this->partnerUrl('/login'), [
            'email' => 'secretbuyer@example.com',
            'password' => 'password',
        ])->assertSessionHas('error')
            ->assertSessionHas('main_site_url');
    }

    public function test_home_tiles_and_orders_are_scoped_and_hide_confidential_data(): void
    {
        $pending = $this->makeOrderItem();
        $this->makeOrderItem(['order_status' => 'processing', 'publisher_status' => 'accepted', 'accepted_at' => now()]);
        $this->makeOrderItem(['order_status' => 'review', 'publisher_status' => 'accepted', 'live_url' => 'https://garden-today.example/post']);
        $this->makeOrderItem(['order_status' => 'completed', 'publisher_status' => 'completed']);
        $this->makeOrderItem(['order_status' => 'cancelled']);
        $this->makeOrderItem([
            'order_status' => 'processing',
            'publisher_status' => 'accepted',
            'modification_requested' => 'yes',
            'completion_notes' => 'Move the link',
        ]);

        $otherPublisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => Role::where('name', 'publisher')->value('id'),
        ]);
        $otherPublisher->roles()->attach(Role::where('name', 'publisher')->value('id'));
        app(PartnerAccountService::class)->mark($otherPublisher, $this->admin, ['legal_name' => 'Other']);
        $otherSite = Site::create([
            'publisher_id' => $otherPublisher->id,
            'site_name' => 'Other Site',
            'site_url' => 'https://other.example',
            'domain' => 'other.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'pl',
            'language' => 'pl',
            'countries' => ['pl'],
            'languages' => ['pl'],
            'category' => 'news',
            'price' => 40,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Other partner site',
            'verified' => true,
            'active' => true,
        ]);
        $foreign = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => '999001',
            'reference_code' => 'REF-FOREIGN',
            'subtotal' => 127.50,
            'tax' => 0,
            'total_amount' => 127.50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $foreignItem = OrderItem::create([
            'order_id' => $foreign->id,
            'site_id' => $otherSite->id,
            'site_name' => 'Other Site',
            'site_url' => 'https://other.example',
            'content_link' => 'https://docs.example/other',
            'price' => 127.50,
            'publisher_amount' => 40,
        ]);

        $home = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/'));
        $home->assertOk();
        $html = $home->getContent();
        $this->assertNoConfidentialLeak($html);
        $this->assertStringContainsString('New / pending', $html);
        $this->assertStringContainsString('Awaiting correction', $html);

        $orders = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/orders'));
        $orders->assertOk();
        $ordersHtml = $orders->getContent();
        $this->assertStringContainsString((string) $pending->order->order_number, $ordersHtml);
        $this->assertStringContainsString('€40.00', $ordersHtml);
        $this->assertStringNotContainsString('999001', $ordersHtml);
        $this->assertNoConfidentialLeak($ordersHtml);

        $this->actingAs($this->partnerUser)->get($this->partnerUrl('/orders/'.$foreignItem->id))->assertNotFound();

        $show = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/orders/'.$pending->id));
        $show->assertOk();
        $showHtml = $show->getContent();
        $this->assertStringContainsString('garden tools', $showHtml);
        $this->assertStringContainsString('B-', $showHtml);
        $this->assertNoConfidentialLeak($showHtml);
    }

    public function test_partner_can_accept_and_submit_using_existing_publisher_flow(): void
    {
        $item = $this->makeOrderItem();
        $this->actingAs($this->partnerUser)
            ->post($this->partnerUrl('/orders/'.$item->id.'/accept'))
            ->assertRedirect();
        $this->assertSame('processing', $item->order->fresh()->status);
        $this->assertSame('accepted', $item->fresh()->publisher_status);

        $this->actingAs($this->partnerUser)
            ->post($this->partnerUrl('/orders/'.$item->id.'/submit'), [
                'live_url' => 'https://garden-today.example/live-post',
            ])->assertRedirect();
        $this->assertSame('https://garden-today.example/live-post', $item->fresh()->live_url);
        $this->assertSame('review', $item->order->fresh()->status);
    }

    public function test_sites_page_shows_only_the_partner_rate(): void
    {
        PartnerCatalogItem::query()->create([
            'partner_id' => $this->partner->id,
            'listing_status' => PartnerCatalogItem::STATUS_LIVE,
            'domain' => 'garden-today.example',
            'site_url' => 'https://garden-today.example',
            'site_name' => 'Garden Today',
            'partner_rate_currency' => 'EUR',
            'partner_rate_publish' => 40,
            'partner_rate_publish_eur' => 40,
            'slb_price_publish_eur' => 127.50,
        ]);
        PartnerCatalogItem::query()->create([
            'partner_id' => $this->partner->id,
            'listing_status' => PartnerCatalogItem::STATUS_UNUSED,
            'domain' => 'held-site.example',
            'site_url' => 'https://held-site.example',
            'site_name' => 'Held site',
            'block_reason' => 'below_margin_floor',
            'partner_rate_currency' => 'EUR',
            'partner_rate_publish' => 40,
            'partner_rate_publish_eur' => 40,
            'slb_price_publish_eur' => 127.50,
        ]);

        $html = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/sites'))->assertOk()->getContent();
        $this->assertStringContainsString('Live', $html);
        $this->assertStringContainsString('Not listed', $html);
        $this->assertStringContainsString('held-site.example', $html);
        $this->assertStringNotContainsString('below_margin_floor', $html);
        $this->assertStringNotContainsString('Unused', $html);
        $this->assertNoConfidentialLeak($html);
        $this->assertStringContainsString('€40.00', $html);
    }

    public function test_money_page_uses_partner_ledger_not_wallet(): void
    {
        $html = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/money'))->assertOk()->getContent();
        $this->assertStringContainsString('Held', $html);
        $this->assertStringContainsString('Available to withdraw', $html);
        $this->assertStringContainsString('No fees', $html);
        $this->assertStringContainsString('20.00', $html);
        $this->assertNoConfidentialLeak($html);
    }

    public function test_settings_email_change_confirms_on_new_address_only(): void
    {
        $this->actingAs($this->partnerUser)->post($this->partnerUrl('/settings/email'), [
            'email' => 'partner-new@example.com',
            'current_password' => 'secret-pass-12',
        ])->assertRedirect();

        $this->assertSame('partner-dash@example.com', $this->partnerUser->fresh()->email);

        $confirm = null;
        Mail::assertQueued(PartnerEmailChangeConfirmMail::class, function ($mail) use (&$confirm) {
            $confirm = $mail->plainToken;

            return $mail->change->new_email === 'partner-new@example.com';
        });
        Mail::assertQueued(PartnerEmailChangeCancelMail::class);

        $this->get($this->partnerUrl('/email-change/confirm/'.$confirm))->assertRedirect();
        $this->assertSame('partner-new@example.com', $this->partnerUser->fresh()->email);
    }

    public function test_email_change_cancel_from_old_address_locks_the_account(): void
    {
        $this->actingAs($this->partnerUser)->post($this->partnerUrl('/settings/email'), [
            'email' => 'hijack@example.com',
            'current_password' => 'secret-pass-12',
        ]);
        $cancel = null;
        Mail::assertQueued(PartnerEmailChangeCancelMail::class, function ($mail) use (&$cancel) {
            $cancel = $mail->plainToken;

            return true;
        });
        $this->get($this->partnerUrl('/email-change/cancel/'.$cancel))->assertRedirect();
        $this->assertSame('partner-dash@example.com', $this->partnerUser->fresh()->email);
        $this->assertNotNull($this->partner->fresh()->security_review_at);
    }

    public function test_one_click_link_is_single_use_order_scoped_and_get_has_no_side_effect(): void
    {
        $item = $this->makeOrderItem();
        $token = app(PartnerEmailActionService::class)->issue($this->partnerUser, (int) $item->id, ['view', 'accept']);

        $first = $this->get($this->partnerUrl('/a/'.$token));
        $first->assertRedirect($this->partnerUrl('/orders/'.$item->id));
        $this->assertSame('pending', $item->order->fresh()->status);

        $second = $this->get($this->partnerUrl('/a/'.$token));
        $second->assertRedirect();
        $this->assertStringContainsString('/login', (string) $second->headers->get('Location'));

        $this->actingAs($this->partnerUser)
            ->withSession([
                'partner_scope_level' => 'order',
                'partner_scope_item_id' => $item->id,
                'partner_scope_max_at' => now()->addHour()->timestamp,
                'partner_scope_idle_at' => now()->addHour()->timestamp,
            ])
            ->get($this->partnerUrl('/money'))
            ->assertRedirect();
    }

    public function test_one_click_link_fails_after_password_change(): void
    {
        $item = $this->makeOrderItem();
        $token = app(PartnerEmailActionService::class)->issue($this->partnerUser, (int) $item->id, ['view']);
        $this->actingAs($this->partnerUser)->post($this->partnerUrl('/settings/password'), [
            'current_password' => 'secret-pass-12',
            'password' => 'brand-new-pass-12',
            'password_confirmation' => 'brand-new-pass-12',
        ]);
        $this->flushSession();
        $afterPassword = $this->get($this->partnerUrl('/a/'.$token));
        $afterPassword->assertRedirect();
        $this->assertStringContainsString('/login', (string) $afterPassword->headers->get('Location'));
    }

    public function test_partner_alerts_replace_leaking_publisher_mail(): void
    {
        $item = $this->makeOrderItem();
        $sent = app(PartnerAlertService::class)->sendNewOrderIfPartner($this->partnerUser, $this->site, [$item->order]);
        $this->assertTrue($sent);
        Mail::assertQueued(PartnerNewOrderMail::class);
        Mail::assertNotQueued(SiteOwnerOrderNotification::class);

        app(PartnerAlertService::class)->sendCorrectionIfPartner($this->partnerUser, $item->order, 'Please move the link.');
        Mail::assertQueued(PartnerCorrectionMail::class);
        Mail::assertNotQueued(ModificationRequested::class);

        $html = (new PartnerNewOrderMail($this->partnerUser, $item, 'https://partners.seolinkbuildings.com/a/demo'))->render();
        $this->assertNoConfidentialLeak($html);
        $this->assertStringNotContainsString('Secret Buyer', $html);

        $payoutHtml = (new PartnerPayoutReleasedMail($this->partnerUser, new Withdrawal([
            'amount' => 40,
            'net_amount' => 40,
            'currency' => 'EUR',
        ])))->render();
        $this->assertNoConfidentialLeak($payoutHtml);
    }

    public function test_path_fallback_login_works_in_testing(): void
    {
        $this->assertTrue(PartnerDashboard::pathFallbackEnabled());
        $this->get('/partners/login')->assertOk()->assertSee('Catalog partners only');
    }

    public function test_main_site_publisher_experience_is_unchanged(): void
    {
        $item = $this->makeOrderItem();
        $this->actingAs($this->partnerUser)
            ->get(route('publisher.tasks'))
            ->assertOk();
        $this->actingAs($this->partnerUser)
            ->post(route('publisher.orders.accept', $item->id))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertSame('processing', $item->order->fresh()->status);
    }

    public function test_set_password_invite_for_partners_uses_the_partners_host(): void
    {
        $url = PublisherStaffOnboardingMail::signedSetPasswordUrl($this->partnerUser);
        $this->assertStringContainsString('partners.seolinkbuildings.com', $url);
        $this->assertStringContainsString('/onboard/password/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_set_password_page_on_partner_host_uses_partner_chrome(): void
    {
        $url = PublisherStaffOnboardingMail::signedSetPasswordUrl($this->partnerUser);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('Set your password', $html);
        $this->assertStringContainsString('Teqno Ltd', $html);
        $this->assertStringNotContainsString('Access your dashboard to manage orders, track campaigns', $html);
        $this->assertNoConfidentialLeak($html);
    }

    private function assertNoConfidentialLeak(string $html): void
    {
        $scan = app(PartnerConfidentiality::class);
        $this->assertSame([], $scan->scanText($html));
        $this->assertSame([], $scan->scanAmounts($html, [127.50]));
        $this->assertStringNotContainsString('127.50', $html);
        $this->assertStringNotContainsString('Secret Buyer', $html);
        $this->assertStringNotContainsString('secretbuyer@example.com', $html);
    }
}
