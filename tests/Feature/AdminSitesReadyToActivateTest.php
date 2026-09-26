<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\MarketingOpsQueues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSitesReadyToActivateTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles']);
    }

    private function site(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ready Row Site',
            'site_url' => 'https://ready-row.example',
            'domain' => 'ready-row.example',
            'da' => 88,
            'dr' => 46,
            'traffic' => 14000,
            'country' => 'fr',
            'countries' => ['fr'],
            'language' => 'fr',
            'languages' => ['fr'],
            'category' => 'Tech',
            'categories' => ['Tech', 'Parenting', 'News', 'Lifestyle'],
            'price' => 120,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Ready to activate listing. ', 3),
            'verified' => true,
            'active' => false,
            'onboarding_status' => null,
            'metrics_manual' => true,
            'metrics_fetched_at' => now(),
        ], $overrides));
    }

    public function test_publisher_rows_name_failures_and_ready_queue_is_per_publisher(): void
    {
        $admin = $this->userWithRole('admin');
        $marketer = $this->userWithRole('marketing');
        $publisher = $this->userWithRole('publisher');
        $other = $this->userWithRole('publisher');

        $thin = $this->site($publisher, [
            'site_name' => 'Maman de 4',
            'site_url' => 'https://maman-de-4.example',
            'domain' => 'maman-de-4.example',
            'da' => 59,
            'dr' => 10,
            'traffic' => 3000,
            'metrics_manual' => false,
        ]);
        $ready = $this->site($publisher, [
            'site_name' => 'Byothe.fr',
            'site_url' => 'https://byothe.example',
            'domain' => 'byothe.example',
        ]);
        $this->site($other, [
            'site_name' => 'Other Review Good',
            'site_url' => 'https://other-review-good.example',
            'domain' => 'other-review-good.example',
            'verified' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'metrics_manual' => false,
        ]);
        $this->site($other, [
            'site_name' => 'Other Review Thin',
            'site_url' => 'https://other-review-thin.example',
            'domain' => 'other-review-thin.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'verified' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'metrics_manual' => false,
        ]);

        $thin->refresh();
        $ready->refresh();
        $this->assertFalse($thin->hasGoodMetrics());
        $this->assertSame([
            'DR 10 (need 30)',
            'traffic 3,000 (need 10,000)',
        ], $thin->qualityBarFailures());
        $this->assertFalse($thin->hasCatalogCover());
        $this->assertFalse($thin->isReadyToActivate());
        $this->assertTrue($ready->isReadyToActivate());
        $this->assertSame(
            [$ready->id],
            Site::query()->readyToActivate()->orderBy('id')->pluck('id')->all()
        );

        $rows = $this->actingAs($admin)
            ->getJson(route('admin.users.sites', $publisher->id))
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.ready_to_activate', 1)
            ->assertJsonPath('summary.below_quality', 1)
            ->json('sites');

        $this->assertSame(2, MarketingOpsQueues::sitesReadyForStaffCount());

        $byId = collect($rows)->keyBy('id');
        $this->assertSame(['DR 10 (need 30)', 'traffic 3,000 (need 10,000)'], $byId[$thin->id]['quality_failures']);
        $this->assertTrue($byId[$thin->id]['missing_cover']);
        $this->assertTrue($byId[$thin->id]['missing_tags']);
        $this->assertFalse($byId[$thin->id]['ready_to_activate']);
        $this->assertTrue($byId[$thin->id]['can_activate']);
        $this->assertSame(['Tech', 'Parenting', 'News', 'Lifestyle'], $byId[$thin->id]['categories_list']);
        $this->assertTrue($byId[$ready->id]['ready_to_activate']);
        $this->assertSame('Manual', $byId[$ready->id]['metrics_source']);
        $this->assertNotNull($byId[$ready->id]['metrics_fetched_label']);

        $marketerRows = collect(
            $this->actingAs($marketer)
                ->getJson(route('marketing.users.sites', $publisher->id))
                ->assertOk()
                ->assertJsonPath('summary.total', 2)
                ->assertJsonPath('summary.ready_to_activate', 1)
                ->json('sites')
        )->keyBy('id');
        $this->assertFalse($marketerRows[$thin->id]['can_activate']);
        $this->assertTrue($marketerRows[$ready->id]['can_activate']);

        $index = $this->actingAs($admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('Ready to activate', false)
            ->assertSee('btn-outline-warning', false)
            ->assertSee('Apply to all', false)
            ->assertSee('Buyer price', false)
            ->assertSee('Fix metrics', false)
            ->assertSee('Not for sale', false)
            ->assertSee('data-staff-filter="ready_to_activate"', false)
            ->assertSee('id="siteUserSummary"', false)
            ->assertSee('formatJoined(site.categories_list, false, 0)', false)
            ->getContent();
        $this->assertStringNotContainsString('btn btn-sm btn-warning', $index);

        $this->actingAs($admin)
            ->get(route('admin.sites.index', ['all' => 1, 'ready_to_activate' => 1]))
            ->assertOk()
            ->assertSee('Byothe.fr', false)
            ->assertDontSee('Maman de 4', false)
            ->assertSee('btn-success', false);

        $this->actingAs($admin)
            ->get(route('admin.sites.index', ['all' => 1, 'below_quality' => 1]))
            ->assertOk()
            ->assertSee('Below quality bar — DR 10 (need 30), traffic 3,000 (need 10,000)', false)
            ->assertSee('No cover', false)
            ->assertSee('No tags', false);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $thin->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('below_quality_bar', true)
            ->assertJsonFragment(['warning' => 'Activated below the quality bar (DA ≥ 30, DR ≥ 30, traffic ≥ 10,000). Listing is live; consider updating metrics before promoting it.']);

        $thin->refresh();
        $thin->forceFill(['active' => false])->save();
        $this->assertFalse((bool) $thin->fresh()->active);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $thin->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('below_quality_bar', true);

        $this->assertFalse((bool) $thin->fresh()->active);

        $bulk = $this->actingAs($marketer)
            ->postJson(route('marketing.sites.bulk-action'), [
                'action' => 'activate',
                'ids' => [$ready->id, $thin->id],
            ])
            ->assertOk()
            ->assertJsonPath('updated', [$ready->id]);

        $this->assertStringContainsString('1 skipped', (string) $bulk->json('message'));
        $this->assertStringContainsString('below the quality bar', (string) $bulk->json('message'));
        $this->assertTrue((bool) $ready->fresh()->active);
        $this->assertFalse((bool) $thin->fresh()->active);
    }
}
