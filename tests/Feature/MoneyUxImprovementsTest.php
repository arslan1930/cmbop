<?php

namespace Tests\Feature;

use App\Mail\PaymentFailedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Billing\BillingDocumentService;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MoneyUxImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private function advertiserWithBonus(float $bonus = 20.0, float $cash = 0.0): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $cash + $bonus,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
    }

    private function site(): Site
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Money UX Site',
            'site_url' => 'https://money-ux.example',
            'domain' => 'money-ux.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function cardOrder(User $advertiser, string $paymentStatus = 'failed', string $status = 'pending'): Order
    {
        $site = $this->site();

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 46,
            'tax' => 0,
            'total_amount' => 46,
            'payment_method' => 'card',
            'payment_status' => $paymentStatus,
            'status' => $status,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 46,
            'content_link' => 'https://example.com/article.docx',
        ]);

        return $order->fresh('items');
    }

    public function test_catalog_explains_spendable_vs_bonus_when_bonus_present(): void
    {
        $user = $this->advertiserWithBonus(20, 0);

        $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Spendable €20.00', false)
            ->assertSee('cash €0.00', false)
            ->assertSee('bonus €20.00', false)
            ->assertSee('Use bonus balance', false)
            ->assertSee('cartTotalBadge', false)
            ->assertSee('cash €0.00 · bonus €20.00', false);
    }

    public function test_checkout_ties_spendable_header_to_bonus_checkbox(): void
    {
        $user = $this->advertiserWithBonus(20, 10);
        $site = $this->site();

        $this->actingAs($user)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('Spendable (header total)', false)
            ->assertSee('Cash (withdrawable)', false)
            ->assertSee('Bonus (purchases only)', false)
            ->assertSee('Use bonus balance', false)
            ->assertSee('Company Name', false);
    }

    public function test_cart_payload_includes_estimated_total(): void
    {
        $user = $this->advertiserWithBonus();
        $site = $this->site();

        $this->actingAs($user)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46.5,
                    'quantity' => 2,
                    'language' => 'en',
                    'country' => 'us',
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->assertJsonPath('cart_total', 93)
            ->assertJsonPath('cart_count', 2);
    }

    public function test_save_billing_requires_company_name(): void
    {
        $user = $this->advertiserWithBonus();

        $this->actingAs($user)
            ->postJson(route('advertiser.save-billing-info'), [
                'billing_name' => 'Alice Agency',
                'country' => 'US',
                'city' => 'Austin',
                'address' => '1 Main St',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name']);

        $this->actingAs($user)
            ->postJson(route('advertiser.save-billing-info'), [
                'billing_name' => 'Alice Agency',
                'company_name' => 'Alice SEO LLC',
                'country' => 'US',
                'state' => 'TX',
                'city' => 'Austin',
                'address' => '1 Main St',
                'postal_code' => '78701',
                'vat_number' => 'US123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertSame('Alice SEO LLC', $user->company_name);
        $this->assertSame('TX', $user->state);
        $this->assertSame('US123', $user->vat_number);

        $this->actingAs($user)
            ->getJson(route('advertiser.get-billing-info'))
            ->assertOk()
            ->assertJsonPath('data.has_info', true);
    }

    public function test_invoice_snapshot_includes_company_state_and_vat(): void
    {
        Mail::fake();
        $user = $this->advertiserWithBonus();
        $user->forceFill([
            'billing_name' => 'Alice Agency',
            'company_name' => 'Alice SEO LLC',
            'address' => '1 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
            'country' => 'US',
            'vat_number' => 'US-VAT-9',
        ])->save();

        $order = $this->cardOrder($user, 'paid', 'pending');
        $order->update(['payment_status' => 'paid', 'paid_at' => now()]);

        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order->fresh(['user', 'items']));
        $this->assertNotNull($invoice);
        $snap = $invoice->billing_snapshot;
        $this->assertSame('Alice SEO LLC', $snap['company'] ?? null);
        $this->assertSame('TX', $snap['state'] ?? null);
        $this->assertSame('US-VAT-9', $snap['vat_number'] ?? null);
    }

    public function test_mark_orders_failed_from_reference_leaves_order_retryable(): void
    {
        Mail::fake();
        $user = $this->advertiserWithBonus();
        $order = $this->cardOrder($user, 'pending', 'pending');

        $failed = app(OrderPaymentService::class)
            ->markOrdersFailedFromReference($order->reference_code, 'Checkout session expired');

        $this->assertCount(1, $failed);
        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('pending', $order->status);

        $this->actingAs($user)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('orders.0.can_retry_payment', true);
    }

    public function test_retry_payment_rejected_when_not_failed(): void
    {
        $user = $this->advertiserWithBonus();
        $order = $this->cardOrder($user, 'paid', 'pending');

        $this->actingAs($user)
            ->postJson(route('advertiser.orders.retry-payment', $order))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_payment_failed_mail_links_to_orders_not_empty_checkout(): void
    {
        Mail::fake();
        $user = $this->advertiserWithBonus();
        $user->forceFill([
            'billing_name' => 'Alice',
            'company_name' => 'Alice Co',
            'address' => '1 Main',
            'city' => 'Austin',
            'country' => 'US',
        ])->save();

        $order = $this->cardOrder($user, 'failed', 'pending');
        $doc = app(BillingDocumentService::class)
            ->handlePaymentFailed($order->fresh(['user', 'items']), 'Session expired');

        $this->assertNotNull($doc);
        $mailable = new PaymentFailedMail($doc->fresh(['user', 'order']));
        $mailable->assertSeeInHtml('Pay again');
        $built = $mailable->build();
        $this->assertStringContainsString('advertiser/orders', $built->viewData['retryUrl'] ?? '');
        $this->assertStringNotContainsString('/checkout"', $built->viewData['retryUrl'] ?? 'x');
    }

    public function test_billing_index_mentions_company_details(): void
    {
        $user = $this->advertiserWithBonus();

        $this->actingAs($user)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertSee('company name', false)
            ->assertSee('VAT / tax ID', false);
    }
}
