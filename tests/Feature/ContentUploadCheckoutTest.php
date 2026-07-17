<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentUploadCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesContentSubmissions;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug, float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site ' . $slug,
            'site_url' => 'https://' . $slug . '.example',
            'domain' => $slug . '.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_manual_checkout_uses_uploaded_submissions_not_google_docs(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        Role::firstOrCreate(['name' => 'admin']);
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'alpha', 40);
        $siteB = $this->activeSite($publisher, 'beta', 60);

        $subA = $this->createApprovedSubmission($advertiser, $siteA->id, 0, 'alpha anchor', 'https://example.com/a');
        $subB = $this->createApprovedSubmission($advertiser, $siteB->id, 0, 'beta anchor', 'https://example.com/b');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1],
                    ['id' => $siteB->id, 'name' => $siteB->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'UP1',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $siteA->id => [$subA->id],
                    $siteB->id => [$subB->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $itemA = OrderItem::where('site_id', $siteA->id)->first();
        $itemB = OrderItem::where('site_id', $siteB->id)->first();

        $this->assertSame($subA->id, $itemA->content_submission_id);
        $this->assertSame($subB->id, $itemB->content_submission_id);
        $this->assertSame('alpha anchor', $itemA->anchor_text);
        $this->assertSame('https://example.com/b', $itemB->target_url);
        $this->assertSame('approved', $itemA->moderation_status);
    }

    public function test_scheduled_checkout_keeps_order_visible_to_publishers(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        Role::firstOrCreate(['name' => 'admin']);
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'sched', 50);
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $date = now()->addDays(10)->toDateString();

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'bank',
                'reference_code' => 'SCH1',
                'publication_mode' => 'scheduled',
                'scheduled_date' => $date,
                'scheduled_time' => '10:00',
                'timezone' => 'UTC',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::where('reference_code', 'SCH1')->first();
        $this->assertNotNull($order);
        // Charged in advance; visible in publisher queue; publish on scheduled date.
        $this->assertSame('pending', $order->status);
        $this->assertSame('scheduled', $order->publication_mode);
        $this->assertNotNull($order->scheduled_publish_at);
    }

    public function test_content_library_order_flow_selects_sites_and_checks_out(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        Role::firstOrCreate(['name' => 'admin']);
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'lib-a', 40);
        $siteB = $this->activeSite($publisher, 'lib-b', 55);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $response = $this->actingAs($advertiser)->post(route('advertiser.content-library.order'), [
            'content_submission_id' => $sub->id,
            'site_ids' => [$siteA->id, $siteB->id],
            'anchor_text' => 'growth marketing guide',
            'target_url' => 'https://example.com/guide',
            'publication_mode' => 'immediate',
        ]);

        $response->assertRedirect(route('advertiser.checkout'));
        $this->assertSame($sub->id, session('checkout_content_submission_id'));
        $this->assertCount(2, session('cart'));

        $checkout = $this->actingAs($advertiser)
            ->withSession([
                'cart' => session('cart'),
                'checkout_content_submission_id' => $sub->id,
                'checkout_schedule' => session('checkout_schedule'),
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'LIB1',
                'publication_mode' => 'immediate',
            ]);

        $checkout->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, OrderItem::where('content_submission_id', $sub->id)->count());
        $this->assertNotNull($sub->fresh()->order_id);
    }

    public function test_checkout_rejects_missing_submission_for_second_site(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'only-a', 40);
        $siteB = $this->activeSite($publisher, 'only-b', 60);
        $subA = $this->createApprovedSubmission($advertiser, $siteA->id);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1],
                    ['id' => $siteB->id, 'name' => $siteB->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'crypto',
                'reference_code' => 'MISS',
                'content_submissions' => [
                    $siteA->id => [$subA->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('reference_code', 'MISS')->count());
    }
}
