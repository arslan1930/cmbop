<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\LiveUrlHealthChecker;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdvertiserModeGapsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, string $host = 'pub-host.example'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Gaps Site',
            'site_url' => 'https://'.$host,
            'domain' => $host,
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
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function advertiserWallet(User $advertiser, float $balance): void
    {
        $roleId = Role::firstOrCreate(['name' => 'advertiser'])->id;
        \App\Models\Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $roleId,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
        ]);
    }

    private function makeOrder(User $advertiser, Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-GAP-'.uniqid(),
            'reference_code' => 'REF-GAP-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ], $orderAttrs));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ], $itemAttrs));

        return $order->fresh(['items']);
    }

    public function test_wallet_checkout_stamps_selected_project_id(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $sub = $this->createApprovedSubmission($advertiser, null, 0, 'tools', 'https://other-client.example/page');
        $this->advertiserWallet($advertiser, 500);

        $project = Project::create([
            'user_id' => $advertiser->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'PROJ1',
                'publication_mode' => 'immediate',
                'project_id' => $project->id,
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order = Order::query()->where('user_id', $advertiser->id)->first();
        $this->assertNotNull($order);
        $this->assertSame($project->id, (int) $order->project_id);
    }

    public function test_projects_page_counts_assigned_orders_even_when_host_differs(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
            'project_id' => $project->id,
        ], [
            'target_url' => 'https://unrelated.example/page',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/project-stage--completed(?![^>]*is-zero)[^>]*>.*?project-stage__count">\s*1\s*</s',
            $html
        );
    }

    public function test_orders_list_includes_assigned_project_even_when_host_differs(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $match = $this->makeOrder($user, $site, [
            'status' => 'processing',
            'project_id' => $project->id,
        ], [
            'target_url' => 'https://other-client.example/page',
        ]);

        $orders = $this->actingAs($user)
            ->getJson(route('advertiser.orders.list', ['project' => $project->id]))
            ->assertOk()
            ->json('orders');

        $this->assertSame([$match->id], collect($orders)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('Acme Client', $orders[0]['project_name'] ?? null);
    }

    public function test_checkout_warns_about_duplicate_destination_hosts(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $sub = $this->createApprovedSubmission($advertiser, null, 0, 'tools', 'https://acme.example/new');

        $this->makeOrder($advertiser, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://www.acme.example/old',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'language' => 'en',
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('Assign to a project', false)
            ->assertSee('checkoutDuplicateHostWarning', false)
            ->assertSee('acme.example', false);
    }

    public function test_dashboard_lists_needs_you_orders(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());
        $order = $this->makeOrder($user, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'live_url' => 'https://live.example/post',
        ]);

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('id="dashNeedsYouQueue"', false)
            ->assertSee($order->order_number, false)
            ->assertSee('Check the live URL', false);
    }

    public function test_reports_copy_does_not_claim_campaign_performance(): void
    {
        $user = $this->advertiser();

        $this->actingAs($user)
            ->get(route('advertiser.reports'))
            ->assertOk()
            ->assertSee('Wallet ledger and order history', false)
            ->assertDontSee('campaign performance', false);
    }

    public function test_completed_live_url_recheck_flags_down_links_as_needs_action(): void
    {
        $this->swap(LiveUrlHealthChecker::class, new class extends LiveUrlHealthChecker
        {
            public function check(string $url): array
            {
                return [
                    'ok' => false,
                    'status' => 404,
                    'checked_at' => now(),
                    'message' => 'Link returned HTTP 404.',
                ];
            }
        });

        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());
        $order = $this->makeOrder($user, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
            'completed_at' => now()->subDay(),
        ], [
            'live_url' => 'https://live.example/gone',
            'live_url_check_ok' => true,
            'live_url_checked_at' => Carbon::now()->subDays(10),
            'completed_at' => now()->subDay(),
        ]);

        $this->artisan('orders:recheck-completed-live-urls --stale-days=7 --limit=10')
            ->assertSuccessful();

        $item = $order->items->first()->fresh();
        $this->assertFalse((bool) $item->live_url_check_ok);
        $this->assertSame(1, AdvertiserOrderStatus::needsActionCountForUser((int) $user->id));

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('link may be down', false)
            ->assertSee('did not respond', false)
            ->assertSee($order->order_number, false);
    }

    public function test_down_live_url_is_needs_you_for_the_assigned_project(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $order = $this->makeOrder($user, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
            'project_id' => $project->id,
            'completed_at' => now()->subDay(),
        ], [
            'target_url' => 'https://other-client.example/gone',
            'live_url' => 'https://live.example/gone',
            'live_url_check_ok' => false,
            'live_url_checked_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        $orders = $this->actingAs($user)
            ->getJson(route('advertiser.orders.list', [
                'project' => $project->id,
                'project_stage' => 'needs_you',
            ]))
            ->assertOk()
            ->json('orders');

        $this->assertSame([$order->id], collect($orders)->pluck('id')->map(fn ($id) => (int) $id)->all());

        $scoped = $this->actingAs($user)
            ->getJson(route('advertiser.orders.list', ['project' => $project->id]))
            ->assertOk()
            ->json('needs_action');
        $this->assertSame(1, (int) $scoped);
    }

    public function test_schedule_includes_completed_live_url_recheck(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("command('orders:recheck-completed-live-urls", $bootstrap);
    }
}
