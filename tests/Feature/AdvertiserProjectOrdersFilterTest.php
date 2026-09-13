<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdvertiserProjectOrdersFilterTest extends TestCase
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

    private function siteFor(User $publisher, string $url = 'https://publisher-host.example'): Site
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'publisher-host.example';

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Project Filter Site',
            'site_url' => $url,
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

    private function makeOrder(User $advertiser, Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-PF-'.uniqid(),
            'reference_code' => 'REF-PF-'.uniqid(),
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

        return $order->fresh('items');
    }

    /**
     * @return list<int>
     */
    private function listIds(User $advertiser, array $query): array
    {
        $orders = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', $query))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('orders');

        return collect($orders)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_orders_page_shows_clearable_project_chip(): void
    {
        $user = $this->advertiser();
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.orders', [
                'project' => $project->id,
                'project_stage' => 'waiting_approval',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Project: Acme Client', $html);
        $this->assertStringContainsString('Needs review', $html);
        $this->assertStringContainsString('id="ordersProjectChipClear"', $html);
        $this->assertStringContainsString('value="'.$project->id.'"', $html);
        $this->assertStringContainsString('value="waiting_approval"', $html);

        $attention = $this->actingAs($user)
            ->get(route('advertiser.orders', [
                'project' => $project->id,
                'project_stage' => 'needs_you',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Project: Acme Client · Needs attention', $attention);
        $this->assertStringNotContainsString('Project: Acme Client · Needs you', $attention);
    }

    public function test_list_filters_by_brief_target_host_not_publisher_site(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher(), 'https://acme.example');

        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $match = $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://www.acme.example/landing',
        ]);
        $publisherOnly = $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://other-client.example/page',
        ]);

        $ids = $this->listIds($user, ['project' => $project->id]);

        $this->assertSame([$match->id], $ids);
        $this->assertNotContains($publisherOnly->id, $ids);
    }

    public function test_waiting_approval_stage_requires_a_live_url(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $ready = $this->makeOrder($user, $site, [
            'status' => 'review',
        ], [
            'target_url' => 'https://acme.example/live',
            'live_url' => 'https://publisher.example/posted',
        ]);
        $waitingUrl = $this->makeOrder($user, $site, [
            'status' => 'review',
        ], [
            'target_url' => 'https://acme.example/waiting',
        ]);

        $readyIds = $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'waiting_approval',
        ]);
        $reviewIds = $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'in_review',
        ]);
        $needsYouIds = $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'needs_you',
        ]);

        $this->assertSame([$ready->id], $readyIds);
        $this->assertSame([$waitingUrl->id], $reviewIds);
        $this->assertSame([$ready->id], $needsYouIds);
    }

    public function test_foreign_project_id_returns_an_empty_list(): void
    {
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        $foreign = Project::create([
            'user_id' => $owner->id,
            'project_name' => 'Owned Client',
            'project_url' => 'https://owned.example',
        ]);

        $this->makeOrder($other, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://owned.example/page',
        ]);

        $ids = $this->listIds($other, ['project' => $foreign->id]);

        $this->assertSame([], $ids);
    }

    public function test_list_matches_brief_target_when_item_url_is_empty(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());
        $submission = $this->createApprovedSubmission(
            $user,
            $site->id,
            target: 'https://www.acme.example/from-brief',
        );

        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $match = $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => '',
            'content_submission_id' => $submission->id,
        ]);
        $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://unrelated.example/page',
        ]);

        $ids = $this->listIds($user, ['project' => $project->id]);

        $this->assertSame([$match->id], $ids);
    }

    public function test_failed_payment_is_rejected_not_needs_review_or_not_started(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $failedPending = $this->makeOrder($user, $site, [
            'status' => 'pending',
            'payment_status' => 'failed',
            'paid_at' => null,
        ], [
            'target_url' => 'https://acme.example/pay',
        ]);
        $failedReview = $this->makeOrder($user, $site, [
            'status' => 'review',
            'payment_status' => 'failed',
        ], [
            'target_url' => 'https://acme.example/live',
            'live_url' => 'https://publisher.example/posted',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<span class="project-stage__label">Rejected<\/span>\s*<span class="project-stage__count[^"]*">\s*2\s*</',
            $html
        );

        $this->assertSame([], $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'not_started',
        ]));
        $this->assertSame([], $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'waiting_approval',
        ]));
        $this->assertSame([], $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'needs_you',
        ]));

        $rejected = $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'rejected',
        ]);
        $this->assertEqualsCanonicalizing([$failedPending->id, $failedReview->id], $rejected);
    }

    public function test_list_matches_target_urls_with_a_port_or_userinfo(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $withPort = $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://acme.example:443/landing',
        ]);
        $withUserinfo = $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://user:pass@acme.example/secure',
        ]);
        $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://acme.example.evil:443/nope',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://other-client.example/?email=foo@acme.example',
        ]);

        $ids = $this->listIds($user, ['project' => $project->id]);

        $this->assertEqualsCanonicalizing([$withPort->id, $withUserinfo->id], $ids);
    }

    public function test_revision_requested_is_needs_improvements_not_in_progress(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $revision = $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://acme.example/rev',
            'modification_requested' => 'yes',
        ]);
        $working = $this->makeOrder($user, $site, [
            'status' => 'processing',
        ], [
            'target_url' => 'https://acme.example/ok',
            'modification_requested' => 'no',
        ]);

        $this->assertSame([$revision->id], $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'needs_improvements',
        ]));
        $this->assertSame([$working->id], $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'in_progress',
        ]));
        $this->assertEqualsCanonicalizing([$revision->id], $this->listIds($user, [
            'project' => $project->id,
            'project_stage' => 'needs_you',
        ]));
    }

    public function test_array_project_params_do_not_500(): void
    {
        $user = $this->advertiser();

        $this->actingAs($user)
            ->get(route('advertiser.orders', [
                'project' => ['1'],
                'project_stage' => ['waiting_approval'],
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('advertiser.orders.list', [
                'project' => ['1'],
                'project_stage' => ['waiting_approval'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
