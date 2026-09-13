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

class AdvertiserProjectsUxTest extends TestCase
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

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Projects UX Site',
            'site_url' => 'https://projects-ux.example',
            'domain' => 'projects-ux.example',
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
            'order_number' => 'ORD-PRJ-'.uniqid(),
            'reference_code' => 'REF-PRJ-'.uniqid(),
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

    private function projectCardHtml(string $html, string $projectName): string
    {
        $needle = e($projectName);
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, 'Expected project "'.$projectName.'" in HTML');

        $nextCard = strpos($html, 'project-card-col', $pos + 1);
        $length = $nextCard !== false ? $nextCard - $pos : 12000;

        return substr($html, $pos, max($length, 1));
    }

    private function badgeCount(string $cardHtml, string $title): int
    {
        $pattern = '/<span class="project-stage__label">'.preg_quote($title, '/').'<\/span>\s*<span class="project-stage__count[^"]*">\s*(\d+)\s*</';
        $this->assertMatchesRegularExpression($pattern, $cardHtml, 'Missing "'.$title.'" badge');
        preg_match($pattern, $cardHtml, $match);

        return (int) $match[1];
    }

    /**
     * @param  array<string, int>  $expected
     */
    private function assertStageCounts(string $cardHtml, array $expected = []): void
    {
        $defaults = [
            'Not started' => 0,
            'In progress' => 0,
            'In review' => 0,
            'Needs review' => 0,
            'Needs you' => 0,
            'Completed' => 0,
            'Rejected' => 0,
        ];

        foreach (array_merge($defaults, $expected) as $label => $count) {
            $this->assertSame($count, $this->badgeCount($cardHtml, $label), $label);
        }
    }

    public function test_campaigns_blade_does_not_use_rand_for_badges(): void
    {
        $blade = file_get_contents(resource_path('views/advertiser/campaigns.blade.php'));

        $this->assertIsString($blade);
        $this->assertStringNotContainsString('rand(', $blade);
        $this->assertStringContainsString('data-slb-confirm="This project will be removed', $blade);
        $this->assertStringContainsString("@section('title', 'Projects')", $blade);
        $this->assertStringContainsString('type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>', $blade);
        $this->assertSame(2, substr_count($blade, 'data-bs-dismiss="modal">Cancel</button>'));
    }

    public function test_update_keeps_the_same_project_name(): void
    {
        $user = $this->advertiser();
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->put(route('advertiser.projects.update', $project), [
                'project_name' => 'Acme Client',
                'project_url' => 'https://acme.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame('Acme Client', $project->fresh()->project_name);
    }

    public function test_update_rejects_another_of_the_same_users_project_names(): void
    {
        $user = $this->advertiser();
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'First Client',
            'project_url' => 'https://first.example',
        ]);
        $second = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Second Client',
            'project_url' => 'https://second.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->put(route('advertiser.projects.update', $second), [
                'project_name' => 'First Client',
                'project_url' => 'https://second.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHasErrors([
                'project_name' => 'You already have a project with this name.',
            ]);

        $this->assertSame('Second Client', $second->fresh()->project_name);
    }

    public function test_update_allows_a_name_already_used_by_another_advertiser(): void
    {
        $other = $this->advertiser();
        Project::create([
            'user_id' => $other->id,
            'project_name' => 'Shared Name',
            'project_url' => 'https://other-shared.example',
        ]);

        $user = $this->advertiser();
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'My Client',
            'project_url' => 'https://mine.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->put(route('advertiser.projects.update', $project), [
                'project_name' => 'Shared Name',
                'project_url' => 'https://mine.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame('Shared Name', $project->fresh()->project_name);
        $this->assertSame('shared-name-'.$user->id, $project->fresh()->slug);
        $this->assertNotSame($project->fresh()->slug, Project::where('user_id', $other->id)->value('slug'));
    }

    public function test_projects_page_shows_zero_badges_when_no_matching_placements(): void
    {
        $user = $this->advertiser();
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Empty Client',
            'project_url' => 'https://empty.example',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->assertSee('Empty Client', false)
            ->getContent();

        $card = $this->projectCardHtml($html, 'Empty Client');
        $this->assertStageCounts($card);
        $this->assertStringContainsString('empty.example', $card);
        $this->assertStringNotContainsString('data-projects-attention', $html);
    }

    public function test_projects_page_counts_placements_by_target_url_host(): void
    {
        $user = $this->advertiser();
        $other = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Beta Client',
            'project_url' => 'https://beta.example',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://www.acme.example/blog/post',
        ]);
        $this->makeOrder($user, $site, [
            'status' => 'processing',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://beta.example/landing',
        ]);
        $this->makeOrder($user, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://unrelated.example/page',
        ]);
        $this->makeOrder($other, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://acme.example/other-user',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $acme = $this->projectCardHtml($html, 'Acme Client');
        $this->assertStageCounts($acme, ['Completed' => 1]);

        $beta = $this->projectCardHtml($html, 'Beta Client');
        $this->assertStageCounts($beta, ['In progress' => 1]);
    }

    public function test_store_rejects_names_that_would_fail_on_update(): void
    {
        $user = $this->advertiser();

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->post(route('advertiser.projects.store'), [
                'project_name' => 'Acme GmbH!',
                'project_url' => 'https://acme-gmbh.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHasErrors([
                'project_name' => 'Use letters, numbers, spaces, and hyphens only.',
            ]);

        $this->assertSame(0, Project::where('user_id', $user->id)->count());
    }

    public function test_store_rejects_a_www_duplicate_of_an_existing_project_host(): void
    {
        $user = $this->advertiser();
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->post(route('advertiser.projects.store'), [
                'project_name' => 'Acme Www',
                'project_url' => 'https://www.acme.example/about',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHasErrors('project_url');

        $this->assertSame(1, Project::where('user_id', $user->id)->count());
    }

    public function test_names_that_share_a_slug_do_not_500(): void
    {
        $user = $this->advertiser();
        $first = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme-one.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->post(route('advertiser.projects.store'), [
                'project_name' => 'Acme-Client',
                'project_url' => 'https://acme-two.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $second = Project::where('user_id', $user->id)->where('project_name', 'Acme-Client')->first();
        $this->assertNotNull($second);
        $this->assertSame('acme-client-'.$user->id, $first->fresh()->slug);
        $this->assertSame('acme-client-'.$user->id.'-2', $second->slug);
    }

    public function test_projects_page_is_linked_from_the_advertiser_sidebar(): void
    {
        $user = $this->advertiser();

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->assertSee('>Projects</span>', false)
            ->getContent();

        $this->assertStringContainsString(route('advertiser.projects.index', [], false), $html);
        $this->assertStringContainsString('<title>Projects</title>', $html);
        $this->assertStringContainsString('>Projects</h2>', $html);
        $this->assertStringContainsString('destination host matches this project', $html);
    }

    public function test_review_without_live_url_counts_as_in_review(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://acme.example/waiting',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $this->assertStageCounts($this->projectCardHtml($html, 'Acme Client'), [
            'In review' => 1,
        ]);
        $this->assertStringNotContainsString('data-projects-attention', $html);
    }

    public function test_review_with_live_url_counts_as_needs_review_and_attention(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://acme.example/live',
            'live_url' => 'https://publisher.example/posted',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $this->assertStageCounts($this->projectCardHtml($html, 'Acme Client'), [
            'Needs review' => 1,
        ]);
        $this->assertStringContainsString('data-projects-attention', $html);
        $this->assertStringContainsString('1 placement needs you across 1 project', $html);
        $this->assertStringNotContainsString('is-hot', $html);
        $this->assertStringNotContainsString('is-attention', $html);
        $this->assertStringNotContainsString('Guest posting', $html);
        $this->assertStringNotContainsString('project-stage__count pulse-badge', $html);
        $this->assertStringContainsString('Show placements needing you', $html);
        $this->assertStringContainsString('View orders', $html);
        $project = Project::where('user_id', $user->id)->where('project_name', 'Acme Client')->first();
        $this->assertNotNull($project);
        $this->assertStringContainsString(
            e(route('advertiser.orders', ['project' => $project->id], false)),
            $html
        );
        $this->assertStringContainsString(
            e(route('advertiser.orders', ['project' => $project->id, 'project_stage' => 'waiting_approval'], false)),
            $html
        );
        $this->assertStringContainsString(
            e(route('advertiser.orders', ['project' => $project->id, 'project_stage' => 'needs_you'], false)),
            $html
        );
    }

    public function test_brief_target_url_matches_project_when_item_url_is_empty(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());
        $submission = $this->createApprovedSubmission(
            $user,
            $site->id,
            target: 'https://www.acme.example/from-brief',
        );

        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'processing',
            'payment_status' => 'paid',
        ], [
            'target_url' => '',
            'content_submission_id' => $submission->id,
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $this->assertStageCounts($this->projectCardHtml($html, 'Acme Client'), [
            'In progress' => 1,
        ]);
    }

    public function test_attention_projects_sort_ahead_of_newer_quiet_cards(): void
    {
        $user = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        $urgent = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Urgent Client',
            'project_url' => 'https://urgent.example',
        ]);
        $urgent->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Quiet Client',
            'project_url' => 'https://quiet.example',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://urgent.example/page',
            'live_url' => 'https://publisher.example/urgent',
        ]);
        $this->makeOrder($user, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://quiet.example/page',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $urgentPos = strpos($html, 'Urgent Client');
        $quietPos = strpos($html, 'Quiet Client');
        $this->assertNotFalse($urgentPos);
        $this->assertNotFalse($quietPos);
        $this->assertLessThan($quietPos, $urgentPos);
        $this->assertTrue($urgent->created_at->lt(Project::where('project_name', 'Quiet Client')->value('created_at')));
    }

    public function test_empty_state_offers_create_project(): void
    {
        $user = $this->advertiser();

        $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->assertSee('No projects yet', false)
            ->assertSee('Create project', false)
            ->assertDontSee('Perfect For Agencies', false);
    }

    public function test_advertiser_cannot_change_another_users_project(): void
    {
        $owner = $this->advertiser();
        $project = Project::create([
            'user_id' => $owner->id,
            'project_name' => 'Owned Client',
            'project_url' => 'https://owned.example',
        ]);
        $other = $this->advertiser();

        $this->actingAs($other)
            ->put(route('advertiser.projects.update', $project), [
                'project_name' => 'Stolen Client',
                'project_url' => 'https://stolen.example',
            ])
            ->assertForbidden();

        $this->actingAs($other)
            ->delete(route('advertiser.projects.destroy', $project))
            ->assertForbidden();

        $this->assertSame('Owned Client', $project->fresh()->project_name);
    }

    public function test_publisher_cannot_open_projects(): void
    {
        $this->actingAs($this->publisher())
            ->get(route('advertiser.projects.index'))
            ->assertForbidden();
    }

    public function test_create_form_explains_name_and_host_rules(): void
    {
        $blade = file_get_contents(resource_path('views/advertiser/partials/project-fields.blade.php'));

        $this->assertIsString($blade);
        $this->assertStringContainsString('Letters, numbers, spaces, and hyphens only', $blade);
        $this->assertStringContainsString('www and the bare host count as the same site', $blade);
    }
}
