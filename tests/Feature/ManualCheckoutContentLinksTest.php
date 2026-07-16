<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManualCheckoutContentLinksTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug, float $price = 50): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site ' . $slug,
            'site_url' => 'https://' . $slug . '.example',
            'domain' => $slug . '.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'US',
            'language' => 'en',
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site ' . $slug,
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_multi_site_manual_checkout_maps_content_links_per_site_not_globally(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        Role::firstOrCreate(['name' => 'admin']);
        $publisher = $this->publisher();

        $siteA = $this->activeSite($publisher, 'alpha', 40);
        $siteB = $this->activeSite($publisher, 'beta', 60);

        $linkA = 'https://docs.google.com/document/d/site-a-doc/edit';
        $linkB = 'https://docs.google.com/document/d/site-b-doc/edit';

        // Cart order: site A then site B (each qty 1).
        // Old bug used global index 1 for site B → looked up content_links[B][1] (missing)
        // and left content_link null (or wrong) instead of content_links[B][0].
        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    [
                        'id' => $siteA->id,
                        'name' => $siteA->site_name,
                        'quantity' => 1,
                        'sensitive_type' => null,
                    ],
                    [
                        'id' => $siteB->id,
                        'name' => $siteB->site_name,
                        'quantity' => 1,
                        'sensitive_type' => null,
                    ],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'MULTI1',
                'content_links' => [
                    $siteA->id => [$linkA],
                    $siteB->id => [$linkB],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame(2, Order::where('reference_code', 'MULTI1')->count());

        $itemA = OrderItem::where('site_id', $siteA->id)->first();
        $itemB = OrderItem::where('site_id', $siteB->id)->first();

        $this->assertNotNull($itemA);
        $this->assertNotNull($itemB);
        $this->assertSame($linkA, $itemA->content_link);
        $this->assertSame($linkB, $itemB->content_link);
    }

    public function test_multi_copy_same_site_maps_each_content_link(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        Role::firstOrCreate(['name' => 'admin']);
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'copies', 25);

        $link1 = 'https://docs.google.com/document/d/copy-one/edit';
        $link2 = 'https://docs.google.com/document/d/copy-two/edit';

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 2,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'bank',
                'reference_code' => 'COPIES',
                'content_links' => [
                    $site->id => [$link1, $link2],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $links = OrderItem::where('site_id', $site->id)
            ->orderBy('id')
            ->pluck('content_link')
            ->all();

        $this->assertSame([$link1, $link2], $links);
    }

    public function test_manual_checkout_rejects_missing_content_link_for_second_site(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'only-a', 40);
        $siteB = $this->activeSite($publisher, 'only-b', 60);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1],
                    ['id' => $siteB->id, 'name' => $siteB->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'crypto',
                'reference_code' => 'MISS2',
                // Only site A provided — site B missing (old global-index path silently null'd this)
                'content_links' => [
                    $siteA->id => ['https://docs.google.com/document/d/only-a/edit'],
                ],
            ]);

        $response->assertOk()->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Site only-b', $response->json('message'));
        $this->assertSame(0, Order::where('reference_code', 'MISS2')->count());
    }
}
